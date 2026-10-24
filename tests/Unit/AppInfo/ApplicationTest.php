<?php

declare(strict_types=1);

namespace OCA\PaperlessSync\Tests\Unit\AppInfo;

use OC;
use OCA\PaperlessSync\Activity\Filter;
use OCA\PaperlessSync\Activity\Provider;
use OCA\PaperlessSync\Activity\Setting;
use OCA\PaperlessSync\AppInfo\Application;
use OCA\PaperlessSync\Command\CreateAccount;
use OCA\PaperlessSync\Command\Forget;
use OCA\PaperlessSync\Command\Run;
use OCA\PaperlessSync\Command\Status;
use OCA\PaperlessSync\Cron\SendJob;
use OCA\PaperlessSync\Cron\SyncJob;
use OCA\PaperlessSync\Listener\FlowListener;
use OCA\PaperlessSync\Listener\LoadAdditionalScriptsListener;
use OCA\PaperlessSync\Notification\Notifier;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\NextcloudStorageService;
use OCA\PaperlessSync\Service\PaperlessClientFactory;
use OCA\PaperlessSync\Service\PaperlessClientFactoryInterface;
use OCA\PaperlessSync\Service\SyncStateRepository;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCA\PaperlessSync\Service\UserFilesystem;
use OCA\PaperlessSync\Service\UserFilesystemInterface;
use OCA\PaperlessSync\SetupChecks\BackgroundJobMode;
use OCA\PaperlessSync\SetupChecks\SyncHealth;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCP\Activity\ActivitySettings;
use OCP\Activity\IFilter;
use OCP\Activity\IProvider;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Notification\INotifier;
use OCP\SetupCheck\ISetupCheck;
use OCP\WorkflowEngine\Events\LoadSettingsScriptsEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;
use Symfony\Component\Console\Command\Command;

final class ApplicationTest extends TestCase {
	private static function appInfo(): SimpleXMLElement {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		self::assertInstanceOf(SimpleXMLElement::class, $info);

		return $info;
	}

	protected function tearDown(): void {
		OC::$server = null;
	}

	public function testTheAppIdIsTheOneOfTheAppInfo(): void {
		self::assertSame((string)self::appInfo()->id, Application::APP_ID);
	}

	public function testTheNamespaceIsTheOneOfTheAppInfo(): void {
		self::assertSame(
			'OCA\\' . (string)self::appInfo()->namespace . '\\AppInfo\\Application',
			Application::class,
		);
	}

	/** Nextcloud registers the commands and the background jobs that the app info names. */
	public function testTheCommandsAndJobsOfTheAppInfoExist(): void {
		$info = self::appInfo();
		$commands = array_map('strval', iterator_to_array($info->commands->command, false));
		$jobs = array_map('strval', iterator_to_array($info->{'background-jobs'}->job, false));

		self::assertSame([Run::class, Status::class, Forget::class, CreateAccount::class], $commands);
		foreach ($commands as $command) {
			self::assertTrue(is_subclass_of($command, Command::class), $command);
		}
		self::assertSame([SyncJob::class, SendJob::class], $jobs, 'A run by hand queues its job when it is asked for.');
	}

	public function testTheAppUsesTheContainerNextcloudRegisteredForIt(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$container = $this->createStub(IAppContainer::class);
		$server = new TestServer([IConfig::class => $config], $container);
		OC::$server = $server;

		$application = new Application();

		self::assertSame($container, $application->getContainer());
		self::assertSame([Application::APP_ID], $server->appContainerRequests);
	}

	public function testTheServiceInterfacesResolveToTheirImplementations(): void {
		$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$aliases = [];
		$registration = $this->createStub(IRegistrationContext::class);
		$registration->method('registerServiceAlias')->willReturnCallback(function (string $alias, string $target) use (&$aliases): void {
			$aliases[$alias] = $target;
		});
		$boot = $this->createMock(IBootContext::class);
		$boot->expects(self::never())->method(self::anything());

		$application->register($registration);
		$application->boot($boot);

		self::assertSame([
			PaperlessClientFactoryInterface::class => PaperlessClientFactory::class,
			NextcloudStorageInterface::class => NextcloudStorageService::class,
			SyncStateRepositoryInterface::class => SyncStateRepository::class,
			UserFilesystemInterface::class => UserFilesystem::class,
		], $aliases);
	}

	/** The notifications and the setup checks of Administration → Overview come from the registration of the app. */
	public function testTheAppRegistersItsNotifierAndItsSetupChecks(): void {
		$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$notifiers = [];
		$checks = [];
		$registration = $this->createStub(IRegistrationContext::class);
		$registration->method('registerNotifierService')->willReturnCallback(function (string $notifier) use (&$notifiers): void {
			$notifiers[] = $notifier;
		});
		$registration->method('registerSetupCheck')->willReturnCallback(function (string $check) use (&$checks): void {
			$checks[] = $check;
		});

		$application->register($registration);

		self::assertSame([Notifier::class], $notifiers);
		self::assertSame([SyncHealth::class, BackgroundJobMode::class], $checks);
		self::assertTrue(is_subclass_of(Notifier::class, INotifier::class));
		self::assertTrue(is_subclass_of(SyncHealth::class, ISetupCheck::class));
		self::assertTrue(is_subclass_of(BackgroundJobMode::class, ISetupCheck::class));
	}

	/**
	 * Files loads the script of the actions of the app through the event that loads the scripts of
	 * other apps, and Flow asks for the operations of the apps and loads the scripts of their forms.
	 */
	public function testTheAppBringsItsActionsIntoFilesAndFlow(): void {
		$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$listeners = [];
		$registration = $this->createStub(IRegistrationContext::class);
		$registration->method('registerEventListener')->willReturnCallback(function (string $event, string $listener) use (&$listeners): void {
			$listeners[] = [$event, $listener];
		});

		$application->register($registration);

		self::assertSame([
			['OCA\Files\Event\LoadAdditionalScriptsEvent', LoadAdditionalScriptsListener::class],
			[RegisterOperationsEvent::class, FlowListener::class],
			[LoadSettingsScriptsEvent::class, FlowListener::class],
		], $listeners);
		self::assertTrue(is_subclass_of(LoadAdditionalScriptsListener::class, IEventListener::class));
		self::assertTrue(is_subclass_of(FlowListener::class, IEventListener::class));
	}

	/** The Activity app reads its setting, filter and provider from the app info, by their classes. */
	public function testTheAppInfoRegistersTheActivityOfTheApp(): void {
		$activity = self::appInfo()->activity;

		self::assertSame([Setting::class], array_map('strval', $activity->xpath('settings/setting') ?: []));
		self::assertSame([Filter::class], array_map('strval', $activity->xpath('filters/filter') ?: []));
		self::assertSame([Provider::class], array_map('strval', $activity->xpath('providers/provider') ?: []));
		self::assertTrue(is_subclass_of(Setting::class, ActivitySettings::class));
		self::assertTrue(is_subclass_of(Filter::class, IFilter::class));
		self::assertTrue(is_subclass_of(Provider::class, IProvider::class));
	}
}

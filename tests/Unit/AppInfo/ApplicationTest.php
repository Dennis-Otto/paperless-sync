<?php

declare(strict_types=1);

namespace OCA\PaperlessSync\Tests\Unit\AppInfo;

use OC;
use OCA\PaperlessSync\AppInfo\Application;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\NextcloudStorageService;
use OCA\PaperlessSync\Service\PaperlessApiService;
use OCA\PaperlessSync\Service\PaperlessClientInterface;
use OCA\PaperlessSync\Service\SyncStateRepository;
use OCA\PaperlessSync\Service\SyncStateRepositoryInterface;
use OCA\PaperlessSync\Tests\Doubles\TestServer;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;

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

	public function testTheAppUsesTheContainerNextcloudRegisteredForIt(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$container = $this->createMock(IAppContainer::class);
		$server = new TestServer([IConfig::class => $config], $container);
		OC::$server = $server;

		$application = new Application();

		self::assertSame($container, $application->getContainer());
		self::assertSame([Application::APP_ID], $server->appContainerRequests);
	}

	public function testTheServiceInterfacesResolveToTheirImplementations(): void {
		$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$aliases = [];
		$registration = $this->createMock(IRegistrationContext::class);
		$registration->method('registerServiceAlias')->willReturnCallback(function (string $alias, string $target) use (&$aliases): void {
			$aliases[$alias] = $target;
		});
		$boot = $this->createMock(IBootContext::class);
		$boot->expects(self::never())->method(self::anything());

		$application->register($registration);
		$application->boot($boot);

		self::assertSame([
			PaperlessClientInterface::class => PaperlessApiService::class,
			NextcloudStorageInterface::class => NextcloudStorageService::class,
			SyncStateRepositoryInterface::class => SyncStateRepository::class,
		], $aliases);
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Settings;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Settings\AdminSection;
use OCA\PaperlessSync\Settings\AdminSettings;
use OCA\PaperlessSync\SetupChecks\BackgroundJobMode;
use OCA\PaperlessSync\SetupChecks\SyncHealth;
use OCA\PaperlessSync\Tests\Doubles\GermanTranslations;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestClock;
use OCP\AppFramework\Services\IInitialState;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminSettingsTest extends TestCase {
	use GermanTranslations;
	use InMemoryConfiguration;

	/** @var IURLGenerator&MockObject */
	private IURLGenerator $urlGenerator;
	/** @var array<string, mixed> the initial state of the page, by its key */
	private array $initialState = [];

	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(static fn (string $route): string => '/route/' . $route);
		$this->urlGenerator->method('getWebroot')->willReturn('/cloud');
		$this->urlGenerator->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => "/apps/{$app}/img/{$file}");
	}

	public function testFormShowsTheConfigurationTheStatusAndTheActions(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');
		$this->settings['status_last_state'] = 'completed-with-errors';
		$this->settings['status_last_report'] = '{"errors":1,"errorMessages":["P123: Paperless is unavailable"]}';
		$this->lazyKeys['status_last_report'] = true;
		$settings = $this->adminSettings();

		$form = $settings->getForm();

		self::assertSame(AppConstants::APP_ID, $form->getApp());
		self::assertSame('settings', $form->getTemplateName());
		$parameters = $form->getParams();
		self::assertInstanceOf(SyncConfig::class, $parameters['config']);
		self::assertSame('https://paperless.example.test', $parameters['config']->paperlessUrl);
		self::assertSame('completed-with-errors', $parameters['status']['state']);
		self::assertSame($parameters['status'], $this->initialState['status'], 'The page shows the status and its errors from the start.');
		self::assertSame(['P123: Paperless is unavailable'], $this->initialState['status']['report']['errorMessages']);
		self::assertNull($this->initialState['status']['nextRun']);
		self::assertSame('/route/paperless_sync.settings.save', $parameters['saveUrl']);
		self::assertSame('/route/paperless_sync.settings.test', $parameters['testUrl']);
		self::assertSame('/route/paperless_sync.settings.reset', $parameters['resetUrl']);
		self::assertSame('/route/paperless_sync.sync.run', $parameters['runUrl']);
		self::assertSame('/route/paperless_sync.sync.status', $parameters['statusUrl']);
		self::assertSame('/route/paperless_sync.settings.preview', $parameters['previewUrl']);
		self::assertSame('/route/files.view.index', $parameters['filesUrl']);
		self::assertSame('/route/paperless_sync.settings.tags', $parameters['tagsUrl']);
		self::assertSame('/cloud/ocs/v2.php/cloud/users', $parameters['usersUrl'], 'The users come from the provisioning API, below the root of the server.');
		self::assertSame($parameters['config'], $this->initialState['config'], 'The page compares the form with the stored configuration.');
	}

	/**
	 * The warnings and errors of the setup checks of the app appear on top of the page as under
	 * Administration → Overview, that of the runs first; what is fine adds nothing.
	 */
	public function testThePageShowsTheWarningsOfTheSetupChecks(): void {
		$this->configService()->save(['enabled' => false] + self::validSettings(), 'secret-token');
		self::assertSame([], $this->adminSettings()->getForm()->getParams()['health'], 'Scheduled runs that are off need nothing.');

		$this->configService()->save(['enabled' => true] + self::validSettings(), 'secret-token');
		$this->settings['backgroundjobs_mode'] = 'cron';
		self::assertSame([], $this->adminSettings()->getForm()->getParams()['health'], 'A mirror that waits for its first run is no warning.');

		unset($this->settings['backgroundjobs_mode']);
		$this->statusService(new TestClock())->failed(new RuntimeException('Paperless is down.'));
		$health = $this->adminSettings()->getForm()->getParams()['health'];

		self::assertSame([
			['severity' => 'error', 'description' => 'Der letzte Lauf von Paperless Sync ist fehlgeschlagen: Paperless is down.'],
			['severity' => 'warning', 'description' => 'Nextcloud führt seine Hintergrundjobs mit AJAX aus, das sie nur startet, während jemand Nextcloud benutzt, sodass die geplanten Läufe von Paperless Sync zurückfallen oder mittendrin abbrechen. Stelle die Hintergrundjobs in den Grundeinstellungen auf Cron und richte den Cron-Job des Systems ein.'],
		], $health);
	}

	/** The page has the stored configuration without the token, which never reaches the browser. */
	public function testThePageGetsTheConfigurationWithoutTheToken(): void {
		$this->configService()->save(self::validSettings(), 'secret-token');

		$this->adminSettings()->getForm();

		$config = $this->initialState['config'];
		self::assertInstanceOf(SyncConfig::class, $config);
		self::assertTrue($config->tokenConfigured);
		self::assertStringNotContainsString('secret-token', json_encode($config, JSON_THROW_ON_ERROR));
	}

	/**
	 * js/settings.js fills every field of the form from the configuration that the server
	 * stored: the field data-setting="paperless_url" from paperlessUrl. Every field has its
	 * value there, and every value of the configuration but the state of the token has its field.
	 * A tag picker has the field of the setting that the template names first in its call.
	 */
	public function testEveryFieldOfTheFormHasAValueOfTheConfiguration(): void {
		$template = file_get_contents(__DIR__ . '/../../../templates/settings.php');
		self::assertIsString($template);
		preg_match_all('/data-setting="([a-z_]+)"|\$tagPicker\(\s*\'([a-z_]+)\'/', $template, $matches);
		$fields = array_map(
			static fn (string $setting): string => lcfirst(str_replace('_', '', ucwords($setting, '_'))),
			array_map(static fn (string $field, string $picker): string => $field . $picker, $matches[1], $matches[2]),
		);
		$values = array_keys($this->configService()->get()->jsonSerialize());

		sort($fields);
		$values = array_values(array_diff($values, ['tokenConfigured']));
		sort($values);
		self::assertSame($values, $fields);
	}

	/**
	 * Every section of the form links to its part of the guide on the website, which MkDocs builds
	 * from docs/guide.md and the parts of README.md that it includes. The anchor of a part is its
	 * heading as MkDocs turns it into one: in lowercase, without punctuation, and with hyphens
	 * for spaces. The App Store links to the same guide.
	 */
	public function testEveryHelpLinkLeadsToAPartOfTheGuide(): void {
		$root = __DIR__ . '/../../..';
		$template = file_get_contents("{$root}/templates/settings.php");
		$guide = file_get_contents("{$root}/docs/guide.md");
		$readme = file_get_contents("{$root}/README.md");
		self::assertIsString($template);
		self::assertIsString($guide);
		self::assertIsString($readme);
		preg_match_all('/\$help\(\'([a-z0-9-]+)\'/', $template, $links);
		self::assertCount(7, $links[1], 'Each of the five sections, each warning of the setup checks, and the tags of the inbox link to the guide.');

		$page = preg_replace_callback('/^--8<-- "README\.md:([a-z-]+)"$/m', static function (array $include) use ($readme): string {
			$name = preg_quote($include[1], '/');
			self::assertSame(1, preg_match("/\\[start:{$name}\\] -->\\n(.*?)<!-- --8<-- \\[end:{$name}\\]/s", $readme, $part), "README.md has no part {$include[1]}.");

			return $part[1];
		}, $guide);
		self::assertIsString($page);
		preg_match_all('/^#{1,6} (.+)$/m', $page, $headings);
		$anchors = array_map(
			static fn (string $heading): string => (string)preg_replace('/[\s-]+/', '-', trim((string)preg_replace('/[^\w\s-]/', '', strtolower($heading)))),
			$headings[1],
		);
		foreach ($links[1] as $anchor) {
			self::assertContains($anchor, $anchors, "The guide has no part #{$anchor}.");
		}

		$info = simplexml_load_file("{$root}/appinfo/info.xml");
		self::assertNotFalse($info);
		self::assertStringStartsWith(AppConstants::GUIDE_URL . '#', (string)$info->documentation->admin);
	}

	/** Every text of the page, of the template and of js/settings.js, has its German translation. */
	public function testEveryTextOfThePageIsTranslatedIntoGerman(): void {
		$root = __DIR__ . '/../../..';
		$catalog = json_decode((string)file_get_contents("{$root}/l10n/de.json"), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($catalog);
		self::assertIsArray($catalog['translations']);
		$texts = [];
		foreach (['templates/settings.php' => '\$l->t', 'js/settings.js' => 'translate'] as $file => $call) {
			preg_match_all("/{$call}\\('((?:[^'\\\\]|\\\\.)*)'/", (string)file_get_contents("{$root}/{$file}"), $matches);
			self::assertNotEmpty($matches[1], "{$file} has no texts to translate.");
			$texts = [...$texts, ...array_map(static fn (string $text): string => str_replace("\\'", "'", $text), $matches[1])];
		}

		self::assertSame([], array_values(array_diff(array_unique($texts), array_keys($catalog['translations']))), 'l10n/de.json and l10n/de.js have no translation of these texts.');
	}

	public function testFormBelongsToTheSectionOfTheApp(): void {
		$settings = $this->adminSettings();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text === 'Paperless Sync' ? 'Paperless-Synchronisation' : $text);
		$section = new AdminSection($l10n, $this->urlGenerator);

		self::assertSame($section->getID(), $settings->getSection());
		self::assertSame(AppConstants::APP_ID, $section->getID());
		self::assertSame('Paperless-Synchronisation', $section->getName());
		self::assertSame('/apps/paperless_sync/img/app.svg', $section->getIcon());
		self::assertSame(55, $section->getPriority());
		self::assertSame(55, $settings->getPriority());
	}

	private function adminSettings(): AdminSettings {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(function (string $key, mixed $data): void {
			$this->initialState[$key] = $data;
		});

		$status = $this->statusService(new TestClock());
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatTimeSpan')->willReturn('vor 2 Stunden');

		return new AdminSettings(
			$this->configService(),
			$status,
			$this->urlGenerator,
			$initialState,
			new SyncHealth($status, $this->configService(), $this->germanL10n(), $formatter),
			new BackgroundJobMode($this->appConfig(), $this->configService(), $this->germanL10n()),
		);
	}
}

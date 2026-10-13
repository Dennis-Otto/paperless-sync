<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Settings;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Settings\AdminSection;
use OCA\PaperlessSync\Settings\AdminSettings;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {
	use InMemoryConfiguration;

	/** @var IURLGenerator&MockObject */
	private IURLGenerator $urlGenerator;
	/** @var array<string, mixed> the initial state of the page, by its key */
	private array $initialState = [];

	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(static fn (string $route): string => '/route/' . $route);
		$this->urlGenerator->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => "/apps/{$app}/img/{$file}");
	}

	public function testFormShowsTheConfigurationTheStatusAndTheActions(): void {
		$this->token = 'secret-token';
		$this->configService()->save(self::validSettings());
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
		self::assertSame('/route/paperless_sync.settings.reset', $parameters['resetUrl']);
		self::assertSame('/route/paperless_sync.sync.run', $parameters['runUrl']);
		self::assertSame('/route/paperless_sync.sync.status', $parameters['statusUrl']);
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

		return new AdminSettings($this->configService(), new StatusService($this->appConfig()), $this->urlGenerator, $initialState);
	}
}

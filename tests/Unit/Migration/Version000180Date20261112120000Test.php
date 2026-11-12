<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Migration;

use OCA\PaperlessSync\Migration\Version000180Date20261112120000;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * An earlier version stored the target user as it was typed, such as Dennis for the user dennis,
 * whose activities, notifications and shares Nextcloud names as it spells the ID (#461).
 */
final class Version000180Date20261112120000Test extends TestCase {
	use InMemoryConfiguration;

	public function testATargetUserInAnotherCaseIsStoredAsNextcloudSpellsIt(): void {
		$this->usersOfNextcloud[] = 'dennis';
		$this->settings = ['paperless_url' => 'https://paperless.example.test', 'target_user' => 'Dennis'];

		$this->migrate('Stored the target user of Paperless Sync as Nextcloud spells the ID: dennis.');

		self::assertSame('dennis', $this->configService()->get()->targetUser);
		self::assertSame('https://paperless.example.test', $this->settings['paperless_url'], 'The other settings stay as they are.');
	}

	public function testATargetUserAsNextcloudSpellsItStaysAsItIs(): void {
		$this->settings = ['target_user' => 'paperless'];

		$this->migrate(null);

		self::assertSame(['target_user' => 'paperless'], $this->settings);
	}

	/** The connection checklist names a target user that Nextcloud doesn't have, which the step leaves alone. */
	public function testATargetUserThatNextcloudDoesNotHaveStaysAsItIs(): void {
		$this->settings = ['target_user' => 'Gone'];

		$this->migrate(null);

		self::assertSame(['target_user' => 'Gone'], $this->settings);
	}

	public function testANewInstallationNeedsNothing(): void {
		$this->migrate(null);

		self::assertSame([], $this->settings);
	}

	/** Run the step, which says $info, or nothing for null. */
	private function migrate(?string $info): void {
		$output = $this->createMock(IOutput::class);
		if ($info === null) {
			$output->expects(self::never())->method('info');
		} else {
			$output->expects(self::once())->method('info')->with($info);
		}
		$step = new Version000180Date20261112120000($this->configService());

		$step->postSchemaChange($output, fn (): ISchemaWrapper => $this->createStub(ISchemaWrapper::class), []);
	}
}

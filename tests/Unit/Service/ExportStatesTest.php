<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Service;

use OCA\PaperlessSync\Service\ExportStates;
use OCA\PaperlessSync\Tests\Doubles\TestStateRepository;
use PHPUnit\Framework\TestCase;

final class ExportStatesTest extends TestCase {
	private TestStateRepository $repository;

	protected function setUp(): void {
		$this->repository = new TestStateRepository();
		$this->repository->exports = [
			7 => ['document_id' => 7, 'path' => 'Archiv/a.pdf', 'state' => 'active', 'missing_runs' => 0, 'last_error' => 'Timeout'],
			9 => ['document_id' => 9, 'path' => 'Archiv/b.pdf', 'state' => 'trash', 'missing_runs' => 2, 'last_error' => null],
		];
		$this->repository->exportsOfOthers['someone-else'] = [7 => ['document_id' => 7, 'path' => 'Archiv/c.pdf']];
	}

	public function testTheStatesOfTheOwnerAreReadOnce(): void {
		$states = new ExportStates($this->repository, TestStateRepository::OWNER);

		self::assertSame('Archiv/a.pdf', $states->find(7)['path'] ?? null);
		self::assertNull($states->find(8));
		self::assertSame([7, 9], array_keys($states->all()));
		self::assertSame(['allExports'], $this->repository->exportCalls);
	}

	public function testOnlyTheValuesThatChangeAreWritten(): void {
		$states = new ExportStates($this->repository, TestStateRepository::OWNER);

		$states->save(7, ['path' => 'Archiv/a.pdf', 'state' => 'active', 'missing_runs' => 0, 'last_error' => null]);
		$states->save(9, ['state' => 'trash', 'missing_runs' => 2]);
		$states->save(7, ['last_error' => null]);

		self::assertSame(['allExports', 'saveExport'], $this->repository->exportCalls, 'A state without a change costs no write.');
		self::assertNull($this->repository->exports[7]['last_error']);
		self::assertArrayHasKey('last_error', $states->find(7) ?? []);
		self::assertNull(($states->find(7) ?? [])['last_error'], 'The state follows the write.');
	}

	public function testANewStateStartsWithTheDefaults(): void {
		$states = new ExportStates($this->repository, TestStateRepository::OWNER);

		$states->save(8, ['path' => 'Archiv/new.pdf']);
		$states->save(8, ['state' => 'active', 'missing_runs' => 0, 'remote_checksum' => null]);

		self::assertSame(['document_id' => 8, 'path' => 'Archiv/new.pdf'], $this->repository->exports[8]);
		self::assertSame(1, $this->repository->exportWrites(), 'The defaults of a new export need no write.');
		self::assertSame('', $states->find(8)['fingerprint'] ?? null);
	}

	public function testADeletedStateIsGone(): void {
		$states = new ExportStates($this->repository, TestStateRepository::OWNER);

		$states->delete(9);

		self::assertNull($states->find(9));
		self::assertArrayNotHasKey(9, $this->repository->exports);
		self::assertSame([7], array_keys($this->repository->exportsOfOthers['someone-else']), 'The states of other owners stay.');
	}
}

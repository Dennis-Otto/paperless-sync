<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit;

use InvalidArgumentException;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\PathTemplateService;
use PHPUnit\Framework\TestCase;

final class PathTemplateServiceTest extends TestCase {
	private PathTemplateService $service;

	protected function setUp(): void {
		$this->service = new PathTemplateService();
	}

	public function testRendersStableStructuredPath(): void {
		$document = [
			'id' => 123,
			'title' => 'Rechnung: Strom / August',
			'correspondent' => 4,
			'document_type' => 7,
			'storage_path' => null,
			'created' => '2026-08-26',
			'added' => '2026-08-27T12:30:00+02:00',
			'original_file_name' => 'scan.PDF',
			'archived_file_name' => 'archive.pdf',
			'mime_type' => 'application/pdf',
		];

		self::assertSame(
			'Energie GmbH/Rechnung/2026/2026-08-26 - Rechnung_ Strom _ August [P123].pdf',
			$this->service->render($this->config(), $document, ['4' => 'Energie GmbH'], ['7' => 'Rechnung'], []),
		);
	}

	public function testUsesConfiguredFallbacksAndWindowsSafeNames(): void {
		$document = [
			'id' => 5,
			'title' => 'CON',
			'created' => null,
			'original_file_name' => 'document',
			'mime_type' => 'application/pdf',
		];

		self::assertSame(
			'_Ohne Korrespondent/_Ohne Dokumenttyp/_Ohne Datum/_Ohne Datum - _CON [P5].pdf',
			$this->service->render($this->config(), $document, [], [], []),
		);
	}

	public function testRejectsTraversalAndUnknownVariables(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->normalizeRelativePath('Dokumente/../Geheim');
	}

	public function testRequiresStableDocumentMarkerVariable(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('{{ id }}');
		$this->service->validateTemplate('{{ title }}.pdf');
	}

	private function config(): SyncConfig {
		return new SyncConfig(
			'https://paperless.example.test',
			true,
			false,
			'paperless',
			'Dokumente/Paperless',
			'Archiv',
			'Eingang',
			'Fehler',
			'_Gelöscht',
			PathTemplateService::DEFAULT_TEMPLATE,
			true,
			true,
			true,
			true,
			'',
			5,
			100,
			'move',
			false,
			false,
			3,
			true,
			true,
			true,
			'replace',
			'_Ohne Korrespondent',
			'_Ohne Dokumenttyp',
			'_Ohne Datum',
			'Ohne Titel',
		);
	}
}

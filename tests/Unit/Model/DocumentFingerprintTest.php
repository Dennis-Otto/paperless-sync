<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

use OCA\PaperlessSync\Model\DocumentFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentFingerprintTest extends TestCase {
	/** @var list<string> */
	private array $temporaryFiles = [];

	protected function tearDown(): void {
		foreach ($this->temporaryFiles as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	/** @return array<string, array{string}> */
	public static function contents(): array {
		return [
			'empty' => [''],
			'small' => ['%PDF-1.4 small'],
			// Longer than one read, which ends within the content.
			'several reads' => [str_repeat('%PDF-chunk', 250_000)],
		];
	}

	#[DataProvider('contents')]
	public function testChecksumsOfAStreamAreThoseOfItsContentAndItIsRewound(string $content): void {
		$stream = $this->stream($content);

		$fingerprint = DocumentFingerprint::of($stream);

		self::assertSame(hash('sha256', $content), $fingerprint->sha256);
		self::assertSame(hash('md5', $content), $fingerprint->md5);
		self::assertSame(0, ftell($stream), 'The write reads the stream from its start.');
	}

	/**
	 * Before, the checksum of a document came from a string of its whole content, so a document
	 * larger than the free memory of PHP ended the run with a fatal error.
	 */
	public function testChecksumsOfALargeDocumentAreComputedWithoutHoldingItInMemory(): void {
		$size = 16 * 1024 * 1024;
		$chunk = str_repeat('%PDF-large', 104_858);
		$path = $this->temporaryFile();
		$stream = fopen($path, 'w+b');
		self::assertIsResource($stream);
		$sha256 = hash_init('sha256');
		$md5 = hash_init('md5');
		for ($written = 0; $written < $size; $written += strlen($chunk)) {
			fwrite($stream, $chunk);
			hash_update($sha256, $chunk);
			hash_update($md5, $chunk);
		}
		rewind($stream);
		memory_reset_peak_usage();
		$before = memory_get_usage();

		$fingerprint = DocumentFingerprint::of($stream);

		self::assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $before, 'A document of 16 MB must be hashed in chunks.');
		self::assertSame(hash_final($sha256), $fingerprint->sha256);
		self::assertSame(hash_final($md5), $fingerprint->md5);
	}

	/** A read that fails, for a broken disk for example, must not give checksums of a part of the document. */
	public function testStreamThatCannotBeReadToItsEndIsReported(): void {
		$path = $this->temporaryFile();
		file_put_contents($path, '%PDF-unreadable');
		$stream = fopen($path, 'ab');
		self::assertIsResource($stream);
		$notices = [];
		set_error_handler(static function (int $level, string $message) use (&$notices): bool {
			$notices[] = $message;
			return true;
		}, E_NOTICE);

		try {
			DocumentFingerprint::of($stream);
			self::fail('A stream that cannot be read must be reported.');
		} catch (RuntimeException $exception) {
			self::assertSame('Could not read the Paperless download to its end.', $exception->getMessage());
		} finally {
			restore_error_handler();
			fclose($stream);
		}
		self::assertCount(1, $notices);
		self::assertStringContainsString('Bad file descriptor', $notices[0]);
	}

	public function testValueHoldsBothChecksumsWithinTheColumn(): void {
		$fingerprint = new DocumentFingerprint(hash('sha256', '%PDF'), hash('md5', '%PDF'));

		self::assertSame(hash('sha256', '%PDF') . ' ' . hash('md5', '%PDF'), $fingerprint->value());
		self::assertSame(97, strlen($fingerprint->value()), 'The column fingerprint holds 128 characters.');
	}

	/** @return array<string, array{string, string, bool}> */
	public static function checksums(): array {
		$sha256 = hash('sha256', '%PDF');
		$md5 = hash('md5', '%PDF');
		$both = $sha256 . ' ' . $md5;

		return [
			'SHA-256 of Paperless-ngx 3' => [$both, $sha256, true],
			'MD5 of Paperless-ngx 2' => [$both, $md5, true],
			'in capitals' => [$both, strtoupper($md5), true],
			'SHA-256 of an earlier version on Paperless-ngx 3' => [$sha256, $sha256, true],
			'SHA-256 of an earlier version on Paperless-ngx 2' => [$sha256, $md5, false],
			'changed content' => [$both, hash('md5', '%PDF-changed'), false],
			'a part of a checksum' => [$both, substr($md5, 0, 16), false],
			'no checksum' => [$both, '', false],
			'no fingerprint' => ['', '', false],
		];
	}

	#[DataProvider('checksums')]
	public function testMatchesAChecksumOfEitherKind(string $fingerprint, string $checksum, bool $matches): void {
		self::assertSame($matches, DocumentFingerprint::matches($fingerprint, $checksum));
	}

	/** @return resource */
	private function stream(string $content) {
		$stream = fopen($this->temporaryFile(), 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, $content);
		rewind($stream);

		return $stream;
	}

	private function temporaryFile(): string {
		$path = tempnam(sys_get_temp_dir(), 'paperless-sync-test-');
		self::assertIsString($path);
		$this->temporaryFiles[] = $path;

		return $path;
	}
}

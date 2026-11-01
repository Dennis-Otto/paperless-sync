<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Model;

use OCA\PaperlessSync\Model\DocumentPermissions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentPermissionsTest extends TestCase {
	/**
	 * The answers of a real Paperless-ngx (tests/fixtures/), with the users who may view the document
	 * by themselves: the account of the app and one more; the group is that of the household.
	 *
	 * @return array<string, array{string, list<int>}>
	 */
	public static function paperlessLines(): array {
		return [
			'Paperless-ngx 2.x' => ['2', [4, 7]],
			'Paperless-ngx 3.x' => ['3', [3, 6]],
		];
	}

	/**
	 * @param list<int> $users
	 */
	#[DataProvider('paperlessLines')]
	public function testReadsWhoMayViewADocumentInTheListOfPaperless(string $line, array $users): void {
		$results = self::recording($line, 'documents-shared')['results'] ?? null;
		self::assertIsArray($results);
		self::assertIsArray($results[0]);
		/** @var array<string, mixed> $document */
		$document = $results[0];

		$permissions = DocumentPermissions::of($document);

		self::assertNotNull($permissions);
		self::assertEqualsCanonicalizing($users, $permissions->users);
		self::assertSame([1], $permissions->groups);
	}

	/**
	 * @param list<int> $users
	 */
	#[DataProvider('paperlessLines')]
	public function testReadsWhoMayViewOneDocumentOfPaperless(string $line, array $users): void {
		$permissions = DocumentPermissions::of(self::recording($line, 'document-shared'));

		self::assertNotNull($permissions);
		self::assertEqualsCanonicalizing($users, $permissions->users);
		self::assertSame([1], $permissions->groups);
	}

	/** Paperless may give an ID as a string of digits, as it may the owner of a document. */
	public function testTakesTheIdsAsStringsOfDigits(): void {
		$permissions = DocumentPermissions::of(['permissions' => ['view' => ['users' => ['4', 5], 'groups' => ['8']]]]);

		self::assertNotNull($permissions);
		self::assertSame([[4, 5], [8]], [$permissions->users, $permissions->groups]);
	}

	/** A document that nobody else may view names nobody, which differs from an answer that doesn't say. */
	public function testADocumentSharedWithNobodyNamesNobody(): void {
		$permissions = DocumentPermissions::of(['permissions' => ['view' => ['users' => [], 'groups' => []]]]);

		self::assertNotNull($permissions);
		self::assertSame([[], []], [$permissions->users, $permissions->groups]);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function answersThatDoNotSay(): array {
		return [
			'without the field, as without full_perms' => [['id' => 1]],
			'permissions that are no list' => [['permissions' => 'everyone']],
			'without who may view' => [['permissions' => ['change' => ['users' => [], 'groups' => []]]]],
			'who may view as no list' => [['permissions' => ['view' => 3]]],
			'without the users' => [['permissions' => ['view' => ['groups' => []]]]],
			'without the groups' => [['permissions' => ['view' => ['users' => []]]]],
			'users that are no list' => [['permissions' => ['view' => ['users' => 3, 'groups' => []]]]],
			'groups that are no list' => [['permissions' => ['view' => ['users' => [], 'groups' => 'household']]]],
			'a user by its name' => [['permissions' => ['view' => ['users' => ['alice'], 'groups' => []]]]],
			'a group of another type' => [['permissions' => ['view' => ['users' => [], 'groups' => [1.5]]]]],
			'a user with a sign' => [['permissions' => ['view' => ['users' => ['-3'], 'groups' => []]]]],
		];
	}

	/**
	 * The run then can't tell who gets a copy, or who no longer may have one.
	 *
	 * @param array<string, mixed> $document
	 */
	#[DataProvider('answersThatDoNotSay')]
	public function testAnAnswerThatDoesNotSayWhoMayViewNamesNoOne(array $document): void {
		self::assertNull(DocumentPermissions::of($document));
	}

	/**
	 * The body of a recording of tests/fixtures/paperless-ngx-<line>/.
	 *
	 * @return array<string, mixed>
	 */
	private static function recording(string $line, string $name): array {
		$path = dirname(__DIR__, 2) . "/fixtures/paperless-ngx-{$line}/{$name}.json";
		$recording = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($recording);
		self::assertIsArray($recording['body']);

		/** @var array<string, mixed> */
		return $recording['body'];
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Unit\Command;

use OCA\PaperlessSync\Command\CreateAccount;
use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Service\AccountPermissions;
use OCA\PaperlessSync\Service\AccountSetup;
use OCA\PaperlessSync\Service\ConnectionChecklist;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\NextcloudStorageInterface;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCA\PaperlessSync\Tests\Doubles\InMemoryConfiguration;
use OCA\PaperlessSync\Tests\Doubles\TestPaperlessClient;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ paperless_sync:create-account, as a scripted setup uses it: the token of a superuser from a
 * file or standard input, the account of the app in Paperless, its token stored, the checklist.
 */
final class CreateAccountTest extends TestCase {
	use InMemoryConfiguration;

	/** What the command prints of the account, with the permissions of the saved export and inbox. */
	private const CREATED = 'Created the user nextcloud-sync in Paperless with the permissions View for Documents, Add for Documents, View for Correspondents, View for Document Types, View for Storage Paths, View for Tags, View for Paperless Tasks, View for UI Settings.';

	private TestPaperlessClient $paperless;
	/** @var NextcloudStorageInterface&Stub */
	private NextcloudStorageInterface $storage;
	private CreateAccount $createAccount;
	private CommandTester $command;
	/** The file with the token of the superuser. */
	private string $tokenFile = '';

	protected function setUp(): void {
		$configService = $this->configService();
		$configService->save(self::validSettings(), 'old-token');
		$this->paperless = new TestPaperlessClient();
		$this->storage = $this->createStub(NextcloudStorageInterface::class);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn('random-password');
		$english = $this->createStub(IL10N::class);
		$english->method('t')->willReturnCallback(static fn (string $text, array|string $parameters = []): string => vsprintf($text, (array)$parameters));
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($english);
		$this->createAccount = new CreateAccount(
			$configService,
			new ConnectionChecklist($this->paperless, $this->storage, $this->ownerMapping(), $english),
			new AccountPermissions(new PathTemplateService()),
			new AccountSetup($this->paperless, $random),
			$factory,
		);
		$this->command = new CommandTester($this->createAccount);
		$file = tempnam(sys_get_temp_dir(), 'paperless-sync-token-');
		self::assertIsString($file);
		$this->tokenFile = $file;
		file_put_contents($this->tokenFile, " superuser-token\n");
	}

	protected function tearDown(): void {
		if (is_file($this->tokenFile)) {
			unlink($this->tokenFile);
		}
	}

	public function testCommandIsNamedAfterTheApp(): void {
		self::assertSame('paperless_sync:create-account', $this->createAccount->getName());
		self::assertStringContainsString('--admin-token-file=-', $this->createAccount->getHelp());
	}

	/** The account is created with the token of the superuser, and its own token is stored. */
	public function testCreatesTheAccountAndStoresItsToken(): void {
		self::assertSame(CreateAccount::OK, $this->execute(['--admin-token-file' => $this->tokenFile]));

		self::assertSame(self::CREATED . "\n"
			. "Connection checklist:\n"
			. "  ✓ Paperless: Paperless answers at this URL.\n"
			. "  ✓ Nextcloud folder: paperless may create files in Documents/Paperless.\n"
			. "Stored the token of the account; the runs use it from now on.\n", $this->command->getDisplay());
		self::assertSame('', $this->command->getErrorOutput());
		self::assertSame(['superuser-token', 'token-of-nextcloud-sync'], array_map(static fn (PaperlessConnection $connection): string => $connection->token(), $this->paperless->connects));
		self::assertSame(['url' => 'https://paperless.example.test', 'token' => 'token-of-nextcloud-sync'], $this->credential);
	}

	/** The token of the superuser from standard input, and the answer as JSON for a script. */
	public function testTakesTheTokenFromStandardInputAndAnswersJson(): void {
		$this->command->setInputs(['superuser-token']);

		self::assertSame(CreateAccount::OK, $this->execute(['--admin-token-file' => '-', '--username' => 'paperless-sync', '--output' => 'json']));

		$answer = json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($answer);
		self::assertSame(['paperless-sync', true, true], [$answer['username'], $answer['created'], $answer['stored']]);
		self::assertSame(['paperless', 'folder'], array_column($answer['checks'], 'id'));
		self::assertStringNotContainsString('superuser-token', $this->command->getDisplay());
		self::assertSame('superuser-token', $this->paperless->connects[0]->token());
	}

	/** A user that exists changes only with --update. */
	public function testAnExistingUserChangesOnlyWithUpdate(): void {
		$this->paperless->accounts['nextcloud-sync'] = ['id' => 7, 'superuser' => false, 'password' => 'old', 'permissions' => [], 'token' => 'token-it-had'];

		self::assertSame(CreateAccount::FAILED, $this->execute(['--admin-token-file' => $this->tokenFile]));
		self::assertSame("The user nextcloud-sync exists in Paperless already. Run the command with --update to give it exactly the permissions that the app needs, and a new random password.\n", $this->command->getErrorOutput());
		self::assertSame('old-token', $this->credential['token'] ?? null);

		self::assertSame(CreateAccount::OK, $this->execute(['--admin-token-file' => $this->tokenFile, '--update' => true]));
		self::assertStringStartsWith('Updated the user nextcloud-sync in Paperless with the permissions View for Documents,', $this->command->getDisplay());
		self::assertSame('token-it-had', $this->credential['token'] ?? null);
	}

	/** A check of one module that fails keeps the account and its token, and exits with 1. */
	public function testAFailedCheckExitsWithOne(): void {
		$this->paperless->preflightChecks = [
			ConnectionCheck::passed(ConnectionCheck::PAPERLESS, 'Paperless answers at this URL.'),
			ConnectionCheck::failed(ConnectionCheck::TAGS, 'The account may not view them (HTTP 403).', [], 'In Paperless, under Users & Groups, give the account the permission View for Tags.'),
			ConnectionCheck::warning(ConnectionCheck::TRASH, 'Visible to the account: %s', ['0'], 'Make the account of the app the owner of the documents to mirror.'),
		];

		self::assertSame(CreateAccount::ERRORS, $this->execute(['--admin-token-file' => $this->tokenFile]));

		self::assertStringContainsString("  ✗ Tags: The account may not view them (HTTP 403). In Paperless, under Users & Groups, give the account the permission View for Tags.\n", $this->command->getDisplay());
		self::assertStringContainsString("  ! Trash: Visible to the account: 0. Make the account of the app the owner of the documents to mirror.\n", $this->command->getDisplay());
		self::assertSame('token-of-nextcloud-sync', $this->credential['token'] ?? null);
	}

	/** A blocking failure keeps the token of the account out of the settings, and says how to go on. */
	public function testABlockingFailureStoresNoToken(): void {
		$this->paperless->preflightChecks = [ConnectionCheck::failed(ConnectionCheck::PAPERLESS, 'Paperless refused the API token (HTTP 401).', [], 'Check that the account is active.', true)];

		self::assertSame(CreateAccount::FAILED, $this->execute(['--admin-token-file' => $this->tokenFile]));

		self::assertSame("Paperless refused the API token (HTTP 401). Check that the account is active. The account is ready in Paperless, but its token is not stored: once this is fixed, run the command again with --update.\n", $this->command->getErrorOutput());
		self::assertSame('old-token', $this->credential['token'] ?? null);
	}

	/** @return iterable<string, array{array<string, string>, string}> the options and what the command says */
	public static function missingTokens(): iterable {
		yield 'no file' => [[], 'Name the file with the API token of a superuser of Paperless: --admin-token-file=FILE, or --admin-token-file=- for standard input.'];
		yield 'a file that is missing' => [['--admin-token-file' => '/nowhere/token'], "The file /nowhere/token can't be read, or holds no token."];
		yield 'an empty standard input' => [['--admin-token-file' => '-'], 'Standard input holds no token.'];
		yield 'an unknown output format' => [['--output' => 'xml'], 'The output format must be plain, json or json_pretty.'];
	}

	/** @param array<string, string> $options */
	#[DataProvider('missingTokens')]
	public function testWithoutATokenNothingReachesPaperless(array $options, string $message): void {
		if (($options['--admin-token-file'] ?? '') === '-') {
			$this->command->setInputs([' ']);
		}

		self::assertSame(CreateAccount::FAILED, $this->execute($options));

		self::assertSame($message . "\n", $this->command->getErrorOutput());
		self::assertSame([], $this->paperless->connects);
	}

	/** Without the saved URL of Paperless, the command doesn't know where to go. */
	public function testNeedsTheSavedUrl(): void {
		$this->settings['paperless_url'] = '';

		self::assertSame(CreateAccount::FAILED, $this->execute(['--admin-token-file' => $this->tokenFile]));

		self::assertStringStartsWith('Save the Paperless URL in the settings first', $this->command->getErrorOutput());
		self::assertSame([], $this->paperless->connects);
	}

	/** Paperless gets no account for a folder in which the target user can't create files. */
	public function testAFolderThatCannotTakeFilesCreatesNoAccount(): void {
		$this->storage->method('test')->willThrowException(new RuntimeException('Not writable.'));

		self::assertSame(CreateAccount::FAILED, $this->execute(['--admin-token-file' => $this->tokenFile]));

		self::assertSame("Nextcloud cannot store the files there: Not writable. Check the target user ID, and that the base folder is a folder in which that user may create files.\n", $this->command->getErrorOutput());
		self::assertSame([], $this->paperless->connects);
	}

	/** @return iterable<string, array{\Throwable, string}> the failure of Paperless and what the command says */
	public static function failedSetups(): iterable {
		yield 'a token of no superuser' => [
			new InvalidSettingException('Paperless does not let the account of this token manage users (HTTP 403). Use the token of a superuser of Paperless.', [], 'account_token'),
			'Paperless does not let the account of this token manage users (HTTP 403). Use the token of a superuser of Paperless.',
		];
		yield 'an address of the local network' => [new PaperlessLocalNetworkException('paperless'), (new PaperlessLocalNetworkException('paperless'))->getMessage()];
		yield 'a Paperless that fails' => [new RuntimeException('Paperless did not list its users: HTTP 500.'), 'Paperless did not set up the account: Paperless did not list its users: HTTP 500.'];
	}

	#[DataProvider('failedSetups')]
	public function testAFailedSetupSaysWhy(\Throwable $failure, string $message): void {
		$this->paperless->accountsException = $failure;

		self::assertSame(CreateAccount::FAILED, $this->execute(['--admin-token-file' => $this->tokenFile]));

		self::assertSame($message . "\n", $this->command->getErrorOutput());
		self::assertSame('old-token', $this->credential['token'] ?? null);
	}

	/** A checklist that fails on its own stores no token. */
	public function testAChecklistThatFailsStoresNoToken(): void {
		$this->paperless->preflightException = new RuntimeException('Broken.');

		self::assertSame(CreateAccount::FAILED, $this->execute(['--admin-token-file' => $this->tokenFile]));

		self::assertSame("Could not validate the Paperless and Nextcloud configuration: Broken.\n", $this->command->getErrorOutput());
		self::assertSame('old-token', $this->credential['token'] ?? null);
	}

	/** @param array<string, bool|string> $input */
	private function execute(array $input): int {
		return $this->command->execute($input, ['capture_stderr_separately' => true]);
	}
}

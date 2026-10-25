<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Command;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Service\AccountPermissions;
use OCA\PaperlessSync\Service\AccountSetup;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\ConnectionChecklist;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PaperlessAccountExistsException;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ paperless_sync:create-account: the setup of the account of the app in Paperless of the
 * settings page, for scripted setups (decision 0027). It takes the token of a superuser of
 * Paperless from a file or from standard input, never from an argument, which the list of
 * processes and the history of the shell would show, and uses it for these requests alone.
 */
final class CreateAccount extends Base {
	/** The signs of the lines of the checklist, as the settings page shows them. */
	private const SIGNS = [ConnectionCheck::PASSED => '✓', ConnectionCheck::WARNING => '!', ConnectionCheck::FAILED => '✗'];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private ConnectionChecklist $checklist,
		private AccountPermissions $permissions,
		private AccountSetup $accountSetup,
		private IFactory $l10nFactory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperless_sync:create-account')
			->setDescription('Create the account of the app in Paperless with the token of a superuser, and store its token')
			->setHelp(<<<'HELP'
				Creates a user in Paperless that is no superuser, with the permissions that the
				modules of the saved settings need, and a random password that nobody learns, and
				stores its token, which the runs use from then on. Then it prints the connection
				checklist. The token of the superuser is used for these requests alone: it is
				neither stored nor logged. Save the Paperless URL in the settings first.

				The token of the superuser comes from a file, or from standard input with -:

				  occ paperless_sync:create-account --admin-token-file=/run/secrets/paperless-admin-token
				  printf '%s' "$TOKEN" | occ paperless_sync:create-account --admin-token-file=-

				A user that exists already changes only with --update, which gives it exactly
				these permissions and a new random password.

				Exit codes: 0 the account is ready and every check passed, 1 the account is ready
				and its token stored, but a check failed, 2 the command failed.
				HELP)
			->addOption('admin-token-file', null, InputOption::VALUE_REQUIRED, 'The file with the API token of a superuser of Paperless, or - for standard input')
			->addOption('username', null, InputOption::VALUE_REQUIRED, 'The username of the account in Paperless', AccountSetup::USERNAME)
			->addOption('update', null, InputOption::VALUE_NONE, 'Update the user when it exists already');
		$this->addOutputOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$format = $this->outputFormat($input, $output);
		if ($format === null) {
			return self::FAILED;
		}
		$superuserToken = $this->superuserToken($input, $output);
		if ($superuserToken === null) {
			return self::FAILED;
		}
		$l10n = $this->l10nFactory->get(AppConstants::APP_ID, 'en');
		$config = $this->configService->get();
		if ($config->paperlessUrl === '') {
			$this->error($output, 'Save the Paperless URL in the settings first: Administration settings → Paperless Sync.');
			return self::FAILED;
		}
		$folder = $this->checklist->folder($config);
		if ($folder->blocks()) {
			$this->error($output, $folder->translatedText($l10n));
			return self::FAILED;
		}
		$permissions = $this->permissions->of($config);
		/** @var string $username an option with a required value and a default */
		$username = $input->getOption('username');
		try {
			$account = $this->accountSetup->setUp(PaperlessConnection::of($config, $superuserToken), $username, $permissions, $input->getOption('update') === true);
		} catch (PaperlessAccountExistsException $exception) {
			$this->error($output, "The user {$exception->username} exists in Paperless already. Run the command with --update to give it exactly the permissions that the app needs, and a new random password.");
			return self::FAILED;
		} catch (InvalidSettingException|PaperlessLocalNetworkException $exception) {
			$this->error($output, $exception->translated($l10n));
			return self::FAILED;
		} catch (\Throwable $exception) {
			$this->error($output, 'Paperless did not set up the account: ' . $exception->getMessage());
			return self::FAILED;
		}

		try {
			$checks = $this->checklist->checks($config, $account['token']);
			$blocking = array_values(array_filter($checks, static fn (ConnectionCheck $check): bool => $check->blocks()));
			if ($blocking === []) {
				$this->configService->save([], $account['token']);
			}
		} catch (\Throwable $exception) {
			$this->error($output, 'Could not validate the Paperless and Nextcloud configuration: ' . $exception->getMessage());
			return self::FAILED;
		}
		$labels = AccountPermissions::labels($permissions, $l10n);
		if ($format !== 'plain') {
			$this->writeJson($output, [
				'username' => $account['username'],
				'created' => $account['created'],
				'stored' => $blocking === [],
				'permissions' => $labels,
				'checks' => array_map(static fn (ConnectionCheck $check): array => $check->translated($l10n), $checks),
			], $format);
		} else {
			$this->line($output, sprintf(
				'%s the user %s in Paperless with the permissions %s.',
				$account['created'] ? 'Created' : 'Updated',
				$account['username'],
				implode(', ', $labels),
			));
			$this->writeChecks($output, $checks, $l10n);
		}
		if ($blocking !== []) {
			$this->error($output, $blocking[0]->translatedText($l10n) . ' The account is ready in Paperless, but its token is not stored: once this is fixed, run the command again with --update.');
			return self::FAILED;
		}
		if ($format === 'plain') {
			$this->line($output, 'Stored the token of the account; the runs use it from now on.');
		}

		return array_filter($checks, static fn (ConnectionCheck $check): bool => $check->status === ConnectionCheck::FAILED) === [] ? self::OK : self::ERRORS;
	}

	/**
	 * The token of the superuser from the file of --admin-token-file, or from standard input for -,
	 * or null after it said what is missing.
	 */
	private function superuserToken(InputInterface $input, OutputInterface $output): ?string {
		/** @var string|null $file an option with a required value */
		$file = $input->getOption('admin-token-file');
		if ($file === null || $file === '') {
			$this->error($output, 'Name the file with the API token of a superuser of Paperless: --admin-token-file=FILE, or --admin-token-file=- for standard input.');
			return null;
		}
		if ($file === '-') {
			$stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
			$content = stream_get_contents($stream ?? STDIN);
		} else {
			$content = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
		}
		$token = is_string($content) ? trim($content) : '';
		if ($token === '') {
			$this->error($output, $file === '-' ? 'Standard input holds no token.' : "The file {$file} can't be read, or holds no token.");
			return null;
		}

		return $token;
	}

	/** @param list<ConnectionCheck> $checks */
	private function writeChecks(OutputInterface $output, array $checks, IL10N $l10n): void {
		$this->line($output, 'Connection checklist:');
		foreach ($checks as $check) {
			$line = $check->translated($l10n);
			$this->line($output, sprintf('  %s %s: %s', self::SIGNS[$check->status], $line['label'], $check->translatedText($l10n)));
		}
	}
}

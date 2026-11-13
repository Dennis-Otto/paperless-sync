<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\SyncReport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * The webhook through which a workflow of Paperless-ngx names a document that it added or changed,
 * so that its copy changes within seconds: its secret, which Paperless sends in the header
 * Authorization as "Bearer <secret>", its address, and what came of its last call.
 *
 * The secret is one of HashedSecrets: random, kept as its SHA-256 alone, so that the settings page
 * shows it once, when it is created, and no one can read it afterwards, neither from the page nor
 * from the database. A new secret replaces the one before at once.
 *
 * @psalm-type LastCall = array{at: int, document: int, result: string, message: string}
 * @psalm-type State = array{configured: bool, created: int, url: string, lastCall: LastCall|null}
 */
final class WebhookService {
	/** The route that Paperless calls. */
	public const ROUTE = AppConstants::APP_ID . '.webhook.receive';
	/** The most characters of the message of the last call that the settings page shows. */
	private const MESSAGE_LENGTH = 1000;
	private const LAST_CALL = 'webhook_last_call';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private HashedSecrets $secrets,
		private ITimeFactory $timeFactory,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * A new secret in place of the one before, which stops working at once. It is returned here
	 * alone, for the settings page to show once.
	 */
	public function create(): string {
		$secret = $this->secrets->create(HashedSecrets::WEBHOOK);
		// The last call belongs to the secret before.
		$this->appConfig->deleteKey(AppConstants::APP_ID, self::LAST_CALL);

		return $secret;
	}

	/** Turns the webhook off: no call is accepted until a new secret is created. */
	public function remove(): void {
		$this->secrets->remove(HashedSecrets::WEBHOOK);
		$this->appConfig->deleteKey(AppConstants::APP_ID, self::LAST_CALL);
	}

	/** Whether the header Authorization of a call carries the secret, as "Bearer <secret>". */
	public function verify(#[\SensitiveParameter] string $authorization): bool {
		return $this->secrets->verify(HashedSecrets::WEBHOOK, $authorization);
	}

	/**
	 * Keeps what came of the last call for the settings page: when, for which document, its result,
	 * such as exported, moved, unchanged or queued, and the message of a failure.
	 */
	public function called(int $document, string $result, string $message = ''): void {
		$this->appConfig->setValueString(AppConstants::APP_ID, self::LAST_CALL, json_encode([
			'at' => $this->timeFactory->getTime(),
			'document' => $document,
			'result' => $result,
			'message' => mb_substr($message, 0, self::MESSAGE_LENGTH),
		], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), lazy: true);
	}

	/**
	 * The webhook as the settings page shows it: whether it has a secret and since when, the
	 * address that Paperless calls, and the last call. Never the secret.
	 *
	 * @return State
	 */
	public function state(): array {
		$created = $this->secrets->created(HashedSecrets::WEBHOOK);

		return [
			'configured' => $created !== null,
			'created' => $created ?? 0,
			'url' => $this->urlGenerator->linkToRouteAbsolute(self::ROUTE),
			'lastCall' => $created === null ? null : $this->lastCall(),
		];
	}

	/**
	 * What came of the run of one document, in a word: failed when it reported an error, exported,
	 * moved or removed for the change that it made, reassigned for a copy that left the archive of
	 * a user, as the document goes to another user now, tagged for a copy whose Nextcloud tags alone
	 * changed, unchanged for a copy that was current already,
	 * excluded for a document that the settings leave out, such as one in the inbox of Paperless,
	 * and missing for one that Paperless doesn't show to the account.
	 */
	public static function result(SyncReport $report): string {
		return match (true) {
			$report->errors > 0 => 'failed',
			$report->exported > 0 => 'exported',
			$report->moved > 0 => 'moved',
			$report->removedExcluded > 0 => 'removed',
			$report->removedReassigned > 0 => 'reassigned',
			$report->tagged > 0 => 'tagged',
			$report->unchanged > 0 => 'unchanged',
			$report->skipped > 0 => 'excluded',
			default => 'missing',
		};
	}

	/** @return LastCall|null */
	private function lastCall(): ?array {
		$decoded = json_decode($this->appConfig->getValueString(AppConstants::APP_ID, self::LAST_CALL, '', lazy: true), true);
		if (!is_array($decoded)) {
			return null;
		}
		$at = $decoded['at'] ?? null;
		$document = $decoded['document'] ?? null;
		$result = $decoded['result'] ?? null;
		$message = $decoded['message'] ?? null;
		if (!is_int($at) || !is_int($document) || !is_string($result) || !is_string($message)) {
			return null;
		}

		return ['at' => $at, 'document' => $document, 'result' => $result, 'message' => $message];
	}
}

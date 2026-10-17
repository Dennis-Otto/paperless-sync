<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Cron\DocumentSyncJob;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\WebhookService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\Lock\LockedException;
use OCP\Security\Bruteforce\IThrottler;

/**
 * The webhook that a workflow of Paperless-ngx calls when it added or changed a document, so that
 * the copy of the document changes within seconds, and its secret, which administrators create on
 * the settings page.
 *
 * The webhook is the one route of the app that answers without a session of Nextcloud: Paperless
 * has none. It opens only with the secret, in the header Authorization as "Bearer <secret>", and
 * it answers every call without it with 401, which Nextcloud counts against the address of the
 * caller and delays, until it refuses the address for a while. A call names only the ID of a
 * document: what the app does with it, it reads from Paperless with its own token, by the rules
 * of a run, so that a caller with the secret can make the app mirror a document of Paperless
 * sooner, and nothing else. docs/security.md has the whole threat model.
 */
final class WebhookController extends Controller {
	/** The action under which Nextcloud counts and delays the calls with a wrong secret. */
	public const THROTTLE_ACTION = 'paperless_sync_webhook';
	/** The most calls that Nextcloud accepts from one address in RATE_PERIOD seconds. */
	public const RATE_LIMIT = 120;
	public const RATE_PERIOD = 60;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private WebhookService $webhook,
		private ConfigService $configService,
		private SyncService $syncService,
		private IJobList $jobList,
		private IThrottler $throttler,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * A call of Paperless for a document that it added or changed, {"document": 123}, as JSON or as
	 * a form; the document may also be its address in Paperless, such as
	 * https://paperless.example.com/documents/123/, the placeholder {{ doc_url }} of Paperless-ngx 2.x,
	 * which has no {{ doc_id }}.
	 *
	 * The run of that document starts at once, unless the settings leave it to the background jobs,
	 * and answers 200 with what it did, or 404 when Paperless shows no such document; it answers 202 when the document waits for the background
	 * jobs, also while another run holds the lock. Paperless waits five seconds for the answer; a
	 * run that takes longer finishes all the same. The trash and the deleted documents stay with the
	 * scheduled runs, which see the whole of Paperless.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	#[AnonRateLimit(limit: self::RATE_LIMIT, period: self::RATE_PERIOD)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/webhook')]
	public function receive(mixed $document = null): JSONResponse {
		if (!$this->webhook->verify($this->request->getHeader('Authorization'))) {
			$response = new JSONResponse(['message' => 'The secret of the webhook is missing or wrong. Send it as the header Authorization: Bearer <secret>.'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);

			return $response;
		}
		// Only a caller with the secret ends the delay of its address, such as Paperless after an
		// administrator fixed a wrong secret in its workflow.
		$this->throttler->resetDelay($this->request->getRemoteAddress(), self::THROTTLE_ACTION, ['action' => self::THROTTLE_ACTION]);
		$documentId = self::documentId($document);
		if ($documentId === null) {
			return new JSONResponse(['message' => 'Name the document as "document", its ID or its address in Paperless, such as {"document": 123}.'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$config = $this->configService->get();
			$this->syncService->requireDocumentRuns($config);
			if (!$config->webhookImmediate) {
				return $this->queue($documentId);
			}
			// Paperless gives up after five seconds; the run goes on without it, rather than leave
			// a copy half written.
			ignore_user_abort(true);
			$report = $this->syncService->syncDocument($documentId);
		} catch (LockedException) {
			return $this->queue($documentId);
		} catch (InvalidSettingException $exception) {
			$this->webhook->called($documentId, 'off', $exception->getMessage());

			return new JSONResponse(['document' => $documentId, 'result' => 'off', 'message' => $exception->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $exception) {
			$this->webhook->called($documentId, 'failed', $exception->getMessage());

			return new JSONResponse(['document' => $documentId, 'result' => 'failed', 'message' => $exception->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$result = WebhookService::result($report);
		$message = $report->errorMessages[0] ?? '';
		$this->webhook->called($documentId, $result, $message);
		$answer = ['document' => $documentId, 'result' => $result];
		if ($message !== '') {
			$answer['message'] = $message;
		}

		return new JSONResponse($answer, match ($result) {
			'failed' => Http::STATUS_INTERNAL_SERVER_ERROR,
			// Paperless may call before it has committed a new document, with more than one worker;
			// a 404 makes it try again after about 1, 2 and 4 seconds, where a 200 would end it.
			'missing' => Http::STATUS_NOT_FOUND,
			default => Http::STATUS_OK,
		});
	}

	/** The webhook as the settings page shows it, without the secret. */
	#[FrontpageRoute(verb: 'GET', url: '/settings/webhook')]
	public function state(): JSONResponse {
		return new JSONResponse($this->webhook->state());
	}

	/**
	 * Creates the secret, or a new one in place of the one before, which stops working at once, and
	 * answers with it: the one time that anyone sees it.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings/webhook')]
	#[PasswordConfirmationRequired]
	public function create(): JSONResponse {
		$secret = $this->webhook->create();

		return new JSONResponse(['secret' => $secret] + $this->webhook->state());
	}

	/** Turns the webhook off: Paperless's calls are refused until a new secret is created. */
	#[FrontpageRoute(verb: 'DELETE', url: '/settings/webhook')]
	#[PasswordConfirmationRequired]
	public function remove(): JSONResponse {
		$this->webhook->remove();

		return new JSONResponse($this->webhook->state());
	}

	/** Queues the run of the document; Nextcloud queues it once, however often Paperless calls. */
	private function queue(int $documentId): JSONResponse {
		$this->jobList->add(DocumentSyncJob::class, ['document' => $documentId]);
		$this->webhook->called($documentId, 'queued');

		return new JSONResponse(['document' => $documentId, 'result' => 'queued'], Http::STATUS_ACCEPTED);
	}

	/**
	 * The ID of the document of a call: a number, also as text, or the address of the document in
	 * Paperless, which ends with /documents/<ID>/; null for anything else.
	 */
	private static function documentId(mixed $document): ?int {
		if (is_int($document)) {
			return $document > 0 ? $document : null;
		}
		if (!is_string($document) || preg_match('~(?:^|/documents/)([1-9]\d{0,17})/?$~', trim($document), $match) !== 1) {
			return null;
		}

		return (int)$match[1];
	}
}

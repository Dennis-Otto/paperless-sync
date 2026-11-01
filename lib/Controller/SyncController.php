<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Cron\ManualSyncJob;
use OCA\PaperlessSync\Model\RunError;
use OCA\PaperlessSync\Model\SyncConfig;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\GettingStarted;
use OCA\PaperlessSync\Service\InvalidSettingException;
use OCA\PaperlessSync\Service\PaperlessLocalNetworkException;
use OCA\PaperlessSync\Service\StatusService;
use OCA\PaperlessSync\Service\StatusTranslation;
use OCA\PaperlessSync\Service\SyncService;
use OCA\PaperlessSync\Service\UnsavedSettingException;
use OCA\PaperlessSync\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Lock\LockedException;

final class SyncController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private SyncService $syncService,
		private StatusService $statusService,
		private IL10N $l10n,
		private ConfigService $configService,
		private IJobList $jobList,
		private IUserSession $userSession,
		private GettingStarted $gettingStarted,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * A dry-run, by default, answers with its report: it changes nothing and makes at most a batch of
	 * changes, so it ends within a request. It may come with the settings of the page, which it uses
	 * without saving them, so that the page shows what they do before a save; they keep the saved
	 * connection to Paperless and the saved target user (ConfigService::validateForDryRun()).
	 *
	 * A run uses the saved settings only. It queues a background job and answers 202 at once: a run
	 * may take longer than a proxy or the web server waits for an answer, and the status follows it.
	 *
	 * @param array<string, mixed>|null $settings
	 */
	#[FrontpageRoute(verb: 'POST', url: '/sync/run')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function run(bool $dryRun = true, ?array $settings = null): JSONResponse {
		if ($settings !== null && !$dryRun) {
			return new JSONResponse(['message' => $this->l10n->t('Save the settings first: only a dry-run can use settings that are not saved.')], Http::STATUS_BAD_REQUEST);
		}
		if (!$dryRun) {
			return $this->queue();
		}
		try {
			$config = $settings === null ? null : $this->configService->validateForDryRun($settings);
		} catch (UnsavedSettingException $exception) {
			// The setting to save first, which the page moves the focus to.
			return new JSONResponse(['message' => $exception->translated($this->l10n), 'setting' => $exception->setting], Http::STATUS_BAD_REQUEST);
		} catch (InvalidSettingException $exception) {
			$answer = ['message' => $exception->translated($this->l10n)];
			if ($exception->setting !== '') {
				$answer['setting'] = $exception->setting;
			}

			return new JSONResponse($answer, Http::STATUS_BAD_REQUEST);
		}
		try {
			return new JSONResponse(StatusTranslation::report($this->syncService->run(true, $config)->jsonSerialize(), $this->l10n));
		} catch (LockedException) {
			return $this->locked();
		} catch (PaperlessLocalNetworkException $exception) {
			return new JSONResponse(
				['message' => $exception->translated($this->l10n), 'setting' => PaperlessLocalNetworkException::SETTING],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		} catch (\Throwable $exception) {
			// A run checks the target user and the base folder again, which may have changed since the save.
			return new JSONResponse(['message' => RunError::of($exception)->translated($this->l10n)], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The status of the last run, with its errors in the language of the person who reads
	 * it: the run kept them in English, as the log shows them.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/sync/status')]
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function status(): JSONResponse {
		return new JSONResponse($this->overview($this->configService->get()));
	}

	/**
	 * Queues the run by hand, after the checks that a run would fail at once, so that the page says
	 * so at once. The status says "queued" before the job exists, so that a job that starts at once
	 * says "running" after it, never before. The job names the person who queued it, whom
	 * the Activity stream names as the author of the run, and Nextcloud queues it once for each.
	 */
	private function queue(): JSONResponse {
		$config = $this->configService->get();
		if (!$config->complete()) {
			return new JSONResponse(['message' => $this->l10n->t('Paperless Sync is not completely configured.')], Http::STATUS_BAD_REQUEST);
		}
		if ($this->statusService->active()) {
			return $this->locked();
		}
		$this->statusService->queued();
		$user = $this->userSession->getUser();
		$this->jobList->add(ManualSyncJob::class, $user === null ? null : ['author' => $user->getUID()]);

		return new JSONResponse(['queued' => true, 'status' => $this->overview($config)], Http::STATUS_ACCEPTED);
	}

	/**
	 * The status as the settings page shows it, with its errors translated and the steps of its
	 * getting-started checklist.
	 *
	 * @return array<string, mixed>
	 */
	private function overview(SyncConfig $config): array {
		$overview = $this->statusService->overview($config);
		$status = StatusTranslation::status($overview, $this->l10n);
		$status['setup'] = $this->gettingStarted->progress($config, $overview);

		return $status;
	}

	private function locked(): JSONResponse {
		return new JSONResponse(['message' => $this->l10n->t('Another synchronization run is already active.')], Http::STATUS_CONFLICT);
	}
}

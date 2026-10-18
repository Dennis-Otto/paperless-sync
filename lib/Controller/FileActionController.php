<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Controller;

use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\FileActions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * "Send to Paperless" in the menu of a file or a folder in Files, and of a selection of them: for
 * the members of the group of the setting, as the upload uses the token of the app. It queues the
 * files, and the answer says how many and what happens next; SendService uploads them in the
 * background and a notification tells the sender how each import ended.
 */
final class FileActionController extends Controller {
	/** The clicks of a user in a minute at most, each of which queues up to a batch of files. */
	public const RATE_LIMIT = 30;
	public const RATE_PERIOD = 60;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private FileActions $fileActions,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Queues the files and the folders with the IDs, as the user sees them in Files.
	 *
	 * @param array<array-key, mixed> $fileIds
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::RATE_LIMIT, period: self::RATE_PERIOD)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/send')]
	public function send(array $fileIds = []): JSONResponse {
		$user = $this->userSession->getUser();
		$config = $this->configService->get();
		if (!$config->complete()) {
			return $this->refusal($this->l10n->t('Paperless Sync is not completely configured.'), Http::STATUS_FORBIDDEN);
		}
		if ($user === null || !$this->fileActions->maySend($config, $user->getUID())) {
			return $this->refusal($this->l10n->t('You may not send files to Paperless. An administrator can allow it in the settings of Paperless Sync.'), Http::STATUS_FORBIDDEN);
		}
		$ids = array_values(array_filter(
			array_map(static fn (mixed $id): int => is_int($id) || (is_string($id) && ctype_digit($id)) ? (int)$id : 0, $fileIds),
			static fn (int $id): bool => $id > 0,
		));
		if ($ids === [] || count($ids) !== count($fileIds)) {
			return $this->refusal($this->l10n->t('Choose the files to send to Paperless.'), Http::STATUS_BAD_REQUEST);
		}

		$result = $this->fileActions->send($config, $user->getUID(), $ids);
		switch ($result['refused']) {
			case FileActions::REFUSED_MISSING:
				return $this->refusal($this->l10n->t('The file was not found, or you may not read it.'), Http::STATUS_NOT_FOUND);
			case FileActions::REFUSED_APP_FOLDER:
				return $this->refusal($this->l10n->t('%s is in the folder of Paperless Sync, which its runs look after: move a file into its inbox to send it to Paperless.', [$result['name']]), Http::STATUS_BAD_REQUEST);
			case FileActions::REFUSED_TOO_MANY:
				return $this->refusal($result['name'] === ''
					? $this->l10n->t('These are more than %s files, the most that Paperless Sync sends at once. Send fewer at a time.', [(string)$result['limit']])
					: $this->l10n->t('%1$s holds more than %2$s files, the most that Paperless Sync sends at once. Send its subfolders one at a time.', [$result['name'], (string)$result['limit']]), Http::STATUS_BAD_REQUEST);
		}

		$sentences = [];
		if ($result['queued'] > 0) {
			$sentences[] = $this->l10n->n('Paperless Sync sends %n file to Paperless. A notification tells you when Paperless has it.', 'Paperless Sync sends %n files to Paperless. A notification tells you when Paperless has each.', $result['queued']);
		}
		if ($result['waiting'] > 0) {
			$sentences[] = $this->l10n->n('%n file is on its way to Paperless already.', '%n files are on their way to Paperless already.', $result['waiting']);
		}
		if ($result['skipped'] > 0) {
			$sentences[] = $this->l10n->n('%n file stays out: Paperless has it already, or it is in the folder of Paperless Sync.', '%n files stay out: Paperless has them already, or they are in the folder of Paperless Sync.', $result['skipped']);
		}
		if ($sentences === []) {
			return $this->refusal($this->l10n->t('There are no files to send.'), Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse([
			'message' => implode(' ', $sentences),
			'queued' => $result['queued'],
			'waiting' => $result['waiting'],
			'skipped' => $result['skipped'],
		]);
	}

	/** @param Http::STATUS_* $status */
	private function refusal(string $message, int $status): JSONResponse {
		return new JSONResponse(['message' => $message], $status);
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Operation;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\FileActions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;

/**
 * "Send to Paperless" in Flow (Administration → Flow): a rule of the administrators sends the files
 * that match its conditions, such as every PDF that a member of a group uploads, to Paperless, with
 * the tags for every import of the inbox and the tags of the rule. The file stays where it is.
 *
 * The operation queues the file as "Send to Paperless" in Files does, for the user who owns it, and
 * SendService uploads it with the next run of cron: with refusals, retries that wait longer each
 * time and the task of each upload. Its owner hears of an import that failed, not of one that
 * worked, as a rule may send many files. The files of the folder of the app, which the runs write,
 * those that Paperless has already by the marker in their names, folders and files that the owner
 * may not read stay out, and so does every file while the app is not connected to Paperless.
 *
 * Only the administrators may use it, because the uploads use the token of the app. The operation
 * never fails the request that wrote the file: an error goes into the log.
 */
final class SendToPaperless implements ISpecificOperation {
	/** The entity of Flow for files, of the app workflowengine, whose class is no public API. */
	public const ENTITY = 'OCA\WorkflowEngine\Entity\File';
	/** The longest name of a tag that Paperless-ngx keeps. */
	public const MAX_TAG_LENGTH = 128;
	/** The codes of the exception of tags(): no list of names, a name with a separator, a name too long. */
	public const INVALID = 1;
	public const SEPARATOR = 2;
	public const TOO_LONG = 3;
	/** The events of the entity after which the file is gone, so nothing is left to send. */
	private const GONE = ['\OCP\Files::postDelete'];
	/** The events whose subject is the source and the new file, after a move or a copy. */
	private const MOVED = ['\OCP\Files::postRename', '\OCP\Files::postCopy'];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private ConfigService $configService,
		private FileActions $fileActions,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The tags of the operation of a rule, as the settings of Flow store it: the JSON of an object
	 * whose only member is the list of their names, {"tags": ["Scans", "Family"]}, or '' for a rule
	 * without tags of its own. A name comes once, whatever its case, as a run compares them.
	 *
	 * @return list<string>
	 * @throws \InvalidArgumentException when the operation is no such object or a name is empty
	 *                                   (INVALID), or a name holds a comma or a line break, which
	 *                                   the list of names of the queue can't hold (SEPARATOR), or
	 *                                   is longer than Paperless keeps (TOO_LONG); the message is
	 *                                   the name
	 */
	public static function tags(string $operation): array {
		if (trim($operation) === '') {
			return [];
		}
		try {
			$value = json_decode($operation, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('', self::INVALID);
		}
		$names = is_array($value) ? ($value['tags'] ?? []) : null;
		if (!is_array($value) || array_diff(array_keys($value), ['tags']) !== [] || !is_array($names) || !array_is_list($names)) {
			throw new \InvalidArgumentException('', self::INVALID);
		}
		$tags = [];
		/** @var mixed $name */
		foreach ($names as $name) {
			if (!is_string($name) || trim($name) === '') {
				throw new \InvalidArgumentException('', self::INVALID);
			}
			$name = trim($name);
			if (preg_match('/[,\r\n]/', $name) === 1) {
				throw new \InvalidArgumentException($name, self::SEPARATOR);
			}
			if (mb_strlen($name) > self::MAX_TAG_LENGTH) {
				throw new \InvalidArgumentException($name, self::TOO_LONG);
			}
			$tags[mb_strtolower($name)] ??= $name;
		}

		return array_values($tags);
	}

	public function getEntityId(): string {
		return self::ENTITY;
	}

	public function getDisplayName(): string {
		return $this->l10n->t('Send to Paperless');
	}

	public function getDescription(): string {
		return $this->l10n->t('Sends the files to Paperless with the tags for every import of the inbox and those of the rule. The files stay where they are.');
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(AppConstants::APP_ID, 'flow.svg');
	}

	/** Only the administrators, as the uploads use the token of the app. */
	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}

	public function validateOperation(string $name, array $checks, string $operation): void {
		try {
			self::tags($operation);
		} catch (\InvalidArgumentException $exception) {
			$tag = $exception->getMessage();
			throw new \UnexpectedValueException(match ($exception->getCode()) {
				self::SEPARATOR => $this->l10n->t('The name of a tag can\'t hold a comma or a line break: %s', [$tag]),
				self::TOO_LONG => $this->l10n->t('Paperless keeps names of tags of at most %1$d characters: %2$s', [self::MAX_TAG_LENGTH, $tag]),
				default => $this->l10n->t('The tags of the rule are no list of names.'),
			});
		}
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		try {
			$file = $this->file($eventName, $event);
			if ($file === null) {
				return;
			}
			// The rules that match, each with the tags of its operation; the first one names the send.
			$ruleId = null;
			$tags = [];
			/** @var mixed $flow */
			foreach ($ruleMatcher->getFlows(false) as $flow) {
				if (!is_array($flow) || !is_numeric($flow['id'] ?? null)) {
					continue;
				}
				$ruleId ??= (int)$flow['id'];
				foreach (self::tagsOfFlow($flow) as $tag) {
					$tags[mb_strtolower($tag)] ??= $tag;
				}
			}
			// The owner of the file sees it as it is; a file without one, as of a group folder, the user who wrote it.
			$userId = $file->getOwner()?->getUID() ?? $this->userSession->getUser()?->getUID();
			if ($ruleId === null || $userId === null) {
				return;
			}
			$fileId = $file->getId();
			$outcome = $this->fileActions->sendByRule($this->configService->get(), $userId, $fileId, array_values($tags), $ruleId);
			$this->logger->debug('The rule {rule} of Flow sends the file {file} of {user} to Paperless: {outcome}', [
				'app' => AppConstants::APP_ID,
				'rule' => $ruleId,
				'file' => $fileId,
				'user' => $userId,
				'outcome' => $outcome,
			]);
		} catch (\Throwable $exception) {
			// The upload, the move or the tag that started the event happened: it must not fail now.
			$this->logger->warning('Paperless Sync could not queue a file of a rule of Flow for Paperless: {error}', [
				'app' => AppConstants::APP_ID,
				'error' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}
	}

	/**
	 * The file of the event, as Flow's entity of files reads it: the subject of an event of the
	 * files, the new file of a move or a copy, the file of a tag that someone assigned, or null for
	 * a folder, a deleted file and every other event.
	 */
	private function file(string $eventName, Event $event): ?File {
		$node = null;
		if ($event instanceof GenericEvent && !in_array($eventName, self::GONE, true)) {
			/**
			 * Nextcloud still dispatches the events of files that Flow listens to as GenericEvents,
			 * whose subject is the node, and its entity of files reads them the same way.
			 *
			 * @psalm-suppress DeprecatedMethod
			 * @var mixed $subject
			 */
			$subject = $event->getSubject();
			/** @var mixed $node */
			$node = in_array($eventName, self::MOVED, true) && is_array($subject) ? ($subject[1] ?? null) : $subject;
		} elseif ($event instanceof MapperEvent && $event->getObjectType() === 'files') {
			$node = $this->rootFolder->getFirstNodeById((int)$event->getObjectId());
		}

		return $node instanceof File ? $node : null;
	}

	/**
	 * The tags of a rule that matched; a rule whose operation another version or a hand in the
	 * database wrote in another shape sends the file without them.
	 *
	 * @param array<array-key, mixed> $flow
	 * @return list<string>
	 */
	private static function tagsOfFlow(array $flow): array {
		/** @var mixed $operation */
		$operation = $flow['operation'] ?? null;
		try {
			return self::tags(is_string($operation) ? $operation : '');
		} catch (\InvalidArgumentException) {
			return [];
		}
	}
}

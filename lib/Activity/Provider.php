<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Activity;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Puts the entries of ActivityPublisher into words, in the language of the reader, with links to
 * the files in Nextcloud and to the new documents in Paperless.
 */
final class Provider implements IProvider {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private ConfigService $configService,
	) {
	}

	/**
	 * @param string $language
	 * @throws UnknownActivityException for an entry of another app, or one that it can't read
	 */
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== AppConstants::APP_ID) {
			throw new UnknownActivityException();
		}
		$l = $this->l10nFactory->get(AppConstants::APP_ID, $language);
		$parameters = $event->getSubjectParameters();
		[$subject, $objects, $link] = match ($event->getSubject()) {
			ActivityPublisher::SUBJECT_RUN => $this->run($l, $parameters),
			ActivityPublisher::SUBJECT_IMPORTED => $this->imported($l, $parameters),
			ActivityPublisher::SUBJECT_IMPORT_FAILED => $this->importFailed($l, $parameters, $event),
			ActivityPublisher::SUBJECT_IMPORT_REJECTED => $this->importRejected($l, $parameters, $event),
			default => throw new UnknownActivityException(),
		};
		$names = [];
		foreach ($objects as $key => $object) {
			$names['{' . $key . '}'] = $object['name'];
		}
		$event->setParsedSubject(strtr($subject, $names))
			->setRichSubject($subject, $objects)
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(AppConstants::APP_ID, 'app.svg')));
		if ($link !== '') {
			$event->setLink($link);
		}

		return $event;
	}

	/**
	 * "Paperless Sync added or updated 3 documents and moved 1 document to _Deleted: a.pdf, b.pdf,
	 * c.pdf and 1 more", each file with its link.
	 *
	 * @param array<array-key, mixed> $parameters
	 * @return array{string, array<string, array<string, string>>, string}
	 */
	private function run(IL10N $l, array $parameters): array {
		$objects = [];
		$changes = [];
		$count = self::count($parameters, 'exported');
		if ($count > 0) {
			// TRANSLATORS: a part of "Paperless Sync %s", such as "Paperless Sync added or updated 3 documents"
			$changes[] = $l->n('added or updated %n document', 'added or updated %n documents', $count);
		}
		$count = self::count($parameters, 'moved');
		if ($count > 0) {
			// TRANSLATORS: a part of "Paperless Sync %s", for documents whose metadata changed
			$changes[] = $l->n('moved %n document', 'moved %n documents', $count);
		}
		$count = self::count($parameters, 'trashed');
		$trash = self::reference($parameters['trash'] ?? null);
		if ($count > 0 && $trash !== null) {
			$objects['trash'] = $this->file($trash);
			// TRANSLATORS: a part of "Paperless Sync %s"; {trash} is the deleted folder, such as _Deleted
			$changes[] = $l->n('moved %n document to {trash}', 'moved %n documents to {trash}', $count);
		}
		$count = self::count($parameters, 'deleted');
		if ($count > 0) {
			// TRANSLATORS: a part of "Paperless Sync %s", for files deleted after their documents were deleted in Paperless
			$changes[] = $l->n('deleted %n document', 'deleted %n documents', $count);
		}
		$count = self::count($parameters, 'removed');
		if ($count > 0) {
			// TRANSLATORS: a part of "Paperless Sync %s", for documents that got a tag that keeps them out of Nextcloud
			$changes[] = $l->n('removed %n excluded document', 'removed %n excluded documents', $count);
		}
		$count = self::count($parameters, 'submitted');
		if ($count > 0) {
			// TRANSLATORS: a part of "Paperless Sync %s", for files of the inbox folder
			$changes[] = $l->n('sent %n file to Paperless', 'sent %n files to Paperless', $count);
		}
		if ($changes === []) {
			throw new UnknownActivityException();
		}

		$files = [];
		foreach (self::references($parameters['files'] ?? null) as $reference) {
			if ($reference['id'] > 0) {
				$key = 'file' . (count($files) + 1);
				$objects[$key] = $this->file($reference);
				$files[] = '{' . $key . '}';
			}
		}
		$more = self::count($parameters, 'more');
		if ($files !== [] && $more > 0) {
			// TRANSLATORS: the end of a list of files, such as "a.pdf, b.pdf and 3 more"
			$files[] = $l->n('%n more', '%n more', $more);
		}
		$folder = self::reference($parameters['folder'] ?? null);
		$link = $folder !== null && $folder['id'] > 0 ? $this->fileLink($folder['id']) : '';
		if ($files === []) {
			// TRANSLATORS: %s lists the changes, such as "added or updated 3 documents and moved 1 document"
			return [$l->t('Paperless Sync %s', [self::list($l, $changes)]), $objects, $link];
		}

		// TRANSLATORS: %1$s lists the changes, such as "added or updated 3 documents", and %2$s the files, such as "a.pdf, b.pdf and 3 more"
		return [$l->t('Paperless Sync %1$s: %2$s', [self::list($l, $changes), self::list($l, $files)]), $objects, $link];
	}

	/**
	 * "Scan.pdf was imported into Paperless as P123", with a link to the new document in Paperless.
	 *
	 * @param array<array-key, mixed> $parameters
	 * @return array{string, array<string, array<string, string>>, string}
	 */
	private function imported(IL10N $l, array $parameters): array {
		$file = self::requiredReference($parameters);
		$objects = ['file' => $this->file($file)];
		$link = $file['id'] > 0 ? $this->fileLink($file['id']) : '';
		$document = $parameters['document'] ?? null;
		if (!is_int($document) || $document < 1) {
			return [$l->t('{file} was imported into Paperless'), $objects, $link];
		}
		$objects['document'] = ['type' => 'highlight', 'id' => (string)$document, 'name' => 'P' . $document];
		$paperlessUrl = rtrim($this->configService->get()->paperlessUrl, '/');
		if ($paperlessUrl !== '') {
			$link = "{$paperlessUrl}/documents/{$document}/details";
			$objects['document']['link'] = $link;
		}

		return [$l->t('{file} was imported into Paperless as {document}'), $objects, $link];
	}

	/**
	 * "Paperless could not import broken.pdf", with the reason of Paperless below it and a link to the
	 * file in the error folder.
	 *
	 * @param array<array-key, mixed> $parameters
	 * @return array{string, array<string, array<string, string>>, string}
	 */
	private function importFailed(IL10N $l, array $parameters, IEvent $event): array {
		$file = self::requiredReference($parameters);
		$this->reason($parameters, $event);

		return [$l->t('Paperless could not import {file}'), ['file' => $this->file($file)], $file['id'] > 0 ? $this->fileLink($file['id']) : ''];
	}

	/**
	 * "Paperless refused notes.bin; it stays in the inbox until it changes", with the reason of
	 * Paperless below it.
	 *
	 * @param array<array-key, mixed> $parameters
	 * @return array{string, array<string, array<string, string>>, string}
	 */
	private function importRejected(IL10N $l, array $parameters, IEvent $event): array {
		$file = self::requiredReference($parameters);
		$this->reason($parameters, $event);

		return [$l->t('Paperless refused {file}; it stays in the inbox until it changes'), ['file' => $this->file($file)], $file['id'] > 0 ? $this->fileLink($file['id']) : ''];
	}

	/**
	 * The reason of Paperless is text, never a template, so it is the plain message of the entry: a
	 * brace in it stays as it is.
	 *
	 * @param array<array-key, mixed> $parameters
	 */
	private function reason(array $parameters, IEvent $event): void {
		if (isset($parameters['reason']) && is_string($parameters['reason']) && $parameters['reason'] !== '') {
			$event->setParsedMessage($parameters['reason']);
		}
	}

	/**
	 * A file of Nextcloud with its link, or, when it is gone, such as an inbox file that Paperless
	 * imported and the app removed, its name alone.
	 *
	 * @param array{id: int, path: string} $reference
	 * @return array<string, string>
	 */
	private function file(array $reference): array {
		$name = basename($reference['path']);
		if ($reference['id'] < 1) {
			return ['type' => 'highlight', 'id' => $reference['path'], 'name' => $name];
		}

		return [
			'type' => 'file',
			'id' => (string)$reference['id'],
			'name' => $name,
			'path' => $reference['path'],
			'link' => $this->fileLink($reference['id']),
		];
	}

	private function fileLink(int $id): string {
		return $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $id]);
	}

	/**
	 * "a, b and c" in the language of the reader.
	 *
	 * @param non-empty-list<string> $items
	 */
	private static function list(IL10N $l, array $items): string {
		$last = array_pop($items);
		if ($items === []) {
			return $last;
		}

		// TRANSLATORS: the last two items of a list, such as "a.pdf, b.pdf and c.pdf"
		return $l->t('%1$s and %2$s', [implode(', ', $items), $last]);
	}

	/** @param array<array-key, mixed> $parameters */
	private static function count(array $parameters, string $key): int {
		return isset($parameters[$key]) && is_int($parameters[$key]) ? max($parameters[$key], 0) : 0;
	}

	/**
	 * @param array<array-key, mixed> $parameters
	 * @return array{id: int, path: string}
	 */
	private static function requiredReference(array $parameters): array {
		return self::reference($parameters['file'] ?? null) ?? throw new UnknownActivityException();
	}

	/** @return list<array{id: int, path: string}> the files that an entry names, without those that it can't read */
	private static function references(mixed $values): array {
		return is_array($values) ? array_values(array_filter(array_map(self::reference(...), $values))) : [];
	}

	/** @return array{id: int, path: string}|null */
	private static function reference(mixed $value): ?array {
		if (!is_array($value)) {
			return null;
		}
		$id = $value['id'] ?? null;
		$path = $value['path'] ?? null;
		if (!is_int($id) || !is_string($path) || $path === '') {
			return null;
		}

		return ['id' => $id, 'path' => $path];
	}
}

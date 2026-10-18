<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Notification;

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\StatusService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Puts the notifications of NotificationPublisher into words, in the language of the reader, with a
 * link to the settings of the app, to the file of the inbox or to the file sent from Files, or to
 * the document in Paperless that a sent file became.
 */
final class Notifier implements INotifier {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private StatusService $status,
	) {
	}

	public function getID(): string {
		return AppConstants::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(AppConstants::APP_ID)->t('Paperless Sync');
	}

	/**
	 * @throws UnknownNotificationException for a notification of another app, or one that it can't read
	 * @throws AlreadyProcessedException for the notification of a failing mirror that works again
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== AppConstants::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(AppConstants::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		switch ($notification->getSubject()) {
			case NotificationPublisher::SUBJECT_FAILING:
				$this->failing($l, $notification);
				break;
			case NotificationPublisher::SUBJECT_IMPORT_FAILED:
				$this->import($notification, $parameters, $l->t('Paperless could not import {file}'));
				break;
			case NotificationPublisher::SUBJECT_IMPORT_REJECTED:
				$this->import($notification, $parameters, $l->t('Paperless refused {file}; it stays in the inbox until it changes'));
				break;
			case NotificationPublisher::SUBJECT_SEND_IMPORTED:
				$this->import($notification, $parameters, $l->t('{file} is in Paperless now'));
				$this->linkToDocument($notification, $parameters);
				break;
			case NotificationPublisher::SUBJECT_SEND_KNOWN:
				$this->import($notification, $parameters, $l->t('Paperless already has {file}'));
				$this->linkToDocument($notification, $parameters);
				break;
			case NotificationPublisher::SUBJECT_SEND_FAILED:
				$this->import($notification, $parameters, $l->t('Paperless could not import {file}'));
				break;
			case NotificationPublisher::SUBJECT_SEND_REFUSED:
				$this->import($notification, $parameters, $l->t('Paperless refused {file}'));
				break;
			case NotificationPublisher::SUBJECT_SEND_GONE:
				// The file is gone, or the user may no longer read it: a link to it would find nothing.
				$file = isset($parameters['file']) && is_array($parameters['file']) ? $parameters['file'] : [];
				$this->import($notification, ['file' => ['id' => 0] + $file] + $parameters, $l->t('Paperless Sync could not send {file}: it was deleted, or you may no longer read it'));
				break;
			default:
				throw new UnknownNotificationException();
		}

		return $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(AppConstants::APP_ID, 'app-dark.svg')));
	}

	/**
	 * "Paperless Sync failed 3 times in a row", with the error of the last run below it and a link
	 * to the settings. It names the row as it is when the reader opens it, and goes away once a run
	 * succeeded, also when the success could not remove it.
	 */
	private function failing(IL10N $l, INotification $notification): void {
		$failures = $this->status->failures();
		if ($failures['runs'] === 0) {
			throw new AlreadyProcessedException();
		}
		$subject = $failures['failed']
			? $l->n('Paperless Sync failed %n time in a row', 'Paperless Sync failed %n times in a row', $failures['runs'])
			: $l->n('Paperless Sync completed with errors %n time in a row', 'Paperless Sync completed with errors %n times in a row', $failures['runs']);
		$notification->setParsedSubject($subject)
			->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => AppConstants::APP_ID]));
		$error = mb_substr($failures['error'], 0, NotificationPublisher::ERROR_LIMIT);
		if ($error !== '') {
			// The error is text, never a template, so it is the plain message: a brace in it stays as it is.
			$notification->setParsedMessage($error);
		}
	}

	/**
	 * The notification of a file that Paperless has opens its document in Paperless, as "Open in
	 * Paperless" in Files does.
	 *
	 * @param array<array-key, mixed> $parameters
	 */
	private function linkToDocument(INotification $notification, array $parameters): void {
		$document = isset($parameters['document']) && is_int($parameters['document']) ? $parameters['document'] : 0;
		$url = isset($parameters['url']) && is_string($parameters['url']) ? $parameters['url'] : '';
		if ($document > 0 && $url !== '') {
			$notification->setLink(rtrim($url, '/') . '/documents/' . $document . '/details');
		}
	}

	/**
	 * "Paperless could not import broken.pdf", with the reason of Paperless below it and a link to
	 * the file.
	 *
	 * @param array<array-key, mixed> $parameters
	 * @throws UnknownNotificationException for a notification without its file
	 */
	private function import(INotification $notification, array $parameters, string $subject): void {
		$file = isset($parameters['file']) && is_array($parameters['file']) ? $parameters['file'] : [];
		if (!isset($file['id'], $file['path']) || !is_int($file['id']) || !is_string($file['path']) || $file['path'] === '') {
			throw new UnknownNotificationException();
		}
		$id = $file['id'];
		$path = $file['path'];
		$name = basename($path);
		if ($id > 0) {
			$link = $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $id]);
			$object = ['type' => 'file', 'id' => (string)$id, 'name' => $name, 'path' => $path, 'link' => $link];
			$notification->setLink($link);
		} else {
			// A file that is gone has its name alone.
			$object = ['type' => 'highlight', 'id' => $path, 'name' => $name];
		}
		$notification->setParsedSubject(str_replace('{file}', $name, $subject))
			->setRichSubject($subject, ['file' => $object]);
		if (isset($parameters['reason']) && is_string($parameters['reason']) && $parameters['reason'] !== '') {
			$notification->setParsedMessage($parameters['reason']);
		}
	}
}

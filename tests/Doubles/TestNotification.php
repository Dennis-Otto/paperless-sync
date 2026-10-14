<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use DateTime;
use LogicException;
use OCP\Notification\IAction;
use OCP\Notification\INotification;

/**
 * A notification as Nextcloud keeps it: what the app sets is what it reads back. Unlike Nextcloud,
 * it checks no rich objects; NotifierTest checks them itself.
 */
final class TestNotification implements INotification {
	private string $app = '';
	private string $user = '';
	private ?DateTime $dateTime = null;
	private string $objectType = '';
	private string $objectId = '';
	private string $subject = '';
	private array $subjectParameters = [];
	private string $parsedSubject = '';
	private string $richSubject = '';
	/** @var array<string, array<string, string>> */
	private array $richSubjectParameters = [];
	private string $message = '';
	private array $messageParameters = [];
	private string $parsedMessage = '';
	private string $link = '';
	private string $icon = '';

	public function setApp(string $app): INotification {
		$this->app = $app;
		return $this;
	}

	public function getApp(): string {
		return $this->app;
	}

	public function setUser(string $user): INotification {
		$this->user = $user;
		return $this;
	}

	public function getUser(): string {
		return $this->user;
	}

	public function setDateTime(DateTime $dateTime): INotification {
		$this->dateTime = $dateTime;
		return $this;
	}

	public function getDateTime(): DateTime {
		return $this->dateTime ?? throw new LogicException('The notification has no time.');
	}

	public function setObject(string $type, string $id): INotification {
		$this->objectType = $type;
		$this->objectId = $id;
		return $this;
	}

	public function getObjectType(): string {
		return $this->objectType;
	}

	public function getObjectId(): string {
		return $this->objectId;
	}

	public function setSubject(string $subject, array $parameters = []): INotification {
		$this->subject = $subject;
		$this->subjectParameters = $parameters;
		return $this;
	}

	public function getSubject(): string {
		return $this->subject;
	}

	public function getSubjectParameters(): array {
		return $this->subjectParameters;
	}

	public function setParsedSubject(string $subject): INotification {
		$this->parsedSubject = $subject;
		return $this;
	}

	public function getParsedSubject(): string {
		return $this->parsedSubject;
	}

	public function setRichSubject(string $subject, array $parameters = []): INotification {
		$this->richSubject = $subject;
		/** @var array<string, array<string, string>> $parameters */
		$this->richSubjectParameters = $parameters;
		return $this;
	}

	public function getRichSubject(): string {
		return $this->richSubject;
	}

	/** @return array<string, array<string, string>> */
	public function getRichSubjectParameters(): array {
		return $this->richSubjectParameters;
	}

	public function setMessage(string $message, array $parameters = []): INotification {
		$this->message = $message;
		$this->messageParameters = $parameters;
		return $this;
	}

	public function getMessage(): string {
		return $this->message;
	}

	public function getMessageParameters(): array {
		return $this->messageParameters;
	}

	public function setParsedMessage(string $message): INotification {
		$this->parsedMessage = $message;
		return $this;
	}

	public function getParsedMessage(): string {
		return $this->parsedMessage;
	}

	public function setRichMessage(string $message, array $parameters = []): INotification {
		throw new LogicException('Paperless Sync sets no rich message.');
	}

	public function getRichMessage(): string {
		return '';
	}

	public function getRichMessageParameters(): array {
		return [];
	}

	public function setLink(string $link): INotification {
		$this->link = $link;
		return $this;
	}

	public function getLink(): string {
		return $this->link;
	}

	public function setIcon(string $icon): INotification {
		$this->icon = $icon;
		return $this;
	}

	public function getIcon(): string {
		return $this->icon;
	}

	public function setPriorityNotification(bool $priorityNotification): INotification {
		throw new LogicException('Paperless Sync sends no priority notifications.');
	}

	public function isPriorityNotification(): bool {
		return false;
	}

	public function createAction(): IAction {
		throw new LogicException('Paperless Sync adds no actions.');
	}

	public function addAction(IAction $action): INotification {
		throw new LogicException('Paperless Sync adds no actions.');
	}

	public function getActions(): array {
		return [];
	}

	public function addParsedAction(IAction $action): INotification {
		throw new LogicException('Paperless Sync adds no actions.');
	}

	public function getParsedActions(): array {
		return [];
	}

	public function isValid(): bool {
		return $this->app !== '' && $this->user !== '' && $this->dateTime !== null && $this->objectType !== '' && $this->objectId !== '' && $this->subject !== '';
	}

	public function isValidParsed(): bool {
		return $this->isValid() && $this->parsedSubject !== '';
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Tests\Doubles;

use OCP\Activity\IEvent;

/**
 * An entry of the activity stream as Nextcloud keeps it: what the app sets is what it reads back.
 * Unlike Nextcloud, it checks no rich objects; ProviderTest checks them itself.
 */
final class TestActivityEvent implements IEvent {
	private string $app = '';
	private string $type = '';
	private string $affectedUser = '';
	private string $author = '';
	private int $timestamp = 0;
	private string $subject = '';
	private array $subjectParameters = [];
	private string $parsedSubject = '';
	private string $richSubject = '';
	/** @var array<string, array<string, string>> */
	private array $richSubjectParameters = [];
	private string $message = '';
	private array $messageParameters = [];
	private string $parsedMessage = '';
	private string $richMessage = '';
	/** @var array<string, array<string, string>> */
	private array $richMessageParameters = [];
	private string $objectType = '';
	private string|int $objectId = '';
	private string $objectName = '';
	private string $link = '';
	private string $icon = '';
	private ?IEvent $child = null;
	private bool $generateNotification = true;

	public function setApp(string $app): IEvent {
		$this->app = $app;
		return $this;
	}

	public function setType(string $type): IEvent {
		$this->type = $type;
		return $this;
	}

	public function setAffectedUser(string $affectedUser): IEvent {
		$this->affectedUser = $affectedUser;
		return $this;
	}

	public function setAuthor(string $author): IEvent {
		$this->author = $author;
		return $this;
	}

	public function setTimestamp(int $timestamp): IEvent {
		$this->timestamp = $timestamp;
		return $this;
	}

	public function setSubject(string $subject, array $parameters = []): IEvent {
		$this->subject = $subject;
		$this->subjectParameters = $parameters;
		return $this;
	}

	public function setParsedSubject(string $subject): IEvent {
		$this->parsedSubject = $subject;
		return $this;
	}

	public function getParsedSubject(): string {
		return $this->parsedSubject;
	}

	public function setRichSubject(string $subject, array $parameters = []): IEvent {
		$this->richSubject = $subject;
		$this->richSubjectParameters = $parameters;
		return $this;
	}

	public function getRichSubject(): string {
		return $this->richSubject;
	}

	public function getRichSubjectParameters(): array {
		return $this->richSubjectParameters;
	}

	public function setMessage(string $message, array $parameters = []): IEvent {
		$this->message = $message;
		$this->messageParameters = $parameters;
		return $this;
	}

	public function setParsedMessage(string $message): IEvent {
		$this->parsedMessage = $message;
		return $this;
	}

	public function getParsedMessage(): string {
		return $this->parsedMessage;
	}

	public function setRichMessage(string $message, array $parameters = []): IEvent {
		$this->richMessage = $message;
		$this->richMessageParameters = $parameters;
		return $this;
	}

	public function getRichMessage(): string {
		return $this->richMessage;
	}

	public function getRichMessageParameters(): array {
		return $this->richMessageParameters;
	}

	public function setObject(string $objectType, string|int $objectId, string $objectName = ''): IEvent {
		$this->objectType = $objectType;
		$this->objectId = $objectId;
		$this->objectName = $objectName;
		return $this;
	}

	public function setLink(string $link): IEvent {
		$this->link = $link;
		return $this;
	}

	public function getApp(): string {
		return $this->app;
	}

	public function getType(): string {
		return $this->type;
	}

	public function getAffectedUser(): string {
		return $this->affectedUser;
	}

	public function getAuthor(): string {
		return $this->author;
	}

	public function getTimestamp(): int {
		return $this->timestamp;
	}

	public function getSubject(): string {
		return $this->subject;
	}

	public function getSubjectParameters(): array {
		return $this->subjectParameters;
	}

	public function getMessage(): string {
		return $this->message;
	}

	public function getMessageParameters(): array {
		return $this->messageParameters;
	}

	public function getObjectType(): string {
		return $this->objectType;
	}

	public function getObjectId(): string|int {
		return $this->objectId;
	}

	public function getObjectName(): string {
		return $this->objectName;
	}

	public function getLink(): string {
		return $this->link;
	}

	public function setIcon(string $icon): IEvent {
		$this->icon = $icon;
		return $this;
	}

	public function getIcon(): string {
		return $this->icon;
	}

	public function setChildEvent(IEvent $child): IEvent {
		$this->child = $child;
		return $this;
	}

	public function getChildEvent(): ?IEvent {
		return $this->child;
	}

	public function isValid(): bool {
		return $this->app !== '' && $this->type !== '' && $this->affectedUser !== '' && $this->subject !== '';
	}

	public function isValidParsed(): bool {
		return $this->isValid() && $this->parsedSubject !== '';
	}

	public function setGenerateNotification(bool $generate): IEvent {
		$this->generateNotification = $generate;
		return $this;
	}

	public function getGenerateNotification(): bool {
		return $this->generateNotification;
	}
}

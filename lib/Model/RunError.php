<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Model;

use OCP\IL10N;

/**
 * An error of a run as the status and the report keep it: its text in English, with %s or %1$s
 * for each parameter, its parameters and the setting that it names. A run, also a scheduled one,
 * has no reader when it writes the error, so it keeps the English text, which the log, the
 * command line and the status API show; translated() gives it in the language of the
 * administrator who reads the settings page (decision record 0016).
 *
 * The text is the key of its translation in l10n/, so it is a literal, the first argument of the
 * constructor at the place that makes the error, where scripts/check-l10n.mjs finds it. A parameter is a value, such as
 * a path, which no translation changes, or an error of its own, such as that of the exception
 * that failed the document, which translated() translates as well. An error that the app didn't
 * write, such as an answer of Paperless or of Nextcloud, stays as it came: plain().
 *
 * @psalm-type Stored = array{message: string, text?: string, parameters?: list<mixed>, setting?: string}
 */
final class RunError implements \JsonSerializable {
	/**
	 * @param string $text the text in English, with %s or %1$s for each parameter, or the message itself of a plain() error
	 * @param list<string|RunError> $parameters the values of its placeholders
	 * @param string $setting the setting that the error names as the way out, or '' for none
	 */
	public function __construct(
		public readonly string $text,
		public readonly array $parameters = [],
		public readonly string $setting = '',
		/** Whether the app wrote the text, which translated() translates; not for a plain() error. */
		public readonly bool $translatable = true,
	) {
	}

	/** An error as it came, such as the message of Paperless or of Nextcloud, which no translation changes. */
	public static function plain(string $message): self {
		return new self($message, [], '', false);
	}

	/**
	 * The error of an exception: the text that the app wrote, for an exception that keeps it, and
	 * otherwise its message as it came, or its class when it has none.
	 */
	public static function of(\Throwable $exception): self {
		if ($exception instanceof TranslatableError) {
			return $exception->runError();
		}
		$message = trim($exception->getMessage());

		return self::plain($message !== '' ? $message : $exception::class);
	}

	/**
	 * The error that the status or the report kept, as jsonSerialize() wrote it, or null for
	 * something else, such as a plain text of an earlier version.
	 */
	public static function stored(mixed $value): ?self {
		if ($value instanceof self) {
			return $value;
		}
		if (!is_array($value) || !isset($value['message']) || !is_string($value['message'])) {
			return null;
		}
		if (!isset($value['text']) || !is_string($value['text'])) {
			return self::plain($value['message']);
		}
		$parameters = [];
		$values = isset($value['parameters']) && is_array($value['parameters']) ? $value['parameters'] : [];
		/** @psalm-suppress MixedAssignment */
		foreach ($values as $parameter) {
			$parameters[] = is_string($parameter) ? $parameter : (self::stored($parameter) ?? '');
		}
		$setting = isset($value['setting']) && is_string($value['setting']) ? $value['setting'] : '';

		return new self($value['text'], $parameters, $setting);
	}

	/** The error in English, as the log shows it. */
	public function message(): string {
		if (!$this->translatable) {
			return $this->text;
		}

		return vsprintf($this->text, array_map(static fn (string|self $parameter): string => is_string($parameter) ? $parameter : $parameter->message(), $this->parameters));
	}

	/** The error in the language of the reader, from the translations of the app. */
	public function translated(IL10N $l10n): string {
		if (!$this->translatable) {
			return $this->text;
		}

		return $l10n->t($this->text, array_map(static fn (string|self $parameter): string => is_string($parameter) ? $parameter : $parameter->translated($l10n), $this->parameters));
	}

	/**
	 * The error with its values shortened to $length characters each, as the status keeps it: the
	 * message of a plain error and every value, but never the text, the key of its translation.
	 */
	public function shortened(int $length): self {
		if (!$this->translatable) {
			return self::plain(mb_substr($this->text, 0, $length));
		}

		return new self($this->text, array_map(static fn (string|self $parameter): string|self => is_string($parameter) ? mb_substr($parameter, 0, $length) : $parameter->shortened($length), $this->parameters), $this->setting);
	}

	/**
	 * The error as the status and the report keep it: its English message, which a reader of an
	 * earlier version shows, and the text, the parameters and the setting of one to translate.
	 *
	 * @return Stored
	 */
	public function jsonSerialize(): array {
		$stored = ['message' => $this->message()];
		if (!$this->translatable) {
			return $stored;
		}
		$stored['text'] = $this->text;
		if ($this->parameters !== []) {
			$stored['parameters'] = array_map(static fn (string|self $parameter): string|array => is_string($parameter) ? $parameter : $parameter->jsonSerialize(), $this->parameters);
		}
		if ($this->setting !== '') {
			$stored['setting'] = $this->setting;
		}

		return $stored;
	}
}

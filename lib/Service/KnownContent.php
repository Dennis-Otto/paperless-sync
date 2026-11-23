<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\DocumentMatch;
use OCA\PaperlessSync\Model\OwnDocument;
use OCA\PaperlessSync\Model\PerUserScope;
use UnexpectedValueException;

/**
 * The document of Paperless that counts as the document of a file, for every place that asks
 * before an upload: the inbox, an upload that Paperless has not finished a day later, and Send to
 * Paperless with the rules of Flow. A document that the SHA-256 of the file found has its content.
 * One that the MD5 alone found, as every document on Paperless-ngx 2.x, counts only once the
 * SHA-256 of its original is that of the file, which one download of the original tells.
 *
 * In per-user ownership, the account of the app sees the documents of every user, so a document
 * with the content of a file counts only when it is the file's user's: by its owner, whose
 * documents go to that user, or as that user's own upload whose answer never came. Every other
 * document doesn't count, and the file goes up as a document of its own. The owner is read before
 * the download, so that a document that doesn't count costs none. KnownContent only reads: the
 * caller gives a lost upload its owner.
 */
final class KnownContent {
	public function __construct(
		private PaperlessClientInterface $paperless,
	) {
	}

	/**
	 * The document of Paperless that counts as the document of a file with these checksums, or null
	 * when there is none, so that the file goes up. Without a scope, as in single ownership, every
	 * document with the content counts, and no owner is read.
	 *
	 * @param int $firstAttempt the time of the first upload of the file that may have reached Paperless, or 0 when there was none
	 * @throws HeldDocumentException when the document may be that of an upload of another user that waits for its owner, so that the file waits
	 * @throws \Throwable when Paperless can't answer the search or name the owner, or the original can't be downloaded
	 */
	public function own(DocumentFingerprint $content, ?PerUserScope $scope = null, int $firstAttempt = 0): ?OwnDocument {
		$match = $this->paperless->findDocument($content);
		if ($match === null) {
			return null;
		}
		$lostUpload = $scope === null || $scope->owners->single ? false : $this->claim($match->id, $scope, $firstAttempt);
		if ($lostUpload === null || !$this->confirms($match, $content)) {
			return null;
		}

		return new OwnDocument($match->id, $lostUpload);
	}

	/**
	 * Whether a document counts for the user of the scope: null when it doesn't, as for a document
	 * of another user or one that Paperless doesn't show the account; false when it is the user's
	 * by its owner, as Owners::userOf() of the owner is that user; true when it is provably the
	 * user's own upload whose answer never came: the account of the app owns it, and Paperless
	 * added it at or after the first upload of the file that may have reached it. Paperless makes
	 * the account the owner of every upload, so a document of the account that the run holds may
	 * be that of an upload of another user, which waits for its owner, and counts for nobody.
	 *
	 * @throws HeldDocumentException for a document that the run holds
	 * @throws UnexpectedValueException when Paperless doesn't name the owner
	 */
	private function claim(int $id, PerUserScope $scope, int $firstAttempt): ?bool {
		$document = $this->paperless->document($id);
		if ($document === null) {
			return null;
		}
		$owner = self::owner($id, $document);
		if ($owner !== null && self::held($owner, $scope)) {
			throw new HeldDocumentException($id);
		}
		if ($owner !== null && $firstAttempt > 0 && $firstAttempt <= self::added($document) && $owner === $this->paperless->accountId()) {
			return true;
		}

		return $scope->owners->userOf($owner) === $scope->user ? false : null;
	}

	/**
	 * Whether the run holds a document of this owner: one of the account while an upload of another
	 * user waits for its owner, or, while Paperless doesn't name its account then, every document
	 * that isn't the user's own user of Paperless's, as any may be the account's.
	 */
	private static function held(int $owner, PerUserScope $scope): bool {
		return $scope->held === false ? $owner !== $scope->owners->paperlessUserOf($scope->user) : $owner === $scope->held;
	}

	/**
	 * The owner of a document as Paperless names it, or null for none.
	 *
	 * @param array<string, mixed> $document
	 * @throws UnexpectedValueException when the answer has no owner, or one that is no ID
	 */
	private static function owner(int $id, array $document): ?int {
		/** @psalm-suppress MixedAssignment */
		$owner = $document['owner'] ?? null;
		if ($owner === null && array_key_exists('owner', $document)) {
			return null;
		}
		if (is_int($owner) || (is_string($owner) && ctype_digit($owner))) {
			return (int)$owner;
		}

		throw new UnexpectedValueException("Paperless did not name the owner of the document {$id}.");
	}

	/**
	 * When Paperless added a document, or 0 when it doesn't say.
	 *
	 * @param array<string, mixed> $document
	 */
	private static function added(array $document): int {
		/** @psalm-suppress MixedAssignment */
		$added = $document['added'] ?? null;
		$time = is_string($added) ? strtotime($added) : false;

		return $time === false ? 0 : $time;
	}

	/**
	 * Whether the original of a document that the search found has the content of these checksums.
	 * Paperless-ngx 2.x searches by MD5, which doesn't tell every two files apart; the SHA-256 of
	 * the original decides. The download goes to a temporary file in reads of 1 MB, and only
	 * reads, so a dry-run makes it too. An original that Paperless answers with 404 confirms
	 * nothing: a document whose original was missing at the migration of its checksums keeps only
	 * its MD5 and has no original to serve, so the file goes up and Paperless decides, instead of
	 * waiting in every run.
	 *
	 * @throws \Throwable when the original can't be downloaded for another reason
	 */
	private function confirms(DocumentMatch $match, DocumentFingerprint $content): bool {
		if ($match->bySha256) {
			return true;
		}
		try {
			$original = $this->paperless->downloadDocument($match->id, true, static function (): void {
			});
		} catch (PaperlessNotFoundException) {
			return false;
		}

		return $original->sha256 === $content->sha256;
	}
}

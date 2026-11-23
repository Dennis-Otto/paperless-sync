<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\DocumentMatch;

/**
 * The document of Paperless that has the content of a file, for every place that asks before an
 * upload: the inbox, an upload that Paperless has not finished a day later, and Send to Paperless
 * with the rules of Flow. A document that the SHA-256 of the file found has its content. One that
 * the MD5 alone found, as every document on Paperless-ngx 2.x, counts only once the SHA-256 of its
 * original is that of the file, which one download of the original tells.
 */
final class KnownContent {
	public function __construct(
		private PaperlessClientInterface $paperless,
	) {
	}

	/**
	 * The ID of the document whose original has the content of these checksums, or null when
	 * Paperless has none.
	 *
	 * @throws \Throwable when Paperless can't answer the search, or the original can't be downloaded
	 */
	public function document(DocumentFingerprint $content): ?int {
		$match = $this->find($content);

		return $match !== null && $this->confirms($match, $content) ? $match->id : null;
	}

	/**
	 * The document that Paperless finds by these checksums, before confirms() says whether it has
	 * their content, for a caller that first looks at the document, such as at its owner.
	 *
	 * @throws \Throwable when Paperless can't answer the search
	 */
	public function find(DocumentFingerprint $content): ?DocumentMatch {
		return $this->paperless->findDocument($content);
	}

	/**
	 * Whether the original of a document that find() returned has the content of these checksums.
	 * Paperless-ngx 2.x searches by MD5, which doesn't tell every two files apart; the SHA-256 of
	 * the original decides. The download goes to a temporary file in reads of 1 MB, and only
	 * reads, so a dry-run makes it too. An original that Paperless answers with 404 confirms
	 * nothing: a document whose original was missing at the migration of its checksums keeps only
	 * its MD5 and has no original to serve, so the file goes up and Paperless decides, instead of
	 * waiting in every run.
	 *
	 * @throws \Throwable when the original can't be downloaded for another reason
	 */
	public function confirms(DocumentMatch $match, DocumentFingerprint $content): bool {
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

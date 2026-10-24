<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessSync\Service;

use JsonException;
use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Model\ConnectionCheck;
use OCA\PaperlessSync\Model\DocumentFingerprint;
use OCA\PaperlessSync\Model\PaperlessConnection;
use OCA\PaperlessSync\Model\PaperlessScan;
use OCA\PaperlessSync\Model\SyncConfig;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\ITempManager;
use OCP\Security\IRemoteHostValidator;
use RuntimeException;
use UnexpectedValueException;

final class PaperlessApiService implements PaperlessClientInterface {
	/**
	 * The version of the API of Paperless that every request asks for, so that the answers keep
	 * their shape whatever version a Paperless serves by default: 9 on Paperless-ngx 2.16 to 2.20,
	 * 10 since 3.0, which serves 9 as well, for at least a year after 10 came (decision 0012).
	 */
	public const API_VERSION = '9';
	/** The first version of Paperless-ngx that serves version 9 of its API. */
	public const MINIMUM_VERSION = '2.16';
	/** The longest version of Paperless-ngx that the status keeps, such as 3.3.0 or 3.4.0-beta.1. */
	private const VERSION_LENGTH = 32;
	/** The longest message of a task that the app keeps, such as the reason of a failed import. */
	private const TASK_MESSAGE_LENGTH = 4000;
	/**
	 * The start of the result of a task that Paperless-ngx 2.x has marked as failed without its
	 * reason yet: the text of the traceback object of Python, such as "<traceback object at 0x…>".
	 */
	private const UNWRITTEN_REASON = '<traceback object at ';
	private const PAGE_SIZE = 1000;
	/** The matching algorithm "None" of Paperless: a tag that Paperless assigns to no document on its own. */
	private const MATCH_NONE = 0;
	/**
	 * The fields of a document that a run reads: those of the path template, the tags of the
	 * exclusion, the revision and the files of the document. A run asks Paperless for these alone,
	 * because the whole document holds its text, which Paperless would send of every document in
	 * every run.
	 */
	public const DOCUMENT_FIELDS = [
		'id', 'title', 'correspondent', 'document_type', 'storage_path', 'created', 'added', 'modified',
		'tags', 'original_file_name', 'archived_file_name', 'mime_type', 'owner', 'archive_serial_number',
		'page_count', 'custom_fields',
	];
	/** The answers to an upload that repeat for the same file: invalid, too large, or of a type Paperless doesn't take. */
	private const PERMANENT_UPLOAD_STATUSES = [400, 413, 415, 422];
	private const REASON_LENGTH = 300;
	/** The lists that the export reads, by the line of the connection checklist, in the order of a run. */
	private const EXPORT_LISTS = [
		ConnectionCheck::TRASH => '/api/trash/',
		ConnectionCheck::CORRESPONDENTS => '/api/correspondents/',
		ConnectionCheck::DOCUMENT_TYPES => '/api/document_types/',
		ConnectionCheck::STORAGE_PATHS => '/api/storage_paths/',
		ConnectionCheck::TAGS => '/api/tags/',
	];
	/** What to do when Paperless doesn't let the account view a list, by the line of the checklist. */
	private const VIEW_HINTS = [
		ConnectionCheck::DOCUMENTS => 'In Paperless, under Users & Groups, give the account the permission View for Documents.',
		ConnectionCheck::TRASH => 'In Paperless, under Users & Groups, give the account the permission View for Documents.',
		ConnectionCheck::CORRESPONDENTS => 'In Paperless, under Users & Groups, give the account the permission View for Correspondents.',
		ConnectionCheck::DOCUMENT_TYPES => 'In Paperless, under Users & Groups, give the account the permission View for Document Types.',
		ConnectionCheck::STORAGE_PATHS => 'In Paperless, under Users & Groups, give the account the permission View for Storage Paths.',
		ConnectionCheck::TAGS => 'In Paperless, under Users & Groups, give the account the permission View for Tags.',
	];
	/** What to do when Paperless answers with an error of its own. */
	private const STATUS_HINT = 'Check that Paperless runs, and the log of Paperless or of the proxy in front of it.';
	/** Why the trash of Paperless may lack the documents of other owners, and what that means for them. */
	private const TRASH_HINT = 'Paperless shows the account in its trash only the documents that it owns and those without an owner. Trashed documents of other owners count as missing: they move to the deleted folder only after the required missing scans. Make the account of the app the owner of the documents to mirror.';
	/** The same, when Paperless doesn't tell the checklist the account, which it would check otherwise. */
	private const TRASH_UNKNOWN_HINT = 'Paperless shows the account in its trash only the documents that it owns and those without an owner. Trashed documents of other owners count as missing: they move to the deleted folder only after the required missing scans. Make the account of the app the owner of the documents to mirror, and give it the permission View for UI Settings, so that this checklist can check it.';
	/** The visible documents of other owners, which the trash of Paperless leaves out. */
	private const FOREIGN_DOCUMENTS_HINT = 'Paperless leaves the documents of other owners out of the trash that it shows the account, and the account sees %s of them. When they go to the trash, their copies count as missing and move to the deleted folder only after the required missing scans. Make the account of the app their owner.';
	/** A superuser sees the trash of every owner, but its token controls all of Paperless. */
	/** Why the trash of Paperless may lack the documents of the users in per-user ownership, and what that means for them. */
	private const PER_USER_TRASH_HINT = 'Paperless shows the account in its trash only the documents that it owns and those without an owner, unless it is a superuser. In per-user ownership, the documents of the users belong to them, so their copies count as missing when they go to the trash, and move to the deleted folder only after the required missing scans.';
	private const SUPERUSER_HINT = 'The account is a superuser: the trash shows it the documents of every owner, but its token controls all of Paperless. Use an account that is no superuser, and make it the owner of the documents to mirror.';
	/** What /api/ui_settings/ needs, which tells the checklist the permissions and the ID of the account. */
	private const UI_SETTINGS_HINT = 'In Paperless, under Users & Groups, give the account the permission View for UI Settings, so that this checklist can read its permissions. The inbox needs Add for Documents and View for Paperless Tasks.';
	/** The account of the token when Paperless refused to name it, without the permission View for UI Settings. */
	private const ACCOUNT_FORBIDDEN = 'forbidden';
	/** The account of the token when Paperless didn't name it for another reason. */
	private const ACCOUNT_UNKNOWN = 'unknown';
	private IClient $client;

	/**
	 * A client of the Paperless of $connection alone, which PaperlessClientFactory makes: it never
	 * reads the settings, so that every request of a run goes where the run started.
	 */
	public function __construct(
		private PaperlessConnection $connection,
		IClientService $clientService,
		private ITempManager $tempManager,
		private IRemoteHostValidator $remoteHostValidator,
	) {
		$this->client = $clientService->newClient();
	}

	public function testConnection(): array {
		$page = $this->requestPage('/api/documents/', ['page' => 1, 'page_size' => 1]);

		return ['version' => $page['version'], 'apiVersion' => self::API_VERSION];
	}

	public function preflight(SyncConfig $config): array {
		try {
			$documents = $this->probe('/api/documents/');
		} catch (PaperlessLocalNetworkException $exception) {
			return [new ConnectionCheck(
				ConnectionCheck::PAPERLESS,
				ConnectionCheck::FAILED,
				$exception->advice(),
				[$exception->host],
				blocking: true,
				setting: PaperlessLocalNetworkException::SETTING,
			)];
		} catch (PaperlessVersionException) {
			return [ConnectionCheck::failed(
				ConnectionCheck::PAPERLESS,
				'Paperless does not serve version %s of its API, which Paperless Sync uses (HTTP 406 Not Acceptable).',
				[self::API_VERSION],
				'Update Paperless-ngx to %s or newer; with a newer Paperless-ngx, update Paperless Sync.',
				true,
				[self::MINIMUM_VERSION],
			)];
		} catch (PaperlessRequestException $exception) {
			return [self::unreachable(ConnectionCheck::PAPERLESS, $exception->getMessage(), true)];
		}
		$status = $documents['status'];
		$listed = self::listed($documents);
		if ($status === 401) {
			return [ConnectionCheck::failed(
				ConnectionCheck::PAPERLESS,
				'Paperless refused the API token (HTTP 401).',
				[],
				'Copy the token again from Paperless, under My Profile in the menu of its account, and check that the account is active.',
				true,
			)];
		}
		// Paperless answers a token without the permission for the documents with its own error in JSON.
		$forbidden = $status === 403 && $documents['data'] !== null;
		if ($listed === null && !$forbidden) {
			return [self::unexpectedAnswer($status, $documents['data'] !== null)];
		}

		$checks = [self::answered($documents['version']), self::visibleDocuments($listed)];
		// The inbox and the files that people send from Files reach Paperless as uploads.
		$uploads = $config->inboxEnabled || $config->sendGroup !== '';
		$account = $config->exportEnabled || $uploads ? $this->account() : self::ACCOUNT_UNKNOWN;
		if ($config->exportEnabled) {
			foreach (self::EXPORT_LISTS as $id => $path) {
				$checks[] = $this->listCheck($id, $path, $account, $config->perUser());
			}
		}
		if ($uploads) {
			$uploadChecks = [self::upload($account)];
			if ($config->inboxFolderTags || $config->inboxTags !== '') {
				$uploadChecks[] = self::inboxTags($account, $config->inboxCreateTags);
			}
			foreach ($uploadChecks as $check) {
				// Without the inbox, only "Send to Paperless" uploads, which the group of the setting
				// offers to admins by default. A token that may not upload still keeps every run
				// working, as for a mirror that never sends, so it gets a warning, not a failure.
				$checks[] = $config->inboxEnabled ? $check : $check->warningOnly();
			}
		}

		return $checks;
	}

	public function documents(bool $notes = false): PaperlessScan {
		// Ordered by their IDs, a document added during the scan joins the last page instead of
		// shifting the pages that the run has not read yet. Only the fields that a run reads come
		// back, without the text of the documents, their permissions, and their notes unless the run
		// writes the metadata next to the archive. A Paperless that ignored the list of fields would
		// still cut the text short.
		return $this->scan('/api/documents/', [
			'ordering' => 'id',
			'fields' => self::documentFields($notes),
			'truncate_content' => 'true',
		]);
	}

	public function trash(): PaperlessScan {
		// Paperless orders its trash on its own; the check of the scan finds the pages that shifted.
		return $this->scan('/api/trash/');
	}

	/** @psalm-suppress MixedAssignment */
	public function document(int $documentId, bool $notes = false): ?array {
		$response = $this->send(
			'GET',
			'/api/documents/' . $documentId . '/',
			[
				// The fields of the scan of a run, so that the document takes the same path as in a run.
				'query' => ['fields' => self::documentFields($notes), 'truncate_content' => 'true'],
				'connect_timeout' => 10,
				'timeout' => 60,
				'http_errors' => false,
			],
		);
		// Paperless answers 404 for a document in its trash, a deleted one, and one that the
		// account may not view, which a run leaves to the scheduled runs as well.
		if ($response->getStatusCode() === Http::STATUS_NOT_FOUND) {
			return null;
		}
		$this->requireSuccess($response->getStatusCode(), 'query document ' . $documentId);
		$data = $this->decodeBody($response->getBody());
		$document = [];
		foreach (is_array($data) ? $data : [] as $key => $value) {
			if (is_string($key)) {
				$document[$key] = $value;
			}
		}
		$id = $document['id'] ?? null;
		if (!is_numeric($id) || (int)$id !== $documentId) {
			throw new UnexpectedValueException("Paperless returned no document {$documentId}.");
		}

		return $document;
	}

	/**
	 * The fields of a document that a run asks for: those that it reads, and the notes of the
	 * document, which only the metadata next to the archive holds.
	 */
	private static function documentFields(bool $notes): string {
		return implode(',', $notes ? [...self::DOCUMENT_FIELDS, 'notes'] : self::DOCUMENT_FIELDS);
	}

	public function correspondents(): array {
		return $this->namedMap('/api/correspondents/');
	}

	public function documentTypes(): array {
		return $this->namedMap('/api/document_types/');
	}

	public function storagePaths(): array {
		return $this->namedMap('/api/storage_paths/');
	}

	/** @psalm-suppress MixedAssignment */
	public function tags(): array {
		/** @var array<string, string> $names */
		$names = [];
		$inbox = [];
		foreach ($this->getAll('/api/tags/') as $key => $tag) {
			$id = (string)$key;
			$name = $tag['name'] ?? null;
			$names[$id] = is_string($name) ? $name : $id;
			if (($tag['is_inbox_tag'] ?? false) === true) {
				$inbox[] = $id;
			}
		}

		return ['names' => $names, 'inbox' => $inbox];
	}

	public function users(): array {
		return $this->namedMap('/api/users/', 'username');
	}

	public function ownedDocuments(?int $owner): int {
		// The filters of the document list of Paperless, as the line of the trash uses them.
		$filter = $owner === null ? ['owner__isnull' => 1] : ['owner__id' => $owner];

		return $this->requestPage('/api/documents/', ['page' => 1, 'page_size' => 1] + $filter)['count'];
	}

	public function accountId(): ?int {
		$account = $this->account();

		return is_array($account) ? $account['id'] : null;
	}

	public function setOwner(int $documentId, int $owner): void {
		$response = $this->send(
			'PATCH',
			"/api/documents/{$documentId}/",
			[
				'json' => ['owner' => $owner],
				'connect_timeout' => 10,
				'timeout' => 60,
				// An error comes back as a response, so that the reason in its body can be read.
				'http_errors' => false,
			],
		);
		$status = $response->getStatusCode();
		// Paperless-ngx 2.x saves the owner and then reads the document again as the account, which
		// no longer sees a document that it gave away without keeping the permission View on it:
		// the answer is 404, but the document has its new owner. The task of the upload named the
		// document, so a 404 means it was given away, or deleted, and has nothing more to change.
		if ($status === 404) {
			return;
		}
		if ($status < 200 || $status >= 300) {
			$reason = $this->errorReason($response->getBody());
			throw new RuntimeException("Paperless did not make the user {$owner} the owner of the document {$documentId}: HTTP {$status}" . ($reason !== '' ? ": {$reason}" : '.'));
		}
	}

	/** @psalm-suppress MixedAssignment */
	public function user(string $username): ?array {
		// The filter of the list of users of Paperless; the username is compared here as well, so
		// that a Paperless that ignores the filter answers right too.
		$answer = $this->probe('/api/users/', ['page' => 1, 'page_size' => self::PAGE_SIZE, 'username__iexact' => $username]);
		self::requireUserManagement($answer['status']);
		if (self::listed($answer) === null) {
			throw new RuntimeException("Paperless did not list its users: HTTP {$answer['status']}.");
		}
		/** @var array{results: list<mixed>} $data what listed() checked */
		$data = $answer['data'];
		foreach ($data['results'] as $user) {
			$id = is_array($user) ? ($user['id'] ?? null) : null;
			if (is_array($user) && ($user['username'] ?? null) === $username && is_int($id)) {
				return ['id' => $id, 'superuser' => ($user['is_superuser'] ?? false) === true];
			}
		}

		return null;
	}

	public function createUser(string $username, #[\SensitiveParameter] string $password, array $permissions): void {
		$answer = $this->change('POST', '/api/users/', [
			'username' => $username,
			'password' => $password,
			'is_active' => true,
			'is_staff' => false,
			'is_superuser' => false,
			'user_permissions' => $permissions,
		]);
		self::requireUserManagement($answer['status']);
		if ($answer['status'] === 400) {
			throw new InvalidSettingException('Paperless did not create the user %1$s: %2$s', [$username, $answer['reason']], 'account_username');
		}
		if ($answer['status'] < 200 || $answer['status'] >= 300) {
			throw new RuntimeException("Paperless did not create the user {$username}: HTTP {$answer['status']}" . ($answer['reason'] !== '' ? ": {$answer['reason']}" : '.'));
		}
	}

	public function updateUser(int $id, #[\SensitiveParameter] string $password, array $permissions): void {
		$answer = $this->change('PATCH', "/api/users/{$id}/", [
			'password' => $password,
			'is_active' => true,
			'user_permissions' => $permissions,
		]);
		self::requireUserManagement($answer['status']);
		if ($answer['status'] < 200 || $answer['status'] >= 300) {
			throw new RuntimeException("Paperless did not change the user {$id}: HTTP {$answer['status']}" . ($answer['reason'] !== '' ? ": {$answer['reason']}" : '.'));
		}
	}

	public function userToken(string $username, #[\SensitiveParameter] string $password): string {
		$answer = $this->change('POST', '/api/token/', ['username' => $username, 'password' => $password]);
		$token = $answer['data']['token'] ?? null;
		if ($answer['status'] !== 200 || !is_string($token) || trim($token) === '') {
			throw new RuntimeException("Paperless did not give the token of the user {$username}: HTTP {$answer['status']}" . ($answer['reason'] !== '' ? ": {$answer['reason']}" : '.'));
		}

		return trim($token);
	}

	/** @psalm-suppress MixedAssignment */
	public function customFields(): array {
		$fields = [];
		foreach ($this->getAll('/api/custom_fields/') as $id => $field) {
			$name = $field['name'] ?? null;
			if (!is_string($name)) {
				continue;
			}
			$type = $field['data_type'] ?? null;
			$extra = $field['extra_data'] ?? null;
			$choices = is_array($extra) ? ($extra['select_options'] ?? null) : null;
			$options = [];
			foreach (is_array($choices) ? $choices : [] as $choice) {
				// Each choice of a select field has an ID, which a document holds, and a label.
				$choiceId = is_array($choice) ? ($choice['id'] ?? null) : null;
				$label = is_array($choice) ? ($choice['label'] ?? null) : null;
				if (is_scalar($choiceId) && is_string($label)) {
					$options[(string)$choiceId] = $label;
				}
			}
			$fields[(string)$id] = ['name' => $name, 'type' => is_string($type) ? $type : '', 'options' => $options];
		}

		return $fields;
	}

	public function newestDocuments(int $count): array {
		$page = $this->requestPage(
			'/api/documents/',
			[
				'page' => 1,
				'page_size' => $count,
				'ordering' => '-added',
				'fields' => implode(',', self::DOCUMENT_FIELDS),
				'truncate_content' => 'true',
			],
		);

		return array_slice($page['results'], 0, $count);
	}

	public function downloadDocument(int $documentId, bool $original, callable $consume): DocumentFingerprint {
		$temporaryPath = $this->tempManager->getTemporaryFile();
		if ($temporaryPath === false) {
			throw new RuntimeException('Could not create a temporary Paperless download file.');
		}
		chmod($temporaryPath, 0600);
		try {
			$response = $this->send(
				'GET',
				'/api/documents/' . $documentId . '/download/',
				[
					'query' => ['original' => $original ? 'true' : 'false'],
					'allow_redirects' => ['max' => 3, 'strict' => true, 'referer' => false, 'protocols' => ['http', 'https']],
					'connect_timeout' => 10,
					'timeout' => 180,
					'sink' => $temporaryPath,
				],
			);
			$this->requireSuccess($response->getStatusCode(), 'download document');
			$download = fopen($temporaryPath, 'rb');
			if (!is_resource($download)) {
				throw new RuntimeException('Could not open the temporary Paperless download.');
			}
			// The temporary file goes on to Nextcloud as it is: no copy of the document, neither in
			// memory nor on disk, whatever its size.
			try {
				$fingerprint = DocumentFingerprint::of($download);
				$consume($download);
			} finally {
				/** @psalm-suppress RedundantCondition Nextcloud may close the stream that it writes. */
				if (is_resource($download)) {
					fclose($download);
				}
			}

			return $fingerprint;
		} finally {
			if (is_file($temporaryPath)) {
				unlink($temporaryPath);
			}
		}
	}

	public function documentChecksum(int $documentId, bool $original): string {
		$response = $this->send(
			'GET',
			'/api/documents/' . $documentId . '/metadata/',
			[
				'connect_timeout' => 10,
				'timeout' => 60,
			],
		);
		$this->requireSuccess($response->getStatusCode(), 'query document metadata');
		$data = $this->decodeBody($response->getBody());
		if (!is_array($data)) {
			throw new UnexpectedValueException('Paperless returned invalid document metadata.');
		}
		$key = $original ? 'original_checksum' : 'archive_checksum';
		$checksum = $data[$key] ?? null;
		if (!is_string($checksum) || $checksum === '') {
			throw new UnexpectedValueException("Paperless did not return {$key}.");
		}

		return $checksum;
	}

	public function createTag(string $name): int {
		$response = $this->send(
			'POST',
			'/api/tags/',
			[
				// Without a match, Paperless assigns the tag to no other document on its own. Without
				// an owner, as the tags of the consume folder of Paperless, every account that may view
				// tags sees it; left out, the owner would be the account of the app.
				'json' => ['name' => $name, 'matching_algorithm' => self::MATCH_NONE, 'owner' => null],
				'connect_timeout' => 10,
				'timeout' => 60,
				// An error comes back as a response, so that the reason in its body can be read.
				'http_errors' => false,
			],
		);
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			$reason = $this->errorReason($response->getBody());
			throw new RuntimeException("Paperless could not create the tag {$name}: HTTP {$status}" . ($reason !== '' ? ": {$reason}" : '.'));
		}
		$data = $this->decodeBody($response->getBody());
		$id = is_array($data) ? self::id($data['id'] ?? null) : null;
		if ($id === null) {
			throw new UnexpectedValueException("Paperless did not return the ID of the new tag {$name}.");
		}

		return $id;
	}

	/** @psalm-suppress DocblockTypeContradiction */
	public function uploadDocument($source, string $filename, array $tags = []): string {
		if (!is_resource($source)) {
			throw new UnexpectedValueException('A readable upload stream is required.');
		}
		$temporaryPath = $this->tempManager->getTemporaryFile();
		if ($temporaryPath === false) {
			throw new RuntimeException('Could not create a temporary Paperless upload file.');
		}
		chmod($temporaryPath, 0600);
		$upload = fopen($temporaryPath, 'w+b');
		try {
			// The copy on local disk has a size, so that the request has a Content-Length. The stream of
			// a file on object storage has none, and Paperless reads a body without one as "No file was
			// submitted.", so that it refused every file of the inbox.
			$size = is_resource($upload) ? stream_copy_to_stream($source, $upload) : false;
			if (!is_resource($upload) || $size === false) {
				throw new RuntimeException('Could not copy the file for the Paperless upload.');
			}
			if ($size === 0) {
				// Paperless refuses it too: "The submitted file is empty."
				throw new PaperlessUploadException('The file is empty.', true);
			}
			rewind($upload);

			return $this->postDocument($upload, $filename, $tags);
		} finally {
			/** @psalm-suppress RedundantCondition Nextcloud's HTTP client may close the stream. */
			if (is_resource($upload)) {
				fclose($upload);
			}
			if (is_file($temporaryPath)) {
				unlink($temporaryPath);
			}
		}
	}

	/**
	 * @param resource $upload
	 * @param list<int> $tags
	 */
	private function postDocument($upload, string $filename, array $tags): string {
		try {
			$response = $this->send(
				'POST',
				'/api/documents/post_document/',
				[
					// Paperless takes the tags of the new document as one field tags for each.
					'multipart' => [
						['name' => 'document', 'contents' => $upload, 'filename' => $filename],
						...array_map(static fn (int $tag): array => ['name' => 'tags', 'contents' => (string)$tag], $tags),
					],
					'connect_timeout' => 10,
					'timeout' => 180,
					// An error comes back as a response, so that the reason in its body can be read.
					'http_errors' => false,
				],
			);
		} catch (PaperlessVersionException $exception) {
			// Every other file would fail the same way, so the file waits as for an unreachable Paperless.
			throw new PaperlessUploadException($exception->getMessage(), false, Http::STATUS_NOT_ACCEPTABLE);
		} catch (PaperlessRequestException $exception) {
			throw new PaperlessUploadException('Paperless could not be reached for the upload: ' . $exception->getMessage(), false);
		}
		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			$reason = $this->errorReason($response->getBody());
			throw new PaperlessUploadException(
				"Paperless answered the upload with HTTP {$status}" . ($reason !== '' ? ": {$reason}" : '.'),
				in_array($status, self::PERMANENT_UPLOAD_STATUSES, true),
				$status,
			);
		}
		$data = $this->decodeBody($response->getBody());
		if (is_string($data) && $data !== '') {
			return $data;
		}
		if (is_array($data)) {
			foreach (['task_id', 'id', 'data'] as $key) {
				if (isset($data[$key]) && is_scalar($data[$key])) {
					return (string)$data[$key];
				}
			}
		}

		throw new UnexpectedValueException('Paperless returned an invalid upload task response.');
	}

	/**
	 * The task of an upload, in either shape of Paperless. Version 9 of its API, which the app asks
	 * for, lists tasks without pages, with the status in upper case, the result as a text, such as
	 * "Success. New document id 456 created" or the reason of a failure, and related_document,
	 * which on Paperless-ngx 2.x is also the original of a duplicate. Version 10 of Paperless-ngx
	 * 3.x lists them in pages, with the status in lower case and the result in result_data, as
	 * document_id, reason, duplicate_of or error_message.
	 *
	 * Paperless-ngx 2.x marks a failed task in two steps: its handler of task_failure writes
	 * FAILURE with the traceback object as the result, "<traceback object at 0x…>", and its
	 * handler of task_postrun the reason a moment later. Such a task is still pending, so that a
	 * poll between the two steps doesn't end the import with the placeholder as its reason.
	 *
	 * @psalm-suppress MixedAssignment
	 */
	public function taskStatus(string $taskId): array {
		$page = $this->requestPage('/api/tasks/', ['task_id' => $taskId, 'page_size' => 10]);
		$task = $page['results'][0] ?? null;
		if ($task === null) {
			return ['status' => 'PENDING', 'message' => 'Paperless does not list the task.', 'documentId' => null, 'listed' => false];
		}
		$status = strtoupper(self::text($task['status'] ?? null));
		$result = $task['result_data'] ?? null;
		$data = is_array($result) ? $result : [];
		$message = self::text($task['result'] ?? null);
		if ($message === '') {
			$message = self::resultMessage($data);
		}
		if ($status === 'FAILURE' && str_starts_with($message, self::UNWRITTEN_REASON)) {
			return ['status' => 'PENDING', 'message' => 'Paperless has marked the task as failed, but not yet written the reason.', 'documentId' => null, 'listed' => true];
		}
		$ids = $task['related_document_ids'] ?? null;
		$documentId = null;
		if ($status === 'SUCCESS') {
			$documentId = self::id($data['document_id'] ?? null)
				?? self::id($task['related_document'] ?? null)
				?? self::id(is_array($ids) ? ($ids[0] ?? null) : null)
				?? (preg_match('/New document id (\d+) created/', $message, $match) === 1 ? self::id($match[1]) : null);
		}

		return [
			'status' => $status !== '' ? $status : 'PENDING',
			'message' => mb_substr($message, 0, self::TASK_MESSAGE_LENGTH),
			'documentId' => $documentId,
			'listed' => true,
		];
	}

	public function findDocument(DocumentFingerprint $content): ?int {
		foreach ([$content->sha256, $content->md5] as $checksum) {
			$page = $this->requestPage('/api/documents/', ['checksum__iexact' => $checksum, 'fields' => 'id', 'page_size' => 1]);
			$documentId = self::id($page['results'][0]['id'] ?? null);
			if ($documentId !== null) {
				return $documentId;
			}
		}

		return null;
	}

	/**
	 * What result_data of version 10 says about the end of a task, in the words that version 9
	 * uses for it; its traceback stays out.
	 *
	 * @param array<array-key, mixed> $data
	 */
	private static function resultMessage(array $data): string {
		foreach (['error_message', 'reason'] as $key) {
			$text = self::text($data[$key] ?? null);
			if ($text !== '') {
				return $text;
			}
		}
		$duplicate = self::id($data['duplicate_of'] ?? null);
		if ($duplicate !== null) {
			return "Not consuming: It is a duplicate of document #{$duplicate}" . (($data['duplicate_in_trash'] ?? false) === true ? ', which is in the trash of Paperless' : '');
		}

		return self::text($data['error_type'] ?? null);
	}

	/** A text of an answer of Paperless, or '' for anything else, such as null or a list. */
	private static function text(mixed $value): string {
		return is_string($value) || is_int($value) || is_float($value) ? trim((string)$value) : '';
	}

	/** The ID of a document or a tag, as a number or as the text of one, or null for anything else. */
	private static function id(mixed $value): ?int {
		if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
			$value = (int)$value;
		}

		return is_int($value) && $value > 0 ? $value : null;
	}

	/**
	 * Every entry of a small list of Paperless, such as its tags, by its ID. Without every tag, a
	 * document with an excluded tag or in the inbox would be exported, and without every
	 * correspondent, document type or storage path, documents would move to other paths. So a list
	 * that came back incomplete fails the run, before it changes anything in the archive.
	 *
	 * @return array<array-key, array<string, mixed>>
	 */
	private function getAll(string $path): array {
		$scan = $this->pages($path);
		if (!$scan['complete']) {
			$received = count($scan['results']);
			throw new UnexpectedValueException("Paperless's list {$path} came back incomplete or changed while the run read it ({$received} of {$scan['listed']} received).");
		}

		return $scan['byId'];
	}

	/** @param array<string, string> $query */
	private function scan(string $path, array $query = []): PaperlessScan {
		$scan = $this->pages($path, $query);

		return new PaperlessScan($scan['results'], $scan['complete'], $scan['listed']);
	}

	/**
	 * Every page of a list, its entries in the order of Paperless and by their IDs. The scan is
	 * complete only when every page carries the same count of Paperless, every page before the last
	 * is full, and the run received as many entries with different IDs as Paperless listed: a page
	 * that came back short or empty, or a list that changed while the run read it, may have left
	 * entries out.
	 *
	 * @param array<string, string> $query
	 * @return array{results: list<array<string, mixed>>, byId: array<array-key, array<string, mixed>>, complete: bool, listed: int}
	 */
	private function pages(string $path, array $query = []): array {
		$results = [];
		$byId = [];
		$complete = true;
		$listed = 0;
		$pages = 1;
		for ($page = 1; $page <= $pages; ++$page) {
			$next = $this->requestPage($path, $query + ['page' => $page, 'page_size' => self::PAGE_SIZE]);
			if ($page === 1) {
				$pages = max(1, (int)ceil($next['count'] / self::PAGE_SIZE));
			} elseif ($next['count'] !== $listed) {
				$complete = false;
			}
			$listed = $next['count'];
			if (!$next['paginated'] || ($page < $pages && count($next['results']) !== self::PAGE_SIZE)) {
				$complete = false;
			}
			foreach ($next['results'] as $result) {
				$results[] = $result;
				if (isset($result['id']) && (is_int($result['id']) || is_string($result['id']))) {
					$byId[(string)$result['id']] = $result;
				}
			}
		}

		return [
			'results' => $results,
			'byId' => $byId,
			'complete' => $complete && count($byId) === $listed && count($results) === $listed,
			'listed' => $listed,
		];
	}

	/**
	 * The name of every entry of a list by its ID, from the field $field of the entry.
	 *
	 * @return array<string, string>
	 * @psalm-suppress MixedAssignment
	 */
	private function namedMap(string $path, string $field = 'name'): array {
		/** @var array<string, string> $map */
		$map = [];
		foreach ($this->getAll($path) as $id => $item) {
			$name = $item[$field] ?? null;
			if (is_string($name)) {
				$map[(string)$id] = $name;
			}
		}

		return $map;
	}

	/**
	 * The page of a list, with the count of Paperless, and whether it was a page of a paginated list
	 * of Paperless: an object with the count and the results.
	 *
	 * @param array<string, int|string> $query
	 * @return array{count: int, results: list<array<string, mixed>>, paginated: bool, version: string}
	 * @psalm-suppress MixedAssignment
	 */
	private function requestPage(string $path, array $query): array {
		$response = $this->send(
			'GET',
			$path,
			[
				'query' => $query,
				'connect_timeout' => 10,
				'timeout' => 60,
			],
		);
		$this->requireSuccess($response->getStatusCode(), 'query ' . $path);
		$data = $this->decodeBody($response->getBody());
		if (!is_array($data)) {
			throw new UnexpectedValueException('Paperless returned an invalid JSON object.');
		}
		$rawResults = isset($data['results']) && is_array($data['results']) ? $data['results'] : (array_is_list($data) ? $data : []);
		/** @var list<array<string, mixed>> $results */
		$results = [];
		foreach ($rawResults as $result) {
			if (is_array($result)) {
				$normalized = [];
				foreach ($result as $key => $value) {
					if (is_string($key)) {
						$normalized[$key] = $value;
					}
				}
				$results[] = $normalized;
			}
		}

		$counted = isset($data['count']) && is_numeric($data['count']);

		return [
			'count' => $counted ? (int)$data['count'] : count($results),
			'results' => $results,
			'paginated' => $counted && isset($data['results']) && is_array($data['results']),
			'version' => self::serverVersion($response),
		];
	}

	/**
	 * The first page of a list of Paperless, or another of its endpoints: the status of the
	 * answer, its JSON, and the version of Paperless that its header X-Version names. An answer
	 * with an error comes back as well, for the line of the checklist that it decides.
	 *
	 * @param array<string, int|string> $query
	 * @return array{status: int, data: array<array-key, mixed>|null, version: string}
	 * @throws PaperlessRequestException when no answer arrives
	 */
	private function probe(string $path, array $query = ['page' => 1, 'page_size' => 1]): array {
		$response = $this->send(
			'GET',
			$path,
			[
				'query' => $query,
				'connect_timeout' => 10,
				'timeout' => 30,
				'http_errors' => false,
			],
		);
		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		/** @psalm-suppress MixedAssignment */
		$data = is_string($body) ? json_decode($body, true) : null;

		return [
			'status' => $response->getStatusCode(),
			'data' => is_array($data) ? $data : null,
			'version' => self::serverVersion($response),
		];
	}

	/**
	 * A request that changes Paperless, with $fields as its JSON: the status of the answer, its
	 * JSON, and the reason of an error in it, in one line. An answer with an error comes back as
	 * well, so that the caller tells a refused token from a refused value.
	 *
	 * @param 'POST'|'PATCH' $method
	 * @param array<string, mixed> $fields
	 * @return array{status: int, data: array<array-key, mixed>|null, reason: string}
	 * @throws PaperlessRequestException when no answer arrives
	 */
	private function change(string $method, string $path, #[\SensitiveParameter] array $fields): array {
		$response = $this->send(
			$method,
			$path,
			[
				'json' => $fields,
				'connect_timeout' => 10,
				'timeout' => 30,
				'http_errors' => false,
			],
		);
		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		$body = is_string($body) ? $body : '';
		/** @psalm-suppress MixedAssignment */
		$data = json_decode($body, true);
		$status = $response->getStatusCode();

		return [
			'status' => $status,
			'data' => is_array($data) ? $data : null,
			'reason' => $status >= 200 && $status < 300 ? '' : $this->errorReason($body),
		];
	}

	/**
	 * Refuses an answer that says that the token can't manage the users of Paperless: a token that
	 * Paperless doesn't know, or one of an account without the permissions, which only a superuser
	 * has at once.
	 */
	private static function requireUserManagement(int $status): void {
		if ($status === Http::STATUS_UNAUTHORIZED) {
			throw new InvalidSettingException('Paperless refused this token (HTTP 401). Copy the token of a superuser of Paperless, under My Profile in the menu of its account.', [], 'account_token');
		}
		if ($status === Http::STATUS_FORBIDDEN) {
			throw new InvalidSettingException('Paperless does not let the account of this token manage users (HTTP 403). Use the token of a superuser of Paperless.', [], 'account_token');
		}
	}

	/**
	 * The number of entries that a successful page of a list of Paperless counts, or null when the
	 * answer is an error or no page of a list.
	 *
	 * @param array{status: int, data: array<array-key, mixed>|null, version: string} $answer
	 */
	private static function listed(array $answer): ?int {
		$data = $answer['data'];
		if ($answer['status'] < 200 || $answer['status'] >= 300 || $data === null || !isset($data['count']) || !is_numeric($data['count']) || !is_array($data['results'] ?? null)) {
			return null;
		}

		return (int)$data['count'];
	}

	/**
	 * What Paperless says of the account of the token, in /api/ui_settings/: its ID, whether it
	 * is a superuser, and its permissions, such as add_document. Paperless answers that page only
	 * for an account with the permission View for UI Settings, and refuses it otherwise.
	 *
	 * @return array{id: int|null, superuser: bool, permissions: list<string>}|self::ACCOUNT_*
	 */
	private function account(): array|string {
		try {
			$answer = $this->probe('/api/ui_settings/', []);
		} catch (PaperlessRequestException) {
			return self::ACCOUNT_UNKNOWN;
		}
		if ($answer['status'] === 403) {
			return self::ACCOUNT_FORBIDDEN;
		}
		$data = $answer['data'] ?? [];
		/** @psalm-suppress MixedAssignment */
		$permissions = $data['permissions'] ?? null;
		if ($answer['status'] !== 200 || !is_array($permissions)) {
			return self::ACCOUNT_UNKNOWN;
		}
		/** @psalm-suppress MixedAssignment */
		$user = $data['user'] ?? null;
		/** @psalm-suppress MixedAssignment */
		$id = is_array($user) ? ($user['id'] ?? null) : null;

		return [
			'id' => is_int($id) ? $id : null,
			'superuser' => is_array($user) && ($user['is_superuser'] ?? false) === true,
			'permissions' => array_values(array_filter($permissions, 'is_string')),
		];
	}

	/**
	 * The line of a list that the export reads, with the number of its entries that the account
	 * sees. The trash gets its line from trashCheck().
	 *
	 * @param array{id: int|null, superuser: bool, permissions: list<string>}|self::ACCOUNT_* $account
	 */
	private function listCheck(string $id, string $path, array|string $account, bool $perUser): ConnectionCheck {
		try {
			$answer = $this->probe($path);
		} catch (PaperlessRequestException $exception) {
			return self::unreachable($id, $exception->getMessage(), false);
		}
		$listed = self::listed($answer);
		if ($listed === null) {
			return match (true) {
				$answer['status'] === 403 => ConnectionCheck::failed($id, 'The account may not view them (HTTP 403).', [], self::VIEW_HINTS[$id]),
				$id === ConnectionCheck::TRASH && $answer['status'] === 404 => ConnectionCheck::failed($id, 'Paperless has no trash (HTTP 404).', [], 'Update Paperless-ngx to %s or newer.', hintParameters: [self::MINIMUM_VERSION]),
				default => ConnectionCheck::failed($id, 'Paperless answered without the list (HTTP %s).', [(string)$answer['status']], self::STATUS_HINT),
			};
		}
		if ($id === ConnectionCheck::TRASH) {
			return $this->trashCheck($listed, $account, $perUser);
		}

		return ConnectionCheck::passed($id, 'Visible to the account: %s', [(string)$listed]);
	}

	/**
	 * The line of the trash, whose list the account may view. Paperless lists in its trash only
	 * the documents that the account owns and those without an owner, unless it is a superuser:
	 * a document of another owner that goes there looks to a run as if it were deleted without
	 * the trash. So the line counts the documents that the account sees but another account
	 * owns, and passes when there are none. A superuser sees every trashed document, but its
	 * token controls all of Paperless, which the line notes instead.
	 *
	 * @param array{id: int|null, superuser: bool, permissions: list<string>}|self::ACCOUNT_* $account
	 */
	private function trashCheck(int $listed, array|string $account, bool $perUser): ConnectionCheck {
		$visible = [(string)$listed];
		// In per-user ownership, the documents of the users are not the account's, and a superuser
		// sees the trash of all of them.
		if ($perUser) {
			return is_array($account) && $account['superuser']
				? ConnectionCheck::passed(ConnectionCheck::TRASH, 'Visible to the account: %s', $visible)
				: ConnectionCheck::warning(ConnectionCheck::TRASH, 'Visible to the account: %s', $visible, self::PER_USER_TRASH_HINT);
		}
		if (!is_array($account)) {
			return ConnectionCheck::warning(ConnectionCheck::TRASH, 'Visible to the account: %s', $visible, $account === self::ACCOUNT_FORBIDDEN ? self::TRASH_UNKNOWN_HINT : self::TRASH_HINT);
		}
		if ($account['superuser']) {
			return ConnectionCheck::warning(ConnectionCheck::TRASH, 'Visible to the account: %s', $visible, self::SUPERUSER_HINT);
		}
		$foreign = null;
		if ($account['id'] !== null) {
			try {
				// The filters of the document list of Paperless: with an owner, and not this account.
				$foreign = self::listed($this->probe('/api/documents/', ['page' => 1, 'page_size' => 1, 'owner__isnull' => 0, 'owner__id__none' => $account['id']]));
			} catch (PaperlessRequestException) {
				$foreign = null;
			}
		}
		if ($foreign === null) {
			return ConnectionCheck::warning(ConnectionCheck::TRASH, 'Visible to the account: %s', $visible, self::TRASH_HINT);
		}
		if ($foreign > 0) {
			return ConnectionCheck::warning(ConnectionCheck::TRASH, 'Visible to the account: %s', $visible, self::FOREIGN_DOCUMENTS_HINT, [(string)$foreign]);
		}

		return ConnectionCheck::passed(ConnectionCheck::TRASH, 'Visible to the account: %s', $visible);
	}

	/** The line of Paperless after it answered, with its version when its header names one. */
	private static function answered(string $version): ConnectionCheck {
		if (preg_match('/^\d+(\.\d+)+/', $version) !== 1) {
			return ConnectionCheck::passed(ConnectionCheck::PAPERLESS, 'Paperless answers at this URL.');
		}
		if (version_compare($version, self::MINIMUM_VERSION, '<')) {
			return ConnectionCheck::warning(
				ConnectionCheck::PAPERLESS,
				'Paperless-ngx %s answers at this URL, but Paperless Sync needs %s or newer.',
				[$version, self::MINIMUM_VERSION],
				'Update Paperless-ngx, so that it serves version %s of its API, which every request of Paperless Sync asks for.',
				[self::API_VERSION],
			);
		}

		return ConnectionCheck::passed(ConnectionCheck::PAPERLESS, 'Paperless-ngx %s answers at this URL.', [$version]);
	}

	/**
	 * The line of the documents: how many the account sees, or that it may see none. An account
	 * that sees no document at all usually lacks the permissions of the documents themselves.
	 */
	private static function visibleDocuments(?int $listed): ConnectionCheck {
		if ($listed === null) {
			return ConnectionCheck::failed(ConnectionCheck::DOCUMENTS, 'The account may not view them (HTTP 403).', [], self::VIEW_HINTS[ConnectionCheck::DOCUMENTS], true);
		}
		if ($listed === 0) {
			return ConnectionCheck::warning(
				ConnectionCheck::DOCUMENTS,
				'Visible to the account: %s',
				['0'],
				'Paperless shows an account only the documents that it owns, that have no owner, or that are shared with it. Give the account access to the documents to mirror: as their owner, through their permissions, or with a workflow of Paperless that sets them.',
			);
		}

		return ConnectionCheck::passed(ConnectionCheck::DOCUMENTS, 'Visible to the account: %s', [(string)$listed]);
	}

	/**
	 * The line of the inbox, as the permissions of the account say: it uploads every file with
	 * Add for Documents, and asks for the task of Paperless that imports it with View for
	 * Paperless Tasks, without which it never learns whether a file arrived.
	 *
	 * @param array{id: int|null, superuser: bool, permissions: list<string>}|self::ACCOUNT_* $account
	 */
	private static function upload(array|string $account): ConnectionCheck {
		if (!is_array($account)) {
			return ConnectionCheck::warning(
				ConnectionCheck::UPLOAD,
				'Paperless did not say whether the account may add documents and view the tasks that import them.',
				[],
				$account === self::ACCOUNT_FORBIDDEN
					? self::UI_SETTINGS_HINT
					: 'The first upload from the inbox shows it; the account needs the permissions Add for Documents and View for Paperless Tasks.',
			);
		}
		$add = $account['superuser'] || self::permitted(AccountPermissions::ADD_DOCUMENT, $account['permissions']);
		$tasks = $account['superuser'] || self::permitted(AccountPermissions::VIEW_PAPERLESS_TASK, $account['permissions']);

		return match (true) {
			$add && $tasks => ConnectionCheck::passed(ConnectionCheck::UPLOAD, 'The account may add documents and view the tasks that import them.'),
			$tasks => ConnectionCheck::failed(
				ConnectionCheck::UPLOAD,
				'The account may not add documents.',
				[],
				'In Paperless, under Users & Groups, give the account the permission Add for Documents.',
			),
			$add => ConnectionCheck::failed(
				ConnectionCheck::UPLOAD,
				'The account may not view the tasks that import its uploads.',
				[],
				'In Paperless, under Users & Groups, give the account the permission View for Paperless Tasks. Without it, the inbox never learns whether Paperless imported a file, and keeps the file.',
			),
			default => ConnectionCheck::failed(
				ConnectionCheck::UPLOAD,
				'The account may neither add documents nor view the tasks that import them.',
				[],
				'In Paperless, under Users & Groups, give the account the permissions Add for Documents and View for Paperless Tasks.',
			),
		};
	}

	/**
	 * The line of the tags that the files of the inbox get: the account finds them with the
	 * permission View for Tags, and creates those that Paperless lacks with Add for Tags. Without
	 * them, the files that need a tag wait in the inbox.
	 *
	 * @param array{id: int|null, superuser: bool, permissions: list<string>}|self::ACCOUNT_* $account
	 */
	private static function inboxTags(array|string $account, bool $create): ConnectionCheck {
		if (!is_array($account)) {
			return ConnectionCheck::warning(
				ConnectionCheck::INBOX_TAGS,
				$create ? 'Paperless did not say whether the account may view and create tags.' : 'Paperless did not say whether the account may view tags.',
				[],
				$account === self::ACCOUNT_FORBIDDEN
					? 'In Paperless, under Users & Groups, give the account the permission View for UI Settings, so that this checklist can read its permissions. The tags of the inbox need View for Tags, and Add for Tags to create the missing ones.'
					: 'The first upload from the inbox shows it; the tags of the inbox need the permission View for Tags, and Add for Tags to create the missing ones.',
			);
		}
		$view = $account['superuser'] || self::permitted(AccountPermissions::VIEW_TAG, $account['permissions']);
		$add = $account['superuser'] || self::permitted(AccountPermissions::ADD_TAG, $account['permissions']);

		return match (true) {
			$view && $add && $create => ConnectionCheck::passed(ConnectionCheck::INBOX_TAGS, 'The account may view and create the tags that the inbox gives its files.'),
			$view && !$create => ConnectionCheck::passed(ConnectionCheck::INBOX_TAGS, 'The account may view the tags that the inbox gives its files.'),
			$view => new ConnectionCheck(
				ConnectionCheck::INBOX_TAGS,
				ConnectionCheck::FAILED,
				'The account may not create tags, so a file that needs a tag that Paperless lacks waits in the inbox.',
				[],
				'In Paperless, under Users & Groups, give the account the permission Add for Tags, or turn off "Create missing tags".',
				setting: 'inbox_create_tags',
			),
			default => ConnectionCheck::failed(
				ConnectionCheck::INBOX_TAGS,
				'The account may not view tags, so the files of the inbox wait in the inbox.',
				[],
				$create && !$add
					? 'In Paperless, under Users & Groups, give the account the permissions View and Add for Tags.'
					: 'In Paperless, under Users & Groups, give the account the permission View for Tags.',
			),
		};
	}

	/**
	 * Whether $permissions hold $permission of the documents app of Paperless, which names its
	 * permissions without their app, as add_document; with it is fine as well.
	 *
	 * @param list<string> $permissions
	 */
	private static function permitted(string $permission, array $permissions): bool {
		return array_intersect([$permission, 'documents.' . $permission], $permissions) !== [];
	}

	/**
	 * The line of a request without an answer, with the message of the failure; a certificate
	 * that Nextcloud doesn't trust gets its own hint.
	 */
	private static function unreachable(string $id, string $message, bool $blocking): ConnectionCheck {
		$certificate = preg_match('/certificate|SSL|TLS|cURL error (35|51|58|60|77|83|90|91)\b/i', $message) === 1;

		return ConnectionCheck::failed(
			$id,
			'Paperless could not be reached: %s',
			[$message],
			$certificate
				? 'Nextcloud does not trust the certificate of Paperless. Use a certificate of a public certificate authority, or import your own with occ security:certificates:import.'
				: 'Check the Paperless URL, and that the Nextcloud server reaches it through its network, firewall and proxy.',
			$blocking,
		);
	}

	/**
	 * The line of an answer that is not one of Paperless's lists: an error of the server, or one
	 * with JSON of its own, or else a page that is no list, such as the login page of a proxy, a
	 * 404 of a wrong path, or the website of another app.
	 */
	private static function unexpectedAnswer(int $status, bool $json): ConnectionCheck {
		if ($status >= 500 || ($json && $status !== 404 && ($status < 200 || $status >= 300))) {
			return ConnectionCheck::failed(ConnectionCheck::PAPERLESS, 'Paperless answered with HTTP %s.', [(string)$status], self::STATUS_HINT, true);
		}

		return ConnectionCheck::failed(
			ConnectionCheck::PAPERLESS,
			'No Paperless API answered at this URL (HTTP %s).',
			[(string)$status],
			'Check the URL: when Paperless runs under a path, such as https://example.com/paperless, the URL needs that path, and a proxy in front of Paperless may answer with its login page.',
			true,
		);
	}

	/**
	 * The version of Paperless-ngx that its header X-Version names, such as 3.3.0, or '' without
	 * one: a proxy may leave the header out, and its text comes from the server, so anything that
	 * doesn't look like a version is left out too.
	 */
	private static function serverVersion(IResponse $response): string {
		$version = ltrim(trim($response->getHeader('X-Version')), 'vV');

		return strlen($version) <= self::VERSION_LENGTH && preg_match('/^\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1 ? $version : '';
	}

	/**
	 * Every request to Paperless, to $path below the URL of the connection, with its token in the
	 * header Authorization, and in its header Accept the version of the API that the app reads, so
	 * that a new default of Paperless doesn't change the shape of its answers. A connection without
	 * a URL or a token sends nothing. When the HTTP client fails before Paperless answers, as when
	 * Paperless can't be reached, only the message of its exception goes on, in a
	 * PaperlessRequestException: the trace of the exception of the client holds the options of the
	 * request with the token, which Nextcloud's log would write, and no attribute can leave them
	 * out of it. A Paperless that doesn't serve the version answers 406 Not Acceptable, which is a
	 * PaperlessVersionException, also when the request takes errors as answers.
	 *
	 * Nextcloud refuses addresses of the local network, against server-side request forgery. When
	 * the connection allows the local network, with the setting "Paperless runs in my local
	 * network", this request alone may go there: its URL is always the one of Paperless that an
	 * administrator entered, and the switch
	 * allow_local_remote_servers, which would lift the protection for every app, stays off.
	 * Without it, a request that follows redirects checks every one of them as Nextcloud does.
	 *
	 * @param 'GET'|'POST'|'PATCH' $method
	 * @param array<string, mixed> $options the options of Nextcloud's HTTP client, without the headers
	 */
	private function send(string $method, string $path, #[\SensitiveParameter] array $options): IResponse {
		$token = $this->connection->token();
		$localNetwork = $this->connection->localNetwork;
		if ($this->connection->url === '' || $token === '') {
			throw new UnexpectedValueException('Paperless is not configured.');
		}
		$url = rtrim($this->connection->url, '/') . '/' . ltrim($path, '/');
		$options['headers'] = $this->headersForToken($token);
		if ($localNetwork) {
			$options['nextcloud'] = ['allow_local_address' => true];
		} elseif (isset($options['allow_redirects']) && is_array($options['allow_redirects'])) {
			$options['allow_redirects'] = ['on_redirect' => $this->refuseLocalRedirect(...)] + $options['allow_redirects'];
		}
		try {
			$response = match ($method) {
				'POST' => $this->client->post($url, $options),
				'PATCH' => $this->client->patch($url, $options),
				default => $this->client->get($url, $options),
			};
		} catch (LocalServerException $exception) {
			$message = $this->failureMessage($exception->getMessage(), $token);
			if (!str_starts_with($message, 'No DNS record found')) {
				throw new PaperlessLocalNetworkException(self::refusedHost($message, $url));
			}
			// Nextcloud says with the same exception that it found no address for a name. Without
			// the setting, it looks the name up only in the DNS, so a name of the hosts file of the
			// server needs the setting; with it, the name has no address at all.
			if ($localNetwork) {
				throw new PaperlessRequestException($message);
			}
			$host = preg_match('/^No DNS record found for (\S+)/', $message, $match) === 1 ? $match[1] : (string)parse_url($url, PHP_URL_HOST);
			throw new PaperlessLocalNetworkException($host, true);
		} catch (\Throwable $exception) {
			// Nextcloud's HTTP client throws an answer with an error, with its status as the code.
			if ($exception->getCode() === Http::STATUS_NOT_ACCEPTABLE) {
				throw new PaperlessVersionException();
			}
			throw new PaperlessRequestException($this->failureMessage($exception->getMessage(), $token));
		}
		if ($response->getStatusCode() === Http::STATUS_NOT_ACCEPTABLE) {
			throw new PaperlessVersionException();
		}

		return $response;
	}

	/**
	 * Refuses a redirect to an address of the local network, as Nextcloud's HTTP client does with
	 * an on_redirect of its own, which the allow_redirects of a request replaces. Nextcloud's DNS
	 * pinning checks every redirect as well, but an administrator can turn it off with dns_pinning.
	 *
	 * @psalm-suppress UnusedParam Guzzle passes the request and the response before the target.
	 */
	private function refuseLocalRedirect(mixed $request, mixed $response, \Stringable $target): void {
		$host = (string)parse_url((string)$target, PHP_URL_HOST);
		if (!$this->remoteHostValidator->isValid($host)) {
			throw new LocalServerException('Host "' . $host . '" violates local access rules');
		}
	}

	/**
	 * The host that Nextcloud refused, as its message names it: 'Host "paperless" violates local
	 * access rules', or 'Host "192.168.1.20" (paperless.lan:8000) violates …' after it looked up the
	 * name, which the administrator knows better than the address. Without one, the host of the
	 * request.
	 */
	private static function refusedHost(string $message, string $url): string {
		if (preg_match('/Host "([^"]+)"(?: \((.+):\d+\))?/', $message, $match) === 1) {
			return ($match[2] ?? '') !== '' ? $match[2] : $match[1];
		}

		return (string)parse_url($url, PHP_URL_HOST);
	}

	/**
	 * The message of a failed request without the queries of its URLs, such as ?page=2 or the ID of
	 * a task, which the log doesn't need, and without the token, should a server repeat it.
	 */
	private function failureMessage(string $message, #[\SensitiveParameter] string $token): string {
		$message = (string)preg_replace('~(https?://[^\s?#`\'"<>]*)\?[^\s#`\'"<>]*~i', '$1', $message);

		return str_replace($token, '[redacted]', $message);
	}

	/** @return array<string, string> */
	private function headersForToken(#[\SensitiveParameter] string $token): array {
		return [
			'Authorization' => 'Token ' . $token,
			'User-Agent' => AppConstants::USER_AGENT,
			'Accept' => 'application/json; version=' . self::API_VERSION,
		];
	}

	/** @param resource|string|null $body @return array<array-key, mixed>|string */
	private function decodeBody($body): array|string {
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		if (!is_string($body) || $body === '') {
			throw new UnexpectedValueException('Paperless returned an empty response body.');
		}
		try {
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($decoded) && !is_string($decoded)) {
				throw new UnexpectedValueException('Paperless returned an unsupported JSON value.');
			}
			return $decoded;
		} catch (JsonException $exception) {
			throw new UnexpectedValueException('Paperless returned invalid JSON.', 0, $exception);
		}
	}

	/**
	 * The reason in the body of an error, in one line: the messages of a validation error of
	 * Paperless, such as {"document": ["File type text/plain not supported"]}, or the title of
	 * the page of a proxy in front of it, such as "413 Request Entity Too Large".
	 *
	 * @param resource|string|null $body
	 * @psalm-suppress MixedAssignment
	 */
	private function errorReason($body): string {
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		if (!is_string($body)) {
			return '';
		}
		$decoded = json_decode($body, true);
		if (is_array($decoded)) {
			$parts = [];
			foreach ($decoded as $field => $value) {
				$messages = [];
				$values = is_array($value) ? $value : [$value];
				array_walk_recursive($values, static function (mixed $item) use (&$messages): void {
					if (is_scalar($item)) {
						$messages[] = (string)$item;
					}
				});
				$text = implode(' ', $messages);
				$parts[] = is_string($field) && !in_array($field, ['detail', 'non_field_errors'], true) ? "{$field}: {$text}" : $text;
			}
			$reason = implode('; ', $parts);
		} elseif (is_string($decoded)) {
			$reason = $decoded;
		} else {
			$reason = preg_match('#<title[^>]*>(.*?)</title>#is', $body, $match) === 1 ? $match[1] : strip_tags($body);
		}

		return mb_substr(trim((string)preg_replace('/\s+/', ' ', html_entity_decode($reason))), 0, self::REASON_LENGTH);
	}

	private function requireSuccess(int $statusCode, string $operation): void {
		if ($statusCode < 200 || $statusCode >= 300) {
			throw new RuntimeException("Paperless could not {$operation}: HTTP {$statusCode}.");
		}
	}
}

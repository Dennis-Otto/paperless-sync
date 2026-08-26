<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{config: \OCA\PaperlessSync\Model\SyncConfig, status: array<string, mixed>, saveUrl: string, resetUrl: string, runUrl: string, statusUrl: string} $_
 */

script('paperless_sync', 'settings');
style('paperless_sync', 'settings');

$config = $_['config'];
$status = $_['status'];
?>

<div
	id="paperless-sync-settings"
	class="section paperless-sync-settings"
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-reset-url="<?php p($_['resetUrl']); ?>"
	data-run-url="<?php p($_['runUrl']); ?>"
	data-status-url="<?php p($_['statusUrl']); ?>"
	data-token-configured="<?php p($config->tokenConfigured ? 'true' : 'false'); ?>">
	<div class="paperless-sync-hero">
		<img src="<?php p(image_path('paperless_sync', 'app.svg')); ?>" alt="" width="64" height="64">
		<div>
			<h2><?php p($l->t('Paperless Sync')); ?></h2>
			<p><?php p($l->t('Keep Paperless as your authoritative archive and make finalized documents available through Nextcloud Files.')); ?></p>
		</div>
	</div>

	<div class="paperless-sync-status-card" aria-live="polite">
		<div>
			<span class="paperless-sync-status-label"><?php p($l->t('Synchronization status')); ?></span>
			<strong id="paperless-sync-last-state"><?php p((string)($status['state'] ?? 'never-run')); ?></strong>
		</div>
		<div>
			<span class="paperless-sync-status-label"><?php p($l->t('Last completed')); ?></span>
			<strong id="paperless-sync-last-completed" data-timestamp="<?php p((string)($status['lastCompleted'] ?? 0)); ?>">—</strong>
		</div>
		<button id="paperless-sync-refresh-status" type="button"><?php p($l->t('Refresh')); ?></button>
	</div>

	<form id="paperless-sync-form">
		<section class="paperless-sync-card">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">1</span><h3><?php p($l->t('Connection and ownership')); ?></h3></div>
				<p><?php p($l->t('The target user owns all synchronized files. No Nextcloud password is required.')); ?></p>
			</div>
			<div class="paperless-sync-grid">
				<label class="paperless-sync-field paperless-sync-field--wide">
					<span><?php p($l->t('Paperless URL')); ?></span>
					<input id="paperless-sync-paperless-url" data-setting="paperless_url" type="url" value="<?php p($config->paperlessUrl); ?>" placeholder="https://paperless.example.com" required>
				</label>
				<label class="paperless-sync-field">
					<span><?php p($l->t('Paperless API token')); ?></span>
					<input id="paperless-sync-token" type="password" autocomplete="new-password" placeholder="<?php p($config->tokenConfigured ? $l->t('Configured — leave blank to keep it') : $l->t('Required')); ?>">
				</label>
				<label class="paperless-sync-field">
					<span><?php p($l->t('Nextcloud target user ID')); ?></span>
					<input data-setting="target_user" type="text" value="<?php p($config->targetUser); ?>" placeholder="paperless-service" required>
				</label>
				<label class="paperless-sync-field paperless-sync-field--wide">
					<span><?php p($l->t('Base folder')); ?></span>
					<input data-setting="base_path" type="text" value="<?php p($config->basePath); ?>" required>
				</label>
			</div>
			<p class="settings-hint"><?php p($l->t('Use a dedicated Paperless account. Grant document upload permission only when inbox import is enabled. The token is stored server-side and is never returned to the browser.')); ?></p>
		</section>

		<section class="paperless-sync-card">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">2</span><h3><?php p($l->t('Schedule and modules')); ?></h3></div>
				<p><?php p($l->t('Keep synchronization disabled until a dry-run has been reviewed.')); ?></p>
			</div>
			<div class="paperless-sync-switches">
				<label><input data-setting="enabled" type="checkbox" <?php if ($config->enabled) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Enable scheduled synchronization')); ?></span></label>
				<label><input data-setting="export_enabled" type="checkbox" <?php if ($config->exportEnabled) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Enable Paperless → Nextcloud archive export')); ?></span></label>
				<label><input data-setting="inbox_enabled" type="checkbox" <?php if ($config->inboxEnabled) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Enable Nextcloud inbox → Paperless import')); ?></span></label>
			</div>
			<div class="paperless-sync-grid">
				<label class="paperless-sync-field">
					<span><?php p($l->t('Interval in minutes')); ?></span>
					<input data-setting="sync_interval_minutes" type="number" min="1" max="1440" value="<?php p((string)$config->syncIntervalMinutes); ?>">
				</label>
				<label class="paperless-sync-field">
					<span><?php p($l->t('Maximum changes per module and run')); ?></span>
					<input data-setting="batch_size" type="number" min="1" max="1000" value="<?php p((string)$config->batchSize); ?>">
				</label>
			</div>
		</section>

		<section class="paperless-sync-card">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">3</span><h3><?php p($l->t('Structured archive')); ?></h3></div>
				<p><?php p($l->t('Metadata changes automatically move files to their new path.')); ?></p>
			</div>
			<div class="paperless-sync-grid">
				<label class="paperless-sync-field">
					<span><?php p($l->t('Archive folder')); ?></span>
					<input data-setting="archive_folder" type="text" value="<?php p($config->archiveFolder); ?>">
				</label>
				<label class="paperless-sync-field">
					<span><?php p($l->t('File conflict policy')); ?></span>
					<select data-setting="conflict_mode">
						<option value="replace" <?php if ($config->conflictMode === 'replace') {
							print_unescaped('selected');
						} ?>><?php p($l->t('Replace synchronized file')); ?></option>
						<option value="skip" <?php if ($config->conflictMode === 'skip') {
							print_unescaped('selected');
						} ?>><?php p($l->t('Skip and report conflict')); ?></option>
					</select>
				</label>
				<label class="paperless-sync-field paperless-sync-field--wide">
					<span><?php p($l->t('Archive path template')); ?></span>
					<input data-setting="path_template" type="text" value="<?php p($config->pathTemplate); ?>" required>
				</label>
			</div>
			<div class="paperless-sync-switches paperless-sync-switches--inline">
				<label><input data-setting="prefer_archive" type="checkbox" <?php if ($config->preferArchive) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Prefer Paperless archive versions')); ?></span></label>
				<label><input data-setting="skip_inbox" type="checkbox" <?php if ($config->skipInbox) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Skip Paperless inbox documents')); ?></span></label>
			</div>
			<label class="paperless-sync-field paperless-sync-field--wide">
				<span><?php p($l->t('Additional excluded Paperless tags')); ?></span>
				<input data-setting="excluded_tags" type="text" value="<?php p($config->excludedTags); ?>" placeholder="Draft, Private">
			</label>
			<p class="settings-hint"><?php p($l->t('Separate tag names with commas. Template variables include id, title, correspondent, document_type, storage_path, created, created_year, created_month, added, added_year, original_filename, and extension.')); ?></p>
		</section>

		<section class="paperless-sync-card">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">4</span><h3><?php p($l->t('Nextcloud inbox')); ?></h3></div>
				<p><?php p($l->t('Files are submitted through the Paperless API and kept until Paperless reports success.')); ?></p>
			</div>
			<div class="paperless-sync-grid">
				<label class="paperless-sync-field"><span><?php p($l->t('Inbox folder')); ?></span><input data-setting="inbox_folder" type="text" value="<?php p($config->inboxFolder); ?>"></label>
				<label class="paperless-sync-field"><span><?php p($l->t('Error folder')); ?></span><input data-setting="error_folder" type="text" value="<?php p($config->errorFolder); ?>"></label>
			</div>
			<div class="paperless-sync-switches paperless-sync-switches--inline">
				<label><input data-setting="recursive_inbox" type="checkbox" <?php if ($config->recursiveInbox) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Scan inbox subfolders')); ?></span></label>
				<label><input data-setting="delete_inbox_after_success" type="checkbox" <?php if ($config->deleteInboxAfterSuccess) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Remove source after successful import')); ?></span></label>
			</div>
		</section>

		<section class="paperless-sync-card paperless-sync-card--danger">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">5</span><h3><?php p($l->t('Trash and deletion safety')); ?></h3></div>
				<p><?php p($l->t('Permanent deletion is disabled by default and should be enabled only after successful parallel testing.')); ?></p>
			</div>
			<div class="paperless-sync-grid">
				<label class="paperless-sync-field">
					<span><?php p($l->t('Paperless trash behavior')); ?></span>
					<select data-setting="trash_mode">
						<option value="move" <?php if ($config->trashMode === 'move') {
							print_unescaped('selected');
						} ?>><?php p($l->t('Move to deleted folder')); ?></option>
						<option value="keep" <?php if ($config->trashMode === 'keep') {
							print_unescaped('selected');
						} ?>><?php p($l->t('Keep archive file in place')); ?></option>
					</select>
				</label>
				<label class="paperless-sync-field"><span><?php p($l->t('Deleted folder')); ?></span><input data-setting="deleted_folder" type="text" value="<?php p($config->deletedFolder); ?>"></label>
				<label class="paperless-sync-field"><span><?php p($l->t('Required consecutive missing scans')); ?></span><input data-setting="missing_grace_runs" type="number" min="1" max="30" value="<?php p((string)$config->missingGraceRuns); ?>"></label>
			</div>
			<div class="paperless-sync-switches">
				<label class="paperless-sync-danger-toggle"><input data-setting="permanent_delete" type="checkbox" <?php if ($config->permanentDelete) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Permanently delete the Nextcloud file after permanent Paperless deletion')); ?></span></label>
				<label><input data-setting="allow_direct_delete" type="checkbox" <?php if ($config->allowDirectDelete) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Also allow deletion when the app never observed the Paperless trash state')); ?></span></label>
				<label><input data-setting="prune_empty_folders" type="checkbox" <?php if ($config->pruneEmptyFolders) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Remove empty folders after moves and deletions')); ?></span></label>
			</div>
		</section>

		<details class="paperless-sync-card paperless-sync-advanced">
			<summary><?php p($l->t('Advanced fallback names')); ?></summary>
			<div class="paperless-sync-grid">
				<label class="paperless-sync-field"><span><?php p($l->t('Missing correspondent')); ?></span><input data-setting="empty_correspondent" type="text" value="<?php p($config->emptyCorrespondent); ?>"></label>
				<label class="paperless-sync-field"><span><?php p($l->t('Missing document type')); ?></span><input data-setting="empty_document_type" type="text" value="<?php p($config->emptyDocumentType); ?>"></label>
				<label class="paperless-sync-field"><span><?php p($l->t('Missing date')); ?></span><input data-setting="empty_date" type="text" value="<?php p($config->emptyDate); ?>"></label>
				<label class="paperless-sync-field"><span><?php p($l->t('Missing title')); ?></span><input data-setting="untitled" type="text" value="<?php p($config->untitled); ?>"></label>
			</div>
		</details>

		<div class="paperless-sync-actions">
			<button id="paperless-sync-save" type="submit" class="primary"><?php p($l->t('Test connection and save')); ?></button>
			<button id="paperless-sync-dry-run" type="button"><?php p($l->t('Run dry-run')); ?></button>
			<button id="paperless-sync-run" type="button"><?php p($l->t('Synchronize now')); ?></button>
			<button id="paperless-sync-reset" type="button" class="paperless-sync-reset"><?php p($l->t('Disconnect')); ?></button>
		</div>
	</form>

	<p id="paperless-sync-message" class="paperless-sync-message" role="status" aria-live="polite"></p>
	<div id="paperless-sync-report" class="paperless-sync-report" hidden>
		<h3><?php p($l->t('Run report')); ?></h3>
		<div id="paperless-sync-report-summary" class="paperless-sync-report-summary"></div>
		<pre id="paperless-sync-report-actions"></pre>
	</div>
</div>

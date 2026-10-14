<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{config: \OCA\PaperlessSync\Model\SyncConfig, status: array<string, mixed>, saveUrl: string, testUrl: string, resetUrl: string, runUrl: string, statusUrl: string, previewUrl: string} $_
 */

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCP\Util;

script('paperless_sync', 'settings');
style('paperless_sync', 'settings');

$config = $_['config'];
$status = $_['status'];
// The state of the last run as StatusService stores it, and its label; js/settings.js has the same.
$state = (string)($status['state'] ?? 'never-run');
$stateLabels = [
	'never-run' => $l->t('Never run'),
	'running' => $l->t('Running'),
	'dry-run-running' => $l->t('Dry-run running'),
	'completed' => $l->t('Completed'),
	'dry-run-completed' => $l->t('Dry-run completed'),
	'completed-with-errors' => $l->t('Completed with errors'),
	'failed' => $l->t('Failed'),
];
// A link from a section to its part of the guide, which names the section for screen readers.
$help = static function (string $anchor, string $section) use ($l): void {
	print_unescaped(sprintf(
		'<a class="paperless-sync-help" href="%s" target="_blank" rel="noreferrer noopener" aria-label="%s">%s</a>',
		Util::sanitizeHTML(AppConstants::GUIDE_URL . '#' . $anchor),
		Util::sanitizeHTML($l->t('Help on %s', [$section])),
		Util::sanitizeHTML($l->t('Help')),
	));
};
?>

<div
	id="paperless-sync-settings"
	class="section paperless-sync-settings"
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-test-url="<?php p($_['testUrl']); ?>"
	data-reset-url="<?php p($_['resetUrl']); ?>"
	data-run-url="<?php p($_['runUrl']); ?>"
	data-status-url="<?php p($_['statusUrl']); ?>"
	data-preview-url="<?php p($_['previewUrl']); ?>">
	<div class="paperless-sync-hero">
		<img src="<?php p(image_path('paperless_sync', 'app.svg')); ?>" alt="" width="64" height="64">
		<div>
			<h2><?php p($l->t('Paperless Sync')); ?></h2>
			<p><?php p($l->t('Keep Paperless as your authoritative archive and make finalized documents available through Nextcloud Files.')); ?></p>
		</div>
	</div>

	<div id="paperless-sync-status" class="paperless-sync-status">
		<div class="paperless-sync-status-card">
			<div>
				<span class="paperless-sync-status-label"><?php p($l->t('Synchronization status')); ?></span>
				<span class="paperless-sync-status-value">
					<span id="paperless-sync-running" class="paperless-sync-spinner icon-loading-small" <?php if (!str_ends_with($state, 'running')) {
						print_unescaped('hidden');
					} ?>></span>
					<strong id="paperless-sync-last-state" data-state="<?php p($state); ?>" aria-live="polite"><?php p($stateLabels[$state] ?? $state); ?></strong>
				</span>
			</div>
			<div>
				<span class="paperless-sync-status-label"><?php p($l->t('Last finished')); ?></span>
				<strong id="paperless-sync-last-ended">—</strong>
			</div>
			<div>
				<span class="paperless-sync-status-label"><?php p($l->t('Last successful run')); ?></span>
				<strong id="paperless-sync-last-success">—</strong>
			</div>
			<div>
				<span class="paperless-sync-status-label"><?php p($l->t('Next scheduled run')); ?></span>
				<strong id="paperless-sync-next-run">—</strong>
			</div>
			<button id="paperless-sync-refresh-status" type="button"><?php p($l->t('Refresh')); ?></button>
			<p id="paperless-sync-paperless-version" class="paperless-sync-status-paperless" hidden></p>
		</div>
		<div id="paperless-sync-errors" class="paperless-sync-errors" role="alert" hidden>
			<h3 id="paperless-sync-errors-title"></h3>
			<ul id="paperless-sync-errors-list"></ul>
		</div>
	</div>

	<form id="paperless-sync-form">
		<section class="paperless-sync-card">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">1</span><h3><?php p($l->t('Connection and ownership')); ?></h3></div>
				<p><?php p($l->t('The target user owns all synchronized files. No Nextcloud password is required.')); ?> <?php $help('paperless-connection', $l->t('Connection and ownership')); ?></p>
			</div>
			<div class="paperless-sync-grid">
				<label class="paperless-sync-field paperless-sync-field--wide">
					<span><?php p($l->t('Paperless URL')); ?></span>
					<input id="paperless-sync-paperless-url" data-setting="paperless_url" type="url" value="<?php p($config->paperlessUrl); ?>" placeholder="https://paperless.example.com" required>
				</label>
				<label class="paperless-sync-field">
					<span><?php p($l->t('Paperless API token')); ?></span>
					<input id="paperless-sync-token" type="password" autocomplete="new-password" aria-describedby="paperless-sync-token-hint" placeholder="<?php p($config->tokenConfigured ? $l->t('Configured — leave blank to keep it') : $l->t('Required')); ?>"<?php if (!$config->tokenConfigured) { ?> required<?php } ?>>
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
			<p class="settings-hint" id="paperless-sync-token-hint"><?php p($l->t('Use a dedicated Paperless account. Grant document upload permission only when inbox import is enabled. The token is stored server-side and is never returned to the browser.')); ?> <?php p($l->t('A new Paperless URL needs the token again, and saving, testing or disconnecting may ask for your password.')); ?></p>
			<div class="paperless-sync-switches">
				<label><input data-setting="paperless_local_network" type="checkbox" aria-describedby="paperless-sync-local-network-hint" <?php if ($config->paperlessLocalNetwork) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Paperless runs in my local network')); ?></span></label>
			</div>
			<p class="settings-hint" id="paperless-sync-local-network-hint"><?php p($l->t('Turn this on when Nextcloud reaches Paperless at an address of your network, such as http://paperless:8000 or http://192.168.1.20:8000, which Nextcloud blocks against server-side request forgery. It allows such an address only for the requests of this app to the Paperless URL above, not for other apps. Paperless can then also redirect these requests to other devices of your network, so turn it on only for a Paperless that you trust.')); ?></p>
			<div class="paperless-sync-test">
				<button id="paperless-sync-test" type="button" aria-describedby="paperless-sync-test-hint"><?php p($l->t('Test connection')); ?></button>
				<p class="settings-hint" id="paperless-sync-test-hint"><?php p($l->t('Checks every Paperless permission that the enabled modules need, and the base folder, without saving.')); ?></p>
			</div>
			<div id="paperless-sync-checks" class="paperless-sync-checks" hidden>
				<h4 id="paperless-sync-checks-title"><?php p($l->t('Connection checklist')); ?></h4>
				<ul id="paperless-sync-checks-list" aria-labelledby="paperless-sync-checks-title"></ul>
			</div>
		</section>

		<section class="paperless-sync-card">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">2</span><h3><?php p($l->t('Schedule and modules')); ?></h3></div>
				<p><?php p($l->t('Keep synchronization disabled until a dry-run has been reviewed.')); ?> <?php $help('background-jobs', $l->t('Schedule and modules')); ?></p>
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
					<input data-setting="sync_interval_minutes" type="number" min="<?php p((string)ConfigService::MINIMUM_INTERVAL_MINUTES); ?>" max="<?php p((string)ConfigService::MAXIMUM_INTERVAL_MINUTES); ?>" value="<?php p((string)$config->syncIntervalMinutes); ?>" aria-describedby="paperless-sync-interval-hint">
				</label>
				<label class="paperless-sync-field">
					<span><?php p($l->t('Maximum changes per module and run')); ?></span>
					<input data-setting="batch_size" type="number" min="1" max="1000" value="<?php p((string)$config->batchSize); ?>">
				</label>
				<p id="paperless-sync-interval-hint" class="settings-hint paperless-sync-field--wide"><?php p($l->t('From 5 minutes to a day: Nextcloud starts background jobs every 5 minutes at most, and only with its system cron. The interval counts from the last run; a dry-run does not move the next one.')); ?></p>
			</div>
		</section>

		<section class="paperless-sync-card">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">3</span><h3><?php p($l->t('Structured archive')); ?></h3></div>
				<p><?php p($l->t('Metadata changes automatically move files to their new path.')); ?> <?php $help('path-template-variables', $l->t('Structured archive')); ?></p>
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
				<!-- The path template, the buttons of its variables, and the paths of the newest documents with it, before a save. -->
				<div id="paperless-sync-template" class="paperless-sync-template paperless-sync-field--wide">
					<label class="paperless-sync-field">
						<span><?php p($l->t('Archive path template')); ?></span>
						<input id="paperless-sync-path-template" class="paperless-sync-code" data-setting="path_template" type="text" value="<?php p($config->pathTemplate); ?>" spellcheck="false" autocomplete="off" autocapitalize="off" aria-describedby="paperless-sync-path-template-hint paperless-sync-path-preview-status" required>
					</label>
					<div class="paperless-sync-variables" role="group" aria-label="<?php p($l->t('Insert a variable at the cursor')); ?>">
						<?php foreach (PathTemplateService::VARIABLES as $variable) { ?>
							<button type="button" class="paperless-sync-code" data-variable="<?php p($variable); ?>" aria-label="<?php p($l->t('Insert %s', ['{{ ' . $variable . ' }}'])); ?>"><?php p($variable); ?></button>
						<?php } ?>
						<button type="button" class="paperless-sync-code" data-variable="custom_fields.<?php p($l->t('Name')); ?>" data-select="<?php p($l->t('Name')); ?>" aria-label="<?php p($l->t('Insert a custom field, then type its name')); ?>">custom_fields.…</button>
					</div>
					<p id="paperless-sync-path-template-hint" class="settings-hint"><?php p($l->t('Write variables as {{ name }}, and a custom field of Paperless as {{ custom_fields.Name }}; {{ id }} is required. A value that a document lacks, such as an owner, tags or a custom field, stays empty.')); ?> <?php p($l->t('Every file name keeps the marker [P{{ id }}] and ends with {{ extension }}, which are added when the template leaves them out. Long titles and names are shortened to the length that Nextcloud allows.')); ?></p>
					<div id="paperless-sync-path-preview" class="paperless-sync-path-preview">
						<p id="paperless-sync-path-preview-status" class="paperless-sync-path-preview-status" aria-live="polite"><?php p($config->tokenConfigured ? $l->t('The paths of the newest documents of Paperless appear here.') : $l->t('Save the connection to Paperless, and the paths of its newest documents appear here.')); ?></p>
						<ul id="paperless-sync-path-preview-list" class="paperless-sync-code" aria-label="<?php p($l->t('Paths of the newest documents in the archive folder')); ?>" hidden></ul>
					</div>
				</div>
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
				<input data-setting="excluded_tags" type="text" value="<?php p($config->excludedTags); ?>" placeholder="<?php p($l->t('Draft, Private')); ?>">
			</label>
			<p class="settings-hint"><?php p($l->t('Separate tag names with commas.')); ?></p>
		</section>

		<section class="paperless-sync-card">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">4</span><h3><?php p($l->t('Nextcloud inbox')); ?></h3></div>
				<p><?php p($l->t('Files are submitted through the Paperless API and kept until Paperless reports success.')); ?> <?php $help('nextcloud-inbox', $l->t('Nextcloud inbox')); ?></p>
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
				<p><?php p($l->t('Permanent deletion is off by default. Turn it on only after a few runs with deletion off looked right.')); ?> <?php $help('deletion-safety', $l->t('Trash and deletion safety')); ?></p>
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
				} ?>> <span><?php p($l->t('Also delete copies of documents that disappeared without passing the Paperless trash')); ?></span></label>
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
			<button id="paperless-sync-save" type="submit" class="primary"><?php p($config->enabled ? $l->t('Test connection and save') : $l->t('Save')); ?></button>
			<button id="paperless-sync-dry-run" type="button"><?php p($l->t('Run dry-run')); ?></button>
			<button id="paperless-sync-run" type="button"><?php p($l->t('Synchronize now')); ?></button>
			<button id="paperless-sync-reset" type="button"><?php p($l->t('Disconnect')); ?></button>
			<p id="paperless-sync-unsaved" class="paperless-sync-unsaved" hidden><?php p($l->t('Unsaved changes: "Run dry-run" tries them without saving them, and "Synchronize now" waits until you save.')); ?></p>
			<p id="paperless-sync-message" class="paperless-sync-message" role="status" aria-live="polite" tabindex="-1"></p>
		</div>
	</form>

	<div id="paperless-sync-report" class="paperless-sync-report" hidden>
		<h3><?php p($l->t('Run report')); ?></h3>
		<p id="paperless-sync-report-meta" class="paperless-sync-report-meta"></p>
		<div id="paperless-sync-report-summary" class="paperless-sync-report-summary"></div>
		<pre id="paperless-sync-report-actions" tabindex="0" role="region" aria-label="<?php p($l->t('File changes of the run')); ?>"></pre>
		<p id="paperless-sync-report-omitted" class="paperless-sync-report-omitted" hidden></p>
	</div>
</div>

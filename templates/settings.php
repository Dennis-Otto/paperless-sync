<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{config: \OCA\PaperlessSync\Model\SyncConfig, status: array<string, mixed>, saveUrl: string, testUrl: string, resetUrl: string, runUrl: string, statusUrl: string, previewUrl: string, filesUrl: string, tagsUrl: string, usersUrl: string, groupsUrl: string, webhookStateUrl: string, webhook: array{configured: bool, created: int, url: string, lastCall: array{at: int, document: int, result: string, message: string}|null}, health: list<array{severity: string, description: string}>} $_
 */

use OCA\PaperlessSync\AppInfo\AppConstants;
use OCA\PaperlessSync\Service\ConfigService;
use OCA\PaperlessSync\Service\PathTemplateService;
use OCP\Util;

script('paperless_sync', 'settings');
style('paperless_sync', 'settings');

$config = $_['config'];
$status = $_['status'];
// The webhook of the workflows of Paperless, without its secret, which only its creation shows.
$webhook = $_['webhook'];
// The warnings and errors of the setup checks of Administration → Overview, as they were when the
// page loaded, which it shows on top.
$health = $_['health'];
// The state of the last run as StatusService stores it, and its label; js/settings.js has the same.
$state = (string)($status['state'] ?? 'never-run');
$stateLabels = [
	'never-run' => $l->t('Never run'),
	'queued' => $l->t('Queued'),
	'running' => $l->t('Running'),
	'dry-run-running' => $l->t('Dry-run running'),
	'completed' => $l->t('Completed'),
	'dry-run-completed' => $l->t('Dry-run completed'),
	'completed-with-errors' => $l->t('Completed with errors'),
	'failed' => $l->t('Failed'),
	'interrupted' => $l->t('Interrupted'),
];
// A link from a section to its part of the guide, in German for an administrator who reads
// German, which names the section for screen readers.
$help = static function (string $anchor, string $section) use ($l): void {
	print_unescaped(sprintf(
		'<a class="paperless-sync-help" href="%s" target="_blank" rel="noreferrer noopener" aria-label="%s">%s</a>',
		Util::sanitizeHTML(AppConstants::guide($l->getLanguageCode(), $anchor)),
		Util::sanitizeHTML($l->t('Help on %s', [$section])),
		Util::sanitizeHTML($l->t('Help')),
	));
};
// A list of tags of Paperless, which js/settings.js shows as a chip for each name, with a field
// that offers the tags of Paperless and takes any name that is typed, also of a tag that Paperless
// doesn't have yet. The setting itself is the hidden input, with the names separated by commas, as
// the server stores them. $id starts the IDs of its elements, $hint tells how to fill it, and
// $more, such as a link to the guide, follows the hint.
$tagPicker = static function (string $setting, string $value, string $id, string $label, string $listLabel, string $hint, ?Closure $more = null) use ($l): void {
	?>
	<div class="paperless-sync-field paperless-sync-field--wide paperless-sync-tags" data-tag-picker="<?php p($setting); ?>">
		<label for="<?php p($id); ?>-input"><?php p($label); ?></label>
		<input data-setting="<?php p($setting); ?>" type="hidden" value="<?php p($value); ?>">
		<ul id="<?php p($id); ?>-list" class="paperless-sync-chips" aria-label="<?php p($listLabel); ?>" hidden></ul>
		<div class="paperless-sync-combobox">
			<input id="<?php p($id); ?>-input" type="text" placeholder="<?php p($l->t('Add a tag')); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php p($id); ?>-options" aria-describedby="<?php p($id); ?>-hint <?php p($id); ?>-status" autocomplete="off" spellcheck="false">
			<ul id="<?php p($id); ?>-options" class="paperless-sync-options" role="listbox" aria-label="<?php p($l->t('Tags of Paperless')); ?>" hidden></ul>
		</div>
		<p id="<?php p($id); ?>-hint" class="settings-hint"><?php p($hint); ?><?php if ($more !== null) { ?> <?php $more(); ?><?php } ?></p>
		<p id="<?php p($id); ?>-status" class="paperless-sync-field-hint" aria-live="polite"></p>
		<span id="<?php p($id); ?>-announcement" class="paperless-sync-visually-hidden" aria-live="polite"></span>
	</div>
	<?php
};
// A run waits for its background job or runs.
$active = $state === 'queued' || str_ends_with($state, 'running');
?>

<div
	id="paperless-sync-settings"
	class="section paperless-sync-settings"
	data-save-url="<?php p($_['saveUrl']); ?>"
	data-test-url="<?php p($_['testUrl']); ?>"
	data-reset-url="<?php p($_['resetUrl']); ?>"
	data-run-url="<?php p($_['runUrl']); ?>"
	data-status-url="<?php p($_['statusUrl']); ?>"
	data-preview-url="<?php p($_['previewUrl']); ?>"
	data-files-url="<?php p($_['filesUrl']); ?>"
	data-tags-url="<?php p($_['tagsUrl']); ?>"
	data-users-url="<?php p($_['usersUrl']); ?>"
	data-groups-url="<?php p($_['groupsUrl']); ?>"
	data-webhook-url="<?php p($_['webhookStateUrl']); ?>">
	<div class="paperless-sync-hero">
		<img src="<?php p(image_path('paperless_sync', 'app.svg')); ?>" alt="" width="64" height="64">
		<div>
			<h2><?php p($l->t('Paperless Sync')); ?></h2>
			<p><?php p($l->t('Keep Paperless as your authoritative archive and make finalized documents available through Nextcloud Files.')); ?></p>
		</div>
	</div>

	<?php foreach ($health as $result) { ?>
	<p class="paperless-sync-health paperless-sync-health--<?php p($result['severity']); ?>" role="<?php p($result['severity'] === 'error' ? 'alert' : 'status'); ?>">
		<strong><?php p($result['severity'] === 'error' ? $l->t('Error') : $l->t('Warning')); ?>:</strong>
		<?php p($result['description']); ?>
		<?php $help('being-notified', $l->t('Being notified')); ?>
	</p>
	<?php } ?>

	<div id="paperless-sync-status" class="paperless-sync-status">
		<div class="paperless-sync-status-card">
			<div>
				<span class="paperless-sync-status-label"><?php p($l->t('Synchronization status')); ?></span>
				<span class="paperless-sync-status-value">
					<span id="paperless-sync-running" class="paperless-sync-spinner icon-loading-small" <?php if (!$active) {
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
				<p><?php p($l->t('The target user owns the synchronized files, or each user those they own in Paperless. No Nextcloud password is required.')); ?> <?php $help('paperless-connection', $l->t('Connection and ownership')); ?></p>
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
				<div class="paperless-sync-field">
					<label for="paperless-sync-target-user"><?php p($l->t('Nextcloud target user ID')); ?></label>
					<div class="paperless-sync-combobox">
						<input id="paperless-sync-target-user" data-setting="target_user" type="text" value="<?php p($config->targetUser); ?>" placeholder="paperless-service" required role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="paperless-sync-target-user-options" aria-describedby="paperless-sync-target-user-hint" autocomplete="off" autocapitalize="none" spellcheck="false">
						<ul id="paperless-sync-target-user-options" class="paperless-sync-options" role="listbox" aria-label="<?php p($l->t('Users of Nextcloud')); ?>" hidden></ul>
					</div>
					<p id="paperless-sync-target-user-hint" class="paperless-sync-field-hint" aria-live="polite"></p>
				</div>
				<label class="paperless-sync-field paperless-sync-field--wide">
					<span><?php p($l->t('Base folder')); ?></span>
					<input data-setting="base_path" type="text" value="<?php p($config->basePath); ?>" required>
				</label>
			</div>
			<!-- Who gets the documents: the target user all of them, or each user of Nextcloud those of their user of Paperless. -->
			<div class="paperless-sync-grid">
				<label class="paperless-sync-field">
					<span><?php p($l->t('Who gets the documents')); ?></span>
					<select id="paperless-sync-ownership" data-setting="ownership" aria-describedby="paperless-sync-ownership-hint">
						<option value="single" <?php if (!$config->perUser()) {
							print_unescaped('selected');
						} ?>><?php p($l->t('The target user, all of them')); ?></option>
						<option value="per-user" <?php if ($config->perUser()) {
							print_unescaped('selected');
						} ?>><?php p($l->t('Each user, those they own in Paperless')); ?></option>
					</select>
				</label>
				<div class="paperless-sync-field" data-per-user<?php if (!$config->perUser()) { ?> hidden<?php } ?>>
					<label for="paperless-sync-user-group"><?php p($l->t('Only members of this group')); ?></label>
					<div class="paperless-sync-combobox">
						<input id="paperless-sync-user-group" data-setting="user_group" type="text" value="<?php p($config->userGroup); ?>" placeholder="<?php p($l->t('Every user')); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="paperless-sync-user-group-options" aria-describedby="paperless-sync-user-group-problem paperless-sync-ownership-hint" autocomplete="off" autocapitalize="none" spellcheck="false">
						<ul id="paperless-sync-user-group-options" class="paperless-sync-options" role="listbox" aria-label="<?php p($l->t('Groups of Nextcloud')); ?>" hidden></ul>
					</div>
					<p id="paperless-sync-user-group-problem" class="paperless-sync-field-hint" aria-live="polite"></p>
				</div>
				<label class="paperless-sync-field paperless-sync-field--wide" data-per-user<?php if (!$config->perUser()) { ?> hidden<?php } ?>>
					<span><?php p($l->t('Paperless users with another Nextcloud ID')); ?></span>
					<textarea data-setting="user_map" rows="3" placeholder="jdoe = john.doe" autocapitalize="none" spellcheck="false" aria-describedby="paperless-sync-user-map-hint"><?php p($config->userMap); ?></textarea>
					<span id="paperless-sync-user-map-hint" class="paperless-sync-field-hint"><?php p($l->t('One on each line: the user of Paperless, an equals sign and the user ID of Nextcloud. Without a line, a user of Paperless gets the user of Nextcloud with the same ID, whatever its case, as single sign-on gives them.')); ?></span>
				</label>
			</div>
			<p class="settings-hint" id="paperless-sync-ownership-hint"><?php p($l->t('With "Each user", every user of Nextcloud gets the documents that their user of Paperless owns, in the same folders below the base folder of their own files, and the files of their inbox become theirs in Paperless. The target user gets the documents without an owner and those whose owner has no user of Nextcloud, so that none is lost; the documents of two users of Paperless for one user of Nextcloud stay where they are until each has a user of its own, and the connection checklist shows who gets which. The account of the token needs to see the documents of every user.')); ?> <?php $help('per-user-ownership', $l->t('Per-user ownership')); ?></p>
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
						<button type="button" class="paperless-sync-code" data-variable="custom_fields.<?php p($l->t('Name')); ?>" data-select="<?php p($l->t('Name')); ?>" title="<?php p($l->t('Insert a custom field, then type its name')); ?>">custom_fields.…</button>
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
			<?php $tagPicker(
				'excluded_tags',
				$config->excludedTags,
				'paperless-sync-excluded-tags',
				$l->t('Additional excluded Paperless tags'),
				$l->t('Excluded tags'),
				$l->t('Pick a tag of Paperless, or type its name and press Enter; a tag that Paperless does not have yet works too.'),
			); ?>
			<?php $tagPicker(
				'included_tags',
				$config->includedTags,
				'paperless-sync-included-tags',
				$l->t('Only mirror documents with these tags'),
				$l->t('Tags to mirror'),
				$l->t('Without tags, the archive mirrors every document. With tags, it mirrors only the documents with at least one of them, and a run removes the copies of the others; an excluded tag still leaves a document out. Paperless must have every tag of the list, or the runs stop before they remove a copy.'),
				static fn () => $help('which-documents-the-archive-mirrors', $l->t('Which documents the archive mirrors')),
			); ?>
			<div class="paperless-sync-switches">
				<label><input data-setting="write_metadata" type="checkbox" aria-describedby="paperless-sync-write-metadata-hint" <?php if ($config->writeMetadata) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Write metadata next to the archive')); ?></span></label>
			</div>
			<p id="paperless-sync-write-metadata-hint" class="settings-hint"><?php p($l->t('A JSON file for each document in the hidden folder .paperless of the archive, with its title, correspondent, tags, notes, custom fields and dates, and an index of every document in index.csv, so that the archive describes itself also without Paperless.')); ?> <?php p($l->t('Everyone who can open the archive folder, also through a share or a public link, can read them, the notes and custom fields as well.')); ?> <?php $help('metadata-next-to-the-archive', $l->t('Metadata next to the archive')); ?></p>
			<div class="paperless-sync-switches">
				<label><input data-setting="nextcloud_tags" type="checkbox" aria-describedby="paperless-sync-nextcloud-tags-hint" <?php if ($config->nextcloudTags) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Show Paperless tags as Nextcloud tags')); ?></span></label>
			</div>
			<p id="paperless-sync-nextcloud-tags-hint" class="settings-hint"><?php p($l->t('Each copy gets the tags of its document as collaborative tags, which every user sees and only administrators assign, so that Files filters and searches the archive by them; they follow every change in Paperless.')); ?> <?php p($l->t('A tag that someone made in Nextcloud stays as it is, also one with the name of a tag of Paperless.')); ?> <?php $help('tags-in-nextcloud', $l->t('Tags in Nextcloud')); ?></p>
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
				<label><input data-setting="inbox_folder_tags" type="checkbox" <?php if ($config->inboxFolderTags) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Subfolders become tags')); ?></span></label>
				<label><input data-setting="inbox_create_tags" type="checkbox" <?php if ($config->inboxCreateTags) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Create missing tags')); ?></span></label>
			</div>
			<?php $tagPicker(
				'inbox_tags',
				$config->inboxTags,
				'paperless-sync-inbox-tags',
				$l->t('Tags for every import'),
				$l->t('Tags for every import'),
				$l->t('Pick tags of Paperless, or type a name and press Enter.') . ' ' . $l->t('With "Subfolders become tags", a file in the subfolder Taxes/2026 of the inbox gets the tags Taxes and 2026, found by their names whatever their case. A run reports the names that Paperless has no tag of, or creates their tags when it may. Paperless names each document after its file.'),
				static fn () => $help('tags-of-the-inbox', $l->t('Tags of the inbox')),
			); ?>
			<div class="paperless-sync-grid">
				<div class="paperless-sync-field">
					<label for="paperless-sync-send-group"><?php p($l->t('Group that may send files')); ?></label>
					<div class="paperless-sync-combobox">
						<input id="paperless-sync-send-group" data-setting="send_group" type="text" value="<?php p($config->sendGroup); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="paperless-sync-send-group-options" aria-describedby="paperless-sync-send-group-problem paperless-sync-send-group-hint" autocomplete="off" autocapitalize="none" spellcheck="false">
						<ul id="paperless-sync-send-group-options" class="paperless-sync-options" role="listbox" aria-label="<?php p($l->t('Groups of Nextcloud')); ?>" hidden></ul>
					</div>
					<p id="paperless-sync-send-group-problem" class="paperless-sync-field-hint" aria-live="polite"></p>
				</div>
			</div>
			<p class="settings-hint" id="paperless-sync-send-group-hint"><?php p($l->t('Its members find "Send to Paperless" in the menu of a file or a folder in Files. A file goes to Paperless with the tags above, those of its subfolders below a sent folder included, and stays where it is; a notification tells the sender when Paperless has it. The uploads use the API token of the app, so choose people whom you trust with its account of Paperless, or leave the field empty to turn the action off. "Open in Paperless" opens the document of a copy of the archive for everyone.')); ?> <?php $help('send-from-files', $l->t('Send from Files')); ?></p>
		</section>

		<section class="paperless-sync-card paperless-sync-card--danger">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">5</span><h3><?php p($l->t('Trash and deletion safety')); ?></h3></div>
				<p><?php p($l->t('Deleting the copies of documents that Paperless deleted for good is off by default. Turn it on only after a few runs without it looked right. A copy that a run deletes goes to the trash bin of its user in Nextcloud.')); ?> <?php $help('deletion-safety', $l->t('Trash and deletion safety')); ?></p>
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
				} ?>> <span><?php p($l->t('Delete the Nextcloud copy after Paperless deletes the document for good')); ?></span></label>
				<label><input data-setting="allow_direct_delete" type="checkbox" <?php if ($config->allowDirectDelete) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Also delete copies of documents that disappeared without passing the Paperless trash')); ?></span></label>
				<label><input data-setting="prune_empty_folders" type="checkbox" <?php if ($config->pruneEmptyFolders) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Remove empty folders after moves and deletions')); ?></span></label>
			</div>
		</section>

		<section class="paperless-sync-card" aria-labelledby="paperless-sync-webhook-title">
			<div class="paperless-sync-card-heading">
				<div><span class="paperless-sync-step">6</span><h3 id="paperless-sync-webhook-title"><?php p($l->t('Changes within seconds')); ?></h3></div>
				<p><?php p($l->t('A workflow of Paperless calls this webhook when it adds or changes a document, and the copy of the document changes within seconds instead of with the next scheduled run. Trashed and deleted documents wait for the scheduled runs.')); ?> <?php $help('changes-within-seconds', $l->t('Changes within seconds')); ?></p>
			</div>
			<div class="paperless-sync-grid">
				<div class="paperless-sync-field paperless-sync-field--wide">
					<label for="paperless-sync-webhook-address"><?php p($l->t('Webhook URL')); ?></label>
					<div class="paperless-sync-copy">
						<input id="paperless-sync-webhook-address" class="paperless-sync-code" type="text" value="<?php p($webhook['url']); ?>" readonly spellcheck="false">
						<button id="paperless-sync-webhook-copy-address" type="button" data-copy="paperless-sync-webhook-address"><?php p($l->t('Copy')); ?></button>
					</div>
				</div>
				<div id="paperless-sync-webhook-secret-field" class="paperless-sync-field paperless-sync-field--wide" hidden>
					<label for="paperless-sync-webhook-secret"><?php p($l->t('Secret')); ?></label>
					<div class="paperless-sync-copy">
						<input id="paperless-sync-webhook-secret" class="paperless-sync-code" type="text" readonly spellcheck="false" autocomplete="off" aria-describedby="paperless-sync-webhook-secret-hint">
						<button id="paperless-sync-webhook-copy-secret" type="button" data-copy="paperless-sync-webhook-secret"><?php p($l->t('Copy')); ?></button>
					</div>
					<p id="paperless-sync-webhook-secret-hint" class="settings-hint"><?php p($l->t('Copy the secret now: it is shown only this once. In Paperless, it goes into the header Authorization of the webhook, as Bearer followed by a space and the secret.')); ?></p>
				</div>
			</div>
			<div class="paperless-sync-webhook-actions">
				<button id="paperless-sync-webhook-create" type="button"><?php p($webhook['configured'] ? $l->t('Create a new secret') : $l->t('Create secret')); ?></button>
				<button id="paperless-sync-webhook-remove" type="button" <?php if (!$webhook['configured']) {
					print_unescaped('hidden');
				} ?>><?php p($l->t('Turn the webhook off')); ?></button>
				<p id="paperless-sync-webhook-state" class="settings-hint" aria-live="polite"><?php p($webhook['configured'] ? $l->t('The webhook accepts calls with its secret.') : $l->t('The webhook is off: it refuses every call until you create its secret.')); ?></p>
			</div>
			<p id="paperless-sync-webhook-last-call" class="settings-hint" hidden></p>
			<div class="paperless-sync-switches">
				<label><input data-setting="webhook_immediate" type="checkbox" aria-describedby="paperless-sync-webhook-immediate-hint" <?php if ($config->webhookImmediate) {
					print_unescaped('checked');
				} ?>> <span><?php p($l->t('Mirror the document during the call')); ?></span></label>
			</div>
			<p id="paperless-sync-webhook-immediate-hint" class="settings-hint"><?php p($l->t('Off, the document waits for the next background jobs, which system cron starts every 5 minutes. On, it waits only while another run is active.')); ?></p>
			<details class="paperless-sync-webhook-guide">
				<summary><?php p($l->t('Set up the workflow in Paperless')); ?></summary>
				<ol>
					<li><?php p($l->t('In Paperless, open Workflows and add a workflow, with the triggers Document Added and Document Updated.')); ?></li>
					<li><?php p($l->t('Add the action Webhook, with the webhook URL above as its Webhook url.')); ?></li>
					<li><?php p($l->t('Turn on "Use parameters for webhook body" and "Send webhook payload as JSON", and add the parameter document with the value {{ doc_id }}. Paperless-ngx 2.x has no {{ doc_id }}: use {{ doc_url }} there.')); ?></li>
					<li><?php p($l->t('Under Webhook headers, add Authorization with the value Bearer, a space and the secret, and save the workflow.')); ?></li>
					<li><?php p($l->t('Change the title of a document in Paperless: its copy moves within seconds, and the last call appears here.')); ?></li>
				</ol>
			</details>
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
		<div class="paperless-sync-report-heading">
			<h3 id="paperless-sync-report-title"><?php p($l->t('Run report')); ?></h3>
			<button id="paperless-sync-report-copy" type="button"><?php p($l->t('Copy report')); ?></button>
		</div>
		<p id="paperless-sync-report-meta" class="paperless-sync-report-meta"></p>
		<div id="paperless-sync-report-summary" class="paperless-sync-report-summary"></div>
		<p id="paperless-sync-report-rest" class="paperless-sync-report-rest" hidden></p>
		<div id="paperless-sync-report-users" class="paperless-sync-report-users" hidden>
			<h4 id="paperless-sync-report-users-title"><?php p($l->t('By user')); ?></h4>
			<ul id="paperless-sync-report-users-list" aria-labelledby="paperless-sync-report-users-title"></ul>
		</div>
		<div id="paperless-sync-report-filters" class="paperless-sync-report-filters" role="group" aria-label="<?php p($l->t('Show changes of one kind')); ?>" hidden></div>
		<div id="paperless-sync-report-actions" class="paperless-sync-report-list" tabindex="0" role="region" aria-label="<?php p($l->t('File changes of the run')); ?>">
			<ul id="paperless-sync-report-entries"></ul>
		</div>
		<p id="paperless-sync-report-omitted" class="paperless-sync-report-omitted" hidden></p>
	</div>
</div>

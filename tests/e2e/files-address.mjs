// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The address of Files, at which files.mjs and screenshots.mjs open it, as Nextcloud links it
// itself: OC.generateUrl('/apps/files') of a page of Nextcloud, without /index.php when the
// pretty URLs of Nextcloud are on, as in the image of Docker, and with it otherwise. Files of
// Nextcloud 36, whose router is that of Vue 3, routes only the address under that base: at
// /index.php/apps/files of a Nextcloud with pretty URLs, it shows its navigation but no list of
// files, which Files of Nextcloud 35 and before still showed.

// The absolute address of the app Files of the Nextcloud that the page shows, a page of
// Nextcloud of a user who signed in.
export function filesAddress(page) {
	return page.evaluate(() => new URL(window.OC.generateUrl('/apps/files'), window.location.href).href)
}

// The address of a folder, such as /Documents, in the view "All files" of Files, as Files
// writes it into the address bar.
export function folderAddress(files, dir) {
	return `${files.replace(/\/+$/, '')}/files?dir=${encodeURIComponent(dir)}`
}

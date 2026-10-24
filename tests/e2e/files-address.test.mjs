// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The address of Files in the browser tests, which scripts/check-project.sh runs with
// node --test, so that a script that opens Files at an address of its own fails here and not
// only in the canary against the coming Nextcloud, whose Files routes no other address.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { afterEach, test } from 'node:test'
import { filesAddress, folderAddress } from './files-address.mjs'

// A page of Nextcloud, whose OC.generateUrl() leaves out /index.php when the pretty URLs are on.
function nextcloudPage(href, prettyUrls, webroot = '') {
	globalThis.window = {
		location: { href },
		OC: {
			generateUrl: (path) => `${webroot}${prettyUrls ? '' : '/index.php'}${path}`,
		},
	}
	return { evaluate: (code) => Promise.resolve(code()) }
}

afterEach(() => {
	delete globalThis.window
})

test('Files is where Nextcloud links it: without /index.php when the pretty URLs are on', async () => {
	assert.equal(await filesAddress(nextcloudPage('http://nextcloud/apps/dashboard/', true)), 'http://nextcloud/apps/files')
})

test('Files keeps /index.php without the pretty URLs, and the webroot of a Nextcloud in a folder', async () => {
	assert.equal(await filesAddress(nextcloudPage('http://nextcloud/index.php/apps/dashboard/', false)), 'http://nextcloud/index.php/apps/files')
	assert.equal(await filesAddress(nextcloudPage('https://cloud.example/nc/apps/dashboard/', true, '/nc')), 'https://cloud.example/nc/apps/files')
})

test('a folder opens in the view "All files", with its path in the query', () => {
	assert.equal(folderAddress('http://nextcloud/apps/files', '/'), 'http://nextcloud/apps/files/files?dir=%2F')
	assert.equal(folderAddress('http://nextcloud/apps/files/', '/Documents/Paperless/Archive'), 'http://nextcloud/apps/files/files?dir=%2FDocuments%2FPaperless%2FArchive')
	assert.equal(folderAddress('http://nextcloud/index.php/apps/files', '/Scans & more/[P1]'), 'http://nextcloud/index.php/apps/files/files?dir=%2FScans%20%26%20more%2F%5BP1%5D')
})

test('no browser test opens Files at an address of its own, which Files of Nextcloud 36 does not route', () => {
	const directory = new URL('.', import.meta.url)
	const scripts = readdirSync(directory).filter((name) => name.endsWith('.mjs') && !name.endsWith('.test.mjs') && name !== 'files-address.mjs')
	assert.ok(scripts.includes('files.mjs') && scripts.includes('screenshots.mjs'), scripts.join(', '))
	for (const name of scripts) {
		const lines = readFileSync(new URL(name, directory), 'utf8').split('\n')
		lines.forEach((line, index) => {
			assert.doesNotMatch(line, /(index\.php|BASE_URL\})\/apps\/files/, `${name}:${index + 1} opens Files at an address of its own; take it from filesAddress() of files-address.mjs: ${line.trim()}`)
		})
	}
})

// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The report of the visual check, which scripts/check-project.sh runs with node --test: the
// changed pixels in red, the share and the table of the pictures that look different, and
// the paths on which the visual check of a pull request renders, which are those of the
// screenshot bot.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { difference, PICTURES, report, shownShare, summary } from './compare.mjs'

const ROOT = new URL('../../', import.meta.url)

// A picture of pixels in a row, each [red, green, blue, alpha].
function picture(...pixels) {
	return { width: pixels.length, height: 1, data: Buffer.from(pixels.flat()) }
}

test('a changed pixel turns red, the others pale gray on white', () => {
	const before = picture([0, 0, 0, 255], [10, 20, 30, 255], [0, 0, 0, 0], [255, 255, 255, 255])
	const after = picture([0, 0, 0, 255], [10, 20, 31, 255], [9, 9, 9, 0], [255, 255, 255, 128])
	const { image, changed } = difference(before, after)
	assert.equal(changed, 2)
	assert.deepEqual([...image.data], [
		// Black stays, pale: a fifth of its darkness.
		204, 204, 204, 255,
		230, 0, 0, 255,
		// Transparent in both, whatever its color: the same, and white.
		255, 255, 255, 255,
		// Only the alpha channel changed.
		230, 0, 0, 255,
	])
	assert.equal(image.width, 4)
	assert.equal(image.height, 1)
})

test('pictures of different sizes have no difference', () => {
	assert.throws(() => difference(picture([0, 0, 0, 255]), picture([0, 0, 0, 255], [0, 0, 0, 255])), /same size/)
	assert.throws(() => difference(picture([0, 0, 0, 255]), { width: 1, height: 2, data: Buffer.alloc(8) }), /same size/)
})

test('the share of the changed pixels reads as in the pull request of the screenshot bot', () => {
	assert.equal(shownShare(null), 'another size or frames')
	assert.equal(shownShare(1), '100 %')
	assert.equal(shownShare(0.00009), '< 0.01 %')
	assert.equal(shownShare(0.0123), '1.23 %')
})

test('the report of a language lists its pictures that look different, and counts the others', () => {
	const tracked = [
		'docs/images/settings-light.png',
		'docs/images/dry-run.gif',
		'docs/images/app.svg',
		'docs/images/diagrams/how-paperless-sync-moves-documents.svg',
		'docs/images/de/settings-light.png',
		'docs/images/de/report-dark.png',
		'screenshots/01-admin-settings.png',
		'screenshots/01-admin-settings-small.png',
	]
	const kept = {
		pictures: [
			{ path: 'docs/images/de/report-dark.png', status: 'changed', share: 0.25 },
			{ path: 'docs/images/new-picture.png', status: 'new', share: null },
			{ path: 'docs/images/settings-light.png', status: 'changed', share: 0.5 },
			{ path: 'screenshots/01-admin-settings-small.png', status: 'deleted' },
			{ path: '.github/social-preview.png', status: 'changed', share: 0.1 },
		],
		ignored: [],
	}
	assert.deepEqual(report('en', kept, tracked), {
		language: 'en',
		images: [
			{ path: 'docs/images/new-picture.png', status: 'new', share: null },
			{ path: 'docs/images/settings-light.png', status: 'changed', share: 0.5 },
			{ path: 'screenshots/01-admin-settings-small.png', status: 'deleted', share: null },
		],
		unchanged: 2,
	})
	assert.deepEqual(report('de', kept, tracked), {
		language: 'de',
		images: [{ path: 'docs/images/de/report-dark.png', status: 'changed', share: 0.25 }],
		unchanged: 1,
	})
	// Without report.json, no picture changed.
	assert.deepEqual(report('de', null, tracked), { language: 'de', images: [], unchanged: 2 })
	assert.throws(() => report('fr', null, tracked), /English \(en\) or German \(de\)/)
})

test('the summary is a table of the pictures that look different, or says that none does', () => {
	assert.equal(summary('de', { images: [], unchanged: 36 }), 'Every German picture looks as committed (36 pictures).\n')
	assert.equal(summary('en', {
		images: [
			{ path: 'docs/images/report-light.png', status: 'changed', share: 0.0123 },
			{ path: 'docs/images/new-picture.png', status: 'new', share: null },
		],
		unchanged: 40,
	}), [
		'2 English pictures look different; 40 look as committed.',
		'',
		'| Picture | Change | Pixels changed |',
		'| --- | --- | ---: |',
		'| `docs/images/report-light.png` | changed | 1.23 % |',
		'| `docs/images/new-picture.png` | new |  |',
		'',
		'The artifact of the run has each one before and after, and the changed pixels in red.',
		'',
	].join('\n'))
})

test('the pictures of each language are those that screenshots.sh renders', () => {
	assert.ok(PICTURES.en.test('docs/images/archive.gif'))
	assert.ok(PICTURES.en.test('screenshots/03-dry-run-report-small.png'))
	assert.ok(!PICTURES.en.test('docs/images/de/archive.gif'))
	assert.ok(!PICTURES.en.test('docs/images/app.svg'))
	assert.ok(PICTURES.de.test('docs/images/de/archive.gif'))
	assert.ok(!PICTURES.de.test('screenshots/01-admin-settings.png'))
})

// The paths of a trigger of a workflow, one per line below "paths:".
function workflowPaths(text) {
	const block = text.match(/^ {4}paths:\n((?: {6}- .+\n)+)/m)
	assert.ok(block, 'the workflow has no paths')
	return block[1].trim().split('\n').map((line) => line.replace(/^\s*- /, '').replace(/^"(.*)"$/, '$1'))
}

test('the visual check renders on the changes on which the screenshot bot renders, but the social preview', () => {
	const listed = readFileSync(new URL('.github/screenshots-paths.txt', ROOT), 'utf8')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '' && !line.startsWith('#'))
	const visual = workflowPaths(readFileSync(new URL('.github/workflows/visual.yml', ROOT), 'utf8'))
	// The visual check leaves out the social preview, which no page of the app shows.
	const pictures = listed.filter((path) => !/social-preview/.test(path))
	assert.deepEqual(pictures.filter((path) => !visual.includes(path)), [], 'the visual check misses these paths of .github/screenshots-paths.txt')
	const own = ['.github/workflows/visual.yml', '.github/screenshots-requirements.txt', 'tests/e2e/compare.mjs']
	assert.deepEqual(visual.filter((path) => !pictures.includes(path) && !own.includes(path)), [], 'the visual check renders on paths that the screenshot bot ignores')
})

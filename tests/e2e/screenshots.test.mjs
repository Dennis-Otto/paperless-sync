// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The animations of the documentation, which scripts/check-project.sh runs with node --test:
// each that screenshots.mjs records is there in both languages and both themes, since the
// READMEs and the website name all four, and each stays small enough for a page on a phone.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { test } from 'node:test'

const IMAGES = new URL('../../docs/images/', import.meta.url)
const LIMIT = 1_000_000
const FOLDERS = ['', 'de/']

const script = readFileSync(new URL('screenshots.mjs', import.meta.url), 'utf8')
const recorded = [...script.matchAll(/saveAnimation\('([^']+)'/g)].map(([, name]) => name)
const gifsIn = (folder) => readdirSync(new URL(folder, IMAGES)).filter((name) => name.endsWith('.gif'))

test('the script records the five animations of the documentation', () => {
	assert.deepEqual(recorded.toSorted(), ['archive', 'dry-run', 'inbox', 'move', 'trash'])
})

test('every animation is there in both languages and both themes, and no other is', () => {
	const expected = recorded.flatMap((name) => [`${name}.gif`, `${name}-dark.gif`]).toSorted()
	for (const folder of FOLDERS) {
		assert.deepEqual(gifsIn(folder).toSorted(), expected, `docs/images/${folder} holds other animations than screenshots.mjs records; run bash tests/e2e/screenshots.sh.`)
	}
})

test('every animation stays below 1 MB', () => {
	for (const folder of FOLDERS) {
		for (const name of gifsIn(folder)) {
			const { size } = statSync(new URL(`${folder}${name}`, IMAGES))
			assert.ok(size < LIMIT, `docs/images/${folder}${name} has ${size} bytes; show less of a scroll or a whole page that changes in tests/e2e/screenshots.mjs.`)
		}
	}
})

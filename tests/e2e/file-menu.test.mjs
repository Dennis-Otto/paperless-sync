// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The menu of the actions of a file in the checks in the browser, which scripts/check-project.sh
// runs with node --test: a click that Files of Nextcloud 36 drops right after Escape closed a
// menu fails the check no more (#515), a menu that never opens still does, and no check but
// screenshots.mjs waits for an item of a menu on its own.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { ATTEMPTS, LAST, SETTLE, closeMenu, openMenu } from './file-menu.mjs'

class TimeoutError extends Error {
	name = 'TimeoutError'
}

// Files with a menu that the first `dropped` clicks don't open, as Nextcloud 36 at times does
// right after Escape, and that a click on its button closes when it is open.
// With `late`, a click opens the menu only after the wait for it ran out, as on a slow runner.
function files({ dropped = 0, error, late = false } = {}) {
	const state = { open: false, clicks: 0, escapes: 0, waits: [], pending: false }
	const item = {
		async isVisible() {
			if (state.pending) {
				state.pending = false
				state.open = true
			}
			return state.open
		},
		async waitFor({ timeout, state: wanted = 'visible' } = {}) {
			state.waits.push({ timeout, wanted })
			if (error) {
				throw error
			}
			if (state.open !== (wanted === 'visible')) {
				throw new TimeoutError(`locator.waitFor: Timeout ${timeout}ms exceeded.`)
			}
		},
	}
	const page = {
		getByRole: (role) => {
			assert.equal(role, 'menuitem')
			return { first: () => item }
		},
		keyboard: {
			async press(key) {
				assert.equal(key, 'Escape')
				state.escapes++
				state.open = false
			},
		},
	}
	const click = async () => {
		state.clicks++
		if (state.clicks > dropped) {
			if (late && !state.open) {
				state.pending = true
			} else {
				state.open = !state.open
			}
		}
	}
	return { page, click, state }
}

test('a click that opens the menu is made once, and the menu stays open', async () => {
	const { page, click, state } = files()
	await openMenu(page, click)
	assert.equal(state.clicks, 1)
	assert.equal(state.open, true)
	assert.deepEqual(state.waits, [{ timeout: SETTLE, wanted: 'visible' }])
})

test('a click that Files drops after Escape is made again, as on Nextcloud 36', async () => {
	const { page, click, state } = files({ dropped: 1 })
	await openMenu(page, click)
	assert.equal(state.clicks, 2)
	assert.equal(state.open, true)
})

test('the last click has the full time, and a menu that never opens fails the check', async () => {
	const { page, click, state } = files({ dropped: Infinity })
	await assert.rejects(openMenu(page, click), { name: 'TimeoutError' })
	assert.equal(state.clicks, ATTEMPTS)
	assert.deepEqual(state.waits.map(({ timeout }) => timeout), [...Array(ATTEMPTS - 1).fill(SETTLE), LAST])
})

test('a menu that opens after its wait ran out is not clicked shut again', async () => {
	const { page, click, state } = files({ late: true })
	await openMenu(page, click)
	assert.equal(state.clicks, 1)
	assert.equal(state.open, true)
})

test('a menu that opens late after the next to last click is not clicked shut either', async () => {
	const { page, click, state } = files({ dropped: ATTEMPTS - 2, late: true })
	await openMenu(page, click)
	assert.equal(state.clicks, ATTEMPTS - 1)
	assert.equal(state.open, true)
})

test('the menu opens on the last of the clicks', async () => {
	const { page, click, state } = files({ dropped: ATTEMPTS - 1 })
	await openMenu(page, click)
	assert.equal(state.clicks, ATTEMPTS)
	assert.equal(state.open, true)
})

test('an error other than a timeout ends it at once', async () => {
	const closed = new Error('Target page, context or browser has been closed')
	const { page, click, state } = files({ error: closed })
	await assert.rejects(openMenu(page, click), closed)
	assert.equal(state.clicks, 1)
})

test('the numbers of the tries can be given', async () => {
	const { page, click, state } = files({ dropped: Infinity })
	await assert.rejects(openMenu(page, click, { attempts: 1, last: 10 }), { name: 'TimeoutError' })
	assert.equal(state.clicks, 1)
	assert.deepEqual(state.waits, [{ timeout: 10, wanted: 'visible' }])
})

test('Escape closes the menu, and closeMenu() waits until its items are gone', async () => {
	const { page, click, state } = files()
	await openMenu(page, click)
	await closeMenu(page)
	assert.equal(state.escapes, 1)
	assert.equal(state.open, false)
	assert.deepEqual(state.waits.at(-1), { timeout: undefined, wanted: 'detached' })
})

// screenshots.mjs opens each menu once, on a page of its own and never right after Escape, which
// is where Files drops the click, and it needs the item that it names for its picture.
const EXEMPT = new Set(['file-menu.mjs', 'screenshots.mjs'])

test('no check in the browser waits for an item of a menu on its own', () => {
	const folder = new URL('.', import.meta.url)
	const scripts = readdirSync(folder).filter((name) => name.endsWith('.mjs') && !name.endsWith('.test.mjs') && !EXEMPT.has(name))
	const own = scripts.filter((name) => /getByRole\('menuitem'[^;\n]*\.waitFor\(/.test(readFileSync(new URL(name, folder), 'utf8')))
	assert.deepEqual(own, [], 'Wait for the menu of a file with openMenu() and closeMenu() of tests/e2e/file-menu.mjs.')
	for (const name of ['accessibility.mjs', 'files.mjs']) {
		assert.match(readFileSync(new URL(name, folder), 'utf8'), /from '\.\/file-menu\.mjs'/, `${name} opens the menu of a file with tests/e2e/file-menu.mjs.`)
	}
})

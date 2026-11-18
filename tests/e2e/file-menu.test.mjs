// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The menu of the actions of a file in the checks in the browser, which scripts/check-project.sh
// runs with node --test: a menu counts as open only when Files has finished opening it and its
// button still says so, a menu that Files closes by itself, as Nextcloud 36 does when the menu
// of a file opens again on the same page, fails at once and says why (#515, #540), a menu that
// never opens still fails, accessibility.mjs opens every menu of a file on the page loaded
// again, and no check but screenshots.mjs waits for an item of a menu on its own.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { TIMEOUT, closeMenu, openMenu } from './file-menu.mjs'

class TimeoutError extends Error {
	name = 'TimeoutError'
}

// Files with the menu of a file that a click opens. With `closesItself`, Files closes it again
// when it has finished opening, as Nextcloud 36 does when the menu opened on the same page
// before; with `opens: false`, the click opens nothing. `steps` records what openMenu() does, in
// its order.
function files({ closesItself = false, opens = true, error } = {}) {
	const state = { open: false, steps: [] }
	const item = {
		async waitFor({ timeout, state: wanted = 'visible' } = {}) {
			state.steps.push(`wait ${wanted} ${timeout}`)
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
		// settled(), which waits for the animations of the page, at whose end Files closes it.
		async evaluate(waiting) {
			assert.equal(typeof waiting, 'function')
			state.steps.push('settled')
			if (closesItself) {
				state.open = false
			}
		},
		locator: (selector) => {
			// Only the button in the row of a file counts, not another open menu of the page.
			assert.equal(selector, '[data-cy-files-list-row-actions] [aria-haspopup="menu"][aria-expanded="true"]')
			return {
				async count() {
					state.steps.push('expanded?')
					return state.open ? 1 : 0
				},
			}
		},
		keyboard: {
			async press(key) {
				assert.equal(key, 'Escape')
				state.steps.push('Escape')
				state.open = false
			},
		},
	}
	const click = async () => {
		state.steps.push('click')
		state.open = opens
	}
	return { page, click, state }
}

test('a click opens the menu, which counts as open once Files has finished opening it', async () => {
	const { page, click, state } = files()
	await openMenu(page, click)
	assert.equal(state.open, true)
	assert.deepEqual(state.steps, ['click', `wait visible ${TIMEOUT}`, 'settled', 'expanded?'])
})

test('a menu that Files closes by itself when it has opened fails at once and says why, as on Nextcloud 36', async () => {
	const { page, click, state } = files({ closesItself: true })
	await assert.rejects(openMenu(page, click), /Files closed the menu of the file by itself .* open it on a page loaded since/)
	assert.deepEqual(state.steps, ['click', `wait visible ${TIMEOUT}`, 'settled', 'expanded?'])
})

test('a menu that never opens fails with the timeout of its wait', async () => {
	const { page, click, state } = files({ opens: false })
	await assert.rejects(openMenu(page, click), { name: 'TimeoutError' })
	assert.deepEqual(state.steps, ['click', `wait visible ${TIMEOUT}`])
})

test('an error of the wait ends it at once', async () => {
	const closed = new Error('Target page, context or browser has been closed')
	const { page, click, state } = files({ error: closed })
	await assert.rejects(openMenu(page, click), closed)
	assert.deepEqual(state.steps, ['click', `wait visible ${TIMEOUT}`])
})

test('the time of the wait can be given', async () => {
	const { page, click, state } = files({ opens: false })
	await assert.rejects(openMenu(page, click, { timeout: 10 }), { name: 'TimeoutError' })
	assert.deepEqual(state.steps, ['click', 'wait visible 10'])
})

test('Escape closes the menu, and closeMenu() waits until its items are gone', async () => {
	const { page, click, state } = files()
	await openMenu(page, click)
	await closeMenu(page)
	assert.equal(state.open, false)
	assert.deepEqual(state.steps.slice(-2), ['Escape', 'wait detached undefined'])
})

// screenshots.mjs opens each menu once, on a page of its own, so never where Files of Nextcloud
// 36 closes it by itself, and it needs the item that it names for its picture.
const EXEMPT = new Set(['file-menu.mjs', 'screenshots.mjs'])
const folder = new URL('.', import.meta.url)

test('no check in the browser waits for an item of a menu on its own', () => {
	const scripts = readdirSync(folder).filter((name) => name.endsWith('.mjs') && !name.endsWith('.test.mjs') && !EXEMPT.has(name))
	const own = scripts.filter((name) => /getByRole\('menuitem'[^;\n]*\.waitFor\(/.test(readFileSync(new URL(name, folder), 'utf8')))
	assert.deepEqual(own, [], 'Wait for the menu of a file with openMenu() and closeMenu() of tests/e2e/file-menu.mjs.')
	for (const name of ['accessibility.mjs', 'files.mjs']) {
		assert.match(readFileSync(new URL(name, folder), 'utf8'), /from '\.\/file-menu\.mjs'/, `${name} opens the menu of a file with tests/e2e/file-menu.mjs.`)
	}
})

// accessibility.mjs opens the menus of Invoice [P123].pdf and receipt.pdf for each width and for
// each message, on one page, so each of them on the page loaded again.
test('accessibility.mjs loads the page again before it opens the menu of a file', () => {
	const source = readFileSync(new URL('accessibility.mjs', folder), 'utf8')
	const helper = source.match(/^async function openMenu\(page, name\) \{\n(?<body>(?:\t.*\n)+)\}$/m)
	assert.ok(helper, 'accessibility.mjs opens the menu of a file with its function openMenu(page, name).')
	assert.match(helper.groups.body, /^\tawait page\.reload\(\)\n/, 'openMenu() of accessibility.mjs loads the page again first, as Files of Nextcloud 36 closes the menu of a file by itself when it opens again on the same page.')
	assert.equal(source.match(/openFileMenu\(/g).length, 1, 'accessibility.mjs opens the menu of a file only in its openMenu(page, name).')
})

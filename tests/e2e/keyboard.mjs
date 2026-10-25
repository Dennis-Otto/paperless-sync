// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The administration settings by keyboard alone, as a person uses them who uses no mouse, or a
// switch or a screen reader that acts as a keyboard:
//
// - From the top of the settings, Tab reaches every control, in a window of a computer, 1280
//   CSS pixels wide, and of a phone, 320 pixels wide, in the order in which the page shows them:
//   from the top to the bottom and on a line from the left to the right (WCAG 2.1.1, 2.4.3). No
//   control that has the focus is hidden behind another, such as behind the buttons that stick
//   to the bottom of the window (2.4.11 of WCAG 2.2).
// - Each control shows that it has the focus, in the light and in the dark theme: around it,
//   the look changes by a contrast of at least 3:1, on as many pixels as a line of one pixel
//   around it has (2.4.7, 1.4.11).
// - The pickers of a user and of tags work with the arrow keys, Enter, Escape and Backspace.
// - Escape cancels the question before Disconnect, and closes Nextcloud's dialog for the
//   password, after which the focus is back on the page, at the button or at the message that
//   says what happened.
//
// a11y.mjs judges, and the check tries it on a seeded page first. run.sh starts it in the image
// of Playwright after accessibility.mjs; it saves nothing.

import { chromium } from 'playwright-core'
import { PNG } from 'pngjs'
import { NARROW, WIDE, breaksOrder, focusIndicator } from './a11y.mjs'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const SETTINGS = '/index.php/settings/admin/paperless_sync'
const SCOPE = '#paperless-sync-settings'
// A place at the top of the settings without a control, where a click puts the start of Tab.
const START = '.paperless-sync-hero h2'
// The room around a control in the pictures of its focus indicator, for an outline outside it.
const ROOM = 6
const NOT_CONFIRMED = { message: 'Password confirmation is required' }

const problems = []

function expect(condition, message) {
	if (!condition) {
		throw new Error(message)
	}
}

// Runs in the page: the controls of the scope that Tab must reach, in the order of the page, which
// the page keeps for focused(): what Tab can focus and is visible and enabled; of a group of
// radio buttons, the checked one. Gives their names. It keeps the look of each as well: two
// controls of the same kind, with the same colors and borders on the same background and
// inside the same box that may cut off an outline, show their focus alike.
function listControls(selector) {
	const root = document.querySelector(selector)
	const name = (element) => {
		const label = element.getAttribute('aria-label') || element.labels?.[0]?.textContent || element.textContent || element.getAttribute('placeholder') || ''
		return `${element.tagName.toLowerCase()}${element.id ? `#${element.id}` : ''} "${label.trim().replace(/\s+/g, ' ').slice(0, 40)}"`
	}
	const controls = [...root.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex]')].filter((element) => {
		if (element.tabIndex < 0 || element.disabled || element.type === 'hidden' || element.closest('[inert]') !== null) {
			return false
		}
		if (element.tagName === 'SUMMARY' && element.parentElement.querySelector(':scope > summary') !== element) {
			return false
		}
		if (element.type === 'radio' && !element.checked && element.form?.querySelector(`input[type="radio"][name="${CSS.escape(element.name)}"]:checked`)) {
			return false
		}
		return element.checkVisibility({ visibilityProperty: true })
	})
	const look = (element) => {
		const style = getComputedStyle(element)
		let backdrop = ''
		let clip = ''
		for (let node = element.parentElement; node !== null; node = node.parentElement) {
			const outer = getComputedStyle(node)
			if (backdrop === '' && outer.backgroundColor !== 'rgba(0, 0, 0, 0)') {
				backdrop = outer.backgroundColor
			}
			if (clip === '' && outer.overflowX !== 'visible') {
				clip = `${node.tagName}.${node.className}`
			}
		}
		return [element.tagName, element.type, element.getAttribute('role'), element.className, style.color, style.backgroundColor, style.borderTopColor, style.borderTopWidth, backdrop, clip].join('|')
	}
	window.e2eControls = controls
	window.e2eLooks = controls.map(look)
	window.e2eName = name
	return controls.map(name)
}

// Runs in the page: the element with the focus, whether it is in the scope, which of the controls
// it is, its box in the scope and what hides it, when something hides it at the points that it
// shows.
function focused(selector) {
	const root = document.querySelector(selector)
	const element = document.activeElement
	if (element === null || !root.contains(element)) {
		return { inside: false, name: element === null ? 'nothing' : window.e2eName(element) }
	}
	const box = element.getBoundingClientRect()
	const origin = root.getBoundingClientRect()
	const related = (hit) => hit !== null && (hit === element || element.contains(hit) || hit.contains(element) || [...(element.labels ?? [])].some((label) => label.contains(hit)))
	const inset = (from, to) => from + Math.min(4, (to - from) / 2)
	const points = [
		[(box.left + box.right) / 2, (box.top + box.bottom) / 2],
		[inset(box.left, box.right), inset(box.top, box.bottom)],
		[inset(box.right, box.left), inset(box.top, box.bottom)],
		[inset(box.left, box.right), inset(box.bottom, box.top)],
		[inset(box.right, box.left), inset(box.bottom, box.top)],
	]
	const hits = points.map(([x, y]) => document.elementFromPoint(x, y))
	const hidden = box.width > 0 && box.height > 0 && !hits.some(related)
	return {
		inside: true,
		index: window.e2eControls.indexOf(element),
		name: window.e2eName(element),
		box: { left: box.left - origin.left, top: box.top - origin.top, right: box.right - origin.left, bottom: box.bottom - origin.top },
		hiddenBy: hidden ? hits.filter((hit) => hit !== null).map((hit) => window.e2eName(hit))[0] ?? 'the edge of the window' : null,
	}
}

async function signIn(browser) {
	const context = await browser.newContext()
	try {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}/index.php/login`)
		await page.locator('input[name="user"]').fill(USER)
		await page.locator('input[name="password"]').fill(PASSWORD)
		await page.locator('input[name="password"]').press('Enter')
		await page.waitForURL((url) => !url.pathname.endsWith('/login'))
		return await context.storageState()
	} finally {
		await context.close()
	}
}

async function openSettings(browser, session) {
	const context = await browser.newContext({ storageState: session, viewport: WIDE, colorScheme: 'light', reducedMotion: 'reduce' })
	const page = await context.newPage()
	await page.goto(`${BASE_URL}${SETTINGS}`)
	await page.locator(SCOPE).waitFor()
	await page.waitForLoadState('networkidle')
	return page
}

// Puts the start of Tab at the top of the settings, as a click there does, and moves the mouse
// out of the way, so that no control looks hovered.
async function startAtTop(page) {
	await page.locator(START).click()
	await page.mouse.move(0, 0)
}

// Tab from the top of the settings to the end: the controls in the order that the focus
// reached them, as their indexes in the list of the page.
async function walk(page, label) {
	const expected = await page.evaluate(listControls, SCOPE)
	await startAtTop(page)
	const order = []
	let previous = null
	for (let step = 0; step <= expected.length + 10; step++) {
		await page.keyboard.press('Tab')
		const focus = await page.evaluate(focused, SCOPE)
		if (!focus.inside) {
			break
		}
		order.push(focus.index)
		if (focus.hiddenBy !== null) {
			problems.push(`${label}: ${focus.name} has the focus but is hidden behind ${focus.hiddenBy}.`)
		}
		if (previous !== null && breaksOrder(previous.box, focus.box)) {
			problems.push(`${label}: Tab goes from ${previous.name} back to ${focus.name}, against the order of the page.`)
		}
		previous = focus
	}
	const missed = expected.filter((_, index) => !order.includes(index))
	if (missed.length > 0) {
		problems.push(`${label}: Tab never reaches ${missed.join(', ')}.`)
	}
	console.log(`Keyboard, ${label}: Tab reaches ${order.length} of ${expected.length} control(s).`)
	return order
}

// Each control that Tab reached: a picture of its area while nothing has the focus, and one
// with the focus. The focus comes from the page after the keys of the walk, which the browser
// shows as a focus of the keyboard (:focus-visible), so that no other control nearby has the
// focus in either picture.
async function focusIndicators(page, order, label) {
	await page.evaluate(listControls, SCOPE)
	const looks = await page.evaluate(() => window.e2eLooks)
	await startAtTop(page)
	await page.keyboard.press('Tab')
	// The first control of each look stands for the others.
	const kinds = new Map()
	for (const index of order) {
		if (!kinds.has(looks[index])) {
			kinds.set(looks[index], { index, count: 0 })
		}
		kinds.get(looks[index]).count++
	}
	let shown = 0
	for (const { index, count } of kinds.values()) {
		const others = count > 1 ? ` and ${count - 1} more control(s) that look like it` : ''
		const area = await page.evaluate(({ index, room }) => {
			document.activeElement?.blur()
			const element = window.e2eControls[index]
			element.scrollIntoView({ block: 'center', inline: 'nearest' })
			const box = element.getBoundingClientRect()
			const left = Math.max(0, Math.floor(box.left) - room)
			const top = Math.max(0, Math.floor(box.top) - room)
			return {
				name: window.e2eName(element),
				// The part of the control in the window, for one taller than the window.
				size: { width: Math.min(box.right, window.innerWidth) - Math.max(box.left, 0), height: Math.min(box.bottom, window.innerHeight) - Math.max(box.top, 0) },
				clip: { x: left, y: top, width: Math.min(window.innerWidth, Math.ceil(box.right) + room) - left, height: Math.min(window.innerHeight, Math.ceil(box.bottom) + room) - top },
			}
		}, { index, room: ROOM })
		const before = await page.screenshot({ clip: area.clip, animations: 'disabled', caret: 'hide' })
		const visible = await page.evaluate((index) => {
			const element = window.e2eControls[index]
			element.focus({ preventScroll: true })
			return document.activeElement === element && element.matches(':focus-visible')
		}, index)
		if (!visible) {
			problems.push(`${label}: ${area.name}${others}: the focus is not one of the keyboard (:focus-visible).`)
			continue
		}
		const after = await page.screenshot({ clip: area.clip, animations: 'disabled', caret: 'hide' })
		const indicator = focusIndicator(PNG.sync.read(before), PNG.sync.read(after), area.size)
		if (indicator.visible) {
			shown += count
		} else {
			problems.push(`${label}: ${area.name}${others}: the focus shows on ${indicator.pixels} pixel(s) with a contrast of 3:1, fewer than the ${indicator.needed} of a line around it.`)
		}
	}
	console.log(`Keyboard, ${label}: ${shown} of ${order.length} control(s), of ${kinds.size} look(s), show the focus with a contrast of at least 3:1.`)
}

// The check checks itself first, on a page with a control of every kind that it must fail: a
// button and a field without a visible focus, a button behind a bar that sticks to the bottom
// of the window, and two buttons that the page shows in the other order than Tab reaches them.
// A button with an outline passes.
async function seeded(browser) {
	const context = await browser.newContext({ viewport: WIDE })
	try {
		const page = await context.newPage()
		await page.setContent(`<div id="paperless-sync-settings" style="padding: 40px">
			<div class="paperless-sync-hero"><h2>Seeded</h2></div>
			<p><button type="button" class="outlined">Visible</button></p>
			<p><button type="button">Invisible</button> <input type="text" aria-label="Faint" style="border: 1px solid #ccc"></p>
			<p style="display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 20px"><button type="button" class="outlined">First</button><button type="button" class="outlined">Second</button></p>
			<p style="position: fixed; bottom: 0; margin: 0"><button type="button" class="outlined">Covered</button></p>
			<div style="position: fixed; inset: auto 0 0; height: 80px; background: #fff">A bar</div>
		</div>
		<style>#paperless-sync-settings :focus { outline: none } #paperless-sync-settings .outlined:focus-visible { outline: 2px solid #222; outline-offset: 2px }</style>`)
		const before = problems.length
		const order = await walk(page, 'seeded page')
		await focusIndicators(page, order, 'seeded page')
		const found = problems.splice(before)
		const fails = (name, kind) => found.some((problem) => problem.includes(name) && problem.includes(kind))
		expect(
			fails('Invisible', 'the focus shows') && fails('Faint', 'the focus shows') && fails('Covered', 'hidden behind') && fails('Second', 'against the order') && !fails('Visible', ''),
			`The check must fail the seeded controls, and only them: ${found.join(' ')}`,
		)
		console.log('Keyboard: the check fails a seeded button and field without a visible focus, a button behind a bar and a focus against the order of the page.')
	} finally {
		await context.close()
	}
}

// The pickers with the keyboard alone: the down arrow opens the list and reaches an option,
// Enter picks it, Escape closes the list and keeps the text; Backspace removes the last tag.
async function pickers(page) {
	const user = page.locator('#paperless-sync-target-user')
	const saved = await user.inputValue()
	await user.focus()
	await user.press('ControlOrMeta+a')
	await user.pressSequentially('e2e-oth')
	await user.press('ArrowDown')
	await page.locator('#paperless-sync-target-user-options [aria-selected="true"]').waitFor()
	expect(await user.getAttribute('aria-expanded') === 'true', 'The down arrow opens the list of users.')
	expect((await user.getAttribute('aria-activedescendant')) !== null, 'The option that the arrow reached is the active descendant of the field.')
	await user.press('Enter')
	expect(await user.inputValue() === 'e2e-other', `Enter picks the user: ${await user.inputValue()}`)
	expect(await user.getAttribute('aria-expanded') === 'false', 'Picking closes the list.')
	await user.press('ArrowDown')
	await page.locator('#paperless-sync-target-user-options [aria-selected="true"]').waitFor()
	await user.press('Escape')
	expect(await user.getAttribute('aria-expanded') === 'false', 'Escape closes the list of users.')
	expect(await user.inputValue() === 'e2e-other', 'Escape keeps the text of the field.')
	expect(await page.evaluate(() => document.activeElement?.id) === 'paperless-sync-target-user', 'The focus stays in the field after Escape.')
	await user.fill(saved)

	const tags = page.locator('#paperless-sync-excluded-tags-input')
	const chips = page.locator('#paperless-sync-excluded-tags-list .paperless-sync-chip')
	const count = await chips.count()
	await tags.focus()
	await tags.pressSequentially('Dra')
	await tags.press('ArrowDown')
	await page.locator('#paperless-sync-excluded-tags-options [aria-selected="true"]').waitFor()
	await tags.press('Enter')
	await page.waitForFunction(({ selector, count }) => document.querySelectorAll(selector).length === count + 1, { selector: '#paperless-sync-excluded-tags-list .paperless-sync-chip', count })
	expect((await chips.last().textContent()).includes('Draft'), `Enter picks the tag of the option: ${await chips.last().textContent()}`)
	await tags.press('Backspace')
	await page.waitForFunction(({ selector, count }) => document.querySelectorAll(selector).length === count, { selector: '#paperless-sync-excluded-tags-list .paperless-sync-chip', count })
	console.log('Keyboard: the pickers of a user and of tags open with the down arrow, pick with Enter, close with Escape and remove with Backspace.')
}

// Escape on the questions of Disconnect: the browser asks first, and Escape there cancels, so
// nothing is sent; then Nextcloud asks for the password, and Escape closes its dialog, after
// which the focus is on the page again. The answer of the server is the page's own, so nothing
// is disconnected.
async function dialogs(page) {
	const deletes = []
	await page.route('**/apps/paperless_sync/settings', async (route) => {
		if (route.request().method() !== 'DELETE') {
			await route.continue()
			return
		}
		deletes.push(route.request().url())
		await route.fulfill({ status: 403, json: NOT_CONFIRMED })
	})
	const reset = page.locator('#paperless-sync-reset')
	await reset.focus()
	// Playwright can't press a key in a dialog of the browser; dismiss() is its Cancel, which
	// Escape is.
	const questions = []
	page.once('dialog', (question) => {
		questions.push(question.message())
		return question.dismiss()
	})
	await page.keyboard.press('Enter')
	await page.waitForTimeout(200)
	expect(questions.length === 1 && questions[0].startsWith('Disconnect Paperless?'), `Disconnect asks first: ${questions.join(' ')}`)
	expect(deletes.length === 0, 'Escape on the question of Disconnect sends nothing.')
	expect(await page.evaluate(() => document.activeElement?.id) === 'paperless-sync-reset', 'After the question, the focus is on Disconnect again.')

	await page.evaluate(() => {
		window.nc_lastLogin = 1
	})
	page.once('dialog', (dialog) => dialog.accept())
	await page.keyboard.press('Enter')
	const dialog = page.getByRole('dialog')
	await dialog.locator('input[type="password"]').waitFor()
	expect(await page.evaluate(() => document.activeElement?.closest('[role="dialog"]') !== null), 'The focus moves into the dialog for the password.')
	// The field for the password of Nextcloud 33 keeps Escape to itself, as a field of a form may;
	// Escape closes the dialog from its other controls, such as the next one.
	await page.keyboard.press('Escape')
	if (await dialog.waitFor({ state: 'detached', timeout: 1_000 }).then(() => false, () => true)) {
		await page.keyboard.press('Tab')
		expect(await page.evaluate(() => document.activeElement?.closest('[role="dialog"]') !== null), 'Tab keeps the focus in the dialog for the password.')
		await page.keyboard.press('Escape')
	}
	await dialog.waitFor({ state: 'detached' })
	const message = page.locator('#paperless-sync-message')
	await message.filter({ hasText: 'Your password was not confirmed, so nothing was changed.' }).waitFor()
	const focus = await page.evaluate(() => document.activeElement?.id ?? '')
	expect(['paperless-sync-reset', 'paperless-sync-message'].includes(focus), `After Escape, the focus is at Disconnect or at the message, not lost: ${focus || 'nothing'}`)
	expect(deletes.length === 1, `Disconnect asked the server once: ${deletes.length}`)
	await page.unroute('**/apps/paperless_sync/settings')
	console.log('Keyboard: Escape cancels the question of Disconnect and closes the dialog for the password, and the focus stays on the page.')
}

const started = Date.now()
const browser = await chromium.launch()
try {
	await seeded(browser)
	const page = await openSettings(browser, await signIn(browser))
	// One page for everything, which switches its width and its theme without loading again.
	const order = await walk(page, 'settings, 1280 px')
	await focusIndicators(page, order, 'settings, light theme, 1280 px')
	await page.setViewportSize(NARROW)
	await walk(page, 'settings, 320 px')
	await page.setViewportSize(WIDE)
	await page.emulateMedia({ colorScheme: 'dark' })
	await focusIndicators(page, order, 'settings, dark theme, 1280 px')
	await page.emulateMedia({ colorScheme: 'light' })
	await pickers(page)
	await dialogs(page)
} finally {
	await browser.close()
	for (const problem of problems) {
		console.error(problem)
	}
}

if (problems.length > 0) {
	console.error(`The settings fail ${problems.length} check(s) by keyboard alone.`)
	process.exit(1)
}
console.log(`The settings work by keyboard alone, at 1280 and 320 px, after ${Math.round((Date.now() - started) / 1000)} s.`)

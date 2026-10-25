// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The judgments of the accessibility checks in the browser (a11y.mjs), which
// scripts/check-project.sh runs with node --test: a seeded finding of every kind fails, such
// as a button without a name, a box wider than a phone, a focus indicator too faint to see and
// a focus that jumps back up the page, and what the page may do passes.

import assert from 'node:assert/strict'
import { afterEach, test } from 'node:test'
import { breaksOrder, contrast, describeViolations, failing, focusIndicator, horizontalOverflow } from './a11y.mjs'

// A finding of axe-core as axe.run() reports it.
function violation(id, impact) {
	return {
		id,
		impact,
		help: `Help of ${id}`,
		helpUrl: `https://dequeuniversity.com/rules/axe/4.14/${id}`,
		nodes: [{ target: ['#paperless-sync-save'], html: '<button id="paperless-sync-save"></button>', failureSummary: 'Fix any of the following:\n  Element does not have inner text' }],
	}
}

test('a button without a name fails the check, a finding of moderate impact is only listed', () => {
	const found = [violation('button-name', 'critical'), violation('color-contrast', 'serious'), violation('region', 'moderate'), violation('landmark', 'minor')]
	assert.deepEqual(failing(found).map((finding) => finding.id), ['button-name', 'color-contrast'])
	assert.deepEqual(failing([]), [])
})

test('the log names the rule, the element and the fix of each finding', () => {
	assert.deepEqual(describeViolations([violation('button-name', 'critical')]), [
		'  critical button-name: Help of button-name (https://dequeuniversity.com/rules/axe/4.14/button-name)',
		'    #paperless-sync-save',
		'      <button id="paperless-sync-save"></button>',
		'      Fix any of the following:\n        Element does not have inner text',
	])
})

// An element of a page in a window 320 pixels wide, with the box that its left and right edge
// make, its parent and its overflow.
function element(tagName, { id = '', classes = [], left = 0, right = 100, height = 20, overflowX = 'visible', parent = null } = {}) {
	return {
		tagName,
		id,
		classList: classes,
		parentElement: parent,
		overflowX,
		getBoundingClientRect: () => ({ left, right, width: right - left, height }),
	}
}

function page(root, elements, { scrollWidth = 300, clientWidth = 300 } = {}) {
	root.scrollWidth = scrollWidth
	root.clientWidth = clientWidth
	root.querySelectorAll = () => elements
	globalThis.document = {
		documentElement: { clientWidth: 320 },
		querySelector: (selector) => (selector === '#paperless-sync-settings' ? root : null),
	}
	globalThis.getComputedStyle = (target) => ({ overflowX: target.overflowX })
}

afterEach(() => {
	delete globalThis.document
	delete globalThis.getComputedStyle
})

test('at 320 pixels, a box that reaches past the window is found, with its place', () => {
	const body = element('body')
	const root = element('div', { id: 'paperless-sync-settings', right: 310, parent: body })
	const table = element('table', { classes: ['paperless-sync-owners', 'wide', 'third'], left: 10, right: 460, parent: root })
	const cell = element('td', { left: 10, right: 400, parent: table })
	page(root, [table, cell], { scrollWidth: 460 })
	assert.deepEqual(horizontalOverflow('#paperless-sync-settings'), [
		'div#paperless-sync-settings scrolls sideways: 460 px of content in 300 px.',
		'table.paperless-sync-owners.wide reaches from 10 to 460 px in a window of 320 px.',
		'td reaches from 10 to 400 px in a window of 320 px.',
	])
})

test('a box that starts left of the window is found as well', () => {
	const root = element('div', { id: 'paperless-sync-settings', right: 310, parent: element('body') })
	page(root, [element('p', { left: -40, right: 200, parent: root })])
	assert.deepEqual(horizontalOverflow('#paperless-sync-settings'), ['p reaches from -40 to 200 px in a window of 320 px.'])
})

test('content that scrolls inside a box of its own, and what has no box, may be wider', () => {
	const root = element('div', { id: 'paperless-sync-settings', right: 310, parent: element('body') })
	const list = element('div', { classes: ['paperless-sync-report-list'], left: 10, right: 310, overflowX: 'auto', parent: root })
	const path = element('code', { left: 10, right: 900, parent: list })
	const hidden = element('p', { left: 0, right: 0, height: 0, parent: root })
	const fits = element('button', { left: 10, right: 320, parent: root })
	page(root, [list, path, hidden, fits])
	assert.deepEqual(horizontalOverflow('#paperless-sync-settings'), [])
})

test('a scope that is not on the page says so instead of passing', () => {
	page(element('div', { id: 'paperless-sync-settings' }), [])
	assert.deepEqual(horizontalOverflow('#paperless-sync-missing'), ['#paperless-sync-missing is not on the page.'])
})

test('the contrast ratio is that of WCAG: 21 for black on white, 1 for a color on itself', () => {
	assert.equal(contrast([0, 0, 0], [255, 255, 255]), 21)
	assert.equal(contrast([118, 118, 118], [118, 118, 118]), 1)
	assert.ok(contrast([118, 118, 118], [255, 255, 255]) >= 4.5)
	assert.ok(contrast([221, 221, 221], [255, 255, 255]) < 1.5)
})

// A picture of size × size pixels around a control of inner × inner pixels in its middle, white,
// with a ring of the color of the argument and the width of one pixel around the control.
function picture(size, inner, ring, width = 1) {
	const data = new Uint8Array(size * size * 4).fill(255)
	const start = (size - inner) / 2 - width
	const end = start + inner + 2 * width
	for (let y = 0; y < size; y++) {
		for (let x = 0; x < size; x++) {
			const inRing = x >= start && x < end && y >= start && y < end
				&& !(x >= start + width && x < end - width && y >= start + width && y < end - width)
			if (ring !== null && inRing) {
				data.set([...ring, 255], (y * size + x) * 4)
			}
		}
	}
	return { width: size, height: size, data }
}

test('a solid ring that contrasts with the page is a focus indicator', () => {
	const result = focusIndicator(picture(30, 20, null), picture(30, 20, [34, 34, 34], 2), { width: 20, height: 20 })
	assert.equal(result.visible, true)
	assert.equal(result.needed, 80)
	assert.ok(result.pixels >= 80)
})

test('no change, a faint ring and a dotted ring of too few pixels are no focus indicator', () => {
	const control = { width: 20, height: 20 }
	assert.equal(focusIndicator(picture(30, 20, null), picture(30, 20, null), control).visible, false)
	assert.equal(focusIndicator(picture(30, 20, null), picture(30, 20, [221, 221, 221], 2), control).visible, false)
	const dotted = picture(30, 20, null)
	for (let x = 4; x < 26; x += 4) {
		dotted.data.set([0, 0, 0, 255], (4 * 30 + x) * 4)
	}
	assert.equal(focusIndicator(picture(30, 20, null), dotted, control).visible, false)
})

test('two pictures of different sizes are an error of the check, not a result', () => {
	assert.throws(() => focusIndicator(picture(30, 20, null), picture(32, 20, null), { width: 20, height: 20 }), /differ in size/)
})

test('the focus goes down the page and to the right on a line, or up into a column to the right', () => {
	const field = { left: 0, top: 100, right: 300, bottom: 140 }
	assert.equal(breaksOrder(field, { left: 0, top: 160, right: 300, bottom: 200 }), false, 'the next line')
	assert.equal(breaksOrder(field, { left: 320, top: 100, right: 620, bottom: 140 }), false, 'the next field on the line')
	assert.equal(breaksOrder({ left: 0, top: 150, right: 300, bottom: 170 }, { left: 320, top: 100, right: 620, bottom: 140 }), false, 'the top of the next column')
	assert.equal(breaksOrder(field, { left: 600, top: 400, right: 700, bottom: 440 }), false, 'far down on the right')
})

test('a focus that goes back to the left on a line, or back up without going right, breaks the order', () => {
	const field = { left: 320, top: 100, right: 620, bottom: 140 }
	assert.equal(breaksOrder(field, { left: 0, top: 100, right: 300, bottom: 140 }), true, 'back on the line')
	assert.equal(breaksOrder(field, { left: 0, top: 20, right: 300, bottom: 60 }), true, 'up and to the left')
	assert.equal(breaksOrder(field, { left: 400, top: 20, right: 600, bottom: 60 }), true, 'up in the same column')
})

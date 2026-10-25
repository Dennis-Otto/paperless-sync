// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// What the accessibility checks in the browser, accessibility.mjs and keyboard.mjs, judge by:
// which findings of axe-core fail, which elements reach past the edge of a narrow window, how
// much a focus indicator stands out and whether the focus moves in the order of the page.
// scripts/check-project.sh tests them with node --test (a11y.test.mjs), with a seeded
// finding of every kind that must fail; both checks also try them on a seeded page in the
// browser before they look at the app, so that a check that can't fail fails.

// The rules of WCAG 2.1 at levels A and AA that axe-core checks.
export const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
// A finding of these impacts fails the check; the others are listed.
export const FAILING = new Set(['serious', 'critical'])
// The contrast that a focus indicator needs with the look of the control without the focus,
// as WCAG 2.2 asks in 2.4.13 and 1.4.11 asks of the parts of a control.
export const FOCUS_CONTRAST = 3
// The widths of the window: a computer, and a phone at the 320 CSS pixels of WCAG 1.4.10,
// at which the page reflows into one column without scrolling sideways.
export const WIDE = { width: 1280, height: 900 }
export const NARROW = { width: 320, height: 720 }

// The findings of axe-core that fail the check.
export function failing(violations) {
	return violations.filter((violation) => FAILING.has(violation.impact))
}

// The lines of the log for the findings of axe-core: each rule with its elements and the fix
// that axe-core names.
export function describeViolations(violations) {
	const lines = []
	for (const violation of violations) {
		lines.push(`  ${violation.impact} ${violation.id}: ${violation.help} (${violation.helpUrl})`)
		for (const node of violation.nodes) {
			lines.push(`    ${node.target.join(' ')}`)
			lines.push(`      ${node.html}`)
			lines.push(`      ${String(node.failureSummary ?? '').replaceAll('\n', '\n      ')}`)
		}
	}
	return lines
}

// Runs in the page, with page.evaluate(horizontalOverflow, selector), and so uses nothing from
// outside: the elements of the scope that reach past the left or the right edge of the window,
// and the scope itself when it scrolls sideways. Content inside an element that scrolls on its
// own, such as a long path in a box with a scroll bar, may be wider; the box itself may not.
export function horizontalOverflow(selector) {
	const root = document.querySelector(selector)
	if (root === null) {
		return [`${selector} is not on the page.`]
	}
	const name = (element) => element.tagName.toLowerCase()
		+ (element.id ? `#${element.id}` : '')
		+ [...element.classList].slice(0, 2).map((value) => `.${value}`).join('')
	const scrolls = (element) => {
		for (let parent = element.parentElement; parent !== null && parent !== root.parentElement; parent = parent.parentElement) {
			const overflow = getComputedStyle(parent).overflowX
			if (overflow === 'auto' || overflow === 'scroll' || overflow === 'hidden' || overflow === 'clip') {
				return true
			}
		}
		return false
	}
	const width = document.documentElement.clientWidth
	const found = []
	if (root.scrollWidth > root.clientWidth + 1) {
		found.push(`${name(root)} scrolls sideways: ${root.scrollWidth} px of content in ${root.clientWidth} px.`)
	}
	for (const element of root.querySelectorAll('*')) {
		const box = element.getBoundingClientRect()
		if (box.width === 0 || box.height === 0 || scrolls(element)) {
			continue
		}
		if (box.right > width + 1 || box.left < -1) {
			found.push(`${name(element)} reaches from ${Math.round(box.left)} to ${Math.round(box.right)} px in a window of ${width} px.`)
		}
	}
	return found
}

// The relative luminance of a color of sRGB, red, green and blue from 0 to 255.
function luminance(red, green, blue) {
	const linear = (value) => {
		const channel = value / 255
		return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
	}
	return 0.2126 * linear(red) + 0.7152 * linear(green) + 0.0722 * linear(blue)
}

// The contrast ratio of two colors, each [red, green, blue], from 1 to 21.
export function contrast(first, second) {
	const a = luminance(...first)
	const b = luminance(...second)
	return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
}

// The focus indicator of a control: two pictures of the same area around it, of the same size,
// before and with the focus, each { width, height, data } with four bytes of RGBA a pixel, as
// pngjs reads a PNG. The pixels that change by at least FOCUS_CONTRAST are the indicator, and it
// needs as many as a solid line of one pixel around the control has, its perimeter in pixels.
export function focusIndicator(before, after, control) {
	if (before.width !== after.width || before.height !== after.height) {
		throw new Error(`The pictures differ in size: ${before.width}x${before.height} and ${after.width}x${after.height}.`)
	}
	let pixels = 0
	for (let offset = 0; offset < before.data.length; offset += 4) {
		const old = [before.data[offset], before.data[offset + 1], before.data[offset + 2]]
		const now = [after.data[offset], after.data[offset + 1], after.data[offset + 2]]
		if (contrast(old, now) >= FOCUS_CONTRAST) {
			pixels++
		}
	}
	const needed = 2 * (Math.round(control.width) + Math.round(control.height))
	return { pixels, needed, visible: pixels >= needed }
}

// Whether the focus, going from the control at the box previous to the one at next, breaks the
// order in which the page shows them, from the top to the bottom and on a line from the left to
// the right: on the same line it goes back to the left, or it goes up without going right, into
// another column. Each box is { left, top, right, bottom } in pixels of the same page.
export function breaksOrder(previous, next) {
	const tolerance = 2
	const sameLine = next.top < previous.bottom - tolerance && next.bottom > previous.top + tolerance
	if (sameLine) {
		return next.left < previous.left - tolerance
	}
	if (next.top >= previous.bottom - tolerance) {
		return false
	}
	return next.left < previous.right - tolerance
}

// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The frozen clock of the pictures, so that two runs of screenshots.sh draw the same pixels
// and the screenshot bot changes a picture only when the app looks different. The server
// writes the moments of the runs and a new secret of the webhook, and Files shows how long
// ago a file changed: all of it differs from run to run. The browser of screenshots.mjs sees
// the moments of the story instead, which the story moves on as it goes (Story.to), the same
// example secret on every run, and every file of Files changed a few seconds ago.
//
// freeze() rewrites what the browser gets, never what the server keeps: the answers of the
// app in JSON and the lists of files in WebDAV. The settings page itself comes as the server
// sent it, since Chromium refuses every script and style of a page that a route answered when
// they come from a server in a private network, such as the containers of screenshots.sh; a
// script that runs before those of the page (frozenInitialState) freezes its initial state.

// When the story of the pictures starts: the quick start, with its first dry-run.
export const STORY_START = '2026-10-18T10:20:00+02:00'

// The secret of the webhook in the pictures, as the page shows it once after its creation:
// plainly an example, which no one takes for a real one.
// The size of the square in each corner of a box that blackCorners() looks at, and how many
// black pixels in it are still a part of the page, such as of an icon.
const CORNER = 16
const BLACK_PIXELS_OF_THE_PAGE = 4

// Whether a picture of pngjs shows a corner of a box of the page, such as the panel of Nextcloud,
// black. After a change of the theme, Chromium now and then draws the corners of a part of the
// page that scrolls black, in other frames on each run, and for longer than two pictures in a
// row, so that only looking at the picture finds it. No theme of Nextcloud has this black.
export function blackCorners({ width, height, data }, { left, top, right, bottom }) {
	const across = [Math.round(left), Math.round(right) - CORNER]
	const down = [Math.round(top), Math.round(bottom) - CORNER]
	return across.some((x0) => down.some((y0) => {
		let black = 0
		for (let y = Math.max(y0, 0); y < Math.min(y0 + CORNER, height); y++) {
			for (let x = Math.max(x0, 0); x < Math.min(x0 + CORNER, width); x++) {
				const pixel = (y * width + x) * 4
				if (data[pixel] === 0 && data[pixel + 1] === 0 && data[pixel + 2] === 0) {
					black++
				}
			}
		}
		return black > BLACK_PIXELS_OF_THE_PAGE
	}))
}

export const EXAMPLE_SECRET = 'example-secret-of-the-screenshots-0123456789'

// The addresses whose answers freeze() rewrites: the routes of the app, with or without
// /index.php and through the OCS API, but not its scripts and styles in /custom_apps/; and
// the files in WebDAV.
const APP = /^\/(?:index\.php\/|ocs\/v[12]\.php\/)?apps\/paperless_sync\//
const DAV = /^\/remote\.php\/(?:dav|webdav)\//
// The requests of WebDAV that list files with their properties.
const LISTINGS = new Set(['PROPFIND', 'REPORT', 'SEARCH'])

export class Story {
	constructor(start = STORY_START) {
		this.now = toSeconds(start)
		this.seen = new Map()
	}

	// Moves the story on to a later moment: what the server does from now on happened then.
	to(moment) {
		this.now = toSeconds(moment)
	}

	// The moment of the story for a moment of the server: the one at which the browser first
	// saw it, the same each time it sees it again. No moment, 0, stays as it is.
	moment(seconds) {
		if (!Number.isInteger(seconds) || seconds <= 0) {
			return seconds
		}
		if (!this.seen.has(seconds)) {
			this.seen.set(seconds, this.now)
		}
		return this.seen.get(seconds)
	}

	// An answer of the app in JSON, with the moments of the story and the example secret.
	answer(value) {
		return frozenAnswer(value, (seconds) => this.moment(seconds), EXAMPLE_SECRET)
	}

	// The script that freezes the initial state of the settings page, for a page that opens
	// now: with the moments that the browser saw so far, and the moment of the story now for
	// one that it never saw.
	initialStateScript() {
		const story = { seen: Object.fromEntries(this.seen), now: this.now, secret: EXAMPLE_SECRET }
		return `(${frozenInitialState})(${frozenAnswer}, ${JSON.stringify(story)})`
	}
}

// A moment, as an ISO date or in seconds since 1970, in seconds since 1970.
function toSeconds(moment) {
	const seconds = typeof moment === 'number' ? moment : Date.parse(moment) / 1000
	if (!Number.isInteger(seconds)) {
		throw new Error(`The story needs a moment in whole seconds, not ${moment}`)
	}
	return seconds
}

// A value of an answer of the app, with moment() for every field of a moment, in seconds
// since 1970: those of the status, the report of a run and the last call of the webhook; and
// with secret for a new secret of the webhook. It names nothing outside of itself, since the
// page runs it too (frozenInitialState).
export function frozenAnswer(value, moment, secret) {
	const moments = ['lastStarted', 'lastCompleted', 'lastEnded', 'lastSuccess', 'failingSince', 'completedAt', 'at']
	const walk = (item) => {
		if (Array.isArray(item)) {
			return item.map(walk)
		}
		if (item === null || typeof item !== 'object') {
			return item
		}
		return Object.fromEntries(Object.entries(item).map(([key, field]) => {
			if (moments.includes(key)) {
				return [key, moment(field)]
			}
			if (key === 'secret' && typeof field === 'string' && field !== '') {
				return [key, secret]
			}
			return [key, walk(field)]
		}))
	}
	return walk(value)
}

// Runs in the settings page before its scripts: once the page is parsed, and before the
// script of the app reads its initial state on DOMContentLoaded, it freezes that state, JSON
// in Base64 in hidden inputs as @nextcloud/initial-state reads it. It names nothing outside of
// itself and its arguments, since the browser gets its text.
export function frozenInitialState(answer, { seen, now, secret }) {
	const moment = (seconds) => (Number.isInteger(seconds) && seconds > 0 ? (seen[seconds] ?? now) : seconds)
	// On window as the event goes down, before every listener of document.
	window.addEventListener('DOMContentLoaded', () => {
		for (const input of document.querySelectorAll('input[id^="initial-state-paperless_sync-"]')) {
			try {
				const state = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(input.value), (character) => character.charCodeAt(0))))
				const bytes = new TextEncoder().encode(JSON.stringify(answer(state, moment, secret)))
				input.value = btoa(Array.from(bytes, (byte) => String.fromCharCode(byte)).join(''))
			} catch {
				// A state that is no JSON stays as it is, and the page shows what it can.
			}
		}
	}, true)
}

// A list of files in WebDAV, in which every file changed at the moment now, in milliseconds:
// Files shows "a few seconds ago" for each, whenever the run synchronized or uploaded it.
export function changedNow(xml, now) {
	const date = new Date(now).toUTCString()
	return xml.replace(/<([A-Za-z][\w.-]*):getlastmodified>[^<]*<\/\1:getlastmodified>/g, `<$1:getlastmodified>${date}</$1:getlastmodified>`)
}

// The headers of an answer that the browser gets with another body: without its length and
// encoding, since the body that the browser gets is the decoded one.
export function plainHeaders(headers) {
	return Object.fromEntries(Object.entries(headers).filter(([name]) => !['content-length', 'content-encoding', 'transfer-encoding'].includes(name.toLowerCase())))
}

// Answers a request with what the server answered, its body changed by rewrite, or as it
// is when rewrite gives undefined. A request whose page closed on the way, such as the
// preview that a page asks for as screenshots.mjs closes it, needs no answer. Apache closes
// a connection after 5 idle seconds, and a request that reuses it in that moment gets no
// answer: a browser sends it again on its own, and route.fetch only with maxRetries.
async function rewritten(route, rewrite) {
	try {
		const response = await route.fetch({ maxRetries: 3 })
		const headers = response.headers()
		const type = headers['content-type'] ?? ''
		const body = await rewrite(response, type)
		await route.fulfill({
			status: response.status(),
			headers: plainHeaders(headers),
			body: body ?? (await response.body()),
		})
	} catch (error) {
		if (!closed(error)) {
			throw error
		}
	}
}

// Whether an error of Playwright says that the page, its context or the browser closed.
export function closed(error) {
	return error?.name === 'TargetClosedError' || /Target page, context or browser has been closed|Request context disposed/.test(String(error?.message))
}

// Freezes the clock of every page of a browser context that opens now, for the story: the
// answers of the app, the initial state of its settings page and the lists of files in WebDAV.
export async function freeze(context, story, now = Date.now) {
	await context.addInitScript({ content: story.initialStateScript() })
	await context.route((url) => APP.test(url.pathname), (route) => rewritten(route, async (response, type) =>
		type.includes('json') ? JSON.stringify(story.answer(await response.json())) : undefined))
	await context.route((url) => DAV.test(url.pathname), (route) => {
		if (!LISTINGS.has(route.request().method())) {
			return route.fallback()
		}
		return rewritten(route, async (response, type) =>
			type.includes('xml') ? changedNow(await response.text(), now()) : undefined)
	})
}

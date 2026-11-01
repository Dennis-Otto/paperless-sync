// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The frozen clock of the pictures, which scripts/check-project.sh runs with node --test:
// the answers that the browser of screenshots.mjs gets show the moments of the story, the
// example secret and files that changed a few seconds ago, whenever screenshots.sh runs.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { afterEach, test } from 'node:test'
import { blackCorners, changedNow, closed, EXAMPLE_SECRET, freeze, frozenAnswer, frozenFolderInfo, frozenInitialState, plainHeaders, Story, STORY_START } from './frozen.mjs'

const AT_10_20 = Date.parse(STORY_START) / 1000
const AT_10_21 = AT_10_20 + 60

test('each moment of the server shows the moment of the story at which the browser first saw it', () => {
	const story = new Story()
	assert.equal(story.moment(1_800_000_007), AT_10_20)
	story.to('2026-10-18T10:21:00+02:00')
	assert.equal(story.moment(1_800_000_093), AT_10_21)
	// Seen again later, a moment keeps the moment of the story that it got first.
	story.to(AT_10_21 + 60)
	assert.equal(story.moment(1_800_000_007), AT_10_20)
	assert.equal(story.moment(1_800_000_093), AT_10_21)
	assert.equal(story.moment(1_800_000_150), AT_10_21 + 60)
})

test('no moment, 0 or null, stays as it is, and the story needs whole seconds', () => {
	const story = new Story()
	assert.equal(story.moment(0), 0)
	assert.equal(story.moment(null), null)
	assert.equal(story.moment(undefined), undefined)
	assert.equal(story.seen.size, 0)
	assert.throws(() => story.to('not a date'), /whole seconds/)
	assert.throws(() => new Story(1.5), /whole seconds/)
})

test('an answer of the app gets the moments of the story in every field of a moment, at any depth', () => {
	const story = new Story()
	const status = {
		state: 'completed',
		lastStarted: 1_800_000_000,
		lastEnded: 1_800_000_001,
		lastSuccess: 1_800_000_001,
		failingSince: 0,
		nextRun: 1_800_003_600,
		summary: { exported: 13 },
		report: { completedAt: 1_800_000_001, actions: [{ path: 'Archive/2026-10-07 - Letter.pdf', document: 190 }] },
		users: [{ lastCall: { at: 1_800_000_002, document: 7 } }],
	}
	assert.deepEqual(story.answer({ ocs: { data: status } }), {
		ocs: {
			data: {
				...status,
				lastStarted: AT_10_20,
				lastEnded: AT_10_20,
				lastSuccess: AT_10_20,
				// The next run counts from the clock of the browser, which the pictures show as Off.
				nextRun: 1_800_003_600,
				report: { ...status.report, completedAt: AT_10_20 },
				users: [{ lastCall: { at: AT_10_20, document: 7 } }],
			},
		},
	})
})

test('a new secret of the webhook shows as the example secret, no secret stays empty', () => {
	const story = new Story()
	assert.deepEqual(story.answer({ configured: true, secret: 'x'.repeat(32) }), { configured: true, secret: EXAMPLE_SECRET })
	assert.deepEqual(story.answer({ configured: false, secret: '' }), { configured: false, secret: '' })
	assert.deepEqual(story.answer([{ secret: null }, 'text', 3]), [{ secret: null }, 'text', 3])
})

// A settings page of Nextcloud with its initial states, as the script of frozenInitialState
// sees it, and the listener that it adds for DOMContentLoaded.
function settingsPage(states) {
	const inputs = Object.entries(states).map(([key, value]) => ({
		id: `initial-state-paperless_sync-${key}`,
		value: typeof value === 'string' ? value : Buffer.from(JSON.stringify(value), 'utf8').toString('base64'),
	}))
	const listeners = []
	globalThis.window = { addEventListener: (type, listener, capture) => listeners.push({ type, listener, capture }) }
	globalThis.document = {
		querySelectorAll: (selector) => {
			assert.equal(selector, 'input[id^="initial-state-paperless_sync-"]')
			return inputs
		},
	}
	const state = (key) => JSON.parse(Buffer.from(inputs.find(({ id }) => id.endsWith(`-${key}`)).value, 'base64').toString('utf8'))
	return { inputs, listeners, state }
}

afterEach(() => {
	delete globalThis.window
	delete globalThis.document
})

test('the initial state of the settings page gets the moments that the browser saw, the moment of the story for the others', () => {
	const story = new Story()
	story.moment(1_800_000_001)
	story.to(AT_10_21)
	const page = settingsPage({
		status: { lastEnded: 1_800_000_093, lastSuccess: 1_800_000_001, failingSince: 0, error: 'Größe' },
		webhook: { configured: true, secret: 'x'.repeat(32), lastCall: null },
		broken: 'not JSON',
	})
	// The text of the script, as the browser runs it, without anything of this module.
	new Function(story.initialStateScript())()
	assert.equal(page.listeners.length, 1)
	assert.equal(page.listeners[0].type, 'DOMContentLoaded')
	// On window as the event goes down, before the listener of the app on document.
	assert.equal(page.listeners[0].capture, true)
	page.listeners[0].listener()
	assert.deepEqual(page.state('status'), { lastEnded: AT_10_21, lastSuccess: AT_10_20, failingSince: 0, error: 'Größe' })
	assert.deepEqual(page.state('webhook'), { configured: true, secret: EXAMPLE_SECRET, lastCall: null })
	assert.equal(page.inputs[2].value, 'not JSON')
	// The page doesn't tell the story what it saw.
	assert.equal(story.seen.size, 1)
})

test('frozenAnswer names nothing outside of itself, as the page runs it', () => {
	const moment = (seconds) => seconds + 1
	const answer = new Function(`return (${frozenAnswer})`)()
	assert.deepEqual(answer({ a: [{ completedAt: 1 }], secret: 's' }, moment, 'x'), { a: [{ completedAt: 2 }], secret: 'x' })
})

test('frozenInitialState freezes each initial state of the app once the page is parsed', () => {
	const page = settingsPage({ status: { lastEnded: 7, report: { completedAt: 9 } }, config: { base_folder: 'Dokumente/Paperless' }, broken: '%%%' })
	frozenInitialState(frozenAnswer, { seen: { 7: 70 }, now: 100, secret: 'x' })
	page.listeners[0].listener()
	assert.deepEqual(page.state('status'), { lastEnded: 70, report: { completedAt: 100 } })
	assert.deepEqual(page.state('config'), { base_folder: 'Dokumente/Paperless' })
	assert.equal(page.inputs[2].value, '%%%')
})

test('the note above the list of Files says the same on every run, by the clock of the browser', () => {
	assert.deepEqual(frozenFolderInfo({ dismissed: ['inbox'], lastSuccess: 7, nextRun: null }, 1_000_000_400), { dismissed: ['inbox'], lastSuccess: 1_000_000 - 300, nextRun: 1_000_000 + 600 })
})

test('every file of a list of WebDAV changed at the moment the browser got the list', () => {
	const now = Date.parse('2026-10-24T20:00:05Z')
	const xml = '<d:multistatus xmlns:d="DAV:"><d:response><d:prop><d:getlastmodified>Sat, 18 Oct 2026 08:21:13 GMT</d:getlastmodified>'
		+ '<d:getetag>"1"</d:getetag></d:prop></d:response><d:response><d:prop><D:getlastmodified>Sun, 19 Oct 2026 01:00:00 GMT</D:getlastmodified></d:prop></d:response></d:multistatus>'
	assert.equal(changedNow(xml, now), xml
		.replace('Sat, 18 Oct 2026 08:21:13 GMT', 'Sat, 24 Oct 2026 20:00:05 GMT')
		.replace('Sun, 19 Oct 2026 01:00:00 GMT', 'Sat, 24 Oct 2026 20:00:05 GMT'))
})

test('an answer with another body loses its length and encoding', () => {
	assert.deepEqual(plainHeaders({ 'content-type': 'application/json', 'Content-Length': '10', 'content-encoding': 'gzip', 'transfer-encoding': 'chunked', etag: '"1"' }), {
		'content-type': 'application/json',
		etag: '"1"',
	})
})

// A browser context of Playwright that only remembers its routes, and a request through them.
function context() {
	const routes = []
	const scripts = []
	return {
		routes,
		scripts,
		addInitScript: (script) => {
			scripts.push(script)
			return Promise.resolve()
		},
		route: (matches, handler) => {
			routes.push({ matches, handler })
			return Promise.resolve()
		},
		async request(address, { method = 'GET', type = 'application/json', body = '', failure = null, resets = 0 } = {}) {
			const url = new URL(address)
			const route = routes.find(({ matches }) => matches(url))
			if (route === undefined) {
				return { passed: 'unrouted' }
			}
			const answer = {}
			await route.handler({
				request: () => ({ method: () => method, url: () => address }),
				fallback: () => {
					answer.passed = 'fallback'
				},
				// As route.fetch of Playwright: it sends a request again for each reset connection
				// that maxRetries allows, and for no other error.
				fetch: ({ maxRetries = 0 } = {}) => {
					if (failure !== null) {
						return Promise.reject(failure)
					}
					if (resets > maxRetries) {
						return Promise.reject(Object.assign(new Error('route.fetch: socket hang up (ECONNRESET)'), { code: 'ECONNRESET' }))
					}
					return Promise.resolve({
						status: () => 200,
						headers: () => ({ 'content-type': type, 'content-length': String(body.length), 'content-encoding': 'gzip' }),
						json: () => Promise.resolve(JSON.parse(body)),
						text: () => Promise.resolve(body),
						body: () => Promise.resolve(Buffer.from(body)),
					})
				},
				fulfill: (fulfilled) => {
					Object.assign(answer, fulfilled)
				},
			})
			return answer
		},
	}
}

test('freeze() freezes the initial state, rewrites the answers of the app and the lists of WebDAV, and nothing else', async () => {
	const story = new Story()
	const browser = context()
	await freeze(browser, story, () => Date.parse('2026-10-24T20:00:05Z'))
	assert.deepEqual(browser.scripts, [{ content: story.initialStateScript() }])
	assert.equal(browser.routes.length, 2)

	const status = await browser.request('http://nextcloud/apps/paperless_sync/sync/status', { body: '{"lastEnded":1800000001}' })
	assert.deepEqual(JSON.parse(status.body), { lastEnded: AT_10_20 })
	assert.deepEqual(status.headers, { 'content-type': 'application/json' })
	assert.equal(status.status, 200)
	const ocs = await browser.request('http://nextcloud/ocs/v2.php/apps/paperless_sync/api/v1/status', { body: '{"ocs":{"data":{"lastSuccess":1800000001}}}' })
	assert.deepEqual(JSON.parse(ocs.body), { ocs: { data: { lastSuccess: AT_10_20 } } })
	const routed = await browser.request('http://nextcloud/index.php/apps/paperless_sync/settings/webhook', { method: 'POST', body: '{"secret":"abc"}' })
	assert.deepEqual(JSON.parse(routed.body), { secret: EXAMPLE_SECRET })
	// The note above the list of Files, by the clock of the browser.
	const note = await browser.request('http://nextcloud/index.php/apps/paperless_sync/api/v1/folder-info', { body: '{"folders":[],"lastSuccess":1800000001,"nextRun":null}' })
	const noon = Date.parse('2026-10-24T20:00:05Z') / 1000
	assert.deepEqual(JSON.parse(note.body), { folders: [], lastSuccess: noon - 300, nextRun: noon + 600 })
	// An answer that is no JSON passes as the server sent it, with its decoded body.
	const download = await browser.request('http://nextcloud/apps/paperless_sync/settings', { type: 'text/plain', body: 'plain' })
	assert.deepEqual(download.body, Buffer.from('plain'))

	const listing = await browser.request('http://nextcloud/remote.php/dav/files/paperless/Documents', { method: 'PROPFIND', type: 'application/xml; charset=utf-8', body: '<d:getlastmodified>Sat, 18 Oct 2026 08:21:13 GMT</d:getlastmodified>' })
	assert.equal(listing.body, '<d:getlastmodified>Sat, 24 Oct 2026 20:00:05 GMT</d:getlastmodified>')
	const binary = await browser.request('http://nextcloud/remote.php/dav/files/paperless/Documents', { method: 'SEARCH', type: 'application/octet-stream', body: 'x' })
	assert.deepEqual(binary.body, Buffer.from('x'))
	assert.equal((await browser.request('http://nextcloud/remote.php/dav/files/paperless/letter.pdf')).passed, 'fallback')

	// The scripts and styles of the app, its settings page, which a route can't answer, and the
	// other pages of Nextcloud go as they are.
	assert.equal((await browser.request('http://nextcloud/custom_apps/paperless_sync/js/settings.js')).passed, 'unrouted')
	assert.equal((await browser.request('http://nextcloud/index.php/settings/admin/paperless_sync')).passed, 'unrouted')
	assert.equal((await browser.request('http://nextcloud/index.php/settings/admin/workflow')).passed, 'unrouted')
	assert.equal((await browser.request('http://nextcloud/index.php/apps/files/files')).passed, 'unrouted')
})

test('a request whose page closed on the way needs no answer, and any other failure shows', async () => {
	const browser = context()
	await freeze(browser, new Story())
	const disposed = new Error('route.fetch: Request context disposed.')
	assert.deepEqual(await browser.request('http://nextcloud/apps/paperless_sync/settings/preview', { failure: disposed }), {})
	const target = Object.assign(new Error('route.fetch: closed'), { name: 'TargetClosedError' })
	assert.deepEqual(await browser.request('http://nextcloud/apps/paperless_sync/settings/preview', { failure: target }), {})
	await assert.rejects(browser.request('http://nextcloud/apps/paperless_sync/sync/status', { failure: new Error('ECONNREFUSED') }), /ECONNREFUSED/)
	assert.equal(closed(new Error('Target page, context or browser has been closed')), true)
	assert.equal(closed(undefined), false)
})

test('a connection that the web server closes just as it is used again takes another, as in a browser', async () => {
	// Apache closes a connection after 5 idle seconds. A request that reuses it in that moment
	// gets no answer, which a browser sends again on its own and route.fetch does not: a poll
	// of the status once ended the render of the pictures with "socket hang up"
	// (Dennis-Otto/paperless-sync#275).
	const browser = context()
	await freeze(browser, new Story(), () => Date.parse('2026-10-24T20:00:05Z'))
	const status = await browser.request('http://nextcloud/apps/paperless_sync/sync/status', { body: '{"lastEnded":1800000001}', resets: 1 })
	assert.deepEqual(JSON.parse(status.body), { lastEnded: AT_10_20 })
	const listing = await browser.request('http://nextcloud/remote.php/dav/files/paperless/Documents', { method: 'PROPFIND', type: 'application/xml', body: '<d:getlastmodified>Sat, 18 Oct 2026 08:21:13 GMT</d:getlastmodified>', resets: 1 })
	assert.equal(listing.body, '<d:getlastmodified>Sat, 24 Oct 2026 20:00:05 GMT</d:getlastmodified>')
	// A server that keeps closing them is a failure, which shows.
	await assert.rejects(browser.request('http://nextcloud/apps/paperless_sync/sync/status', { resets: 100 }), /ECONNRESET/)
})

test('every route.fetch of the checks in the browser takes another connection when one was closed', () => {
	const folder = new URL('./', import.meta.url)
	const scripts = readdirSync(folder).filter((name) => name.endsWith('.mjs') && !name.endsWith('.test.mjs'))
	const calls = scripts.flatMap((name) => [...readFileSync(new URL(name, folder), 'utf8').matchAll(/route\.fetch\(([^)]*)\)/g)]
		.map((call) => ({ name, options: call[1] })))
	assert.ok(calls.length > 0)
	for (const { name, options } of calls) {
		assert.match(options, /maxRetries/, `${name} calls route.fetch(${options}) without maxRetries.`)
	}
})

// A picture of a white page with black squares: [x, y, size].
function pictureWith(width, height, squares) {
	const data = new Uint8Array(width * height * 4).fill(255)
	for (const [x0, y0, size] of squares) {
		for (let y = y0; y < y0 + size; y++) {
			for (let x = x0; x < x0 + size; x++) {
				data.fill(0, (y * width + x) * 4, (y * width + x) * 4 + 3)
			}
		}
	}
	return { width, height, data }
}

const PANEL = { left: 8, top: 50, right: 92, bottom: 110 }

test('a picture whose panel has clean corners passes', () => {
	assert.equal(blackCorners(pictureWith(100, 120, []), PANEL), false)
})

test('a black corner of the panel is found, at each of its four corners', () => {
	for (const corner of [[8, 50], [86, 50], [8, 104], [86, 104]]) {
		assert.equal(blackCorners(pictureWith(100, 120, [[...corner, 6]]), PANEL), true, `the corner at ${corner}`)
	}
})

test('a box with a fraction of a pixel is rounded to the pixels of the picture', () => {
	assert.equal(blackCorners(pictureWith(100, 120, [[86, 50, 6]]), { ...PANEL, right: 91.6, top: 50.4 }), true)
})

test('a few black pixels in a corner are a part of the page, and black in its middle counts for nothing', () => {
	assert.equal(blackCorners(pictureWith(100, 120, [[8, 104, 2]]), PANEL), false)
	assert.equal(blackCorners(pictureWith(100, 120, [[40, 70, 20]]), PANEL), false)
})

test('a box that reaches over the picture looks only at the pixels that the picture has', () => {
	assert.equal(blackCorners(pictureWith(100, 120, []), { left: -8, top: -8, right: 108, bottom: 128 }), false)
	assert.equal(blackCorners(pictureWith(100, 120, [[0, 0, 6]]), { left: -8, top: -8, right: 108, bottom: 128 }), true)
})

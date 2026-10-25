// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The browser of the checks in the browser, browser.mjs, which scripts/check-project.sh runs
// with node --test: with a browser of its own in place of Chromium, which writes what
// Playwright would write, it records a trace of every window, keeps the trace and the pages
// of the windows that are there when a check fails, also of one that the check closed in
// finally, and keeps nothing of a check that passes.

import assert from 'node:assert/strict'
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, test } from 'node:test'
import { appScript, coverageSwitches, launch } from './browser.mjs'

let folder
let errors

beforeEach(() => {
	folder = join(mkdtempSync(join(tmpdir(), 'browser-')), 'artifacts')
	errors = []
	console.error = (line) => errors.push(line)
})

afterEach(() => {
	rmSync(join(folder, '..'), { recursive: true, force: true })
})

// A browser that does what the checks ask of Chromium, and writes a file where Playwright
// would: the screenshot of a page and the zip of a trace. Its pages record coverage as
// Chromium does: scripts(address) gives the entries of V8 of the page at that address,
// which stopJSCoverage() returns once its startJSCoverage() is done, or throws, as for a
// page that crashed, when it gives an error.
function fakeType({ screenshot = true, scripts = defaultScripts } = {}) {
	const log = []
	const contexts = []
	const type = {
		log,
		async launch(options) {
			type.options = options
			return {
				contexts: () => [...contexts],
				async newContext(options = {}) {
					const pages = []
					const listeners = []
					let tracing = false
					log.push(`open ${options.name ?? 'window'}`)
					function open(address) {
						let recording = false
						const page = {
							url: () => address,
							async screenshot({ path, fullPage }) {
								if (!screenshot) {
									throw new Error('Target page, context or browser has been closed\nCall log:\n  - taking page screenshot')
								}
								assert.equal(fullPage, true)
								writeFileSync(path, 'png')
							},
							coverage: {
								async startJSCoverage({ resetOnNavigation }) {
									await new Promise((resolve) => setTimeout(resolve, 1))
									recording = true
									log.push(`record ${address}: resetOnNavigation ${resetOnNavigation}`)
								},
								async stopJSCoverage() {
									assert.ok(recording, `${address} stops a coverage that it never started`)
									recording = false
									log.push(`stop ${address}`)
									const entries = scripts(address)
									if (entries instanceof Error) {
										throw entries
									}
									return entries
								},
							},
							async close() {
								pages.splice(pages.indexOf(page), 1)
								log.push(`close ${address}`)
							},
						}
						pages.push(page)
						for (const listener of listeners) {
							listener(page)
						}
						return page
					}
					const context = {
						tracing: {
							async start(settings) {
								tracing = true
								log.push(`trace ${settings.title}: ${settings.screenshots} ${settings.snapshots}`)
							},
							async stop(settings = {}) {
								if (!tracing) {
									throw new Error('Must start tracing before stopping')
								}
								tracing = false
								if (settings.path) {
									writeFileSync(settings.path, 'zip')
								}
								log.push(settings.path ? `save ${settings.path.slice(folder.length + 1)}` : 'discard')
							},
						},
						pages: () => [...pages],
						on(event, listener) {
							assert.equal(event, 'page')
							listeners.push(listener)
						},
						async newPage() {
							return open(`http://nextcloud/${options.name ?? 'window'}/${pages.length + 1}`)
						},
						// A page that the site opens, such as the tab of a link with target _blank.
						popup(address) {
							return open(address)
						},
						async close() {
							contexts.splice(contexts.indexOf(context), 1)
							log.push(`close ${options.name ?? 'window'}`)
						},
					}
					contexts.push(context)
					return context
				},
				async newPage(options) {
					const context = await this.newContext(options)
					return context.newPage()
				},
				async close() {
					log.push('close the browser')
				},
			}
		},
	}
	return type
}

// What V8 counts in a page of the settings: a script of the app, served from custom_apps
// with its version, and one of Nextcloud, which the coverage leaves out.
function defaultScripts(address) {
	return [
		{
			url: 'http://nextcloud/custom_apps/paperless_sync/js/settings.js?v=0c1e5a',
			scriptId: '17',
			source: '(function() {})()\n',
			functions: [{ functionName: '', isBlockCoverage: true, ranges: [{ startOffset: 0, endOffset: 18, count: 1 }] }],
		},
		{ url: 'http://nextcloud/core/js/main.js?v=1', scriptId: '3', source: 'OC = {}\n', functions: [] },
		{ url: `${address}#inline`, scriptId: '4', functions: [] },
	]
}

test('a check that passes keeps nothing, and the trace of each window ends unsaved', async () => {
	const type = fakeType()
	const browser = await launch('files', { folder, type })
	const first = await browser.newContext({ name: 'admin' })
	await first.newPage()
	await first.close()
	// The window that the check closed stays until it opens the next one.
	assert.deepEqual(type.log, ['open admin', 'trace files.mjs, window 1: true true'])
	const second = await browser.newContext({ name: 'other' })
	await second.close()
	await browser.close()
	assert.deepEqual(type.log, [
		'open admin',
		'trace files.mjs, window 1: true true',
		'discard',
		'close admin',
		'open other',
		'trace files.mjs, window 2: true true',
		'close the browser',
	])
	assert.equal(existsSync(folder), false)
	assert.deepEqual(errors, [])
})

test('a check that fails keeps the trace and the pages of the windows that are there, also of one that it closed', async () => {
	const type = fakeType()
	const browser = await launch('settings', { folder, type })
	const passed = await browser.newContext({ name: 'signed-in' })
	await passed.close()
	await browser.newPage({ name: 'settings' })
	const popup = await browser.newContext({ name: 'popup' })
	await popup.newPage()
	await popup.newPage()
	// The check closes the popup in finally, before the failure reaches its end.
	await popup.close()
	const error = new Error('locator.waitFor: Timeout 60000ms exceeded.\nCall log:\n  - waiting for locator(\'[data-cy-files-list-row-name="receipt.pdf"]\') to be visible')
	const kept = await browser.keep(error)
	assert.deepEqual(kept, ['settings-2-1.png', 'settings-2.trace.zip', 'settings-3-1.png', 'settings-3-2.png', 'settings-3.trace.zip'])
	assert.deepEqual(readdirSync(folder).sort(), [...kept].sort())
	assert.equal(readFileSync(join(folder, 'settings-3.trace.zip'), 'utf8'), 'zip')
	assert.deepEqual(errors, [
		'The check in the browser settings.mjs fails: locator.waitFor: Timeout 60000ms exceeded.',
		'Kept for the artifact of the job: settings-2-1.png, settings-2.trace.zip, settings-3-1.png, settings-3-2.png, settings-3.trace.zip.',
	])
	// A second failure keeps only what is new, and the windows that are kept close without a trace.
	await browser.newContext({ name: 'later' })
	assert.deepEqual(await browser.keep('2 check(s) by keyboard alone fail'), ['settings-4.trace.zip'])
	assert.deepEqual(errors.slice(2), ['The check in the browser settings.mjs fails: 2 check(s) by keyboard alone fail', 'Kept for the artifact of the job: settings-4.trace.zip.'])
	assert.equal(type.log.filter((line) => line === 'discard').length, 1)
	assert.ok(type.log.includes('close popup'))
})

test('a page without a screenshot leaves the trace of its window', async () => {
	const browser = await launch('flow', { folder, type: fakeType({ screenshot: false }) })
	const context = await browser.newContext({ name: 'flow' })
	await context.newPage()
	assert.deepEqual(await browser.keep(new Error('Saving the rule keeps its three tags: []')), ['flow-1.trace.zip'])
	assert.deepEqual(errors, [
		'No screenshot of http://nextcloud/flow/1: Target page, context or browser has been closed',
		'The check in the browser flow.mjs fails: Saving the rule keeps its three tags: []',
		'Kept for the artifact of the job: flow-1.trace.zip.',
	])
})

test('a trace that cannot be saved is named, and the failure still is', async () => {
	const browser = await launch('keyboard', { folder, type: fakeType() })
	const context = await browser.newContext({ name: 'keyboard' })
	await context.tracing.stop()
	assert.deepEqual(await browser.keep(new Error('\n\n')), [])
	assert.deepEqual(errors, [
		'No trace of the window 1 of keyboard.mjs: Must start tracing before stopping',
		'The check in the browser keyboard.mjs fails: an error without a message',
	])
})

test('without a folder the browser records nothing and only names the failure', async () => {
	const type = fakeType()
	const browser = await launch('accessibility', { folder: '', type })
	const context = await browser.newContext({ name: 'check' })
	await context.newPage()
	await context.close()
	assert.deepEqual(await browser.keep('3 serious or critical violation(s)'), [])
	assert.deepEqual(type.log, ['open check', 'close check'])
	assert.deepEqual(errors, ['The check in the browser accessibility.mjs fails: 3 serious or critical violation(s)'])
})

test('every check in the browser starts its browser here, and keeps what it has before it closes it', () => {
	for (const check of ['accessibility', 'keyboard', 'settings', 'files', 'flow']) {
		const source = readFileSync(new URL(`./${check}.mjs`, import.meta.url), 'utf8')
		assert.match(source, new RegExp(`const browser = await launch\\('${check}'\\)`), `${check}.mjs starts Chromium itself`)
		assert.doesNotMatch(source, /chromium\.launch\(/, `${check}.mjs starts Chromium itself`)
		assert.match(source, /\} catch \(error\) \{\n\tawait browser\.keep\(error\)\n\tthrow error\n\} finally \{\n\tawait browser\.close\(\)/, `${check}.mjs keeps nothing when it fails`)
	}
})

test('appScript names the scripts of the app by their path in the repository, and no other script', () => {
	assert.equal(appScript('http://nextcloud/custom_apps/paperless_sync/js/settings.js?v=0c1e5a'), 'js/settings.js')
	assert.equal(appScript('https://cloud.example/apps/paperless_sync/js/files.js'), 'js/files.js')
	assert.equal(appScript('http://nextcloud/index.php/apps/paperless_sync/js/flow.js#start'), 'js/flow.js')
	for (const other of ['http://nextcloud/core/js/main.js', 'http://nextcloud/custom_apps/paperless_sync/l10n/de.js', 'http://nextcloud/custom_apps/other_app/js/settings.js', 'http://nextcloud/custom_apps/paperless_sync/js/settings.json', '', undefined]) {
		assert.equal(appScript(other), null, other)
	}
})

test('with a folder for the coverage, each page records from before the check gets it until it closes, and the scripts of the app reach <check>.json', async () => {
	const coverage = join(folder, '..', 'coverage')
	const type = fakeType()
	const browser = await launch('files', { folder: '', coverage, type })
	const page = await browser.newPage({ name: 'admin' })
	// The page records before the check gets it, so that it counts the first script too.
	assert.deepEqual(type.log, ['open admin', 'record http://nextcloud/admin/1: resetOnNavigation false'])
	const other = await browser.newContext({ name: 'other' })
	const early = await other.newPage()
	const popup = other.popup('http://paperless/documents/7/details')
	await other.newPage()
	// A page that the check closes counts, also when its window closes later.
	await early.close()
	await other.close()
	await page.close()
	await browser.close()
	const log = type.log.filter((line) => !line.startsWith('open '))
	for (const address of ['http://nextcloud/admin/1', 'http://nextcloud/other/1', popup.url(), 'http://nextcloud/other/3']) {
		assert.equal(log.filter((line) => line === `stop ${address}`).length, 1, `${address} stops once`)
		assert.ok(log.indexOf(`record ${address}: resetOnNavigation false`) < log.indexOf(`stop ${address}`), address)
	}
	assert.ok(log.indexOf('stop http://nextcloud/other/1') < log.indexOf('close http://nextcloud/other/1'))
	assert.ok(log.indexOf('stop http://nextcloud/other/3') < log.indexOf('close other'))
	assert.equal(log.at(-1), 'close the browser')
	const entries = JSON.parse(readFileSync(join(coverage, 'files.json'), 'utf8'))
	assert.equal(entries.length, 4)
	assert.deepEqual(entries[0], {
		file: 'js/settings.js',
		url: 'http://nextcloud/custom_apps/paperless_sync/js/settings.js?v=0c1e5a',
		source: '(function() {})()\n',
		functions: [{ functionName: '', isBlockCoverage: true, ranges: [{ startOffset: 0, endOffset: 18, count: 1 }] }],
	})
	assert.ok(entries.every((entry) => entry.file === 'js/settings.js'))
	assert.deepEqual(errors, [])
})

test('the coverage counts the windows that the traces keep open, and a second browser of a check writes a file of its own', async () => {
	const coverage = join(folder, '..', 'coverage')
	for (const round of [1, 2]) {
		const type = fakeType()
		const browser = await launch('settings', { folder, coverage, type })
		const context = await browser.newContext({ name: `round ${round}` })
		await context.newPage()
		// The traces keep the window that the check closed until the browser closes.
		await context.close()
		assert.ok(!type.log.includes(`stop http://nextcloud/round ${round}/1`))
		await browser.close()
		assert.ok(type.log.includes(`stop http://nextcloud/round ${round}/1`))
	}
	assert.deepEqual(readdirSync(coverage).sort(), ['settings-2.json', 'settings.json'])
	assert.equal(JSON.parse(readFileSync(join(coverage, 'settings-2.json'), 'utf8')).length, 1)
})

test('a page whose coverage cannot be read is named, and the coverage of the others still counts', async () => {
	const coverage = join(folder, '..', 'coverage')
	const scripts = (address) => (address.endsWith('/1') ? new Error('Target crashed\nCall log:\n  - stopping coverage') : defaultScripts(address))
	const browser = await launch('flow', { folder: '', coverage, type: fakeType({ scripts }) })
	const context = await browser.newContext({ name: 'flow' })
	await context.newPage()
	await context.newPage()
	await browser.close()
	assert.deepEqual(errors, ['No coverage of http://nextcloud/flow/1: Target crashed'])
	assert.equal(JSON.parse(readFileSync(join(coverage, 'flow.json'), 'utf8')).length, 1)
})

test('without a folder for the coverage, no page records it', async () => {
	const type = fakeType()
	const browser = await launch('keyboard', { folder: '', coverage: '', type })
	const context = await browser.newContext({ name: 'keyboard' })
	await context.newPage()
	await browser.close()
	assert.deepEqual(type.log, ['open keyboard', 'close the browser'])
})

test('Chromium keeps the frame of a page across its documents for the coverage, with the feature of Playwright for its screenshots', async () => {
	assert.deepEqual(coverageSwitches({}), ['--enable-features=CDPScreenshotNewSurface,RenderDocument:level/crashed-frame'])
	assert.deepEqual(coverageSwitches({ PLAYWRIGHT_LEGACY_SCREENSHOT: '1' }), ['--enable-features=RenderDocument:level/crashed-frame'])
	const measured = fakeType()
	await launch('settings', { folder: '', coverage: join(folder, '..', 'coverage'), type: measured })
	assert.deepEqual(measured.options, { args: coverageSwitches() })
	const plain = fakeType()
	await launch('settings', { folder, coverage: '', type: plain })
	assert.deepEqual(plain.options, {})
})

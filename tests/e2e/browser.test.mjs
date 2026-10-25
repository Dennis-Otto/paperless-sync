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
import { launch } from './browser.mjs'

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
// would: the screenshot of a page and the zip of a trace.
function fakeType({ screenshot = true } = {}) {
	const log = []
	const type = {
		log,
		async launch() {
			return {
				async newContext(options = {}) {
					const pages = []
					let tracing = false
					log.push(`open ${options.name ?? 'window'}`)
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
						pages: () => pages,
						async newPage() {
							const address = `http://nextcloud/${options.name ?? 'window'}/${pages.length + 1}`
							const page = {
								url: () => address,
								async screenshot({ path, fullPage }) {
									if (!screenshot) {
										throw new Error('Target page, context or browser has been closed\nCall log:\n  - taking page screenshot')
									}
									assert.equal(fullPage, true)
									writeFileSync(path, 'png')
								},
							}
							pages.push(page)
							return page
						},
						async close() {
							log.push(`close ${options.name ?? 'window'}`)
						},
					}
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

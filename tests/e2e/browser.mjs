// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The browser of the checks in the browser, accessibility.mjs, keyboard.mjs, settings.mjs,
// files.mjs and flow.mjs, which keeps what it takes to understand a failure without running
// the suite again. In the folder of E2E_BROWSER_ARTIFACTS, which run.sh sets, each window of
// a check, a context of Playwright, records a trace from its start: every action with a
// screenshot of the page, its requests and what the page logged. With E2E_BROWSER_SNAPSHOTS=1,
// which run.sh sets unless it is the first attempt of a job of the CI, each action also keeps
// a snapshot of the page, which takes about a fifth of the time of the checks. When the
// check fails, keep() saves the trace of each window that is still there as
// <check>-<window>.trace.zip, which `npx playwright show-trace` and trace.playwright.dev
// open, and a screenshot of the whole of each of its pages as <check>-<window>-<page>.png.
// It also prints the line "The check in the browser <check>.mjs fails: <error>", which
// failure.py takes as the first error of the run, and the files that it kept. On success nothing is kept, and without
// the folder nothing is recorded.
//
// A check closes its windows in finally, which runs before its failure reaches the end of
// the check, so a window that the check closes stays until the check opens the next one or
// ends: its trace and its pages are still there when keep() comes.
//
// In the folder of E2E_BROWSER_COVERAGE, which run.sh sets on the newest Nextcloud, each
// page records with the coverage of V8 in Chromium which parts of the JavaScript of the app
// run in it, from its start and across its navigations. When the check closes the page, its
// window or the browser, the coverage of the scripts of the app, js/*.js, goes to
// <check>.json, which coverage.mjs turns into the lines and branches that the checks run.
// browser.test.mjs checks it all with a browser of its own, without Chromium.

import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

// A script of the app as Nextcloud serves it, from apps/ or custom_apps/ and with ?v=…, such
// as /custom_apps/paperless_sync/js/settings.js?v=0c1e5a…, and its path in the repository.
const APP_SCRIPT = /\/(?:custom_)?apps\/paperless_sync\/(js\/[^/?#]+\.js)(?:[?#]|$)/

// The path of a script of the app in the repository, such as js/settings.js, or null for any
// other script of the page.
export function appScript(url) {
	return APP_SCRIPT.exec(url ?? '')?.[1] ?? null
}

// The switches of Chromium for the coverage. Chromium gives each document that a page goes to
// a frame of its own (RenderDocument), and the coverage of V8 ends with the frame, so a check
// that loads its page again, as settings.mjs does, would count only its last document. With
// RenderDocument for a crashed frame alone, as Chromium had it before, the page keeps its frame
// and its coverage counts every document. A second --enable-features replaces the one that
// Playwright sets, so this one names that feature of Playwright as well.
export function coverageSwitches(environment = process.env) {
	const features = environment.PLAYWRIGHT_LEGACY_SCREENSHOT ? [] : ['CDPScreenshotNewSurface']
	return [`--enable-features=${[...features, 'RenderDocument:level/crashed-frame'].join(',')}`]
}

// The first line of an error or a reason, without the call log that Playwright adds.
function firstLine(reason) {
	const text = reason instanceof Error ? reason.message : String(reason)
	return text.split('\n').find((line) => line.trim() !== '')?.trim() ?? 'an error without a message'
}

// Starts Chromium for the check of that name, such as files for files.mjs. The browser is
// the one of Playwright, with keep(reason) added, which saves what the check leaves and
// names the failure; the check calls it before it closes the browser.
export async function launch(check, { folder = process.env.E2E_BROWSER_ARTIFACTS ?? '', coverage = process.env.E2E_BROWSER_COVERAGE ?? '', snapshots = process.env.E2E_BROWSER_SNAPSHOTS === '1', type } = {}) {
	const browserType = type ?? (await import('playwright-core')).chromium
	const browser = await browserType.launch(coverage ? { args: coverageSwitches() } : {})
	if (coverage) {
		measure(browser, check, coverage)
	}
	const windows = []
	let opened = 0

	// Ends the windows that the check closed: they passed, so their traces go unsaved.
	async function closeClosed() {
		for (const window of windows.filter((each) => each.closed)) {
			windows.splice(windows.indexOf(window), 1)
			if (!window.kept) {
				await window.context.tracing.stop().catch(() => {})
			}
			await window.close()
		}
	}

	browser.keep = async (reason) => {
		const kept = []
		if (folder) {
			mkdirSync(folder, { recursive: true })
		}
		for (const window of windows.filter((each) => !each.kept)) {
			window.kept = true
			const name = `${check}-${window.number}`
			for (const [index, page] of window.context.pages().entries()) {
				const file = `${name}-${index + 1}.png`
				try {
					await page.screenshot({ path: join(folder, file), fullPage: true, timeout: 10_000 })
					kept.push(file)
				} catch (error) {
					console.error(`No screenshot of ${page.url()}: ${firstLine(error)}`)
				}
			}
			try {
				await window.context.tracing.stop({ path: join(folder, `${name}.trace.zip`) })
				kept.push(`${name}.trace.zip`)
			} catch (error) {
				console.error(`No trace of the window ${window.number} of ${check}.mjs: ${firstLine(error)}`)
			}
		}
		console.error(`The check in the browser ${check}.mjs fails: ${firstLine(reason)}`)
		if (kept.length > 0) {
			console.error(`Kept for the artifact of the job: ${kept.join(', ')}.`)
		}
		return kept
	}

	if (!folder) {
		return browser
	}
	const newContext = browser.newContext.bind(browser)
	// browser.newPage() opens its window through this one as well.
	browser.newContext = async (options) => {
		await closeClosed()
		const context = await newContext(options)
		opened += 1
		const window = { number: opened, context, close: context.close.bind(context), closed: false, kept: false }
		await context.tracing.start({ screenshots: true, snapshots, title: `${check}.mjs, window ${opened}` })
		context.close = async () => {
			window.closed = true
		}
		windows.push(window)
		return context
	}
	return browser
}

// Records the coverage of each page of the browser and writes that of the scripts of the app
// to <check>.json in the folder when the browser closes: the entries of V8, each with the
// path of its script in the repository, its source and the counts of its functions and
// blocks. A page starts recording before the check gets it, and one that the site opens, such
// as a popup, as soon as it is there; each one stops when it, its window or the browser
// closes, so that the coverage of a page that the check closed early counts as well.
function measure(browser, check, folder) {
	const started = new WeakMap()
	const watched = new WeakSet()
	const stopped = new WeakSet()
	const entries = []

	function start(page) {
		if (!started.has(page)) {
			started.set(page, page.coverage.startJSCoverage({ resetOnNavigation: false }).catch((error) => {
				console.error(`No coverage of ${page.url()}: ${firstLine(error)}`)
			}))
		}
		return started.get(page)
	}

	async function stop(page) {
		if (!started.has(page) || stopped.has(page)) {
			return
		}
		stopped.add(page)
		await started.get(page)
		try {
			for (const entry of await page.coverage.stopJSCoverage()) {
				const file = appScript(entry.url)
				if (file !== null) {
					entries.push({ file, url: entry.url, source: entry.source ?? '', functions: entry.functions })
				}
			}
		} catch (error) {
			console.error(`No coverage of ${page.url()}: ${firstLine(error)}`)
		}
	}

	function watch(page) {
		start(page)
		if (watched.has(page)) {
			return page
		}
		watched.add(page)
		const close = page.close.bind(page)
		page.close = async (options) => {
			await stop(page)
			return close(options)
		}
		return page
	}

	const newContext = browser.newContext.bind(browser)
	// browser.newPage() opens its window through this one as well.
	browser.newContext = async (options) => {
		const context = await newContext(options)
		context.on('page', watch)
		const newPage = context.newPage.bind(context)
		context.newPage = async () => {
			const page = watch(await newPage())
			await start(page)
			return page
		}
		const closeContext = context.close.bind(context)
		context.close = async (options) => {
			for (const page of context.pages()) {
				await stop(page)
			}
			return closeContext(options)
		}
		return context
	}

	const close = browser.close.bind(browser)
	browser.close = async (options) => {
		for (const context of browser.contexts()) {
			for (const page of context.pages()) {
				await stop(page)
			}
		}
		mkdirSync(folder, { recursive: true })
		let name = `${check}.json`
		for (let number = 2; existsSync(join(folder, name)); number++) {
			name = `${check}-${number}.json`
		}
		writeFileSync(join(folder, name), JSON.stringify(entries))
		return close(options)
	}
}

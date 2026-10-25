// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The browser of the checks in the browser, accessibility.mjs, keyboard.mjs, settings.mjs,
// files.mjs and flow.mjs, which keeps what it takes to understand a failure without running
// the suite again. In the folder of E2E_BROWSER_ARTIFACTS, which run.sh sets, each window of
// a check, a context of Playwright, records a trace from its start: every action with a
// screenshot and a snapshot of the page, its requests and what the page logged. When the
// check fails, keep() saves the trace of each window that is still there as
// <check>-<window>.trace.zip, which `npx playwright show-trace` and trace.playwright.dev
// open, and a screenshot of the whole of each of its pages as <check>-<window>-<page>.png.
// It also prints the line "The check in the browser <check>.mjs fails: <error>", which
// failure.py takes as the first error of the run, and the files that it kept. On success nothing is kept, and without
// the folder nothing is recorded.
//
// A check closes its windows in finally, which runs before its failure reaches the end of
// the check, so a window that the check closes stays until the check opens the next one or
// ends: its trace and its pages are still there when keep() comes. browser.test.mjs checks
// it all with a browser of its own, without Chromium.

import { mkdirSync } from 'node:fs'
import { join } from 'node:path'

// The first line of an error or a reason, without the call log that Playwright adds.
function firstLine(reason) {
	const text = reason instanceof Error ? reason.message : String(reason)
	return text.split('\n').find((line) => line.trim() !== '')?.trim() ?? 'an error without a message'
}

// Starts Chromium for the check of that name, such as files for files.mjs. The browser is
// the one of Playwright, with keep(reason) added, which saves what the check leaves and
// names the failure; the check calls it before it closes the browser.
export async function launch(check, { folder = process.env.E2E_BROWSER_ARTIFACTS ?? '', type } = {}) {
	const browserType = type ?? (await import('playwright-core')).chromium
	const browser = await browserType.launch()
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
		await context.tracing.start({ screenshots: true, snapshots: true, title: `${check}.mjs, window ${opened}` })
		context.close = async () => {
			window.closed = true
		}
		windows.push(window)
		return context
	}
	return browser
}

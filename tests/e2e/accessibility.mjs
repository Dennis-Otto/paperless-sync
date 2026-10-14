// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// axe-core checks the pages of the app in Chromium against WCAG 2.1 at levels A and
// AA, in the light and the dark theme of Nextcloud. It looks only into the element
// that holds the app's own markup, so what Nextcloud draws around it doesn't count.
// A serious or critical violation fails the check; the others are listed. run.sh
// starts it in the image of Playwright, inside the network of the Compose project,
// after npm ci of package-lock.json.

import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { chromium } from 'playwright-core'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
// The synthetic token of an account of the mock that may neither view the tags nor add documents.
const VIEW_ONLY_TOKEN = process.env.E2E_VIEW_ONLY_TOKEN ?? 'e2e-view-only-token'
const AXE = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8')
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
const FAILING = new Set(['serious', 'critical'])
const THEMES = ['light', 'dark']

const FAILED_STATUS = {
	lastStarted: 1791880000,
	lastCompleted: 1791876400,
	lastEnded: 1791880060,
	lastSuccess: 1791876400,
	state: 'failed',
	summary: {},
	error: 'Paperless could not list documents: HTTP 502.',
	report: {
		dryRun: false,
		completedAt: 0,
		errors: 51,
		omittedActions: 3,
		errorMessages: ['P123: Not enough free space in Nextcloud for Documents/Paperless/Archive/invoice [P123].pdf.'],
		actions: ['ERROR: P123: Not enough free space in Nextcloud for Documents/Paperless/Archive/invoice [P123].pdf.'],
	},
	nextRun: null,
}

// The pages of the app, each with the element that holds its markup and what to do
// before the check, such as opening a section or showing a result.
const PAGES = [
	{
		name: 'administration settings',
		path: '/index.php/settings/admin/paperless_sync',
		scope: '#paperless-sync-settings',
		async prepare(page) {
			await page.locator('.paperless-sync-advanced summary').click()
		},
	},
	{
		// The dry-run over the larger archive of the mock lists more changes than the
		// report shows at once, so the list scrolls.
		name: 'report of a dry-run',
		path: '/index.php/settings/admin/paperless_sync',
		scope: '#paperless-sync-settings',
		async prepare(page) {
			await page.locator('#paperless-sync-dry-run').click()
			await page.locator('#paperless-sync-report').waitFor({ state: 'visible' })
			await page.locator('#paperless-sync-dry-run:enabled').waitFor()
		},
	},
	{
		// The status of a run that failed after errors of single documents, which no run of
		// the mock leaves: the page gets it as the answer to Refresh.
		name: 'status of a failed run',
		path: '/index.php/settings/admin/paperless_sync',
		scope: '#paperless-sync-settings',
		async prepare(page) {
			await page.route('**/apps/paperless_sync/sync/status', (route) => route.fulfill({ json: FAILED_STATUS }))
			await page.locator('#paperless-sync-refresh-status').click()
			await page.locator('#paperless-sync-errors').waitFor({ state: 'visible' })
		},
	},
	{
		// The connection checklist with lines that passed, failed and have a note: Test
		// connection with the token of an account with too few permissions.
		name: 'connection checklist',
		path: '/index.php/settings/admin/paperless_sync',
		scope: '#paperless-sync-settings',
		async prepare(page) {
			await page.locator('#paperless-sync-token').fill(VIEW_ONLY_TOKEN)
			await page.locator('#paperless-sync-test').click()
			await page.locator('.paperless-sync-check--failed').first().waitFor()
			await page.locator('#paperless-sync-test:enabled').waitFor()
		},
	},
	{
		// The note about unsaved changes and an error next to the buttons, here the answer
		// of Nextcloud to a session that ended.
		name: 'unsaved changes and an error',
		path: '/index.php/settings/admin/paperless_sync',
		scope: '#paperless-sync-settings',
		async prepare(page) {
			await page.locator('[data-setting="batch_size"]').fill('7')
			await page.locator('#paperless-sync-unsaved').waitFor({ state: 'visible' })
			await page.route('**/apps/paperless_sync/sync/status', (route) => route.fulfill({ status: 412, json: { message: 'CSRF check failed' } }))
			await page.locator('#paperless-sync-refresh-status').click()
			await page.locator('#paperless-sync-message.paperless-sync-message--error').waitFor()
		},
	},
]

// Signs in through the login form, as a person does, and keeps the session for every
// page and theme.
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

async function check(browser, session, target, theme) {
	const context = await browser.newContext({
		storageState: session,
		colorScheme: theme,
		viewport: { width: 1280, height: 900 },
	})
	try {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}${target.path}`)
		await page.locator(target.scope).waitFor()
		await target.prepare(page)
		await page.waitForLoadState('networkidle')
		await page.evaluate(AXE)
		return await page.evaluate(
			({ scope, tags }) => window.axe.run(scope, {
				runOnly: { type: 'tag', values: tags },
				resultTypes: ['violations'],
			}),
			{ scope: target.scope, tags: WCAG_TAGS },
		)
	} finally {
		await context.close()
	}
}

const browser = await chromium.launch()
let failures = 0
try {
	const session = await signIn(browser)
	for (const target of PAGES) {
		for (const theme of THEMES) {
			const { violations, passes } = await check(browser, session, target, theme)
			const failing = violations.filter((violation) => FAILING.has(violation.impact))
			failures += failing.length
			console.log(`axe-core, ${target.name}, ${theme} theme: ${passes.length} rule(s) passed, ${violations.length} violated, ${failing.length} of them serious or critical`)
			for (const violation of violations) {
				console.log(`  ${violation.impact} ${violation.id}: ${violation.help} (${violation.helpUrl})`)
				for (const node of violation.nodes) {
					console.log(`    ${node.target.join(' ')}`)
					console.log(`      ${node.html}`)
					console.log(`      ${node.failureSummary.replaceAll('\n', '\n      ')}`)
				}
			}
		}
	}
} finally {
	await browser.close()
}

if (failures > 0) {
	console.error(`axe-core found ${failures} serious or critical violation(s) of WCAG 2.1 AA in the app.`)
	process.exit(1)
}
console.log('axe-core found no serious or critical violation of WCAG 2.1 AA in the app.')

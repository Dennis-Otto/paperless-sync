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

// The status of a run whose report has a change of every kind, with their badges, which
// no single run of the mock makes: the page gets it as the answer to Refresh.
const EVERY_KIND = [
	['ERROR: P7: Not enough free space', { type: 'error', document: 7, detail: 'P7: Not enough free space' }],
	['IMPORT ERROR: Documents/Paperless/Inbox/broken.pdf -> Documents/Paperless/Failed/broken.pdf', { type: 'import-error', path: 'Documents/Paperless/Inbox/broken.pdf', target: 'Documents/Paperless/Failed/broken.pdf', detail: 'Unsupported mime type' }],
	['IMPORT REJECTED: Documents/Paperless/Inbox/notes.bin: File type not supported', { type: 'import-rejected', path: 'Documents/Paperless/Inbox/notes.bin', detail: 'File type not supported' }],
	['EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.', { type: 'export-stopped' }],
	['TAGS UNKNOWN: Paperless shows the account no tag named 2026, so the files of the inbox go to Paperless without it. Create the tags in Paperless or share them with the account, or turn on "Create missing tags".', { type: 'tags-unknown', values: { names: '2026', count: 1 } }],
	['NEW TAG: Insurance', { type: 'new-tag', detail: 'Insurance' }],
	['IMPORT: Documents/Paperless/Inbox/Insurance/policy.pdf (tags: Insurance)', { type: 'import', path: 'Documents/Paperless/Inbox/Insurance/policy.pdf', values: { tags: 'Insurance' } }],
	['EXPORT P1: Documents/Paperless/Archive/invoice.pdf', { type: 'export', document: 1, path: 'Documents/Paperless/Archive/invoice.pdf' }],
	['MOVE P2: Documents/Paperless/Archive/letter.pdf', { type: 'move', document: 2, path: 'Documents/Paperless/Archive/letter.pdf' }],
	['TRASH P3: Documents/Paperless/Deleted/old.pdf', { type: 'trash', document: 3, path: 'Documents/Paperless/Deleted/old.pdf' }],
	['DELETE P4: Documents/Paperless/Deleted/older.pdf', { type: 'delete', document: 4, path: 'Documents/Paperless/Deleted/older.pdf' }],
	['WAIT P5: missing run 1/3', { type: 'wait', document: 5, path: 'Documents/Paperless/Archive/gone.pdf', values: { scans: 1, limit: 3 } }],
	['IMPORT SUCCESS: Documents/Paperless/Inbox/scan.pdf -> P456', { type: 'import-success', path: 'Documents/Paperless/Inbox/scan.pdf', document: 456 }],
]
const REPORT_STATUS = {
	...FAILED_STATUS,
	state: 'completed-with-errors',
	error: '',
	report: {
		dryRun: false,
		completedAt: 1791880060,
		errors: 1,
		exported: 1,
		moved: 1,
		movedToTrash: 1,
		permanentlyDeleted: 1,
		importsSubmitted: 1,
		importsSucceeded: 1,
		importsFailed: 2,
		unchanged: 11,
		skipped: 1,
		omittedActions: 0,
		errorMessages: ['P7: Not enough free space'],
		actions: EVERY_KIND.map(([line]) => line),
		entries: EVERY_KIND.map(([, entry]) => entry),
	},
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
		// A report with a change of every kind, its badges, its counts and its filters, one of
		// them on.
		name: 'report with every kind of change',
		path: '/index.php/settings/admin/paperless_sync',
		scope: '#paperless-sync-settings',
		async prepare(page) {
			await page.route('**/apps/paperless_sync/sync/status', (route) => route.fulfill({ json: REPORT_STATUS }))
			await page.locator('#paperless-sync-refresh-status').click()
			await page.locator('#paperless-sync-report-filters button[data-filter="error"]').click()
			await page.locator('#paperless-sync-report-filters button[aria-pressed="true"][data-filter="error"]').waitFor()
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
	{
		// The pickers: a target user that Nextcloud doesn't know, a tag that Paperless doesn't
		// have, and the open list of the tags of Paperless, with the option that the down arrow
		// reached.
		name: 'user and tag pickers',
		path: '/index.php/settings/admin/paperless_sync',
		scope: '#paperless-sync-settings',
		async prepare(page) {
			const user = page.locator('#paperless-sync-target-user')
			await user.fill('nobody-here')
			await user.press('Tab')
			await page.locator('#paperless-sync-target-user-hint:not(:empty)').waitFor()
			const tags = page.locator('#paperless-sync-excluded-tags-input')
			await tags.fill('Drfat')
			await tags.press('Enter')
			await page.locator('#paperless-sync-excluded-tags-list .paperless-sync-chip--new').waitFor()
			await tags.fill('a')
			await tags.press('ArrowDown')
			await page.locator('#paperless-sync-excluded-tags-options [aria-selected="true"]').waitFor()
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

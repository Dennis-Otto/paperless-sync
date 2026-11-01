// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// axe-core checks the pages of the app in Chromium against WCAG 2.1 at levels A and
// AA, in the light and the dark theme of Nextcloud, each in a window of a computer, 1280
// CSS pixels wide, and of a phone, 320 pixels wide: the administration settings, the actions
// of the app in the menu of Files with the messages after sending, the note above the list of a
// folder of the app, and the form of a rule of Flow. It looks only into the elements that hold the app's own markup, so what Nextcloud
// draws around them doesn't count. A serious or critical violation fails the check; the
// others are listed. At 320 pixels, the settings, the report, the note and the form of Flow also
// reflow into the window, with nothing that reaches past its edge (WCAG 1.4.10). a11y.mjs
// judges, and the check tries it on a seeded page first. run.sh starts it in the image of
// Playwright, inside the network of the Compose project, after npm ci of package-lock.json,
// with the files and the rule of Flow that it made for files.mjs and flow.mjs.

import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { NARROW, WCAG_TAGS, WIDE, describeViolations, failing, horizontalOverflow } from './a11y.mjs'
import { launch } from './browser.mjs'
import { filesAddress, folderAddress } from './files-address.mjs'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
// The synthetic token of an account of the mock that may neither view the tags nor add documents.
const VIEW_ONLY_TOKEN = process.env.E2E_VIEW_ONLY_TOKEN ?? 'e2e-view-only-token'
const AXE = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8')
const THEMES = ['light', 'dark']
const SIZES = [['1280 px', WIDE], ['320 px', NARROW]]
const SETTINGS = '/index.php/settings/admin/paperless_sync'
const FOLDER_INFO = '**/apps/paperless_sync/api/v1/folder-info'

// The folder of the user in Files shows the note of a kind: the answer of the app names it as that
// folder of the app, and the page asks anew, so that each part of the check starts from it.
async function showFolderNote(page, kind, dismissed = []) {
	await page.unroute(FOLDER_INFO)
	await page.route(FOLDER_INFO, (route) => route.fulfill({
		json: {
			folders: [{ kind, id: 0, path: '' }, { kind: 'errors', id: 0, path: 'Failed imports' }],
			lastSuccess: Math.round(Date.now() / 1000) - 300,
			nextRun: Math.round(Date.now() / 1000) + 300,
			dismissed,
		},
	}))
	await page.reload()
}

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
	['IMPORT SET ASIDE: Documents/Paperless/Inbox/holiday.mp4: video', { type: 'import-set-aside', path: 'Documents/Paperless/Inbox/holiday.mp4', values: { kind: 'video' } }],
	['IMPORT SET ASIDE: Documents/Paperless/Inbox/setup.exe: program', { type: 'import-set-aside', path: 'Documents/Paperless/Inbox/setup.exe', values: { kind: 'program' } }],
	['IMPORT SET ASIDE: Documents/Paperless/Inbox/backup.zip: archive', { type: 'import-set-aside', path: 'Documents/Paperless/Inbox/backup.zip', values: { kind: 'archive' } }],
	['SET ASIDE: 3 files of the inbox are videos, programs or archives, which Paperless can\'t use as documents, so they stay in the inbox and don\'t go to Paperless: holiday.mp4, setup.exe, backup.zip. Move them out of the inbox or delete them.', { type: 'inbox-set-aside', values: { names: 'holiday.mp4, setup.exe, backup.zip', count: 3 } }],
	['EXPORT STOPPED: Nextcloud cannot store files in the archive; the other documents wait for the next run.', { type: 'export-stopped' }],
	['TAGS UNKNOWN: Paperless shows the account no tag named 2026, so the files of the inbox go to Paperless without it. Create the tags in Paperless or share them with the account, or turn on "Create missing tags".', { type: 'tags-unknown', values: { names: '2026', count: 1 } }],
	['TAGS TAKEN: Nextcloud has tags named Invoice that Paperless Sync did not make, so the copies go without them. Rename the tag in Nextcloud or in Paperless.', { type: 'tags-taken', values: { names: 'Invoice', count: 1 } }],
	['TAGS NOT CREATED: Nextcloud lets only users and the command line create tags, so the copies get the tags named Paid, Tax 2026 with the next run of the background jobs by cron or of occ paperless_sync:run.', { type: 'tags-not-created', values: { names: 'Paid, Tax 2026', count: 2 } }],
	['NEW TAG: Insurance', { type: 'new-tag', detail: 'Insurance' }],
	['IMPORT: Documents/Paperless/Inbox/Insurance/policy.pdf (tags: Insurance)', { type: 'import', path: 'Documents/Paperless/Inbox/Insurance/policy.pdf', values: { tags: 'Insurance' } }],
	['EXPORT P1: Documents/Paperless/Archive/invoice.pdf', { type: 'export', document: 1, path: 'Documents/Paperless/Archive/invoice.pdf' }],
	['MOVE P2: Documents/Paperless/Archive/letter.pdf', { type: 'move', document: 2, path: 'Documents/Paperless/Archive/letter.pdf' }],
	['TRASH P3: Documents/Paperless/Deleted/old.pdf', { type: 'trash', document: 3, path: 'Documents/Paperless/Deleted/old.pdf' }],
	['DELETE P4: Documents/Paperless/Deleted/older.pdf', { type: 'delete', document: 4, path: 'Documents/Paperless/Deleted/older.pdf' }],
	['WAIT P5: missing run 1/3', { type: 'wait', document: 5, path: 'Documents/Paperless/Archive/gone.pdf', values: { scans: 1, limit: 3 } }],
	['IMPORT SUCCESS: Documents/Paperless/Inbox/scan.pdf -> P456', { type: 'import-success', path: 'Documents/Paperless/Inbox/scan.pdf', document: 456 }],
	['TAGS P1: +Tax 2026 -Inbox', { type: 'tags', document: 1, path: 'Documents/Paperless/Archive/invoice.pdf', values: { added: 'Tax 2026', removed: 'Inbox' } }],
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
		tagged: 1,
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
		path: SETTINGS,
		reflow: true,
		scope: '#paperless-sync-settings',
		async prepare(page) {
			await page.locator('.paperless-sync-advanced summary').click()
		},
	},
	{
		// Getting started, which hides after the first successful run of run.sh and comes back
		// on request, with steps that are done and the next one.
		name: 'getting started',
		path: SETTINGS,
		reflow: true,
		scope: '#paperless-sync-settings',
		async prepare(page) {
			await page.locator('#paperless-sync-getting-started-show').click()
			await page.locator('#paperless-sync-getting-started [aria-current="step"]').waitFor()
		},
	},
	{
		// The dry-run over the larger archive of the mock lists more changes than the
		// report shows at once, so the list scrolls.
		name: 'report of a dry-run',
		path: SETTINGS,
		reflow: true,
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
		path: SETTINGS,
		reflow: true,
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
		path: SETTINGS,
		reflow: true,
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
		path: SETTINGS,
		reflow: true,
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
		path: SETTINGS,
		reflow: true,
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
		path: SETTINGS,
		reflow: true,
		scope: '#paperless-sync-settings',
		async prepare(page) {
			const user = page.locator('#paperless-sync-target-user')
			await user.fill('nobody-here')
			await user.press('Tab')
			await page.locator('#paperless-sync-target-user-hint:not(:empty)').waitFor()
			const tags = page.locator('#paperless-sync-excluded-tags-input')
			await tags.fill('Drfat')
			// The new tag changes a setting of the path preview, which reloads and is dimmed while
			// it does; axe would sometimes measure the contrast of the dimmed text.
			const previewed = page.waitForResponse((response) => response.url().includes('/settings/preview'))
			await tags.press('Enter')
			await page.locator('#paperless-sync-excluded-tags-list .paperless-sync-chip--new').waitFor()
			await previewed
			await page.locator('#paperless-sync-path-preview:not([aria-busy="true"])').waitFor()
			await tags.fill('a')
			await tags.press('ArrowDown')
			await page.locator('#paperless-sync-excluded-tags-options [aria-selected="true"]').waitFor()
		},
	},
	{
		// The note above the list of Files, in each kind of folder of the app, with its links and
		// the button that hides it, and the question that is left of one that the user hid.
		name: 'Note above the list of Files',
		files: '/',
		parts: [
			...['archive', 'inbox', 'errors'].map((kind) => ({
				name: `Note of the ${kind}`,
				scope: `.paperless-sync-folder-note[data-kind="${kind}"]`,
				reflow: true,
				prepare: (page) => showFolderNote(page, kind),
			})),
			{
				name: 'Question of a hidden note',
				scope: '.paperless-sync-folder-question',
				reflow: true,
				prepare: (page) => showFolderNote(page, 'inbox', ['inbox']),
			},
		],
	},
	{
		// The actions of the app in the menu of Files, in the folder that run.sh uploaded the
		// files to, and the messages after sending. Files closes its menu when the window changes
		// its size, and a message goes after a few seconds, so each part is prepared again for
		// every width, and the page stays open for all of them.
		name: 'Files',
		files: '/',
		parts: [
			{
				// "Open in Paperless" in the menu of a copy of a document of Paperless, whose name
				// has the marker [P123].
				name: 'Open in Paperless in the menu of a copy',
				scope: '[data-cy-files-list-row-action="paperless_sync-open"]',
				async prepare(page) {
					await openMenu(page, 'Invoice [P123].pdf')
				},
			},
			{
				// "Send to Paperless" in the menu of a file of the user's own.
				name: 'Send to Paperless in the menu of a file',
				scope: '[data-cy-files-list-row-action="paperless_sync-send"]',
				async prepare(page) {
					await openMenu(page, 'receipt.pdf')
				},
			},
			{
				// The message that the file is on its way and the one of an error, which the page
				// gets as the answers to Send to Paperless, so that nothing is queued.
				name: 'messages after sending',
				scope: '[data-e2e-message]',
				async prepare(page) {
					let sent = 0
					await page.unroute('**/apps/paperless_sync/api/v1/send')
					await page.route('**/apps/paperless_sync/api/v1/send', (route) => route.fulfill(++sent === 1
						? { json: { message: 'Paperless Sync sends 1 file to Paperless.' } }
						: { status: 429, json: { message: 'Too many files were sent in a short time. Wait a minute and try again.' } }))
					for (const text of ['Paperless Sync sends 1 file to Paperless.', 'Too many files were sent in a short time.']) {
						await openMenu(page, 'receipt.pdf')
						await page.locator('[data-cy-files-list-row-action="paperless_sync-send"] button').click()
						await markMessage(page, text)
					}
				},
			},
		],
	},
	{
		// The form of the rule of Send to Paperless that run.sh saved, with its tags, in the
		// settings of Flow.
		name: 'rule of Flow',
		path: '/index.php/settings/admin/workflow',
		scope: 'oca-paperless_sync-send-to-paperless',
		reflow: true,
		async prepare(page) {
			await page.waitForFunction(() => document.querySelector('oca-paperless_sync-send-to-paperless input')?.value === 'Private, insurance')
		},
	},
]

// Opens the menu of the actions of a file in Files with its button, which a phone has as well,
// and closes the menu that is open first.
async function openMenu(page, name) {
	const row = page.locator(`[data-cy-files-list-row-name=${JSON.stringify(name)}]`)
	await row.waitFor({ timeout: 60_000 })
	if (await page.getByRole('menuitem').first().isVisible().catch(() => false)) {
		await page.keyboard.press('Escape')
		await page.getByRole('menuitem').first().waitFor({ state: 'detached' })
	}
	await row.locator('[data-cy-files-list-row-actions] button[aria-haspopup="menu"]').click()
	await page.getByRole('menuitem').first().waitFor()
}

// Marks the message of Nextcloud with the text for axe-core, as its markup differs between the
// versions: .toastify up to Nextcloud 34, an element with the role status or alert from 35
// on. The innermost element that shows the text counts, not the list that Nextcloud 34 and
// newer read out to a screen reader, which shows nothing.
async function markMessage(page, text) {
	await page.waitForFunction((text) => {
		const messages = [...document.querySelectorAll('.toastify, [role="status"], [role="alert"]')]
			.filter((element) => element.textContent.includes(text) && element.getBoundingClientRect().width > 1 && element.checkVisibility({ visibilityProperty: true }))
		const innermost = messages.filter((element) => !messages.some((other) => other !== element && element.contains(other)))
		for (const element of innermost) {
			element.setAttribute('data-e2e-message', '')
		}
		return innermost.length > 0
	}, text)
}

// Signs in through the login form, as a person does, and keeps the session for every
// page and theme, with the address of Files that the first page after the login links.
async function signIn(browser) {
	const context = await browser.newContext()
	try {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}/index.php/login`)
		await page.locator('input[name="user"]').fill(USER)
		await page.locator('input[name="password"]').fill(PASSWORD)
		await page.locator('input[name="password"]').press('Enter')
		await page.waitForURL((url) => !url.pathname.endsWith('/login'))
		return { session: await context.storageState(), files: await filesAddress(page) }
	} finally {
		await context.close()
	}
}

// Waits until the page is still after a change of the theme or the width: no transition of a
// color or a size that axe-core would measure halfway, and the next frame drawn.
async function settle(page) {
	await page.evaluate(async () => {
		await Promise.all(document.getAnimations()
			.filter((animation) => animation.effect?.getComputedTiming().endTime !== Infinity)
			.map((animation) => animation.finished.catch(() => undefined)))
		await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
	})
}

async function axe(page, scope) {
	if (await page.evaluate(() => window.axe === undefined)) {
		await page.evaluate(AXE)
	}
	return page.evaluate(
		({ selector, tags }) => window.axe.run(selector, {
			runOnly: { type: 'tag', values: tags },
			resultTypes: ['violations'],
		}),
		{ selector: scope, tags: WCAG_TAGS },
	)
}

// The check checks itself first: a page with a button without a name and a box wider than the
// window of a phone must fail it, or a check that can't fail would pass the app.
async function seeded(browser) {
	const context = await browser.newContext({ viewport: NARROW })
	try {
		const page = await context.newPage()
		await page.setContent('<main id="seeded"><h1>Seeded</h1><button type="button"></button><p style="width: 600px">A line too wide for a phone</p></main>')
		const { violations } = await axe(page, '#seeded')
		const overflow = await page.evaluate(horizontalOverflow, '#seeded')
		if (!failing(violations).some((violation) => violation.id === 'button-name') || overflow.length === 0) {
			throw new Error(`The check passes a seeded page that it must fail: ${violations.map((violation) => violation.id).join(', ')}; ${overflow.join(' ')}`)
		}
		console.log('axe-core and the check of the reflow fail a seeded button without a name and a box too wide for a phone.')
	} finally {
		await context.close()
	}
}

// Opens a page as a fresh window of the signed-in session, prepares it and checks it at each
// width in each theme, which the page switches without loading again. A page with parts
// prepares each part again for every width, on the same page.
async function check(browser, { session, files }, target) {
	const context = await browser.newContext({ storageState: session, colorScheme: 'light', viewport: WIDE })
	const results = []
	try {
		const page = await context.newPage()
		if (target.files) {
			await page.goto(folderAddress(files, target.files))
		} else {
			await page.goto(`${BASE_URL}${target.path}`)
			await target.prepare(page)
			await page.waitForLoadState('networkidle')
		}
		for (const [size, viewport] of SIZES) {
			await page.setViewportSize(viewport)
			for (const part of target.parts ?? [target]) {
				if (part !== target) {
					await page.emulateMedia({ colorScheme: THEMES[0] })
					await part.prepare(page)
				}
				await page.locator(part.scope).first().waitFor()
				for (const theme of THEMES) {
					await page.emulateMedia({ colorScheme: theme })
					await settle(page)
					const { violations, passes } = await axe(page, part.scope)
					const overflow = part.reflow && viewport === NARROW ? await page.evaluate(horizontalOverflow, part.scope) : []
					results.push({ label: `${part.name}, ${theme} theme, ${size}`, violations, passes, overflow })
				}
			}
		}
	} finally {
		await context.close()
	}
	return results
}

const started = Date.now()
const browser = await launch('accessibility')
let failures = 0
try {
	await seeded(browser)
	const signedIn = await signIn(browser)
	for (const target of PAGES) {
		let found = 0
		for (const { label, violations, passes, overflow } of await check(browser, signedIn, target)) {
			found += failing(violations).length + overflow.length
			console.log(`axe-core, ${label}: ${passes.length} rule(s) passed, ${violations.length} violated, ${failing(violations).length} of them serious or critical${overflow.length > 0 ? `; ${overflow.length} element(s) reach past the window` : ''}`)
			for (const line of describeViolations(violations)) {
				console.log(line)
			}
			for (const line of overflow.slice(0, 10)) {
				console.log(`  reflow: ${line}`)
			}
		}
		// The window of the page is still there, so its trace shows each width and theme.
		if (found > 0) {
			await browser.keep(`${found} serious or critical violation(s) or element(s) past the window in ${target.name}`)
		}
		failures += found
	}
} catch (error) {
	await browser.keep(error)
	throw error
} finally {
	await browser.close()
}

if (failures > 0) {
	console.error(`The accessibility check found ${failures} serious or critical violation(s) of WCAG 2.1 AA, or element(s) that reach past a window of 320 px, in the app.`)
	process.exit(1)
}
console.log(`axe-core found no serious or critical violation of WCAG 2.1 AA in the app, and the settings and the form of Flow reflow at 320 px, after ${Math.round((Date.now() - started) / 1000)} s.`)

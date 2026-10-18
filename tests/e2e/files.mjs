// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Checks the actions of the app in the menu of Files, as their users see them. e2e-admin,
// a member of the group admin of the setting "Group that may send files", finds "Send to
// Paperless" for a file of their own but not for a copy of a document of Paperless, whose
// name has the marker [P123]; that one has "Open in Paperless", which opens the document
// at the Paperless URL of the settings. A click on "Send to Paperless" queues the file and
// says so. e2e-other, who isn't a member, finds no "Send to Paperless" at all. run.sh starts
// it in the image of Playwright after settings.mjs, with the files receipt.pdf and
// "Invoice [P123].pdf" of e2e-admin and letter.pdf of e2e-other that it uploaded.

import { chromium } from 'playwright-core'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const ADMIN = process.env.E2E_USER ?? 'e2e-admin'
const OTHER = process.env.E2E_OTHER_USER ?? 'e2e-other'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const SEND = 'Send to Paperless'
const OPEN = 'Open in Paperless'

function expect(condition, message) {
	if (!condition) {
		throw new Error(message)
	}
}

async function signIn(browser, user) {
	const context = await browser.newContext({ locale: 'en-US', viewport: { width: 1280, height: 900 } })
	const page = await context.newPage()
	await page.goto(`${BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(PASSWORD)
	await page.locator('input[name="password"]').press('Enter')
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	await page.goto(`${BASE_URL}/index.php/apps/files/files?dir=/`)
	return { context, page }
}

// The row of a file in the list of Files.
function row(page, name) {
	return page.locator(`[data-cy-files-list-row-name=${JSON.stringify(name)}]`)
}

// Opens the menu of the actions of a file, as a right click on its row does, and gives the
// names of its actions; the menu stays open for a click.
async function actionsOf(page, name) {
	await row(page, name).waitFor({ timeout: 60_000 })
	await row(page, name).click({ button: 'right' })
	const items = page.getByRole('menuitem')
	await items.first().waitFor()
	return (await items.allTextContents()).map((text) => text.trim())
}

async function closeMenu(page) {
	await page.keyboard.press('Escape')
	await page.getByRole('menuitem').first().waitFor({ state: 'detached' })
}

const browser = await chromium.launch()
try {
	const admin = await signIn(browser, ADMIN)
	const own = await actionsOf(admin.page, 'receipt.pdf')
	expect(own.includes(SEND), `A member of the group must find "${SEND}" for a file of their own: ${own.join(', ')}`)
	expect(!own.includes(OPEN), `A file without a marker has no "${OPEN}": ${own.join(', ')}`)
	await admin.page.getByRole('menuitem', { name: SEND }).click()
	const toast = admin.page.getByText('Paperless Sync sends 1 file to Paperless.', { exact: false })
	await toast.waitFor()
	console.log('Files: a member of the group sends a file of their own, and a message says so.')

	const copy = await actionsOf(admin.page, 'Invoice [P123].pdf')
	expect(copy.includes(OPEN), `A copy of a document of Paperless must have "${OPEN}": ${copy.join(', ')}`)
	expect(!copy.includes(SEND), `Paperless has a copy of its document already, so it has no "${SEND}": ${copy.join(', ')}`)
	const [opened] = await Promise.all([
		admin.context.waitForEvent('page'),
		admin.page.getByRole('menuitem', { name: OPEN }).click(),
	])
	// The address of the document, which the mock answers with an error of its own.
	await opened.waitForURL(/\/documents\/123\/details$/, { waitUntil: 'commit' })
	await opened.close()
	console.log('Files: a copy of a document opens it in Paperless, and Send to Paperless leaves it out.')
	await admin.context.close()

	const other = await signIn(browser, OTHER)
	const others = await actionsOf(other.page, 'letter.pdf')
	expect(!others.includes(SEND), `A user outside the group must not find "${SEND}": ${others.join(', ')}`)
	await closeMenu(other.page)
	await other.context.close()
	console.log('Files: a user outside the group finds no Send to Paperless.')
} finally {
	await browser.close()
}

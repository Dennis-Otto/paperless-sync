// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Checks the settings page in Chromium as an administrator uses it. Saving needs the
// password of the administrator, confirmed in the last 30 minutes
// (#[PasswordConfirmationRequired]), and right after the login it counts as confirmed.
// So the check makes the page and the server 30 minutes older: the page must show
// Nextcloud's dialog for the password, confirm it with the server and save again. The
// stored token goes only to the URL that it was saved with, so the page asks for the
// token as soon as the URL changes. run.sh starts it in the image of Playwright, inside
// the network of the Compose project, after the accessibility check, while Paperless
// and the saved configuration of run.sh are there.

import { chromium } from 'playwright-core'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const SETTINGS = '/apps/paperless_sync/settings'
// What Nextcloud answers to a route with #[PasswordConfirmationRequired] before the
// password is confirmed.
const NOT_CONFIRMED = { message: 'Password confirmation is required' }

function expect(condition, message) {
	if (!condition) {
		throw new Error(message)
	}
}

const browser = await chromium.launch()
try {
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
	await page.goto(`${BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(USER)
	await page.locator('input[name="password"]').fill(PASSWORD)
	await page.locator('input[name="password"]').press('Enter')
	await page.waitForURL((url) => !url.pathname.endsWith('/login'))
	await page.goto(`${BASE_URL}/index.php/settings/admin/paperless_sync`)

	const url = page.locator('#paperless-sync-paperless-url')
	const token = page.locator('#paperless-sync-token')
	const required = () => token.evaluate((input) => input.required)
	const savedUrl = await url.inputValue()
	expect(!(await required()), 'With the saved URL, the stored token must be enough.')
	await url.fill(`${savedUrl}/other-app`)
	expect(await required(), 'Another URL must ask for the token again.')
	expect((await token.getAttribute('placeholder')) === 'Required again for a new URL', 'The token must say why it is required again.')
	await url.fill(`${savedUrl}/`)
	expect(!(await required()), 'The saved URL with a slash at its end is the same URL.')
	console.log('Settings: another URL asks for the token again, the saved one does not.')

	const saves = []
	await page.route(`**${SETTINGS}`, async (route) => {
		if (route.request().method() !== 'POST') {
			await route.continue()
			return
		}
		saves.push(route.request().postDataJSON())
		if (saves.length === 1) {
			await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify(NOT_CONFIRMED) })
		} else {
			await route.continue()
		}
	})
	// Nextcloud's dialog asks only when the last confirmation that the page knows of is
	// older than 30 minutes; after the confirmation, it knows of the new one.
	await page.evaluate(() => {
		window.nc_lastLogin = 1
	})
	await page.locator('#paperless-sync-save').click()
	const password = page.getByRole('dialog').locator('input[type="password"]')
	await password.fill(PASSWORD)
	await page.getByRole('dialog').locator('button[type="submit"]').click()
	// The buttons are busy until the save has an answer.
	await page.locator('#paperless-sync-save:enabled').waitFor()
	const message = await page.locator('#paperless-sync-message').textContent()
	expect(message === 'Connection successful. Configuration saved.', `The save failed: ${message}`)
	expect(saves.length === 2, `The page saved ${saves.length} times instead of once more after the password.`)
	expect(JSON.stringify(saves[1]) === JSON.stringify(saves[0]), 'The page must send the same settings again after the password.')
	console.log('Settings: saving asks for the password when Nextcloud requires it, and saves after it.')
} finally {
	await browser.close()
}

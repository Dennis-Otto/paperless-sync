// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Checks "Send to Paperless" in the settings of Flow, as an administrator uses it: Flow offers
// the operation, and the form of the rule that run.sh saved through the API of Flow shows its
// tags, Private and insurance. A tag typed after them gets the tags of Paperless as suggestions,
// and saving the rule keeps the three tags as its operation, as the API of Flow then reads it.
// run.sh starts it in the image of Playwright after files.mjs.

import { chromium } from 'playwright-core'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const ADMIN = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const OPERATION = 'OCA\\PaperlessSync\\Operation\\SendToPaperless'
const FORM = 'oca-paperless_sync-send-to-paperless'

function expect(condition, message) {
	if (!condition) {
		throw new Error(message)
	}
}

// The tags of the rules of the operation, as the API of Flow gives them to its settings.
async function savedTags(page) {
	return page.evaluate(async (operation) => {
		const response = await fetch('/ocs/v2.php/apps/workflowengine/api/v1/workflows/global?format=json', {
			headers: { 'OCS-APIRequest': 'true', requesttoken: window.OC.requestToken },
		})
		const rules = (await response.json()).ocs.data[operation] ?? []
		return rules.map((rule) => JSON.parse(rule.operation || '{}').tags ?? [])
	}, OPERATION)
}

const browser = await chromium.launch()
try {
	const context = await browser.newContext({ locale: 'en-US', viewport: { width: 1280, height: 900 } })
	const page = await context.newPage()
	await page.goto(`${BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(ADMIN)
	await page.locator('input[name="password"]').fill(PASSWORD)
	await page.locator('input[name="password"]').press('Enter')
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	await page.goto(`${BASE_URL}/index.php/settings/admin/workflow`)

	await page.getByText('Sends the files to Paperless with the tags for every import of the inbox', { exact: false }).first().waitFor({ timeout: 60_000 })
	const field = page.locator(FORM).getByLabel('Tags in Paperless')
	await field.waitFor()
	expect(await field.inputValue() === 'Private, insurance', `The form shows the tags of the rule: ${await field.inputValue()}`)
	console.log('Flow: the settings offer Send to Paperless, and the form of a rule shows its tags.')

	await field.press('End')
	await field.pressSequentially(', Dr')
	await page.waitForFunction((form) => [...document.querySelectorAll(`${form} datalist option`)].some((option) => option.value === 'Private, insurance, Draft'), FORM)
	await field.pressSequentially('aft')
	await page.getByRole('button', { name: 'Save' }).first().click()
	// Flow asks for the password again only when the last confirmation is older than half an hour.
	const deadline = Date.now() + 30_000
	let tags = []
	while (Date.now() < deadline) {
		const password = page.getByRole('dialog').locator('input[type="password"]')
		if (await password.isVisible().catch(() => false)) {
			await password.fill(PASSWORD)
			await password.press('Enter')
		}
		tags = await savedTags(page)
		if (JSON.stringify(tags) === JSON.stringify([['Private', 'insurance', 'Draft']])) {
			break
		}
		await page.waitForTimeout(500)
	}
	expect(JSON.stringify(tags) === JSON.stringify([['Private', 'insurance', 'Draft']]), `Saving the rule keeps its three tags: ${JSON.stringify(tags)}`)
	console.log('Flow: the form suggests the tags of Paperless, and saving keeps the tags of the rule.')
	await context.close()
} finally {
	await browser.close()
}

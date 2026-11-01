// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The app with Nextcloud in French, the first language that has no pictures and so no other run
// in the browser. run.sh starts it last, on an installation whose default language it set to fr,
// whose administrator has no language of their own and whose names it took away: the settings page
// must speak French, from PHP and from the catalog of the browser, with the plurals of French and
// what the server refuses, and must propose the French names of the folders and the fallbacks,
// which a new installation gets.

import { readFileSync } from 'node:fs'
import { launch } from './browser.mjs'
import { appLanguage } from './language.mjs'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const TOKEN = process.env.E2E_TOKEN ?? 'e2e-only-token'
const CATALOG = process.env.E2E_CATALOG ?? new URL('../../l10n/fr.json', import.meta.url)
const L = appLanguage('fr', JSON.parse(readFileSync(CATALOG, 'utf8')).translations)

function expect(condition, message) {
	if (!condition) {
		throw new Error(message)
	}
}

const browser = await launch('french')
try {
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
	await page.goto(`${BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(USER)
	await page.locator('input[name="password"]').fill(PASSWORD)
	await page.locator('input[name="password"]').press('Enter')
	await page.waitForURL((url) => !url.pathname.endsWith('/login'))
	await page.goto(`${BASE_URL}/index.php/settings/admin/paperless_sync`)

	// The page, which PHP writes.
	const language = await page.evaluate(() => document.documentElement.lang)
	expect(language === 'fr', `Nextcloud must speak French on the page, not "${language}".`)
	await page.getByRole('heading', { name: L.t('Connection and ownership'), exact: true }).waitFor({ timeout: 15000 })
	console.log('French: the settings page is in French.')

	// The names that a new installation proposes, as the page shows them before the first save.
	const proposed = {
		base_path: L.base,
		archive_folder: L.archive,
		inbox_folder: L.inbox,
		error_folder: L.failed,
		deleted_folder: L.deleted,
		empty_correspondent: L.t('_No correspondent'),
		empty_document_type: L.t('_No document type'),
		empty_date: L.t('_No date'),
		untitled: L.t('Untitled'),
	}
	for (const [setting, name] of Object.entries(proposed)) {
		const value = await page.locator(`[data-setting="${setting}"]`).inputValue()
		expect(value === name, `The setting ${setting} must propose "${name}", not "${value}".`)
	}
	console.log(`French: a new installation proposes ${L.base}, ${L.archive}, ${L.inbox}, ${L.failed} and ${L.deleted}.`)

	// The catalog for the browser, with its plurals: Nextcloud picks their forms there by a table of
	// its own, not by the rule of the catalog, which has a third form for millions.
	const [refresh, one, many, none] = await page.evaluate(() => [
		window.t('paperless_sync', 'Refresh'),
		window.n('paperless_sync', '%n minute', '%n minutes', 1),
		window.n('paperless_sync', '%n minute', '%n minutes', 5),
		window.n('paperless_sync', '%n minute', '%n minutes', 0),
	])
	expect(refresh === L.t('Refresh'), `A text of the browser must be French: ${refresh}`)
	expect(one === '1 minute' && many === '5 minutes' && none === '0 minute', `The plurals must follow French: ${one}, ${many}, ${none}`)
	console.log('French: the scripts of the page read the catalog, and its plurals follow French.')

	// What the server refuses, through the answer that the page shows, with the apostrophe of French.
	await page.locator('#paperless-sync-paperless-url').fill('ftp://paperless-mock:8080')
	await page.locator('#paperless-sync-token').fill(TOKEN)
	await page.locator('#paperless-sync-save').click()
	await page.locator('#paperless-sync-save:enabled').waitFor()
	const refused = L.t('The Paperless URL must use HTTP or HTTPS.')
	await page.waitForFunction((text) => document.querySelector('#paperless-sync-message').textContent === text, refused, { timeout: 15000 })
	console.log('French: what the server refuses is in French.')
} catch (error) {
	await browser.keep(error)
	throw error
} finally {
	await browser.close()
}

// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The app in every language of its catalogs, l10n/, of which only English and German have
// pictures and so other runs in the browser. run.sh starts it last, on an installation whose
// default language it set to French, whose administrator has no language of their own and whose
// names it took away. First, as a new installation: the settings page must speak French, from PHP
// and from the catalog of the browser, with the plurals of French and what the server refuses, and
// must propose the French names of the folders and the fallbacks. Then the administrator takes
// each further language as their own, through the API of Nextcloud, and the page must speak it in
// the same ways, in the words of its own catalog, and still propose the names of French, the
// language of Nextcloud, whoever reads. A catalog that Nextcloud doesn't find or read, or one that
// lacks a text or a form of a plural, fails here.

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { request } from 'playwright-core'
import { launch } from './browser.mjs'
import { appLanguage, CATALOGS, MARKS } from './language.mjs'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const TOKEN = process.env.E2E_TOKEN ?? 'e2e-only-token'
const FOLDER = process.env.E2E_CATALOGS ?? fileURLToPath(new URL('../../l10n', import.meta.url))
const PAGE = '/index.php/settings/admin/paperless_sync'
// The default language of Nextcloud, which run.sh sets, first, and then every other one.
const DEFAULT = 'fr'
const ORDER = [DEFAULT, ...CATALOGS.filter((code) => code !== DEFAULT)]

function expect(condition, message) {
	if (!condition) {
		throw new Error(message)
	}
}

function language(code) {
	return appLanguage(code, JSON.parse(readFileSync(join(FOLDER, `${code}.json`), 'utf8')).translations)
}

// The language of the administrator, as their personal settings set it.
async function setLanguage(code) {
	const api = await request.newContext({ baseURL: BASE_URL, httpCredentials: { username: USER, password: PASSWORD, send: 'always' } })
	try {
		const response = await api.put(`/ocs/v2.php/cloud/users/${USER}`, {
			headers: { 'OCS-APIRequest': 'true' },
			form: { key: 'language', value: code },
		})
		expect(response.ok(), `Nextcloud didn't set the language ${code}: HTTP ${response.status()}`)
	} finally {
		await api.dispose()
	}
}

// The page in the language L: what PHP writes, what the scripts of the page read from the catalog
// for the browser, with the plurals that Nextcloud picks there by a table of its own, not by the
// rule of the catalog, which has a third form for millions in French and Spanish, and what the
// server refuses, through the answer that the page shows.
async function speaks(page, L) {
	const lang = await page.evaluate(() => document.documentElement.lang)
	expect(lang === L.pageLanguage, `Nextcloud must speak ${L.name} on the page, as "${L.pageLanguage}", not "${lang}".`)
	await page.getByRole('heading', { name: L.t('Connection and ownership'), exact: true }).waitFor({ timeout: 15000 })
	const mark = L.t(MARKS.page)
	const written = await page.evaluate((text) => document.body.textContent.includes(text), mark)
	expect(written, `The page must say "${mark}", in the words of l10n/${L.code}.json.`)

	const [script, refresh, one, many, none] = await page.evaluate((text) => [
		window.t('paperless_sync', text),
		window.t('paperless_sync', 'Refresh'),
		window.n('paperless_sync', '%n minute', '%n minutes', 1),
		window.n('paperless_sync', '%n minute', '%n minutes', 5),
		window.n('paperless_sync', '%n minute', '%n minutes', 0),
	], MARKS.script)
	expect(script === L.t(MARKS.script) && refresh === L.t('Refresh'), `The scripts of the page must read l10n/${L.code}.js: ${script} ${refresh}`)
	const plurals = [1, 5, 0].map((count) => L.n('%n minute', '%n minutes', count))
	expect(
		JSON.stringify([one, many, none]) === JSON.stringify(plurals),
		`The plurals must follow ${L.name}, ${plurals.join(', ')}: ${one}, ${many}, ${none}`,
	)

	await page.locator('#paperless-sync-paperless-url').fill('ftp://paperless-mock:8080')
	await page.locator('#paperless-sync-token').fill(TOKEN)
	await page.locator('#paperless-sync-save').click()
	await page.locator('#paperless-sync-save:enabled').waitFor()
	const refused = L.t('The Paperless URL must use HTTP or HTTPS.')
	await page.waitForFunction((text) => document.querySelector('#paperless-sync-message').textContent === text, refused, { timeout: 15000 })
}

// The names that a new installation proposes, as the page shows them before the first save, in
// the default language of Nextcloud, L.
async function proposes(page, L) {
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
}

const browser = await launch('languages')
try {
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
	await page.goto(`${BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(USER)
	await page.locator('input[name="password"]').fill(PASSWORD)
	await page.locator('input[name="password"]').press('Enter')
	await page.waitForURL((url) => !url.pathname.endsWith('/login'))
	const fallback = language(DEFAULT)
	for (const code of ORDER) {
		const L = language(code)
		if (code !== DEFAULT) {
			await setLanguage(code)
		}
		await page.goto(`${BASE_URL}${PAGE}`)
		await speaks(page, L)
		await proposes(page, fallback)
		const title = L.name[0].toUpperCase() + L.name.slice(1)
		console.log(code === DEFAULT
			? `${title}: a new installation speaks ${L.name}, with its plurals and what the server refuses, and proposes ${L.base}, ${L.archive}, ${L.inbox}, ${L.failed} and ${L.deleted}.`
			: `${title}: the settings page speaks ${L.name}, with its plurals and what the server refuses, and still proposes the names of ${fallback.name}.`)
	}
} catch (error) {
	await browser.keep(error)
	throw error
} finally {
	await browser.close()
}

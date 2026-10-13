// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Checks the settings page in Chromium as an administrator uses it. Saving needs the
// password of the administrator, confirmed in the last 30 minutes
// (#[PasswordConfirmationRequired]), and right after the login it counts as confirmed.
// So the check makes the page and the server 30 minutes older: the page must show
// Nextcloud's dialog for the password, confirm it with the server and save again. The
// stored token goes only to the URL that it was saved with, so the page asks for the
// token as soon as the URL changes. Nextcloud refuses the mock at its address of the
// network of Compose unless "Paperless runs in my local network" is on, and the page
// leads there. The form always shows what the server stored, says when it holds unsaved
// changes, and shows every result and error next to the buttons, translated, also when
// a proxy answers with a page of its own. The CSS of Nextcloud, which styles every button
// and input, leaves Disconnect at the end of the bar and every checkbox next to its label. run.sh starts it in the image of Playwright,
// inside the network of the Compose project, after the accessibility check, while
// Paperless and the saved configuration of run.sh are there, and it leaves that
// configuration as it found it.

import { chromium, request } from 'playwright-core'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
// The synthetic token of the mock, which run.sh saved, to connect again after Disconnect.
const TOKEN = process.env.E2E_TOKEN ?? 'e2e-only-token'
const PAGE = '/index.php/settings/admin/paperless_sync'
const SETTINGS = '/apps/paperless_sync/settings'
const RUN = '/apps/paperless_sync/sync/run'
const STATUS = '/apps/paperless_sync/sync/status'
// What Nextcloud answers to a route with #[PasswordConfirmationRequired] before the
// password is confirmed.
const NOT_CONFIRMED = { message: 'Password confirmation is required' }
// The page of a proxy that stopped waiting for a long run.
const GATEWAY_TIMEOUT = '<html><head><title>504 Gateway Time-out</title></head><body><center><h1>504 Gateway Time-out</h1></center><hr><center>nginx</center></body></html>'
const SAVED = 'Connection successful. Configuration saved.'
const SAVED_UNTESTED = 'Saved. Scheduled synchronization is off; the connection was not tested.'

function expect(condition, message) {
	if (!condition) {
		throw new Error(message)
	}
}

// The language of the administrator, through the provisioning API of Nextcloud, which
// answers without a challenge for the password, so the request sends it at once.
async function setLanguage(language) {
	const api = await request.newContext({ baseURL: BASE_URL, httpCredentials: { username: USER, password: PASSWORD, send: 'always' } })
	try {
		const response = await api.put(`/ocs/v2.php/cloud/users/${USER}`, {
			headers: { 'OCS-APIRequest': 'true' },
			form: { key: 'language', value: language },
		})
		expect(response.ok(), `Nextcloud didn't set the language ${language}: HTTP ${response.status()}`)
	} finally {
		await api.dispose()
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
	await page.goto(`${BASE_URL}${PAGE}`)

	const url = page.locator('#paperless-sync-paperless-url')
	const token = page.locator('#paperless-sync-token')
	const schedule = page.locator('[data-setting="enabled"]')
	const localNetwork = page.locator('[data-setting="paperless_local_network"]')
	const batchSize = page.locator('[data-setting="batch_size"]')
	const save = page.locator('#paperless-sync-save')
	const dryRun = page.locator('#paperless-sync-dry-run')
	const run = page.locator('#paperless-sync-run')
	const reset = page.locator('#paperless-sync-reset')
	const refresh = page.locator('#paperless-sync-refresh-status')
	const unsaved = page.locator('#paperless-sync-unsaved')
	const message = page.locator('#paperless-sync-message')
	const required = () => token.evaluate((input) => input.required)
	const focused = (locator) => locator.evaluate((element) => element === document.activeElement)
	// The buttons are busy until an action has its answer; the save button stays enabled
	// with unsaved changes, "Synchronize now" doesn't.
	const settle = () => page.locator('#paperless-sync-save:enabled').waitFor()
	// The message that follows the progress message of an action, once the page has its answers.
	const messageAfter = async (progress) => {
		await page.waitForFunction((text) => document.querySelector('#paperless-sync-message').textContent !== text, progress)
		return message.textContent()
	}
	// What the form shows, as the page sends it.
	const form = () => page.locator('#paperless-sync-settings').evaluate((root) => Object.fromEntries(
		Array.from(root.querySelectorAll('[data-setting]'), (input) => [input.dataset.setting, input.type === 'checkbox' ? input.checked : input.value]),
	))
	// Whether leaving the page asks first, as the browser asks the page before it unloads.
	const asksBeforeLeaving = () => page.evaluate(() => {
		const event = new Event('beforeunload', { cancelable: true })
		window.dispatchEvent(event)
		return event.defaultPrevented
	})

	// The CSS of Nextcloud gives every button a margin and every input the height of a
	// button. Disconnect, which can't be undone, must still keep its distance at the end of
	// the bar, and a checkbox must sit on the first line of its label, not below it.
	const bar = await page.locator('.paperless-sync-actions').boundingBox()
	const disconnect = await reset.boundingBox()
	expect(
		bar !== null && disconnect !== null && bar.x + bar.width - (disconnect.x + disconnect.width) <= 20 && disconnect.x - bar.x > bar.width / 2,
		`Disconnect must sit at the right end of the bar: ${JSON.stringify({ bar, disconnect })}`,
	)
	const misaligned = await page.locator('.paperless-sync-switches label').evaluateAll((labels) => labels.flatMap((label) => {
		const box = label.querySelector('input').getBoundingClientRect()
		const text = document.createRange()
		text.selectNodeContents(label.querySelector('span'))
		const line = text.getClientRects()[0]
		const offset = (box.top + box.height / 2) - (line.top + line.height / 2)
		return Math.abs(offset) < 4 && box.height <= 24 ? [] : [`${label.textContent.trim()}: ${box.height} px high, ${offset.toFixed(1)} px off the middle of its first line`]
	}))
	expect(misaligned.length === 0, `Checkboxes must align with the first line of their labels: ${misaligned.join('; ')}`)
	const interval = page.locator('[data-setting="sync_interval_minutes"]')
	expect((await interval.getAttribute('min')) === '5', 'The interval must be at least the 5 minutes between two background jobs.')
	const helpLinks = await page.locator('.paperless-sync-help').evaluateAll((links) => links.map((link) => [link.href, link.getAttribute('aria-label')]))
	expect(
		helpLinks.length === 5 && helpLinks.every(([href, label]) => href.startsWith('https://dennis-otto.github.io/paperless-sync/guide.html#') && label.startsWith('Help on ')),
		`Every section must link to its part of the guide: ${JSON.stringify(helpLinks)}`,
	)
	console.log('Settings: Disconnect sits at the end of the bar, checkboxes align with their labels, and every section links to the guide.')

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
	await save.click()
	const password = page.getByRole('dialog').locator('input[type="password"]')
	await password.fill(PASSWORD)
	await page.getByRole('dialog').locator('button[type="submit"]').click()
	await settle()
	// Without the schedule and a new token, saving needs no test of the connection.
	expect((await message.textContent()) === SAVED_UNTESTED, `The save failed: ${await message.textContent()}`)
	expect(saves.length === 2, `The page saved ${saves.length} times instead of once more after the password.`)
	expect(JSON.stringify(saves[1]) === JSON.stringify(saves[0]), 'The page must send the same settings again after the password.')
	await page.unroute(`**${SETTINGS}`)
	console.log('Settings: saving asks for the password when Nextcloud requires it, and saves after it.')

	expect((await url.inputValue()) === savedUrl, `The form must show the URL as the server stored it, without the slash: ${await url.inputValue()}`)
	expect(await unsaved.isHidden(), 'After the save, nothing is unsaved.')
	console.log('Settings: the form shows the settings as the server stored them, normalized.')

	const savedBatchSize = await batchSize.inputValue()
	await batchSize.fill(String(Number(savedBatchSize) + 1))
	expect(await unsaved.isVisible(), 'A change must show the note about unsaved changes.')
	expect(await run.isDisabled(), '"Synchronize now" must wait for the save of a change.')
	expect(await dryRun.isEnabled(), 'A dry-run of the saved settings stays possible.')
	expect(await asksBeforeLeaving(), 'Leaving the page with a change must ask first.')
	await batchSize.fill(savedBatchSize)
	expect(await unsaved.isHidden(), 'Undoing the change leaves nothing unsaved.')
	expect(await run.isEnabled(), '"Synchronize now" is possible again.')
	expect(!(await asksBeforeLeaving()), 'Leaving the page without a change asks nothing.')
	console.log('Settings: a change shows the note, holds "Synchronize now" back and asks before leaving the page.')

	// A change made while the server answers a save stays in the form, as an unsaved change.
	let answer = () => {}
	const answered = new Promise((resolve) => {
		answer = resolve
	})
	await page.route(`**${SETTINGS}`, async (route) => {
		if (route.request().method() === 'POST') {
			await answered
		}
		await route.continue()
	})
	await save.click()
	const changedBatchSize = String(Number(savedBatchSize) + 2)
	await batchSize.fill(changedBatchSize)
	answer()
	await settle()
	await page.unroute(`**${SETTINGS}`)
	expect((await message.textContent()) === SAVED_UNTESTED, `The save failed: ${await message.textContent()}`)
	expect((await batchSize.inputValue()) === changedBatchSize, 'A change made during the save must stay in the form.')
	expect(await unsaved.isVisible() && await run.isDisabled(), 'A change made during the save is unsaved.')
	await batchSize.fill(savedBatchSize)
	expect(await unsaved.isHidden(), 'The saved settings are the ones that the save sent.')
	console.log('Settings: a change made while the server answers a save stays, as an unsaved change.')

	// A token that is entered is tested, also with the schedule off.
	await token.fill(TOKEN)
	await localNetwork.uncheck()
	await save.click()
	await settle()
	const refusal = await message.textContent()
	expect(
		refusal === 'Nextcloud blocks connections to local addresses such as paperless-mock. If Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.',
		`A refused local address must say what to do: ${refusal}`,
	)
	expect(await focused(localNetwork), 'The focus must move to the setting that the message names.')
	await localNetwork.check()
	await save.click()
	await settle()
	expect((await message.textContent()) === SAVED, `The save with the local network allowed failed: ${await message.textContent()}`)
	expect((await token.inputValue()) === '', 'The page forgets the token once it is saved.')
	console.log('Settings: Nextcloud refuses a Paperless in the local network until its setting allows it, and the page leads there.')

	// The message sits next to the buttons, which stick to the bottom of the window, also
	// while the top of the long page is in view.
	await page.locator('#paperless-sync-settings').evaluate((root) => root.scrollIntoView({ block: 'start' }))
	await url.fill('ftp://paperless-mock:8080')
	await token.fill(TOKEN)
	await save.click()
	await settle()
	expect((await message.textContent()) === 'The Paperless URL must use HTTP or HTTPS.', `An invalid URL must be refused: ${await message.textContent()}`)
	const box = await message.boundingBox()
	expect(box !== null && box.y >= 0 && box.y + box.height <= 900, `The message must be in view, not at ${JSON.stringify(box)}.`)
	expect((await message.getAttribute('role')) === 'alert' && await focused(message), 'An error must be an alert and take the focus.')
	await url.fill(savedUrl)
	await token.fill('')
	expect(await unsaved.isHidden(), 'Undoing the changes leaves nothing unsaved.')
	console.log('Settings: an error appears next to the buttons, in view, and takes the focus.')

	// A proxy in front of Nextcloud stops waiting for a long run and answers with a page of
	// its own, while the run continues, as the status shows.
	await page.route(`**${RUN}`, (route) => route.fulfill({ status: 504, contentType: 'text/html', body: GATEWAY_TIMEOUT }))
	await page.route(`**${STATUS}`, async (route) => {
		const response = await route.fetch()
		await route.fulfill({ response, json: { ...(await response.json()), state: 'dry-run-running' } })
	})
	await dryRun.click()
	const timeout = await messageAfter('Calculating synchronization changes…')
	await settle()
	expect(
		timeout === 'The run takes longer than the web server waits for an answer. It continues in the background, and the status above shows when it ends.',
		`A gateway timeout must say that the run continues: ${timeout}`,
	)
	expect(!timeout.includes('<'), 'The page must never show the HTML of an answer.')
	await page.unroute(`**${STATUS}`)
	// A bad gateway after which the status shows no running run: PHP ended without an answer.
	await page.route(`**${RUN}`, (route) => route.fulfill({ status: 502, contentType: 'text/html', body: '<html><body><h1>502 Bad Gateway</h1></body></html>' }))
	await dryRun.click()
	const badGateway = await messageAfter('Calculating synchronization changes…')
	await settle()
	expect(
		badGateway === 'The web server answered with status 502. The status above shows how the run ended.',
		`A bad gateway after the run ended must not say that it continues: ${badGateway}`,
	)
	expect(await focused(message), 'The error of a bad gateway must take the focus.')
	await page.unroute(`**${RUN}`)
	console.log('Settings: a gateway timeout of a run says that it continues while the status shows it running, without the page of the proxy.')

	// A run with errors says how many, and a refresh of the status that fails afterwards
	// leaves that message alone.
	await page.route(`**${RUN}`, (route) => route.fulfill({
		json: {
			dryRun: false,
			completedAt: Math.floor(Date.now() / 1000),
			exported: 1,
			errors: 2,
			actions: ['ERROR P7: Synthetic failure.', 'ERROR P8: Synthetic failure.', 'EXPORT P9 Documents/Paperless/Archive/P9.pdf'],
		},
	}))
	await page.route(`**${STATUS}`, (route) => route.fulfill({ status: 503, contentType: 'text/html', body: '<html><body>Service Unavailable</body></html>' }))
	const statusAnswer = page.waitForResponse((response) => response.url().endsWith(STATUS))
	page.once('dialog', (dialog) => dialog.accept())
	await run.click()
	await settle()
	await statusAnswer
	// The page handles the answer of the status in its next task.
	await page.evaluate(() => new Promise((resolve) => {
		window.setTimeout(resolve, 200)
	}))
	const withErrors = await message.textContent()
	expect(withErrors === 'Synchronization completed with 2 errors. See the report.', `A run with errors must count them: ${withErrors}`)
	expect(await page.locator('#paperless-sync-report').isVisible(), 'The report of the run must be shown.')
	await page.unroute(`**${RUN}`)
	console.log('Settings: a run with errors counts them, and a failed refresh of the status keeps that message.')

	// Errors without an answer of the app: no connection, and a session that ended.
	await page.unroute(`**${STATUS}`)
	await page.route(`**${STATUS}`, (route) => route.abort('failed'))
	await refresh.click()
	await settle()
	expect((await message.textContent()) === 'Nextcloud could not be reached. Check the connection and try again.', `A failed connection must be explained: ${await message.textContent()}`)
	await page.unroute(`**${STATUS}`)
	await page.route(`**${STATUS}`, (route) => route.fulfill({ status: 412, json: { message: 'CSRF check failed' } }))
	await refresh.click()
	await settle()
	expect((await message.textContent()) === 'Your session has expired. Reload the page and try again.', `An ended session must be explained: ${await message.textContent()}`)
	await page.unroute(`**${STATUS}`)
	await refresh.click()
	await settle()
	expect((await message.textContent()) === 'Status refreshed.', `The status must refresh again: ${await message.textContent()}`)
	console.log('Settings: a failed connection and an ended session say what to do.')

	// Disconnect deletes the token and turns off the schedule, and the form shows what
	// stays, as a reload of the page shows it too.
	let question = ''
	page.once('dialog', (dialog) => {
		question = dialog.message()
		dialog.accept()
	})
	await reset.click()
	await settle()
	expect(question.startsWith('Disconnect Paperless? This deletes the stored API token'), `Disconnect must say what it does: ${question}`)
	expect(
		(await message.textContent()) === 'Paperless disconnected: the API token is deleted and scheduled synchronization is off. The other settings and the files are unchanged.',
		`Disconnect failed: ${await message.textContent()}`,
	)
	const disconnected = await form()
	expect(
		disconnected.paperless_url === savedUrl && disconnected.target_user === 'sync-target' && disconnected.base_path === 'Documents/Paperless',
		`Disconnect must keep the connection settings: ${JSON.stringify(disconnected)}`,
	)
	expect(disconnected.permanent_delete === true, 'Disconnect must keep the deletion policy of the administrator.')
	expect(disconnected.enabled === false && disconnected.paperless_local_network === false, 'Disconnect turns off the schedule and the local network.')
	expect(await required() && (await token.getAttribute('placeholder')) === 'Required', 'Without a stored token, the token is required.')
	expect(await unsaved.isHidden(), 'The form shows what the server stored.')
	await page.reload()
	expect(JSON.stringify(await form()) === JSON.stringify(disconnected), 'After a reload, the form shows the same settings as after Disconnect.')
	console.log('Settings: Disconnect deletes the token and turns off the schedule, and the form shows the settings that stay.')

	await token.fill(TOKEN)
	await localNetwork.check()
	await save.click()
	await settle()
	expect((await message.textContent()) === SAVED, `Connecting again failed: ${await message.textContent()}`)
	expect(!(await schedule.isChecked()), 'The schedule stays off, as run.sh saved it.')
	console.log('Settings: connecting again needs the token and the local network.')

	// The questions of the page are in the language of the administrator.
	await setLanguage('de')
	try {
		await page.reload()
		let german = ''
		page.once('dialog', (dialog) => {
			german = dialog.message()
			dialog.dismiss()
		})
		await reset.click()
		expect(german.startsWith('Paperless trennen? Das gespeicherte API-Token wird gelöscht'), `The question must be German: ${german}`)
		expect(!(await required()), 'Dismissing the question keeps the token.')
		console.log('Settings: the questions of the page are in the language of the administrator.')
	} finally {
		await setLanguage('en')
	}
} finally {
	await browser.close()
}

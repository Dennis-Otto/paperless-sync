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
// leads there. Test connection lists every Paperless permission that the modules need,
// with what to grant, for the token of an account with too few permissions as well, and
// the checklist goes when a setting that it checked changes. The form always shows what
// the server stored, says when it holds unsaved
// changes, and shows every result and error next to the buttons, translated, also when
// a proxy answers with a page of its own. The CSS of Nextcloud, which styles every button
// and input, leaves Disconnect at the end of the bar and every checkbox next to its label.
// The target user comes from the users of Nextcloud and the excluded tags from the tags of
// Paperless, while a tag that Paperless doesn't have yet can still be typed. run.sh starts it in the image of Playwright,
// inside the network of the Compose project, after the accessibility check, while
// Paperless and the saved configuration of run.sh are there, and it leaves that
// configuration as it found it.

import { chromium, request } from 'playwright-core'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
// The synthetic token of the mock, which run.sh saved, to connect again after Disconnect.
const TOKEN = process.env.E2E_TOKEN ?? 'e2e-only-token'
// The synthetic token of an account of the mock that may neither view the tags nor add documents.
const VIEW_ONLY_TOKEN = process.env.E2E_VIEW_ONLY_TOKEN ?? 'e2e-view-only-token'
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
	const test = page.locator('#paperless-sync-test')
	const checklist = page.locator('#paperless-sync-checks')
	// The state of a line of the checklist, from its class: passed, warning or failed.
	const lineState = (id) => page.locator(`[data-check="${id}"]`).evaluate((line) => line.className.replace(/.*paperless-sync-check--/, ''))
	const dryRun = page.locator('#paperless-sync-dry-run')
	const run = page.locator('#paperless-sync-run')
	const reset = page.locator('#paperless-sync-reset')
	const refresh = page.locator('#paperless-sync-refresh-status')
	const unsaved = page.locator('#paperless-sync-unsaved')
	const message = page.locator('#paperless-sync-message')
	const state = page.locator('#paperless-sync-last-state')
	const spinner = page.locator('#paperless-sync-running')
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
		helpLinks.length === 11 && helpLinks.every(([href, label]) => href.startsWith('https://dennis-otto.github.io/paperless-sync/guide.html#') && label.startsWith('Help on ')),
		`Every section, the tags to mirror, the metadata next to the archive, the tags in Nextcloud and sending from Files must link to their parts of the guide: ${JSON.stringify(helpLinks)}`,
	)
	console.log('Settings: Disconnect sits at the end of the bar, checkboxes align with their labels, and every section links to the guide.')

	const savedUrl = await url.inputValue()
	expect(!(await required()), 'With the saved URL, the stored token must be enough.')
	await url.fill(`${savedUrl}/other-app`)
	expect(await required(), 'Another URL must ask for the token again.')
	expect((await token.getAttribute('placeholder')) === 'Required again for a new URL', 'The token must say why it is required again.')
	await url.fill(`${savedUrl}/`)
	expect(!(await required()), 'The saved URL with a slash at its end is the same URL.')
	await url.fill(savedUrl)
	console.log('Settings: another URL asks for the token again, the saved one does not.')

	// The save button says whether saving tests the connection, as the server decides it:
	// when the schedule will run or a token was entered.
	expect((await save.textContent()) === 'Save', `With the schedule off, saving tests nothing: ${await save.textContent()}`)
	await schedule.check()
	expect((await save.textContent()) === 'Test connection and save', 'With the schedule on, saving tests the connection.')
	await schedule.uncheck()
	await token.fill(TOKEN)
	expect((await save.textContent()) === 'Test connection and save', 'A token that was entered is tested.')
	await token.fill('')
	expect((await save.textContent()) === 'Save', 'Without the token, saving tests nothing again.')
	console.log('Settings: the save button says whether saving tests the connection.')

	// The target user comes from the users of Nextcloud, and a user ID that Nextcloud doesn't
	// know is marked before the save.
	const targetUserField = page.locator('#paperless-sync-target-user')
	const userOptions = page.locator('#paperless-sync-target-user-options [role="option"]')
	const userHint = page.locator('#paperless-sync-target-user-hint')
	const savedUser = await targetUserField.inputValue()
	expect((await userHint.textContent()) === '' && (await targetUserField.getAttribute('aria-invalid')) === null, 'The saved target user exists.')
	await targetUserField.fill('sync')
	await userOptions.first().waitFor()
	const offeredUsers = await userOptions.allTextContents()
	expect(
		JSON.stringify(offeredUsers) === JSON.stringify(['sync-target']) && (await targetUserField.getAttribute('aria-expanded')) === 'true',
		`Typing must offer the users whose ID holds the text: ${JSON.stringify(offeredUsers)}`,
	)
	await targetUserField.press('ArrowDown')
	const activeUser = await targetUserField.getAttribute('aria-activedescendant')
	expect(activeUser !== null && (await page.locator(`[id="${activeUser}"]`).getAttribute('aria-selected')) === 'true', 'The down arrow must reach the first user.')
	await targetUserField.press('Enter')
	expect((await targetUserField.inputValue()) === 'sync-target', `Enter must pick the user: ${await targetUserField.inputValue()}`)
	expect((await targetUserField.getAttribute('aria-expanded')) === 'false' && (await message.textContent()) === '', 'Picking a user closes the list and doesn\'t submit the form.')
	await targetUserField.fill('nobody-here')
	await targetUserField.press('Tab')
	await page.locator('#paperless-sync-target-user-hint:not(:empty)').waitFor()
	expect(
		(await userHint.textContent()) === 'Nextcloud has no user with the ID nobody-here. Pick one from the list.' && (await targetUserField.getAttribute('aria-invalid')) === 'true',
		`A user that Nextcloud doesn't know must be marked: ${await userHint.textContent()}`,
	)
	await targetUserField.fill(savedUser)
	await targetUserField.press('Tab')
	await page.locator('#paperless-sync-target-user-hint:empty').waitFor({ state: 'attached' })
	expect((await targetUserField.getAttribute('aria-invalid')) === null && await unsaved.isHidden(), 'The saved user again is no change.')
	console.log('Settings: the target user is picked from the users of Nextcloud, and an unknown user ID is marked before the save.')

	// The excluded tags come from the tags of Paperless; a name that Paperless doesn't have is
	// possible and marked, and the save sends what is typed but not yet added.
	const excludedTags = () => page.locator('[data-setting="excluded_tags"]').inputValue()
	const tagInput = page.locator('#paperless-sync-excluded-tags-input')
	const tagOptions = page.locator('#paperless-sync-excluded-tags-options [role="option"]')
	const chips = page.locator('#paperless-sync-excluded-tags-list li')
	expect((await excludedTags()) === '', `run.sh saves no excluded tags: ${await excludedTags()}`)
	await tagInput.click()
	await tagOptions.first().waitFor()
	// The tags of the mock, and those that the scenario of the inbox created, in the order of
	// their letters, case aside.
	const offeredTags = await tagOptions.allTextContents()
	const inOrder = offeredTags.every((tag, index) => index === 0 || offeredTags[index - 1].localeCompare(tag, 'en', { sensitivity: 'base', numeric: true }) < 0)
	expect(
		['Draft', 'Inbox', 'Private'].every((tag) => offeredTags.includes(tag)) && !offeredTags.includes('Tax, 2026') && inOrder,
		`The field must offer the tags of Paperless that the setting can hold, in order, without "Tax, 2026": ${JSON.stringify(offeredTags)}`,
	)
	await tagInput.fill('pri')
	await page.locator('#paperless-sync-excluded-tags-options [role="option"]', { hasText: 'Private' }).click()
	expect((await excludedTags()) === 'Private' && (await chips.allTextContents()).join('|') === 'Private×', `A click must add the tag: ${await excludedTags()}`)
	expect(await unsaved.isVisible() && await focused(tagInput), 'A new tag is an unsaved change, and the focus stays in the field.')
	await tagInput.fill('Drfat')
	const addTyped = page.locator('#paperless-sync-excluded-tags-options [role="option"]', { hasText: 'Add "Drfat"' })
	await addTyped.waitFor()
	expect((await addTyped.textContent()).includes('not in Paperless'), 'Adding a name that Paperless doesn\'t have must say so.')
	await save.click()
	await settle()
	expect((await message.textContent()) === SAVED_UNTESTED, `The save of the tags failed: ${await message.textContent()}`)
	expect((await excludedTags()) === 'Private, Drfat', `The save must send the name that was typed too: ${await excludedTags()}`)
	expect((await chips.nth(1).textContent()).includes('not in Paperless') && !(await chips.nth(0).textContent()).includes('not in Paperless'), 'A tag that Paperless doesn\'t have must be marked.')
	expect(await unsaved.isHidden(), 'After the save, the tags are saved.')
	await tagInput.fill('draft')
	await tagInput.press('Enter')
	expect((await excludedTags()) === 'Private, Drfat, Draft', `A typed name must take the spelling of Paperless: ${await excludedTags()}`)
	expect((await tagInput.getAttribute('aria-expanded')) === 'false', 'Enter adds the name and closes the list.')
	await tagInput.press('Backspace')
	expect((await excludedTags()) === 'Private, Drfat' && await unsaved.isHidden(), 'Backspace in the empty field must remove the last tag.')
	await tagInput.fill('private')
	expect(await unsaved.isVisible(), 'A typed name is an unsaved change.')
	await tagInput.press('Enter')
	expect((await excludedTags()) === 'Private, Drfat' && await unsaved.isHidden(), 'A name that is already in the list changes nothing.')
	await page.getByRole('button', { name: 'Remove Drfat' }).click()
	await page.getByRole('button', { name: 'Remove Private' }).click()
	expect((await excludedTags()) === '' && await chips.count() === 0 && await focused(tagInput), 'The buttons of the tags must remove them and leave the focus in the field.')
	await save.click()
	await settle()
	expect((await message.textContent()) === SAVED_UNTESTED && (await excludedTags()) === '', `Removing the tags again failed: ${await message.textContent()}`)
	console.log('Settings: the excluded tags are picked from the tags of Paperless, a tag that Paperless doesn\'t have yet can be typed and is marked, and the save sends what is typed.')

	// The tags for every import come from the same tags of Paperless, picked with the keyboard.
	const inboxTags = () => page.locator('[data-setting="inbox_tags"]').inputValue()
	const inboxTagInput = page.locator('#paperless-sync-inbox-tags-input')
	const inboxTagOptions = page.locator('#paperless-sync-inbox-tags-options [role="option"]')
	expect((await inboxTags()) === '', `run.sh leaves no tags for every import: ${await inboxTags()}`)
	await inboxTagInput.focus()
	await inboxTagInput.press('ArrowDown')
	await page.locator('#paperless-sync-inbox-tags-options [aria-selected="true"]').waitFor()
	const offeredInboxTags = await inboxTagOptions.allTextContents()
	expect(JSON.stringify(offeredInboxTags) === JSON.stringify(offeredTags), `The tags for every import must offer the same tags: ${JSON.stringify(offeredInboxTags)}`)
	await inboxTagInput.press('Enter')
	expect((await inboxTags()) === offeredTags[0] && await unsaved.isVisible(), `Enter must add the tag that the arrow reached: ${await inboxTags()}`)
	expect((await page.locator('#paperless-sync-inbox-tags-announcement').textContent()) === `Added: ${offeredTags[0]}`, 'A screen reader must hear which tag was added.')
	await inboxTagInput.press('Backspace')
	expect((await inboxTags()) === '' && await unsaved.isHidden(), 'Backspace must remove the tag again.')
	console.log('Settings: the tags for every import are picked from the tags of Paperless as well, also with the keyboard.')

	// The tags to mirror come from the same tags of Paperless, and a change shows the paths of the
	// preview anew, which then marks the newest documents that lack every tag to mirror.
	const includedTags = () => page.locator('[data-setting="included_tags"]').inputValue()
	const includedTagInput = page.locator('#paperless-sync-included-tags-input')
	const includedTagOptions = page.locator('#paperless-sync-included-tags-options [role="option"]')
	expect((await includedTags()) === '', `run.sh leaves no tags to mirror: ${await includedTags()}`)
	await includedTagInput.click()
	await includedTagOptions.first().waitFor()
	const offeredIncludedTags = await includedTagOptions.allTextContents()
	expect(JSON.stringify(offeredIncludedTags) === JSON.stringify(offeredTags), `The tags to mirror must offer the same tags: ${JSON.stringify(offeredIncludedTags)}`)
	await includedTagInput.fill('draft')
	await includedTagInput.press('Enter')
	expect((await includedTags()) === 'Draft' && await unsaved.isVisible(), `A typed tag to mirror must take the spelling of Paperless: ${await includedTags()}`)
	// No document of the mock has Draft, so the preview marks each of the newest as skipped.
	await page.waitForFunction(() => {
		const items = [...document.querySelectorAll('#paperless-sync-path-preview-list li')]
		return items.length > 0 && items.every((item) => item.querySelector('.paperless-sync-path-preview-skipped') !== null)
	}, null, { timeout: 5000 })
	await includedTagInput.press('Backspace')
	expect((await includedTags()) === '' && await unsaved.isHidden(), 'Backspace must remove the tag to mirror again.')
	// A tag to mirror that Paperless lacks stops the runs, so the preview says why and marks the
	// tag field, not the valid path template.
	await includedTagInput.fill('Nextclod,')
	await page.locator('#paperless-sync-path-preview-status', { hasText: 'Paperless has no tag named "Nextclod"' }).waitFor({ timeout: 5000 })
	expect(
		(await page.locator('#paperless-sync-path-template').getAttribute('aria-invalid')) === null && (await includedTagInput.getAttribute('aria-invalid')) === 'true',
		'A missing tag to mirror marks the tag field as invalid, not the path template.',
	)
	await includedTagInput.press('Backspace')
	await page.locator('#paperless-sync-path-preview-list li').first().waitFor({ timeout: 5000 })
	expect((await includedTags()) === '' && (await includedTagInput.getAttribute('aria-invalid')) === null, 'Without the missing tag, the tag field is valid again.')
	console.log('Settings: the tags to mirror are picked from the tags of Paperless, and the preview marks the documents without one of them.')

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
	expect(await dryRun.isEnabled(), 'A dry-run of the unsaved settings stays possible.')
	expect(await asksBeforeLeaving(), 'Leaving the page with a change must ask first.')
	await batchSize.fill(savedBatchSize)
	expect(await unsaved.isHidden(), 'Undoing the change leaves nothing unsaved.')
	expect(await run.isEnabled(), '"Synchronize now" is possible again.')
	expect(!(await asksBeforeLeaving()), 'Leaving the page without a change asks nothing.')
	console.log('Settings: a change shows the note, holds "Synchronize now" back and asks before leaving the page.')

	// The preview under the path template shows where the newest documents of Paperless go
	// with the template of the form, once the typing pauses, without a save; an invalid
	// template says why, as a save would, and a variable goes in at the cursor.
	const template = page.locator('#paperless-sync-path-template')
	const previewList = page.locator('#paperless-sync-path-preview-list')
	const savedTemplate = await template.inputValue()
	await template.fill('{{ created_year }}/{{ title }} [P{{ id }}]')
	await previewList.locator('li', { hasText: /^\d{4}\/[^/]+ \[P\d+\]\.\w+/ }).first().waitFor({ timeout: 5000 })
	expect(await unsaved.isVisible(), 'A new template is an unsaved change.')
	await template.fill('{{ titel }} [P{{ id }}]')
	await page.locator('#paperless-sync-path-preview-status', { hasText: 'Unknown archive path variable: {{ titel }}.' }).waitFor({ timeout: 5000 })
	expect((await template.getAttribute('aria-invalid')) === 'true' && await previewList.isHidden(), 'An invalid template is marked as invalid, without paths.')
	await template.fill('[P{{ id }}]')
	await template.press('Home')
	await page.locator('[data-variable="created_year"]').click()
	expect((await template.inputValue()) === '{{ created_year }}[P{{ id }}]', `The variable must go in at the cursor: ${await template.inputValue()}`)
	await template.fill(savedTemplate)
	await previewList.locator('li').first().waitFor({ timeout: 5000 })
	expect((await template.getAttribute('aria-invalid')) === null && await unsaved.isHidden(), 'The saved template is valid and nothing is unsaved.')
	console.log('Settings: the preview shows the paths of the newest documents with the template of the form, and a variable goes in at the cursor.')

	// A dry-run tries the unsaved settings of the form, and the report says so; without a
	// change, it sends none.
	const sentRuns = []
	await page.route(`**${RUN}`, async (route) => {
		sentRuns.push(route.request().postDataJSON())
		await route.continue()
	})
	await template.fill('{{ created_year }}/{{ title }} [P{{ id }}]')
	await dryRun.click()
	await settle()
	expect(sentRuns[0].dryRun === true && sentRuns[0].settings?.path_template === '{{ created_year }}/{{ title }} [P{{ id }}]', `The dry-run must send the template of the form: ${JSON.stringify(sentRuns[0])}`)
	expect((await page.locator('#paperless-sync-report-meta').textContent()).startsWith('Dry-run of unsaved settings, finished'), `The report must say that it shows unsaved settings: ${await page.locator('#paperless-sync-report-meta').textContent()}`)
	expect(await unsaved.isVisible(), 'The dry-run saves nothing.')
	await template.fill(savedTemplate)
	await dryRun.click()
	await settle()
	expect(sentRuns[1].settings === undefined, `Without a change, the dry-run sends no settings: ${JSON.stringify(sentRuns[1])}`)
	await page.unroute(`**${RUN}`)
	console.log('Settings: a dry-run tries the unsaved settings without saving them.')

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

	// A token that is entered is tested, also with the schedule off. The setting that the
	// message names lies under the bar of the buttons, which sticks to the bottom of the
	// window, and the focus must bring it into view.
	await token.fill(TOKEN)
	await localNetwork.uncheck()
	// Test connection leads to the setting that its failed check names, as a refused save does.
	await test.click()
	await settle()
	expect(await lineState('paperless') === 'failed', 'Test connection must report the refused local address.')
	expect(await focused(localNetwork), 'The focus must move to the setting that the failed check names.')
	await localNetwork.evaluate((input) => input.scrollIntoView({ block: 'end' }))
	await save.click()
	await settle()
	const refusal = await message.textContent()
	expect(
		refusal === 'Nextcloud blocks connections to local addresses such as paperless-mock. If Paperless runs in your network, turn on "Paperless runs in my local network" and save the settings.',
		`A refused local address must say what to do: ${refusal}`,
	)
	expect(await focused(localNetwork), 'The focus must move to the setting that the message names.')
	const inView = () => {
		const input = document.querySelector('[data-setting="paperless_local_network"]').getBoundingClientRect()
		const bar = document.querySelector('.paperless-sync-actions').getBoundingClientRect()
		return input.top >= 0 && input.bottom <= bar.top
	}
	const covered = await page.waitForFunction(inView, null, { timeout: 5000 }).then(() => false, () => true)
	expect(!covered, `The setting with the focus must be in view, above the bar of the buttons: ${JSON.stringify(await localNetwork.boundingBox())}`)
	await localNetwork.check()
	await save.click()
	await settle()
	expect((await message.textContent()) === SAVED, `The save with the local network allowed failed: ${await message.textContent()}`)
	expect((await token.inputValue()) === '', 'The page forgets the token once it is saved.')
	console.log('Settings: Nextcloud refuses a Paperless in the local network until its setting allows it, and the page leads there.')

	// The save tested every permission that the export and the inbox need, and shows how.
	const lines = await page.locator('#paperless-sync-checks-list > li').evaluateAll((items) => items.map((item) => item.dataset.check))
	expect(await checklist.isVisible(), 'A save that tested the connection shows its checklist.')
	expect(
		JSON.stringify(lines) === JSON.stringify(['paperless', 'documents', 'trash', 'correspondents', 'document_types', 'storage_paths', 'tags', 'upload', 'folder']),
		`The checklist must list every check of the export and the inbox: ${JSON.stringify(lines)}`,
	)
	expect((await page.locator('.paperless-sync-check--passed').count()) === lines.length, 'Every check passes with the token of the mock.')

	// The token of an account that may view the documents but not the tags passed the old test
	// of the documents alone; every export failed afterwards. Test connection names the
	// permissions to grant, without saving anything.
	await token.fill(VIEW_ONLY_TOKEN)
	expect(await checklist.isHidden(), 'A new token makes the checklist outdated.')
	await test.click()
	await settle()
	const tested = await message.textContent()
	expect(tested === '2 checks failed. The connection checklist says what to do.', `The test must count the failed checks: ${tested}`)
	expect(await lineState('tags') === 'failed' && await lineState('upload') === 'failed', 'The tags and the upload must fail.')
	expect(
		(await page.locator('[data-check="tags"] .paperless-sync-check-hint').textContent()) === 'In Paperless, under Users & Groups, give the account the permission View for Tags.',
		'The line of the tags must name the permission to grant.',
	)
	expect(await lineState('trash') === 'warning', 'An account that sees documents of another owner gets a note: the trash leaves them out.')
	expect((await page.locator('[data-check="tags"] .paperless-sync-check-state').textContent()) === 'Failed', 'The state of a line is a word, not a color alone.')
	expect(await unsaved.isVisible(), 'The test saves nothing: the token stays an unsaved change.')
	// The group that may send files decides whether the checklist tests the upload, so a change
	// of it makes the checklist outdated as well.
	const sendGroup = page.locator('#paperless-sync-send-group')
	const savedSendGroup = await sendGroup.inputValue()
	await sendGroup.fill(savedSendGroup === '' ? 'admin' : '')
	expect(await checklist.isHidden(), 'A change of the group that may send files makes the checklist outdated.')
	await sendGroup.fill(savedSendGroup)
	await sendGroup.press('Escape')
	await token.fill('')
	expect(await unsaved.isHidden() && await checklist.isHidden(), 'Without the token, nothing is unsaved, and the checklist of the other token is gone.')
	console.log('Settings: Test connection names every Paperless permission that a token lacks, with what to grant, and saves nothing.')

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
	expect((await message.getAttribute('role')) === 'alert', 'An error must be an alert.')
	expect(await focused(url), 'The focus must move to the setting that the error names.')
	await url.fill(savedUrl)
	// An error that names no setting takes the focus itself.
	const archiveFolder = page.locator('[data-setting="archive_folder"]')
	const savedArchiveFolder = await archiveFolder.inputValue()
	await archiveFolder.fill('Archiv/2026')
	await save.click()
	await settle()
	expect((await message.textContent()) === 'Folder names must contain exactly one path component.', `A nested folder name must be refused: ${await message.textContent()}`)
	expect(await focused(message), 'An error that names no setting must take the focus.')
	await archiveFolder.fill(savedArchiveFolder)
	await token.fill('')
	expect(await unsaved.isHidden(), 'Undoing the changes leaves nothing unsaved.')
	console.log('Settings: an error appears next to the buttons, in view, and moves the focus to the setting that it names, or takes it.')

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

	// Neither the run nor the status answers: how the run ended is unknown, and the status
	// shows the last state that the server sent, not a dry-run that never ends.
	const stateBefore = await state.textContent()
	await page.route(`**${RUN}`, (route) => route.fulfill({ status: 504, contentType: 'text/html', body: GATEWAY_TIMEOUT }))
	await page.route(`**${STATUS}`, (route) => route.fulfill({ status: 503, contentType: 'text/html', body: '<html><body>Service Unavailable</body></html>' }))
	await dryRun.click()
	const unknown = await messageAfter('Calculating synchronization changes…')
	await settle()
	expect(
		unknown === 'The web server answered with status 504, and the state of the run could not be read. Choose "Refresh" to see how the run ended.',
		`Without the status, the page must not point to it: ${unknown}`,
	)
	expect(
		(await state.textContent()) === stateBefore && await spinner.isHidden(),
		`The status must show the last state that the server sent, ${stateBefore}, not ${await state.textContent()}.`,
	)
	await page.unroute(`**${STATUS}`)
	await page.unroute(`**${RUN}`)
	console.log('Settings: when neither the run nor the status answers, the status keeps the last state that the server sent.')

	// "Synchronize now" queues the run as a background job and answers at once. The page
	// follows the status, also past an answer that fails, until the run ends, and then says
	// how it ended, with the count of its errors.
	const now = Math.floor(Date.now() / 1000)
	const queuedStatus = { state: 'queued', lastStarted: 0, lastCompleted: 0, lastEnded: 0, lastSuccess: 0, summary: {}, error: '', report: {}, nextRun: null }
	const statusAnswers = [
		{ status: 503, contentType: 'text/html', body: '<html><body>Service Unavailable</body></html>' },
		{ json: { ...queuedStatus, state: 'running', lastStarted: now } },
		{
			json: {
				...queuedStatus,
				state: 'completed-with-errors',
				lastStarted: now,
				lastCompleted: now + 1,
				lastEnded: now + 1,
				report: {
					dryRun: false,
					completedAt: now + 1,
					exported: 1,
					errors: 2,
					unchanged: 11,
					skipped: 1,
					omittedActions: 0,
					errorMessages: ['P7: Synthetic failure.', 'P8: Synthetic failure.'],
					actions: ['EXPORT P9: Documents/Paperless/Archive/P9.pdf', 'ERROR: P7: Synthetic failure.', 'ERROR: P8: Synthetic failure.'],
					entries: [
						{ type: 'export', document: 9, path: 'Documents/Paperless/Archive/P9.pdf' },
						{ type: 'error', document: 7, detail: 'P7: Synthetic failure.' },
						{ type: 'error', document: 8, detail: 'P8: Synthetic failure.' },
					],
				},
			},
		},
	]
	// The status at the end of the run, whose report the page shows in German too.
	const finishedStatus = statusAnswers[statusAnswers.length - 1]
	await page.route(`**${RUN}`, (route) => route.fulfill({ status: 202, json: { queued: true, status: queuedStatus } }))
	await page.route(`**${STATUS}`, (route) => route.fulfill(statusAnswers.length > 1 ? statusAnswers.shift() : statusAnswers[0]))
	// The report opens the folder of a file only for the owner of the archive, the target user,
	// so the administrator of the check takes that role here.
	const targetUser = await page.locator('[data-setting="target_user"]').inputValue()
	await page.evaluate((uid) => {
		window.OC.getCurrentUser = () => ({ uid, displayName: uid })
	}, targetUser)
	page.once('dialog', (dialog) => dialog.accept())
	await run.click()
	const queued = await messageAfter('Queuing the synchronization…')
	expect(
		queued === 'The synchronization starts with the next background jobs of Nextcloud, within a few minutes. The status above follows it, and this message says how it ends.',
		`A queued run must say that it waits for the background jobs: ${queued}`,
	)
	expect((await state.textContent()) === 'Queued', 'The status must show the queued run.')
	expect(await spinner.isVisible(), 'The status must show that a run is coming.')
	const withErrors = await messageAfter(queued)
	expect(withErrors === 'Synchronization completed with 2 errors. See the report.', `A run with errors must count them: ${withErrors}`)
	expect(await page.locator('#paperless-sync-report').isVisible(), 'The report of the run must be shown.')
	expect((await state.textContent()) !== 'Queued' && await spinner.isHidden(), 'The status must show that the run has ended.')
	await page.unroute(`**${RUN}`)
	console.log('Settings: "Synchronize now" queues the run, follows it through the status past a failed answer, and counts its errors.')

	// The report: a heading of its own, only the counts that aren't 0 and a sentence for the
	// rest, the errors first with their badges, a filter for each kind, and a link to the
	// folder of a file that the run wrote, inside a region that scrolls with the keyboard.
	const shownReport = () => page.locator('#paperless-sync-report').evaluate((report) => ({
		title: report.querySelector('#paperless-sync-report-title').textContent,
		counts: Array.from(report.querySelectorAll('.paperless-sync-count'), (count) => `${count.lastChild.textContent} ${count.firstChild.textContent}`),
		rest: report.querySelector('#paperless-sync-report-rest').textContent,
		badges: Array.from(report.querySelectorAll('#paperless-sync-report-entries li:not([hidden]) .paperless-sync-badge'), (badge) => badge.textContent),
		filters: Array.from(report.querySelectorAll('#paperless-sync-report-filters button'), (button) => `${button.textContent}${button.getAttribute('aria-pressed') === 'true' ? ' (on)' : ''}`),
		links: Array.from(report.querySelectorAll('a.paperless-sync-entry-file'), (link) => link.getAttribute('href').replace(/^.*(?=\/apps\/files\/)/, '')),
		region: `${report.querySelector('#paperless-sync-report-actions').getAttribute('role')} ${report.querySelector('#paperless-sync-report-actions').tabIndex}`,
	}))
	const shown = await shownReport()
	const expected = {
		title: 'Run report',
		counts: ['Errors 2', 'Exported 1'],
		rest: '11 unchanged, 1 skipped',
		badges: ['Error', 'Error', 'Export'],
		filters: ['All3 (on)', 'Error2', 'Export1'],
		links: ['/apps/files/?dir=%2FDocuments%2FPaperless%2FArchive'],
		region: 'region 0',
	}
	expect(JSON.stringify(shown) === JSON.stringify(expected), `The report must show ${JSON.stringify(expected)}, not ${JSON.stringify(shown)}`)
	await page.locator('#paperless-sync-report-filters button[data-filter="export"]').click()
	const filtered = await shownReport()
	expect(JSON.stringify(filtered.badges) === '["Export"]' && filtered.filters[2] === 'Export1 (on)', `The filter must show the export alone: ${JSON.stringify(filtered)}`)
	await page.locator('#paperless-sync-report-filters button[data-filter=""]').click()
	// "Copy report" copies the heading, the counts and the lines in English, for a bug report.
	// The page of the check is plain HTTP, where the browser has no Clipboard API, so the page
	// copies the text that it selects; the check takes the text from the copy command.
	await page.evaluate(() => {
		document.execCommand = (command) => {
			window.copiedReport = command === 'copy' ? document.querySelector('.paperless-sync-copy-area').value : ''
			return command === 'copy'
		}
	})
	await page.locator('#paperless-sync-report-copy').click()
	await page.waitForFunction(() => document.querySelector('#paperless-sync-message').textContent === 'The report is copied to the clipboard.')
	const copied = await page.evaluate(() => window.copiedReport)
	expect(
		copied.startsWith('Run report\nRun, finished ') && copied.includes('\nErrors: 2, Exported: 1\n11 unchanged, 1 skipped\n\nEXPORT P9: Documents/Paperless/Archive/P9.pdf\nERROR: P7: Synthetic failure.\n'),
		`The copied report must have its heading, its counts and its lines: ${copied}`,
	)
	console.log('Settings: the report shows the errors first, only the counts that are not 0, a filter for each kind, links to the files and copies itself.')

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

	// The webhook of Paperless, whose secret run.sh created: a new secret replaces it, after a
	// question, and the page shows it this once, selected for copying; a reload has it no more.
	const webhookCreate = page.locator('#paperless-sync-webhook-create')
	const webhookSecret = page.locator('#paperless-sync-webhook-secret')
	expect((await webhookCreate.textContent()) === 'Create a new secret', `run.sh created the secret of the webhook: ${await webhookCreate.textContent()}`)
	expect(await page.locator('#paperless-sync-webhook-remove').isVisible(), 'A webhook with a secret can be turned off.')
	expect(await webhookSecret.isHidden(), 'The page shows no secret before one is created.')
	let webhookQuestion = ''
	page.once('dialog', (dialog) => {
		webhookQuestion = dialog.message()
		dialog.accept()
	})
	await webhookCreate.click()
	await settle()
	expect(webhookQuestion.startsWith('Create a new secret? The secret before stops working at once'), `A new secret must say what it does: ${webhookQuestion}`)
	expect((await message.textContent()) === 'Secret created. Copy it now: it is shown only this once.', `Creating the secret failed: ${await message.textContent()}`)
	const secret = await webhookSecret.inputValue()
	expect(/^[A-Za-z0-9]{48}$/.test(secret) && await focused(webhookSecret), 'The new secret shows once, with the focus on it for copying.')
	await page.reload()
	expect(await webhookSecret.isHidden() && !(await page.content()).includes(secret), 'After a reload, the page has the secret no more.')
	expect((await page.locator('#paperless-sync-webhook-state').textContent()) === 'The webhook accepts calls with its secret.', 'The webhook keeps its new secret.')
	console.log('Settings: a new secret of the webhook replaces the old one, shows once, and a reload has it no more.')

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
		expect((await save.textContent()) === 'Speichern', `The save button must be German: ${await save.textContent()}`)
		expect(!(await required()), 'Dismissing the question keeps the token.')
		console.log('Settings: the questions of the page are in the language of the administrator.')

		// The times of the runs are in the format of the locale of Nextcloud, not in that of the
		// browser, which is en-US here and would write "Oct 14, 2026".
		await page.waitForFunction(() => /\d/.test(document.querySelector('#paperless-sync-last-ended').textContent), null, { timeout: 10000 })
		const time = await page.locator('#paperless-sync-last-ended').textContent()
		expect(/^\d{1,2}\.\d{1,2}\.\d{4}/.test(time), `The time of the last run must be in the German format: ${time}`)
		console.log('Settings: the times of the runs are in the format of the locale of Nextcloud.')

		// The report is German too, but for the lines of the errors, which stay as the run wrote them.
		await page.route(`**${STATUS}`, (route) => route.fulfill(finishedStatus))
		await refresh.click()
		await page.waitForFunction(() => document.querySelector('#paperless-sync-report-title').textContent === 'Laufbericht')
		const germanReport = await page.locator('#paperless-sync-report').evaluate((report) => [
			...Array.from(report.querySelectorAll('.paperless-sync-badge'), (badge) => badge.textContent),
			report.querySelector('#paperless-sync-report-rest').textContent,
			report.querySelector('#paperless-sync-report-copy').textContent,
		])
		const germanExpected = ['Fehler', 'Fehler', 'Export', '11 unverändert, 1 übersprungen', 'Bericht kopieren']
		expect(JSON.stringify(germanReport) === JSON.stringify(germanExpected), `The report must be German: ${JSON.stringify(germanReport)}`)
		await page.unroute(`**${STATUS}`)
		console.log('Settings: the report is in the language of the administrator.')

		// So is what the server refuses, and the setting that it names gets the focus. The
		// browser checks the numbers itself, so a URL that only the server refuses.
		const urlBefore = await url.inputValue()
		await url.fill('ftp://paperless-mock:8080')
		await token.fill(TOKEN)
		await save.click()
		await settle()
		const refusal = await message.textContent()
		expect(refusal === 'Die Paperless-Adresse muss HTTP oder HTTPS verwenden.', `The refusal must be German: ${refusal}`)
		expect(await focused(url), 'The focus must move to the setting that the refusal names.')
		await url.fill(urlBefore)
		await token.fill('')
		console.log('Settings: what the server refuses is in the language of the administrator, and the setting that it names gets the focus.')
	} finally {
		await setLanguage('en')
	}
} finally {
	await browser.close()
}

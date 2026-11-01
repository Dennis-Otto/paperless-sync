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
//
// The note above the list: sync-target, the target user of run.sh, finds it in the archive and
// below it, in the inbox and in the error folder, in the words of each, and in no other folder or
// the trash bin. They hide each kind on its own, also after loading the page again, and bring it
// back. e2e-other finds it only in the archive that sync-target shares with them, at the path of
// that share, and learns nothing else from the app.

import { launch } from './browser.mjs'
import { filesAddress, folderAddress } from './files-address.mjs'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const ADMIN = process.env.E2E_USER ?? 'e2e-admin'
const OTHER = process.env.E2E_OTHER_USER ?? 'e2e-other'
const TARGET = process.env.E2E_TARGET_USER ?? 'sync-target'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const SEND = 'Send to Paperless'
const OPEN = 'Open in Paperless'
// The folders of the app that run.sh saves in the settings.
const BASE = '/Documents/Paperless'
const ARCHIVE = `${BASE}/Archive`
const INBOX = `${BASE}/Inbox`
const ERRORS = `${BASE}/Errors`
// A folder below the archive, for this check alone.
const BELOW = 'e2e-below-the-archive'
const NOTE = '.paperless-sync-folder-note'
const QUESTION = '.paperless-sync-folder-question'
const FOLDER_INFO = '**/apps/paperless_sync/api/v1/folder-info'
const FOLDER_INFO_PATH = /\/apps\/paperless_sync\/api\/v1\/folder-info$/
const DISMISSED = '**/apps/paperless_sync/api/v1/folder-info/dismissed'

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
	await page.goto(folderAddress(await filesAddress(page), '/'))
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

// A call of WebDAV or of the sharing API as the user, as run.sh makes them.
function asUser(user, path, options) {
	return fetch(`${BASE_URL}${path}`, {
		...options,
		headers: {
			Authorization: `Basic ${Buffer.from(`${user}:${PASSWORD}`).toString('base64')}`,
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
			...options.headers,
		},
	})
}

async function setLocaleOfTarget(locale) {
	const response = await asUser(TARGET, `/ocs/v2.php/cloud/users/${TARGET}`, {
		method: 'PUT',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ key: 'locale', value: locale }),
	})
	expect(response.ok, `The locale of the target user could not be set: HTTP ${response.status}`)
}

function folderOfTarget(method, name) {
	return asUser(TARGET, `/remote.php/dav/files/${TARGET}${ARCHIVE}/${name}`, { method })
}

// Shares the archive of the target user with the user, read-only, and gives the ID of the share
// and the path at which the user finds the folder.
async function shareArchive(user) {
	const response = await asUser(TARGET, '/ocs/v2.php/apps/files_sharing/api/v1/shares', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ path: ARCHIVE, shareType: 0, shareWith: user, permissions: 1 }),
	})
	expect(response.ok, `The share of the archive failed: HTTP ${response.status}`)
	const { ocs: { data } } = await response.json()
	return { id: data.id, path: data.file_target }
}

// Opens an address of Files and gives the answer of the app, which the note needs, once it came.
async function openAddress(page, address) {
	const answer = page.waitForResponse((response) => FOLDER_INFO_PATH.test(new URL(response.url()).pathname))
	await page.goto(address)
	await page.locator('[data-cy-files-list]').waitFor()
	return answer
}

function openFolder(page, files, dir) {
	return openAddress(page, folderAddress(files, dir))
}

// Whether there is neither a note nor what is left of one, a moment after the app answered.
async function expectNoNote(page, where) {
	await page.waitForTimeout(300)
	expect(await page.locator(`${NOTE}, ${QUESTION}`).count() === 0, `${where} has no note.`)
}

async function expectNote(page, kind, ...texts) {
	const note = page.locator(`${NOTE}[data-kind="${kind}"]`)
	await note.waitFor()
	const text = await note.innerText()
	for (const part of texts) {
		expect(text.includes(part), `The note of the ${kind} must say "${part}": ${text}`)
	}
	return note
}

// Answers the question of the note for the folders of the user with some of its fields changed, by
// an object or by a function that gives one as the answer goes out, as a slow page must not age a
// moment that the note words in minutes.
function changeFolderInfo(page, change) {
	return page.route(FOLDER_INFO, async (route) => {
		const response = await route.fetch({ maxRetries: 3 })
		await route.fulfill({ response, json: { ...(await response.json()), ...(typeof change === 'function' ? change() : change) } })
	})
}

function secondsFromNow(seconds) {
	return Math.round(Date.now() / 1000) + seconds
}

// The inbox says when the next run sends its files, for each of the answers of the app.
async function checkTheNextRun(page, files) {
	for (const [seconds, words] of [
		[null, 'Scheduled synchronization is off'],
		[-60, 'with the next background job'],
		[10 * 60, 'in about 10 minutes'],
		[3 * 3600, 'in about 3 hours'],
		[3 * 86400, 'in about 3 days'],
	]) {
		await changeFolderInfo(page, () => ({ nextRun: seconds === null ? null : secondsFromNow(seconds) }))
		await openFolder(page, files, INBOX)
		await expectNote(page, 'inbox', words, 'Videos, programs and archives stay here.')
		await page.unroute(FOLDER_INFO)
	}
}

// A kind of the note hides for this user, and only that kind, also after loading the page again;
// the focus stays with the button that took the place of the last one; and the question brings it
// back. When the app can't save that, a message says so.
async function checkHidingTheNote(page, files) {
	await openFolder(page, files, ERRORS)
	const note = await expectNote(page, 'errors', '.error.txt')
	const hidden = page.waitForResponse((response) => response.url().endsWith('/folder-info/dismissed'))
	await note.getByRole('button', { name: 'Hide this note' }).click()
	await hidden
	const question = page.locator(`${QUESTION}[data-kind="errors"] button`)
	await question.waitFor()
	expect(await question.evaluate((button) => button === document.activeElement), 'The focus goes to the question that took the place of the button.')
	expect(await page.locator(NOTE).count() === 0, 'A hidden note is gone at once.')

	await openFolder(page, files, ERRORS)
	await question.waitFor()
	await openFolder(page, files, INBOX)
	await expectNote(page, 'inbox')

	await openFolder(page, files, ERRORS)
	const shown = page.waitForResponse((response) => response.url().endsWith('/folder-info/dismissed'))
	await question.click()
	await shown
	const back = await expectNote(page, 'errors', '.error.txt')
	const hide = back.getByRole('button', { name: 'Hide this note' })
	expect(await hide.evaluate((button) => button === document.activeElement), 'The focus goes to the button that hides the note again.')
	await openFolder(page, files, ERRORS)
	await expectNote(page, 'errors')
	console.log('Files: a user hides a kind of the note, it stays hidden on the next page, and the question brings it back.')

	await page.route(DISMISSED, (route) => route.abort())
	await hide.click()
	await page.getByText('Your choice could not be saved', { exact: false }).first().waitFor()
	await page.unroute(DISMISSED)
	await page.route(DISMISSED, (route) => route.fulfill({ status: 500, json: {} }))
	await openFolder(page, files, ERRORS)
	await hide.click()
	await page.getByText('Your choice could not be saved', { exact: false }).first().waitFor()
	await page.unroute(DISMISSED)
	await openFolder(page, files, ERRORS)
	await expectNote(page, 'errors')
	console.log('Files: when the choice can\'t be saved, a message says so, and the note comes back.')
}

async function checkTheFoldersOfTheTarget(browser) {
	const { context, page } = await signIn(browser, TARGET)
	const files = await filesAddress(page)
	const made = await folderOfTarget('MKCOL', BELOW)
	expect(made.status === 201, `The folder below the archive could not be made: HTTP ${made.status}`)
	try {
		await openFolder(page, files, '/')
		await expectNoNote(page, 'The files of the user outside the folders of the app')
		await openFolder(page, files, BASE)
		await expectNoNote(page, 'The base folder, which holds the folders of the app,')

		// From the base folder into the folders of the app, as Files changes its folder without
		// loading the page: the note follows.
		await page.locator('[data-cy-files-list-row-name="Archive"] [data-cy-files-list-row-name-link]').click()
		const updated = await expectNote(page, 'archive', 'Mirror of Paperless', 'don\'t reach Paperless', 'Open Paperless')
		await page.locator(`[data-cy-files-list-row-name="${BELOW}"] [data-cy-files-list-row-name-link]`).click()
		await page.waitForURL((url) => url.searchParams.get('dir') === `${ARCHIVE}/${BELOW}`)
		await expectNote(page, 'archive', 'Mirror of Paperless')
		const link = updated.getByRole('link', { name: 'Open Paperless' })
		expect(await link.getAttribute('target') === '_blank' && /^https?:\/\//.test(await link.getAttribute('href')), 'Open Paperless opens the address of the settings in a window of its own.')
		console.log('Files: the note follows into the archive and below it.')

		await changeFolderInfo(page, () => ({ lastSuccess: secondsFromNow(-5 * 60) }))
		await openFolder(page, files, ARCHIVE)
		await expectNote(page, 'archive', 'Updated 5 minutes ago')
		// The times stand in a sentence of the language, so a locale of another language stays out.
		await setLocaleOfTarget('de_DE')
		try {
			await openFolder(page, files, ARCHIVE)
			await expectNote(page, 'archive', 'Updated 5 minutes ago')
		} finally {
			await setLocaleOfTarget('en_US')
		}
		await page.unroute(FOLDER_INFO)
		await changeFolderInfo(page, { lastSuccess: 0 })
		await openFolder(page, files, ARCHIVE)
		await expectNote(page, 'archive', 'Not updated yet')
		await page.unroute(FOLDER_INFO)

		await checkTheNextRun(page, files)
		const inbox = await expectNote(page, 'inbox')
		await inbox.getByRole('link', { name: 'Errors' }).click()
		await page.waitForURL((url) => url.searchParams.get('dir') === ERRORS)
		await expectNote(page, 'errors', 'These files did not reach Paperless.', 'The reason of each failure is in the .error.txt next to the file.')
		console.log('Files: the inbox says when its files go to Paperless and links the error folder, which says where the reasons are.')

		// The trash bin has other paths: a folder at its root, of the same path as the root of the
		// files of the user, has no note, which it has among the files.
		await changeFolderInfo(page, { folders: [{ kind: 'archive', id: 0, path: '' }] })
		await openFolder(page, files, '/')
		await expectNote(page, 'archive')
		await openAddress(page, `${files.replace(/\/+$/, '')}/trashbin`)
		await expectNoNote(page, 'The trash bin')
		await page.unroute(FOLDER_INFO)
		// No answer, or not one of the app, leaves no note, and Files goes on.
		await page.route(FOLDER_INFO, (route) => route.abort())
		await page.goto(folderAddress(files, ARCHIVE))
		await page.locator('[data-cy-files-list]').waitFor()
		await expectNoNote(page, 'A folder without an answer of the app')
		await page.unroute(FOLDER_INFO)
		await page.route(FOLDER_INFO, (route) => route.fulfill({ status: 503, body: 'Unavailable' }))
		await openFolder(page, files, ARCHIVE)
		await expectNoNote(page, 'A folder with an error of the app')
		await page.unroute(FOLDER_INFO)
		console.log('Files: no note in the base folder, outside it or in the trash bin, or without an answer of the app.')

		await checkHidingTheNote(page, files)
	} finally {
		await folderOfTarget('DELETE', BELOW)
		await context.close()
	}
}

// Another user finds the note in what the target user shared with them, at the path of that
// share, and the answer of the app has nothing else.
async function checkAnotherUser(browser) {
	const { context, page } = await signIn(browser, OTHER)
	const files = await filesAddress(page)
	const answer = await (await openFolder(page, files, '/')).json()
	expect(JSON.stringify(answer) === JSON.stringify({ folders: [], lastSuccess: 0, nextRun: null, dismissed: [] }), `A user without a share learns nothing from the app: ${JSON.stringify(answer)}`)
	await expectNoNote(page, 'The files of another user')

	const share = await shareArchive(OTHER)
	try {
		const { folders } = await (await openFolder(page, files, share.path)).json()
		await expectNote(page, 'archive', 'Mirror of Paperless')
		const paths = folders.map(({ kind, path }) => `${kind}: /${path}`).join(', ')
		expect(paths === `archive: ${share.path}`, `The answer names the archive at the path of the share, and nothing else: ${paths}`)
		console.log(`Files: another user finds the note in the archive shared with them, at ${share.path}.`)
	} finally {
		await asUser(TARGET, `/ocs/v2.php/apps/files_sharing/api/v1/shares/${share.id}`, { method: 'DELETE' })
		await context.close()
	}
}

const browser = await launch('files')
try {
	const admin = await signIn(browser, ADMIN)
	const own = await actionsOf(admin.page, 'receipt.pdf')
	expect(own.includes(SEND), `A member of the group must find "${SEND}" for a file of their own: ${own.join(', ')}`)
	expect(!own.includes(OPEN), `A file without a marker has no "${OPEN}": ${own.join(', ')}`)
	await admin.page.getByRole('menuitem', { name: SEND }).click()
	// The toast, which Nextcloud 34 and newer also announce in a list of their own.
	const toast = admin.page.getByText('Paperless Sync sends 1 file to Paperless.', { exact: false }).first()
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

	await checkTheFoldersOfTheTarget(browser)
	await checkAnotherUser(browser)
} catch (error) {
	await browser.keep(error)
	throw error
} finally {
	await browser.close()
}

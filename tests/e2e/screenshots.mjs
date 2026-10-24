// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Takes the pictures of the documentation (docs/images/) and of the App Store
// (screenshots/, each with its small thumbnail) in Chromium, in the light and the dark
// theme of Nextcloud, and records two animations as GIFs. screenshots.sh sets the app
// up with the synthetic archive of showcase.py and starts this script in the image of
// Playwright; the pictures go to the folder pictures/ next to this script, which
// screenshots.sh returns as a tar archive. With E2E_LANGUAGE=de, and Nextcloud in German,
// it takes the pictures of the German documentation instead, in docs/images/de/, and no
// screenshots of the App Store (language.mjs).
//
// The story follows the quick start: a dry-run, the first synchronization, the archive
// in Files, a dry-run a few days later that shows every kind of change, and a run that
// ends with an error, which the status keeps.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import gifenc from 'gifenc'
import { chromium } from 'playwright-core'
import { PNG } from 'pngjs'
import { filesAddress, folderAddress } from './files-address.mjs'
import { pictureLanguage } from './language.mjs'
import { thumbnail } from './thumbnail.mjs'

// gifenc is a CommonJS module, whose functions come with its default export.
const { applyPalette, GIFEncoder, quantize } = gifenc

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const MOCK_URL = process.env.E2E_MOCK_URL ?? 'http://paperless-mock:8080'
const ADMIN = process.env.E2E_USER ?? 'e2e-admin'
const OWNER = process.env.E2E_TARGET_USER ?? 'paperless'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
// The token of an account of the mock that may neither view the tags nor add documents, for
// a connection checklist that shows what it finds.
const VIEW_ONLY_TOKEN = process.env.E2E_VIEW_ONLY_TOKEN ?? 'e2e-view-only-token'
const OUTPUT = fileURLToPath(new URL('pictures', import.meta.url))
// The language of the pictures, with the German catalog of the app, which screenshots.sh
// hands over next to this script.
const LANGUAGE = process.env.E2E_LANGUAGE ?? 'en'
const CATALOG = process.env.E2E_CATALOG ?? fileURLToPath(new URL('../../l10n/de.json', import.meta.url))
const L = pictureLanguage(LANGUAGE, LANGUAGE === 'en' ? {} : JSON.parse(readFileSync(CATALOG, 'utf8')).translations)
const IMAGES = L.images
const THEMES = ['light', 'dark']
const VIEWPORT = { width: 1280, height: 800 }
const TALL_VIEWPORT = { width: VIEWPORT.width, height: 1400 }
const SETTINGS = `${BASE_URL}/index.php/settings/admin/paperless_sync`
const ARCHIVE = `/${L.base}/${L.archive}`
const CARDS = ['connection', 'schedule', 'archive', 'inbox', 'deletion', 'webhook']

// The address of Files, as Nextcloud links it, which signIn() takes from the first page after
// the login; files-address.mjs says why.
let filesApp = ''

function files(dir) {
	return folderAddress(filesApp, dir)
}

function save(path, data) {
	mkdirSync(dirname(`${OUTPUT}/${path}`), { recursive: true })
	writeFileSync(`${OUTPUT}/${path}`, data)
	console.log(`${path}: ${Math.round(data.length / 1024)} KB`)
}

// A screenshot of the App Store, screenshots/<name>.png, and its small thumbnail next to it,
// screenshots/<name>-small.png, which appinfo/info.xml names as its small-thumbnail. A
// screenshot of the page is opaque, so the thumbnail keeps no alpha channel, which would
// only make the file larger.
function saveScreenshot(name, data) {
	if (!L.appStore) {
		return
	}
	save(`screenshots/${name}.png`, data)
	save(`screenshots/${name}-small.png`, PNG.sync.write(thumbnail(PNG.sync.read(data)), { colorType: 2 }))
}

// Switches the scenario or the tasks of the Paperless mock.
async function control(path) {
	const response = await fetch(`${MOCK_URL}/control/${path}`, { method: 'POST' })
	if (!response.ok) {
		throw new Error(`The Paperless mock refused ${path}: HTTP ${response.status}`)
	}
}

// Puts a file into the folders of the owner of the archive, or of another user, as a person
// would through a client of Nextcloud.
async function upload(path, content, user = OWNER) {
	const authorization = `Basic ${Buffer.from(`${user}:${PASSWORD}`).toString('base64')}`
	const parts = path.split('/')
	for (let depth = 1; depth < parts.length; depth++) {
		const folder = parts.slice(0, depth).map(encodeURIComponent).join('/')
		await fetch(`${BASE_URL}/remote.php/dav/files/${user}/${folder}`, { method: 'MKCOL', headers: { authorization } })
	}
	const response = await fetch(`${BASE_URL}/remote.php/dav/files/${user}/${parts.map(encodeURIComponent).join('/')}`, {
		method: 'PUT',
		headers: { authorization },
		body: content,
	})
	if (!response.ok) {
		throw new Error(`Could not upload ${path}: HTTP ${response.status}`)
	}
}

// Signs in through the login form, as a person does, and keeps the session.
async function signIn(browser, user) {
	const context = await browser.newContext()
	try {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}/index.php/login`)
		await page.locator('input[name="user"]').fill(user)
		await page.locator('input[name="password"]').fill(PASSWORD)
		await page.locator('input[name="password"]').press('Enter')
		await page.waitForURL((url) => !url.pathname.endsWith('/login'))
		filesApp ||= await filesAddress(page)
		return await context.storageState()
	} finally {
		await context.close()
	}
}

async function open(browser, session, theme, url) {
	const context = await browser.newContext({
		storageState: session,
		colorScheme: theme,
		viewport: VIEWPORT,
		locale: L.browserLocale,
		timezoneId: 'Europe/Berlin',
	})
	const page = await context.newPage()
	await page.goto(url)
	return { context, page }
}

// Waits until the page is still: no requests, the fonts loaded, the animations over.
async function settle(page) {
	await page.waitForLoadState('networkidle')
	await page.evaluate(() => document.fonts.ready)
	await page.waitForTimeout(400)
}

// Opens the folder tree in the navigation of Files down to the folders, one after the
// other; Files remembers what is open.
async function expandFolderTree(page, folders) {
	for (const name of [L.nextcloud('Folder tree'), ...folders]) {
		const entry = page.locator(`xpath=//li[contains(@class, "files-navigation__item")]/div[contains(@class, "app-navigation-entry")][.//*[contains(@class, "app-navigation-entry__name")][normalize-space() = "${name}"]]`)
			.filter({ visible: true })
			.first()
		if ((await entry.locator('a').first().getAttribute('aria-expanded')) !== 'true') {
			await entry.locator('button.icon-collapse').click()
		}
	}
	await settle(page)
}

// A picture of the part of the page from the top of one element to the bottom of another
// below it, such as a button and the result that it shows, with a margin around them. The
// first element goes to the top of the window, below the header of Nextcloud, so that a
// tall result, such as the whole connection checklist, fits below it.
async function shotFromTo(page, first, last, margin = 16) {
	await first.evaluate((element) => {
		element.style.scrollMarginTop = '120px'
		element.scrollIntoView({ block: 'start' })
	})
	const viewport = page.viewportSize()
	const top = await first.boundingBox()
	const bottom = await last.boundingBox()
	const x = Math.max(0, Math.min(top.x, bottom.x) - margin)
	const y = Math.max(0, top.y - margin)
	return page.screenshot({
		clip: {
			x,
			y,
			width: Math.min(viewport.width - x, Math.max(top.x + top.width, bottom.x + bottom.width) - x + margin),
			height: Math.min(viewport.height - y, bottom.y + bottom.height + margin - y),
		},
	})
}

// A picture of a part of the page, with a margin of what is around it. A part that is
// taller than the window gets a window as tall as it, so that the header of Nextcloud
// covers none of it.
async function shot(page, locator, margin = 16) {
	const height = (await locator.boundingBox()).height + 4 * margin + 120
	if (height > page.viewportSize().height) {
		await page.setViewportSize({ width: VIEWPORT.width, height: Math.ceil(height) })
	}
	await locator.evaluate((element) => element.scrollIntoView({ block: 'center' }))
	const box = await locator.boundingBox()
	const viewport = page.viewportSize()
	const x = Math.max(0, box.x - margin)
	const y = Math.max(0, box.y - margin)
	return page.screenshot({
		clip: {
			x,
			y,
			width: Math.min(viewport.width - x, box.width + 2 * margin),
			height: Math.min(viewport.height - y, box.height + 2 * margin),
		},
	})
}

// Records the frames of a GIF: each a picture of the page with how long it shows. A
// drawn pointer moves to what is clicked, since a picture of the page has none.
class Animation {
	constructor(page) {
		this.page = page
		this.frames = []
		this.position = { x: VIEWPORT.width * 0.6, y: VIEWPORT.height * 0.55 }
	}

	async frame(delay = 80) {
		const { width, height, data } = PNG.sync.read(await this.page.screenshot())
		const last = this.frames.at(-1)
		if (last !== undefined && last.data.equals(data)) {
			last.delay += delay
		} else {
			this.frames.push({ width, height, data, delay })
		}
	}

	async hold(milliseconds) {
		await this.frame(milliseconds)
	}

	// Frames of whatever moves on its own, such as scrolling.
	async record(milliseconds, interval = 80) {
		for (let elapsed = 0; elapsed < milliseconds; elapsed += interval) {
			await this.frame(interval)
		}
	}

	// Frames until the promise is settled, such as the end of a run.
	async until(promise, interval = 200) {
		let done = false
		const settled = promise.finally(() => {
			done = true
		})
		while (!done) {
			await this.frame(interval)
		}
		await settled
	}

	async pointer(x, y, pressed = false) {
		await this.page.evaluate(({ x, y, pressed }) => {
			let pointer = document.getElementById('screenshot-pointer')
			if (pointer === null) {
				pointer = document.createElement('div')
				pointer.id = 'screenshot-pointer'
				pointer.innerHTML = '<span></span><svg width="22" height="28" viewBox="0 0 22 28"><path d="M2 2v20l5.5-5 3.5 8.5 3.5-1.5-3.5-8.2H19z" fill="#fff" stroke="#111" stroke-width="1.6" stroke-linejoin="round"/></svg>'
				Object.assign(pointer.style, { position: 'fixed', left: '0', top: '0', zIndex: '2147483647', pointerEvents: 'none' })
				Object.assign(pointer.firstChild.style, {
					position: 'absolute', left: '-16px', top: '-16px', width: '32px', height: '32px', borderRadius: '50%',
					background: 'rgba(0, 130, 201, 0.35)', border: '2px solid rgba(0, 130, 201, 0.9)', display: 'none',
				})
				document.body.append(pointer)
			}
			pointer.style.transform = `translate(${x}px, ${y}px)`
			pointer.firstChild.style.display = pressed ? 'block' : 'none'
		}, { x, y, pressed })
	}

	async moveTo(locator, steps = 12) {
		await locator.scrollIntoViewIfNeeded()
		const box = await locator.boundingBox()
		const from = this.position
		const to = { x: box.x + box.width / 2, y: box.y + box.height / 2 }
		for (let step = 1; step <= steps; step++) {
			const t = step / steps
			const eased = t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2
			await this.pointer(from.x + (to.x - from.x) * eased, from.y + (to.y - from.y) * eased)
			await this.frame(30)
		}
		this.position = to
	}

	async click(locator) {
		await this.moveTo(locator)
		await this.pointer(this.position.x, this.position.y, true)
		await this.frame(250)
		await this.pointer(this.position.x, this.position.y)
		await locator.click()
	}

	// One palette for every frame, from a sample of all of them, so that no color
	// flickers between frames; every frame after the first draws only what changed.
	encode() {
		const { width, height } = this.frames[0]
		const pixels = width * height
		const stride = Math.max(1, Math.floor((this.frames.length * pixels) / 1_000_000))
		const sample = new Uint8Array(Math.ceil(pixels / stride) * 4 * this.frames.length)
		let offset = 0
		for (const frame of this.frames) {
			for (let pixel = 0; pixel < pixels; pixel += stride) {
				sample.set(frame.data.subarray(pixel * 4, pixel * 4 + 4), offset)
				offset += 4
			}
		}
		const palette = quantize(sample.slice(0, offset), 255)
		const transparent = palette.length
		const gif = GIFEncoder()
		let previous = null
		for (const [number, frame] of this.frames.entries()) {
			const index = applyPalette(new Uint8Array(frame.data), palette)
			const changes = previous === null ? index : index.map((color, pixel) => (color === previous[pixel] ? transparent : color))
			gif.writeFrame(changes, width, height, {
				palette: number === 0 ? [...palette, [255, 0, 255]] : undefined,
				delay: frame.delay,
				transparent: number > 0,
				transparentIndex: transparent,
				dispose: 1,
			})
			previous = index
		}
		gif.finish()
		return Buffer.from(gif.bytes())
	}
}

const browser = await chromium.launch()
try {
	const admin = await signIn(browser, ADMIN)
	const owner = await signIn(browser, OWNER)
	await control('reset')
	await control('scenario/showcase')
	await upload(`${L.base}/${L.inbox}/${L.receipt}`, '%PDF-1.4 Synthetic receipt of the screenshots.\n')

	// The quick start: a dry-run lists what would change, then the first synchronization.
	{
		const { context, page } = await open(browser, admin, 'light', SETTINGS)
		await page.locator('#paperless-sync-settings').waitFor()
		await settle(page)
		const animation = new Animation(page)
		await animation.hold(1800)
		await animation.click(page.locator('#paperless-sync-dry-run'))
		await animation.until(page.locator('#paperless-sync-report').waitFor({ state: 'visible' }))
		await animation.until(page.locator('#paperless-sync-dry-run:enabled').waitFor())
		await animation.record(1000)
		await animation.hold(3500)
		// The button asks first, in a dialog of the browser that no screenshot shows.
		page.once('dialog', (dialog) => dialog.accept())
		await animation.click(page.locator('#paperless-sync-run'))
		await animation.until(page.locator('#paperless-sync-message', { hasText: L.t('Synchronization completed.') }).waitFor())
		await animation.hold(2500)
		await page.locator('#paperless-sync-settings').evaluate((element) => element.scrollIntoView({ behavior: 'smooth', block: 'start' }))
		await animation.record(1000)
		await animation.hold(3500)
		save(`${IMAGES}/dry-run.gif`, animation.encode())
		await context.close()
	}

	// The settings after the first synchronization, as a whole and section by section.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		await page.locator('#paperless-sync-settings').waitFor()
		await settle(page)
		const overview = await page.screenshot()
		save(`${IMAGES}/settings-${theme}.png`, overview)
		if (theme === 'light') {
			saveScreenshot('01-admin-settings', overview)
		}
		// The bar of the buttons sticks to the bottom of the window and would cover a section.
		await page.addStyleTag({ content: '.paperless-sync-actions { visibility: hidden; }' })
		// The secret of the webhook, as the page shows it once, right after its creation.
		if (theme === THEMES[1]) {
			page.once('dialog', (dialog) => dialog.accept())
		}
		await page.locator('#paperless-sync-webhook-create').click()
		await page.locator('#paperless-sync-webhook-secret-field').waitFor()
		await page.locator('#paperless-sync-webhook-create:enabled').waitFor()
		await page.locator('#paperless-sync-webhook-secret').evaluate((input) => input.blur())
		for (const [index, card] of CARDS.entries()) {
			save(`${IMAGES}/settings-${card}-${theme}.png`, await shot(page, page.locator('section.paperless-sync-card').nth(index)))
		}
		// The connection checklist of an account that lacks three permissions, below the
		// connection settings: lines that passed, failed and have a note.
		// It is taller than the window of the other pictures, so the window grows for it.
		await page.setViewportSize(TALL_VIEWPORT)
		await page.locator('#paperless-sync-token').fill(VIEW_ONLY_TOKEN)
		await page.locator('#paperless-sync-test').click()
		await page.locator('.paperless-sync-check--failed').first().waitFor()
		await page.locator('#paperless-sync-test:enabled').waitFor()
		save(`${IMAGES}/settings-checklist-${theme}.png`, await shotFromTo(page, page.locator('.paperless-sync-test'), page.locator('#paperless-sync-checks')))
		await context.close()
	}

	// The preview under the path template, a moment after a new template was typed: where
	// the newest documents would go, before a save.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		const template = page.locator('#paperless-sync-path-template')
		await template.waitFor()
		await template.fill('{{ correspondent }}/{{ created_year }}/{{ created_month_name }}/{{ created_day }} {{ title }} [P{{ id }}]{{ extension }}')
		// The start of the template in view, as after typing it and going back to its start.
		await template.press('Home')
		await page.locator('#paperless-sync-path-preview-list li', { hasText: /^[^/]+\/\d{4}\/\p{Lu}\p{Ll}+\//u }).first().waitFor()
		await settle(page)
		await page.addStyleTag({ content: '.paperless-sync-actions { visibility: hidden; }' })
		save(`${IMAGES}/path-preview-${theme}.png`, await shot(page, page.locator('#paperless-sync-template')))
		await context.close()
	}

	// The excluded tags while a name is typed, in a page of its own, as the picture of the path
	// template is.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		await page.locator('#paperless-sync-settings').waitFor()
		await settle(page)
		await page.addStyleTag({ content: '.paperless-sync-actions { visibility: hidden; }' })
		// The excluded tags while a name is typed: a typo that Paperless has no tag of, and the
		// tags of Paperless that hold the letters typed, in the list below the field.
		// In the middle of the window, clear of the bar of Nextcloud at its top, with room for the list.
		await page.locator('[data-tag-picker="excluded_tags"]').evaluate((element) => element.scrollIntoView({ block: 'center' }))
		const tags = page.locator('#paperless-sync-excluded-tags-input')
		await tags.fill('Drfat')
		await tags.press('Enter')
		await page.locator('#paperless-sync-excluded-tags-list .paperless-sync-chip--new').waitFor()
		await tags.fill('Dr')
		await page.locator('#paperless-sync-excluded-tags-options [role="option"]', { hasText: 'Draft' }).waitFor()
		await tags.press('ArrowDown')
		await settle(page)
		const field = await page.locator('[data-tag-picker="excluded_tags"]').boundingBox()
		const list = await page.locator('#paperless-sync-excluded-tags-options').boundingBox()
		const top = Math.max(0, field.y - 16)
		save(`${IMAGES}/settings-tags-${theme}.png`, await page.screenshot({
			clip: {
				x: Math.max(0, field.x - 16),
				y: top,
				width: field.width + 32,
				height: Math.min(VIEWPORT.height, Math.max(field.y + field.height, list.y + list.height) + 16) - top,
			},
		}))
		await context.close()
	}

	// Through the archive to a document, which opens in the viewer of Nextcloud.
	{
		const { context, page } = await open(browser, owner, 'light', files(ARCHIVE))
		const row = (name) => page.locator(`[data-cy-files-list-row-name^="${name}"] [data-cy-files-list-row-name-link]`)
		await row('Example Insurance').waitFor()
		await expandFolderTree(page, [...L.base.split('/'), L.archive])
		const animation = new Animation(page)
		await animation.hold(1500)
		for (const [folder, next] of [['Example Insurance', 'Contract'], ['Contract', '2026'], ['2026', '2026-01-15 - Home insurance policy']]) {
			await animation.click(row(folder))
			await animation.until(row(next).waitFor())
			await animation.until(settle(page))
			await animation.hold(900)
		}
		await animation.click(row('2026-01-15 - Home insurance policy'))
		await animation.until(page.locator('.viewer').waitFor())
		await animation.until(page.waitForTimeout(1500))
		await animation.hold(3000)
		save(`${IMAGES}/archive.gif`, animation.encode())
		await context.close()
	}

	// The archive in Files: the folders of a correspondent, a type and a year.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, owner, theme, files(`${ARCHIVE}/City Utilities/Invoice/2026`))
		await page.locator('[data-cy-files-list-row-name^="2026-06-30 - Electricity bill June"]').waitFor()
		await expandFolderTree(page, [...L.base.split('/'), L.archive, 'City Utilities', 'Invoice'])
		const archive = await page.screenshot()
		save(`${IMAGES}/archive-${theme}.png`, archive)
		if (theme === 'light') {
			saveScreenshot('02-structured-archive', archive)
		}
		await context.close()
	}


	// The menu of a file in Files: the administrator, a member of the group that may send
	// files, sends a scanned letter to Paperless, and a copy of the archive opens its
	// document there. A right click opens the menu at the pointer, which the picture leaves
	// out; it shows the row of the file and the menu below it.
	await upload(L.letter, '%PDF-1.4 Synthetic letter of the screenshots.\n', ADMIN)
	const menuOf = async (page, row, action) => {
		await row.waitFor()
		await settle(page)
		const box = await row.boundingBox()
		await row.click({ button: 'right', position: { x: Math.min(420, box.width / 2), y: box.height / 2 } })
		await page.getByRole('menuitem', { name: action }).waitFor()
		await settle(page)
		const menu = await page.getByRole('menuitem', { name: action }).locator('xpath=ancestor::*[@role="menu"][1]').boundingBox()
		const x = Math.max(0, Math.min(box.x, menu.x) - 16)
		const y = Math.max(0, box.y - 16)
		return page.screenshot({
			clip: {
				x,
				y,
				width: Math.min(VIEWPORT.width - x, Math.max(box.x + box.width, menu.x + menu.width) - x + 16),
				height: Math.min(VIEWPORT.height - y, menu.y + menu.height + 16 - y),
			},
		})
	}
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, files(`/${L.letter.split('/')[0]}`))
		save(`${IMAGES}/files-send-${theme}.png`, await menuOf(page, page.locator(`[data-cy-files-list-row-name="${L.letter.split('/')[1]}"]`), L.t('Send to Paperless')))
		await context.close()
	}
	for (const theme of THEMES) {
		const { context, page } = await open(browser, owner, theme, files(`${ARCHIVE}/City Utilities/Invoice/2026`))
		save(`${IMAGES}/files-open-${theme}.png`, await menuOf(page, page.locator('[data-cy-files-list-row-name^="2026-06-30 - Electricity bill June"]'), L.t('Open in Paperless')))
		await context.close()
	}

	// A rule of Flow that sends every PDF that someone uploads into a folder with the tag
	// "To Paperless" to Paperless, with the tags Letters and Bank, in German "An Paperless"
	// with Briefe and Bank, saved as the settings of Flow save it; the picture shows the rule
	// with the form of Paperless Sync.
	{
		const { context, page } = await open(browser, admin, 'light', `${BASE_URL}/index.php/settings/admin/workflow`)
		const saved = await page.evaluate(async ({ flowTag, flowTags }) => {
			const headers = { 'Content-Type': 'application/json', 'OCS-APIRequest': 'true', requesttoken: window.OC.requestToken }
			const tag = await fetch('/remote.php/dav/systemtags/', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', requesttoken: window.OC.requestToken },
				body: JSON.stringify({ name: flowTag, userVisible: true, userAssignable: true }),
			})
			const tagId = tag.headers.get('Content-Location')?.split('/').pop() ?? ''
			const rule = await fetch('/ocs/v2.php/apps/workflowengine/api/v1/workflows/global?format=json', {
				method: 'POST',
				headers,
				body: JSON.stringify({
					class: 'OCA\\PaperlessSync\\Operation\\SendToPaperless',
					name: '',
					checks: [
						{ class: 'OCA\\WorkflowEngine\\Check\\FileMimeType', operator: 'is', value: 'application/pdf' },
						{ class: 'OCA\\WorkflowEngine\\Check\\FileSystemTags', operator: 'is', value: tagId },
					],
					operation: JSON.stringify({ tags: flowTags }),
					entity: 'OCA\\WorkflowEngine\\Entity\\File',
					events: ['\\OCP\\Files::postCreate'],
				}),
			})
			return `${tag.status} ${rule.status}`
		}, { flowTag: L.flowTag, flowTags: L.flowTags })
		if (saved !== '201 200') {
			throw new Error(`The rule of Flow for the pictures was not saved: ${saved}`)
		}
		await context.close()
	}
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, `${BASE_URL}/index.php/settings/admin/workflow`)
		const rule = page.locator('.rule', { has: page.locator('oca-paperless_sync-send-to-paperless') })
		await rule.waitFor()
		await page.locator('oca-paperless_sync-send-to-paperless input').evaluate((input) => input.blur())
		await settle(page)
		save(`${IMAGES}/flow-rule-${theme}.png`, await shot(page, rule))
		await context.close()
	}

	// A few days later: a new title, the trash, an excluded tag, new documents and a
	// finished import, all in the report of a dry-run.
	await control('scenario/showcase-update')
	await control('tasks/success')
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		await page.locator('#paperless-sync-settings').waitFor()
		await settle(page)
		await page.locator('#paperless-sync-dry-run').click()
		await page.locator('#paperless-sync-report').waitFor({ state: 'visible' })
		await page.locator('#paperless-sync-dry-run:enabled').waitFor()
		await settle(page)
		await page.addStyleTag({ content: '.paperless-sync-actions { visibility: hidden; }' })
		const report = await shot(page, page.locator('#paperless-sync-report'))
		save(`${IMAGES}/report-${theme}.png`, report)
		if (theme === 'light') {
			saveScreenshot('03-dry-run-report', report)
		}
		await context.close()
	}

	// A document that Paperless lists but doesn't hand out: the run ends with its error,
	// which the status shows again on the next visit of the settings.
	await control('scenario/showcase-error')
	{
		const { context, page } = await open(browser, admin, 'light', SETTINGS)
		await page.locator('#paperless-sync-settings').waitFor()
		page.once('dialog', (dialog) => dialog.accept())
		await page.locator('#paperless-sync-run').click()
		await page.locator('#paperless-sync-errors').waitFor({ state: 'visible' })
		await context.close()
	}
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		await page.locator('#paperless-sync-errors').waitFor({ state: 'visible' })
		await settle(page)
		save(`${IMAGES}/status-error-${theme}.png`, await shot(page, page.locator('#paperless-sync-status')))
		await context.close()
	}
} finally {
	await browser.close()
}

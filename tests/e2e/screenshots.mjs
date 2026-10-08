// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Takes the pictures of the documentation (docs/images/) and of the App Store
// (screenshots/) in Chromium, in the light and the dark theme of Nextcloud, and records
// two animations as GIFs. screenshots.sh sets the app up with the synthetic archive of
// showcase.py and starts this script in the image of Playwright; the pictures go to
// /tmp/pictures, which the script returns as a tar archive.
//
// The story follows the quick start: a dry-run, the first synchronization, the archive
// in Files, and a dry-run a few days later that shows every kind of change.

import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import gifenc from 'gifenc'
import { chromium } from 'playwright-core'
import { PNG } from 'pngjs'

// gifenc is a CommonJS module, whose functions come with its default export.
const { applyPalette, GIFEncoder, quantize } = gifenc

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const MOCK_URL = process.env.E2E_MOCK_URL ?? 'http://paperless-mock:8080'
const ADMIN = process.env.E2E_USER ?? 'e2e-admin'
const OWNER = process.env.E2E_TARGET_USER ?? 'paperless'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const OUTPUT = '/tmp/pictures'
const THEMES = ['light', 'dark']
const VIEWPORT = { width: 1280, height: 800 }
const SETTINGS = `${BASE_URL}/index.php/settings/admin/paperless_sync`
const ARCHIVE = '/Dokumente/Paperless/Archiv'
const CARDS = ['connection', 'schedule', 'archive', 'inbox', 'deletion']

function files(dir) {
	return `${BASE_URL}/index.php/apps/files/files?dir=${encodeURIComponent(dir)}`
}

function save(path, data) {
	mkdirSync(dirname(`${OUTPUT}/${path}`), { recursive: true })
	writeFileSync(`${OUTPUT}/${path}`, data)
	console.log(`${path}: ${Math.round(data.length / 1024)} KB`)
}

// Switches the scenario or the tasks of the Paperless mock.
async function control(path) {
	const response = await fetch(`${MOCK_URL}/control/${path}`, { method: 'POST' })
	if (!response.ok) {
		throw new Error(`The Paperless mock refused ${path}: HTTP ${response.status}`)
	}
}

// Puts a file into the folders of the owner of the archive, as a person would through
// a client of Nextcloud.
async function upload(path, content) {
	const authorization = `Basic ${Buffer.from(`${OWNER}:${PASSWORD}`).toString('base64')}`
	const parts = path.split('/')
	for (let depth = 1; depth < parts.length; depth++) {
		const folder = parts.slice(0, depth).map(encodeURIComponent).join('/')
		await fetch(`${BASE_URL}/remote.php/dav/files/${OWNER}/${folder}`, { method: 'MKCOL', headers: { authorization } })
	}
	const response = await fetch(`${BASE_URL}/remote.php/dav/files/${OWNER}/${parts.map(encodeURIComponent).join('/')}`, {
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
		locale: 'en-US',
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
	for (const name of ['Folder tree', ...folders]) {
		const entry = page.locator(`xpath=//li[contains(@class, "files-navigation__item")]/div[contains(@class, "app-navigation-entry")][.//*[contains(@class, "app-navigation-entry__name")][normalize-space() = "${name}"]]`)
			.filter({ visible: true })
			.first()
		if ((await entry.locator('a').first().getAttribute('aria-expanded')) !== 'true') {
			await entry.locator('button.icon-collapse').click()
		}
	}
	await settle(page)
}

// A picture of a part of the page, with a margin of what is around it.
async function shot(page, locator, margin = 16) {
	await locator.evaluate((element) => element.scrollIntoView({ block: 'center' }))
	const box = await locator.boundingBox()
	const x = Math.max(0, box.x - margin)
	const y = Math.max(0, box.y - margin)
	return page.screenshot({
		clip: {
			x,
			y,
			width: Math.min(VIEWPORT.width - x, box.width + 2 * margin),
			height: Math.min(VIEWPORT.height - y, box.height + 2 * margin),
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
	await upload('Dokumente/Paperless/Eingang/Receipts/Hardware store.pdf', '%PDF-1.4 Synthetic receipt of the screenshots.\n')

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
		await animation.until(page.locator('#paperless-sync-message', { hasText: 'Synchronization completed.' }).waitFor())
		await animation.hold(500)
		await page.locator('#paperless-sync-settings').evaluate((element) => element.scrollIntoView({ behavior: 'smooth', block: 'start' }))
		await animation.record(1000)
		await animation.hold(3500)
		save('docs/images/dry-run.gif', animation.encode())
		await context.close()
	}

	// The settings after the first synchronization, as a whole and section by section.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		await page.locator('#paperless-sync-settings').waitFor()
		await settle(page)
		const overview = await page.screenshot()
		save(`docs/images/settings-${theme}.png`, overview)
		if (theme === 'light') {
			save('screenshots/01-admin-settings.png', overview)
		}
		// The bar of the buttons sticks to the bottom of the window and would cover a section.
		await page.addStyleTag({ content: '.paperless-sync-actions { visibility: hidden; }' })
		for (const [index, card] of CARDS.entries()) {
			save(`docs/images/settings-${card}-${theme}.png`, await shot(page, page.locator('section.paperless-sync-card').nth(index)))
		}
		await context.close()
	}

	// Through the archive to a document, which opens in the viewer of Nextcloud.
	{
		const { context, page } = await open(browser, owner, 'light', files(ARCHIVE))
		const row = (name) => page.locator(`[data-cy-files-list-row-name^="${name}"] [data-cy-files-list-row-name-link]`)
		await row('Example Insurance').waitFor()
		await expandFolderTree(page, ['Dokumente', 'Paperless', 'Archiv'])
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
		save('docs/images/archive.gif', animation.encode())
		await context.close()
	}

	// The archive in Files: the folders of a correspondent, a type and a year.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, owner, theme, files(`${ARCHIVE}/City Utilities/Invoice/2026`))
		await page.locator('[data-cy-files-list-row-name^="2026-06-30 - Electricity bill June"]').waitFor()
		await expandFolderTree(page, ['Dokumente', 'Paperless', 'Archiv', 'City Utilities', 'Invoice'])
		const archive = await page.screenshot()
		save(`docs/images/archive-${theme}.png`, archive)
		if (theme === 'light') {
			save('screenshots/02-structured-archive.png', archive)
		}
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
		save(`docs/images/report-${theme}.png`, report)
		if (theme === 'light') {
			save('screenshots/03-dry-run-report.png', report)
		}
		await context.close()
	}
} finally {
	await browser.close()
}

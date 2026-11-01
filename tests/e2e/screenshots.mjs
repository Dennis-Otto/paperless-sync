// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Takes the pictures of the documentation (docs/images/) and of the App Store
// (screenshots/, each with its small thumbnail) in Chromium, in the light and the dark
// theme of Nextcloud, and records five animations as GIFs, each in both themes: NAME.gif
// and NAME-dark.gif. screenshots.sh sets the app
// up with the synthetic archive of showcase.py and starts this script in the image of
// Playwright; the pictures go to the folder pictures/ next to this script, which
// screenshots.sh returns as a tar archive. With E2E_LANGUAGE=de, and Nextcloud in German,
// it takes the pictures of the German documentation instead, in docs/images/de/, and no
// screenshots of the App Store (language.mjs).
//
// The story follows the quick start: getting started, a dry-run, the first synchronization,
// the archive in Files, a dry-run a few days later that shows every kind of change, and a
// run that ends with an error, which the status keeps. A week later, two scans go through
// the inbox, a document of a new type and title moves, and a bill goes to the trash of
// Paperless and comes back, each as an animation of Files with captions.
//
// Two runs draw the same pixels, so that the screenshot bot changes a picture only when the
// app looks different: the data of the Paperless mock is fixed (showcase.py), the moments of
// the runs and the secret of the webhook are those of the story (frozen.mjs), every picture
// waits until the page stands still, and an animation takes its frames at fixed steps of
// what it shows, never at moments in time, whose frames would depend on the speed of the
// machine.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import gifenc from 'gifenc'
import { chromium } from 'playwright-core'
import { PNG } from 'pngjs'
import { filesAddress, folderAddress } from './files-address.mjs'
import { blackCorners, freeze, Story } from './frozen.mjs'
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
// A phone, 390 pixels wide, as Files shows itself in the browser of a phone: the size of
// a common phone, with touch and the narrow layout of Nextcloud, for the guide of the
// people who use the archive (docs/use.md).
const PHONE = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }
const SETTINGS = `${BASE_URL}/index.php/settings/admin/paperless_sync`
const ARCHIVE = `/${L.base}/${L.archive}`
// The two scans of the inbox, named as a scanner names them, in both languages: the Paperless
// mock takes the name of a file in the path of /control/task-of/, as it comes, without spaces.
const SCANS = { imported: 'Scan_20261025_0815.pdf', failed: 'Scan_20261025_0816.pdf' }
const CARDS = ['connection', 'schedule', 'archive', 'inbox', 'deletion', 'webhook']
// How long a run that "Synchronize now" queued may take: a background job of Nextcloud runs
// it, and the page asks for its status every few seconds.
const QUEUED_RUN = { timeout: 120_000 }
// The moments of the story, which the pictures show instead of those of the run of the script.
const story = new Story()

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

// Opens the page in a browser of its own, the size of VIEWPORT unless `device` names
// another, such as PHONE.
async function open(browser, session, theme, url, device = { viewport: VIEWPORT }) {
	const context = await browser.newContext({
		storageState: session,
		colorScheme: theme,
		...device,
		locale: L.browserLocale,
		timezoneId: 'Europe/Berlin',
	})
	await freeze(context, story)
	const page = await context.newPage()
	await page.goto(url)
	return { context, page }
}

// A picture of the page, or of the part of it that options.clip names, once it stands
// still: its images decoded, its transitions at their end, and two pictures in a row the
// same, so that an image that shows a moment later, such as an avatar, or a scroll that is
// still going changes no pixel from run to run.
async function picture(page, options = {}) {
	await page.evaluate(() => Promise.all([...document.images].map((image) =>
		Promise.race([image.decode().catch(() => {}), new Promise((resolve) => setTimeout(resolve, 2000))]))))
	let last = await page.screenshot({ ...options, animations: 'disabled' })
	for (let look = 0; look < 20; look++) {
		const next = await page.screenshot({ ...options, animations: 'disabled' })
		if (next.equals(last)) {
			return next
		}
		last = next
	}
	console.warn('The page never stood still for a picture; its pixels may differ from run to run.')
	return last
}

// Waits until no part of the page scrolls any more: the places of all that scrolls stayed
// the same for five looks, 50 milliseconds apart. The page scrolls smoothly on its own, such
// as to a new report or checklist, and a picture whose part of the page was taken while it
// moved would show it at another place on each run.
async function standStill(page) {
	await page.evaluate(() => new Promise((resolve) => {
		const scrolling = [document.scrollingElement, ...document.querySelectorAll('*')].filter((element) =>
			element === document.scrollingElement
			|| (element.scrollHeight > element.clientHeight && /auto|scroll/.test(getComputedStyle(element).overflowY)))
		let last = ''
		let looks = 0
		const started = Date.now()
		const look = () => {
			const tops = scrolling.map((element) => element.scrollTop).join()
			looks = tops === last ? looks + 1 : 0
			last = tops
			if (looks >= 5 || Date.now() - started > 10_000) {
				resolve()
			} else {
				setTimeout(look, 50)
			}
		}
		look()
	}))
}

// Scrolls an element into the window once the page stands still, and waits until it stands
// still again: a smooth scroll of the page itself would move the element after a scroll that
// came before its end.
async function scrollInto(page, locator, block) {
	await standStill(page)
	await locator.evaluate((element, block) => element.scrollIntoView({ behavior: 'instant', block }), block)
	await standStill(page)
}

// Waits until the page is still: no requests, the fonts loaded, the animations over.
async function settle(page) {
	await page.waitForLoadState('networkidle')
	await page.evaluate(() => document.fonts.ready)
	await page.waitForTimeout(400)
}

// The first entry of the folder tree in the navigation of Files with the name that it shows.
function folderTreeEntry(page, name) {
	return page.locator(`xpath=//li[contains(@class, "files-navigation__item")]/div[contains(@class, "app-navigation-entry")][.//*[contains(@class, "app-navigation-entry__name")][normalize-space() = "${name}"]]`)
		.filter({ visible: true })
		.first()
}

// Opens the folder tree in the navigation of Files down to the folders, one after the
// other; Files remembers what is open.
async function expandFolderTree(page, folders) {
	for (const name of [L.nextcloud('Folder tree'), ...folders]) {
		const entry = folderTreeEntry(page, name)
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
	})
	await scrollInto(page, first, 'start')
	const viewport = page.viewportSize()
	const top = await first.boundingBox()
	const bottom = await last.boundingBox()
	const x = Math.max(0, Math.min(top.x, bottom.x) - margin)
	const y = Math.max(0, top.y - margin)
	return picture(page, {
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
	await scrollInto(page, locator, 'center')
	const box = await locator.boundingBox()
	const viewport = page.viewportSize()
	const x = Math.max(0, box.x - margin)
	const y = Math.max(0, box.y - margin)
	return picture(page, {
		clip: {
			x,
			y,
			width: Math.min(viewport.width - x, box.width + 2 * margin),
			height: Math.min(viewport.height - y, box.height + 2 * margin),
		},
	})
}

// Shows the page in a theme of Nextcloud, light or dark, as a browser in that color scheme
// would: the styles follow the color scheme at once, and the avatars, which Nextcloud draws
// for each theme and the page chose as it loaded, get the picture of the theme. It returns
// once the page has drawn the theme: Chromium draws the background of Nextcloud anew a moment
// later, and a picture of that moment shows its corners black.
async function showTheme(page, theme) {
	await page.emulateMedia({ colorScheme: theme })
	await page.evaluate((dark) => {
		const avatar = /(\/avatar\/[^/?\s]+\/\d+)(?:\/dark)?/g
		for (const image of document.querySelectorAll('img[src*="/avatar/"]')) {
			for (const name of ['src', 'srcset']) {
				const value = image.getAttribute(name)
				const themed = value?.replace(avatar, dark ? '$1/dark' : '$1')
				if (value !== null && themed !== value) {
					image.setAttribute(name, themed)
				}
			}
		}
	}, theme === 'dark')
	await page.evaluate(() => new Promise((resolve) => {
		requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(resolve, 150)))
	}))
}

// Draws the caption of an animation at the bottom of the window, in the middle of the content
// of the app, beside its navigation, in the colors of the theme that the page shows, or takes
// it away for null. It runs in the page, so it names nothing outside of itself.
function drawCaption(text) {
	let caption = document.getElementById('screenshot-caption')
	if (text === null) {
		caption?.remove()
		return
	}
	if (caption === null) {
		caption = document.createElement('div')
		caption.id = 'screenshot-caption'
		Object.assign(caption.style, {
			position: 'fixed',
			bottom: '32px',
			transform: 'translateX(-50%)',
			zIndex: '2147483646',
			boxSizing: 'border-box',
			width: 'max-content',
			maxWidth: '860px',
			padding: '14px 22px',
			borderRadius: '14px',
			border: '2px solid var(--color-primary-element)',
			background: 'var(--color-main-background)',
			color: 'var(--color-main-text)',
			boxShadow: '0 6px 24px var(--color-box-shadow)',
			font: '600 18px/1.45 var(--font-face)',
			textAlign: 'center',
			pointerEvents: 'none',
		})
		document.body.append(caption)
	}
	const content = (document.querySelector('#app-content-vue') ?? document.querySelector('#app-content'))?.getBoundingClientRect()
	const left = content === undefined ? 0 : content.left
	const width = content === undefined ? window.innerWidth : content.width
	caption.style.left = `${Math.round(left + width / 2)}px`
	caption.style.maxWidth = `${Math.min(860, Math.round(width) - 48)}px`
	caption.textContent = text
}

// Saves the GIFs of an animation, one for each of its themes: NAME.gif in the light theme,
// NAME-dark.gif in the dark one.
function saveAnimation(name, animation) {
	for (const theme of animation.themes) {
		save(`${IMAGES}/${name}${theme === THEMES[0] ? '' : `-${theme}`}.gif`, animation.encode(theme))
	}
}

// Records the frames of a GIF: each a picture of the page with how long it shows. A
// drawn pointer moves to what is clicked, since a picture of the page has none, and a
// caption says what happens where no picture shows it, such as in Paperless.
//
// One recording makes a GIF for each theme: the page shows the first theme, and each frame
// is taken in every theme in turn (showTheme), so that a story that changes the app, such as
// the first synchronization, needs to happen only once.
//
// The frames are the same on every run: each shows the page with its transitions at their
// end, the animation waits for the server without frames, and a scroll is drawn in fixed
// steps (glide). A frame taken at a moment in time would catch a page that is still busy or
// scrolling at another place on each run, as fast as the machine and the server are.
class Animation {
	constructor(page, themes = THEMES) {
		this.page = page
		this.themes = themes
		this.frames = Object.fromEntries(themes.map((theme) => [theme, []]))
		this.position = { x: VIEWPORT.width * 0.6, y: VIEWPORT.height * 0.55 }
		this.text = null
	}

	// Takes the page as it shows now in every theme: first in the one that it shows, then in
	// each other one, and back.
	async frame(delay = 80) {
		await this.page.evaluate(drawCaption, this.text)
		for (const [number, theme] of this.themes.entries()) {
			if (number > 0) {
				await showTheme(this.page, theme)
			}
			const { width, height, data } = PNG.sync.read(await this.drawn(theme))
			const frames = this.frames[theme]
			const last = frames.at(-1)
			if (last !== undefined && last.data.equals(data)) {
				last.delay += delay
			} else {
				frames.push({ width, height, data, delay })
			}
		}
		if (this.themes.length > 1) {
			await showTheme(this.page, this.themes[0])
		}
	}

	// The picture of the page in a theme that it shows, without black corners on the panel of
	// Nextcloud, which Chromium draws now and then after a change of the theme and which stay
	// until it draws them anew: it does, when the theme changes back and forth.
	async drawn(theme) {
		const other = this.themes.find((name) => name !== theme) ?? theme
		const panel = await this.page.evaluate(() => {
			const { left, top, right, bottom } = (document.querySelector('#content') ?? document.querySelector('#content-vue')).getBoundingClientRect()
			return { left, top, right, bottom }
		})
		let shown = await picture(this.page)
		for (let look = 0; look < 10 && blackCorners(PNG.sync.read(shown), panel); look++) {
			console.warn(`Chromium drew the corners of the panel black in the ${theme} theme; taking the frame again.`)
			await showTheme(this.page, other)
			await showTheme(this.page, theme)
			shown = await picture(this.page)
		}
		return shown
	}

	// Shows a caption at the bottom of the window from the next frame on, or none for null.
	caption(text) {
		this.text = text
	}

	// A picture of the page as the last frame shows it in the first theme, with its caption but
	// without the pointer, for a screenshot of the App Store.
	async still() {
		const pointer = (visibility) => this.page.evaluate((visibility) => {
			document.getElementById('screenshot-pointer')?.style.setProperty('visibility', visibility)
		}, visibility)
		await pointer('hidden')
		const shown = await this.drawn(this.themes[0])
		await pointer('visible')
		return shown
	}

	async hold(milliseconds) {
		await this.frame(milliseconds)
	}

	// Waits until the promise is settled, such as the end of a run, without frames: what the
	// page shows meanwhile depends on how fast the server answers.
	async until(promise) {
		await promise
	}

	// Remembers where each part of the page that scrolls stands, for glide().
	async mark() {
		await this.page.evaluate(() => {
			const scrolling = [document.scrollingElement, ...document.querySelectorAll('*')].filter((element) =>
				element === document.scrollingElement
				|| (element.scrollHeight > element.clientHeight && /auto|scroll/.test(getComputedStyle(element).overflowY)))
			window.screenshotScrolling = [...new Set(scrolling)].map((element) => ({ element, top: element.scrollTop }))
		})
	}

	// Frames of the scroll from where mark() found the page to where it stands once it stands
	// still, in steps of the same easing as the pointer: the page scrolls smoothly on its own,
	// as when it shows a report, at a speed that differs from run to run. A scroll changes every
	// pixel of a frame, and three steps keep the animation below the 1 MB that
	// screenshots.test.mjs allows.
	async glide(steps = 3) {
		await standStill(this.page)
		await this.page.evaluate(() => {
			for (const scrolled of window.screenshotScrolling) {
				scrolled.end = scrolled.element.scrollTop
				scrolled.element.scrollTo({ top: scrolled.top, behavior: 'instant' })
			}
		})
		for (let step = 1; step <= steps; step++) {
			const t = step / steps
			const eased = t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2
			await this.page.evaluate((eased) => {
				for (const { element, top, end } of window.screenshotScrolling) {
					element.scrollTo({ top: Math.round(top + (end - top) * eased), behavior: 'instant' })
				}
			}, eased)
			await this.frame(60)
		}
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
		// Where the page stands before the click, for a scroll that the click starts (glide).
		await this.mark()
		await locator.click()
	}

	// The GIF of a theme. One palette for every frame, from a sample of all of them, so that
	// no color flickers between frames; every frame after the first draws only what changed.
	// White keeps its exact color: the paper of a document in the viewer is white also in the
	// dark theme, where the sample has too few white pixels for a color of its own and would
	// tint the page.
	encode(theme = this.themes[0]) {
		const frames = this.frames[theme]
		const { width, height } = frames[0]
		const pixels = width * height
		const stride = Math.max(1, Math.floor((frames.length * pixels) / 1_000_000))
		const sample = new Uint8Array(Math.ceil(pixels / stride) * 4 * frames.length)
		let offset = 0
		for (const frame of frames) {
			for (let pixel = 0; pixel < pixels; pixel += stride) {
				sample.set(frame.data.subarray(pixel * 4, pixel * 4 + 4), offset)
				offset += 4
			}
		}
		const white = ([red, green, blue]) => red === 255 && green === 255 && blue === 255
		let palette = quantize(sample.slice(0, offset), 255)
		if (!palette.some(white)) {
			palette = [...quantize(sample.slice(0, offset), 254), [255, 255, 255]]
		}
		const transparent = palette.length
		const gif = GIFEncoder()
		let previous = null
		for (const [number, frame] of frames.entries()) {
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

// Chromium draws the same pixels on every run: it decodes each image as it draws it, instead
// of later and in other ways from run to run, such as the avatar in the header, and draws a
// whole tile anew, not the part that changed.
const browser = await chromium.launch({
	args: ['--disable-checker-imaging', '--disable-image-animation-resync', '--disable-partial-raster'],
})
try {
	const admin = await signIn(browser, ADMIN)
	const owner = await signIn(browser, OWNER)
	await control('reset')
	await control('scenario/showcase')
	await upload(`${L.base}/${L.inbox}/${L.receipt}`, '%PDF-1.4 Synthetic receipt of the screenshots.\n')

	// Getting started on the new installation, whose connection and target user are saved: the
	// first two steps are done and the dry-run is next.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		await page.locator('#paperless-sync-getting-started [data-step="dry-run"][aria-current="step"]').waitFor()
		await settle(page)
		save(`${IMAGES}/settings-getting-started-${theme}.png`, await shot(page, page.locator('#paperless-sync-getting-started')))
		await context.close()
	}

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
		// The page scrolls the report into view.
		await animation.glide()
		await animation.hold(3500)
		// A minute later, the first synchronization. The button asks first, in a dialog of the
		// browser that no screenshot shows.
		story.to('2026-10-18T10:21:00+02:00')
		page.once('dialog', (dialog) => dialog.accept())
		await animation.click(page.locator('#paperless-sync-run'))
		await animation.until(page.locator('#paperless-sync-message', { hasText: L.t('Synchronization completed.') }).waitFor(QUEUED_RUN))
		await animation.glide()
		await animation.hold(2500)
		await animation.mark()
		await page.locator('#paperless-sync-settings').evaluate((element) => element.scrollIntoView({ behavior: 'instant', block: 'start' }))
		await animation.glide()
		await animation.hold(3500)
		saveAnimation('dry-run', animation)
		await context.close()
	}

	// The settings after the first synchronization, as a whole and section by section.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		await page.locator('#paperless-sync-settings').waitFor()
		await settle(page)
		const overview = await picture(page)
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
		// The setup of the account of the app in Paperless, open, with the token of a superuser
		// typed, as before the click that creates the account; then it folds away again.
		const account = page.locator('#paperless-sync-account')
		await account.locator('summary').click()
		await page.locator('#paperless-sync-account-token').fill('synthetic-superuser-token')
		await page.locator('#paperless-sync-account-token').evaluate((input) => input.blur())
		save(`${IMAGES}/settings-account-${theme}.png`, await shot(page, account))
		await account.locator('summary').click()
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
		await scrollInto(page, page.locator('[data-tag-picker="excluded_tags"]'), 'center')
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
		save(`${IMAGES}/settings-tags-${theme}.png`, await picture(page, {
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
		saveAnimation('archive', animation)
		await context.close()
	}

	// The archive in Files: the folders of a correspondent, a type and a year.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, owner, theme, files(`${ARCHIVE}/City Utilities/Invoice/2026`))
		await page.locator('[data-cy-files-list-row-name^="2026-06-30 - Electricity bill June"]').waitFor()
		await expandFolderTree(page, [...L.base.split('/'), L.archive, 'City Utilities', 'Invoice'])
		const archive = await picture(page)
		save(`${IMAGES}/archive-${theme}.png`, archive)
		if (theme === 'light') {
			saveScreenshot('02-structured-archive', archive)
		}
		await context.close()
	}

	// The note above the list of the archive, which says that the folder is a mirror of Paperless.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, owner, theme, files(ARCHIVE))
		const note = page.locator('.paperless-sync-folder-note')
		await note.waitFor()
		const folder = page.locator('[data-cy-files-list-row-name="City Utilities"]')
		await folder.waitFor()
		await settle(page)
		save(`${IMAGES}/folder-note-${theme}.png`, await shotFromTo(page, note, folder, 4))
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
		return picture(page, {
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

	// Files on a phone, for the people who use the archive: the archive with a folder for
	// each correspondent, as the browser of a phone shows it, and in the folder of a year
	// the menu of a copy, which a tap on its button of actions opens, with Open in Paperless.
	for (const theme of THEMES) {
		const { context, page } = await open(browser, owner, theme, files(ARCHIVE), PHONE)
		await page.locator('[data-cy-files-list-row-name="City Utilities"]').waitFor()
		await settle(page)
		save(`${IMAGES}/phone-archive-${theme}.png`, await picture(page))
		await page.goto(files(`${ARCHIVE}/City Utilities/Invoice/2026`))
		const copy = page.locator('[data-cy-files-list-row-name^="2026-06-30 - Electricity bill June"]')
		await copy.waitFor()
		await settle(page)
		await copy.locator('[data-cy-files-list-row-actions] .action-item__menutoggle').click()
		await page.getByRole('menuitem', { name: L.t('Open in Paperless') }).waitFor()
		await settle(page)
		save(`${IMAGES}/phone-menu-${theme}.png`, await picture(page))
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
	story.to('2026-10-18T10:22:00+02:00')
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
		await page.locator('#paperless-sync-errors').waitFor({ state: 'visible', ...QUEUED_RUN })
		await context.close()
	}
	for (const theme of THEMES) {
		const { context, page } = await open(browser, admin, theme, SETTINGS)
		await page.locator('#paperless-sync-errors').waitFor({ state: 'visible' })
		await settle(page)
		save(`${IMAGES}/status-error-${theme}.png`, await shot(page, page.locator('#paperless-sync-status')))
		await context.close()
	}

	// A week later, without the document that Paperless doesn't hand out. Each run of this part
	// is one of Synchronize now, in a window of its own that no picture shows.
	await control('scenario/showcase-update')
	const synchronize = async (moment) => {
		story.to(moment)
		const { context, page } = await open(browser, admin, 'light', SETTINGS)
		try {
			await page.locator('#paperless-sync-settings').waitFor()
			page.once('dialog', (dialog) => dialog.accept())
			await page.locator('#paperless-sync-run').click()
			await page.locator('#paperless-sync-message', { hasText: L.t('Synchronization completed.') }).waitFor(QUEUED_RUN)
		} finally {
			await context.close()
		}
	}
	// A folder or a file in the list of Files by its name, and a folder in the folder tree of the
	// navigation, which shows each folder of the archive at one place, where the path above the
	// list folds away the folders that don't fit.
	const row = (page, name) => page.locator(`[data-cy-files-list-row-name="${name}"] [data-cy-files-list-row-name-link]`)
	const tree = (page, name) => folderTreeEntry(page, name).locator('a').first()
	// Opens a folder in the window of an animation with a click, and waits until Files shows
	// what it holds. After a run that moved folders, Files shows them anew (fresh), as it does
	// when it opens: its folder tree would still show the folders as they were.
	const enter = async (animation, page, link, shown, fresh = false) => {
		await animation.click(link)
		if (fresh) {
			await animation.until(page.waitForLoadState('networkidle'))
			await animation.until(page.reload())
		}
		await animation.until(shown.waitFor())
		await animation.until(settle(page))
		await animation.hold(900)
	}

	// The inbox: two scans arrive in the folder Inbox, the next runs send them to Paperless, which
	// imports the first, and the second, which Paperless couldn't import, waits in Failed imports
	// next to the reason. The App Store shows the scans as they arrive.
	{
		const inbox = `${L.base}/${L.inbox}`
		const { context, page } = await open(browser, owner, 'light', files(`/${inbox}`))
		await page.locator('[data-cy-files-content-breadcrumbs]').waitFor()
		await expandFolderTree(page, L.base.split('/'))
		const animation = new Animation(page)
		await animation.hold(1200)
		animation.caption(L.captions.inboxArrive)
		await animation.until(upload(`${inbox}/${SCANS.imported}`, '%PDF-1.4 Synthetic letter of an insurance, scanned for the screenshots.\n'))
		await animation.until(upload(`${inbox}/${SCANS.failed}`, '%PDF-1.4 Synthetic damaged page, scanned for the screenshots.\n'))
		await animation.until(page.reload())
		await animation.until(row(page, SCANS.failed).waitFor())
		await animation.until(settle(page))
		await animation.hold(3500)
		saveScreenshot('04-inbox', await animation.still())
		animation.caption(L.captions.inboxRun)
		await animation.hold(800)
		// The first run uploads both, and the next one follows their tasks to their ends.
		await animation.until(synchronize('2026-10-25T08:20:00+02:00'))
		await animation.until(control(`task-of/${SCANS.imported}/success`))
		await animation.until(control(`task-of/${SCANS.failed}/failure`))
		await animation.until(synchronize('2026-10-25T08:25:00+02:00'))
		await animation.until(page.reload())
		await animation.until(page.locator('[data-cy-files-content-breadcrumbs]').waitFor())
		await animation.until(settle(page))
		await animation.hold(3000)
		animation.caption(L.captions.inboxFailed)
		await enter(animation, page, tree(page, L.failed), row(page, `${SCANS.failed}.error.txt`))
		await animation.hold(4000)
		saveAnimation('inbox', animation)
		await context.close()
	}

	// A move: in Paperless, the credit card terms become a contract with a new title, and the
	// next run moves their copy from the folder Letter of Example Bank to Contract, with its new
	// name.
	{
		const bank = `${ARCHIVE}/Example Bank`
		const { context, page } = await open(browser, owner, 'light', files(`${bank}/Letter/2026`))
		await row(page, '2026-10-07 - Credit card terms [P190].pdf').waitFor()
		await expandFolderTree(page, [...L.base.split('/'), L.archive])
		const animation = new Animation(page)
		animation.caption(L.captions.moveChange)
		await animation.hold(3500)
		await control('scenario/showcase-moved')
		await animation.until(synchronize('2026-10-25T08:30:00+02:00'))
		animation.caption(L.captions.moveRun)
		await animation.hold(1500)
		await enter(animation, page, tree(page, 'Example Bank'), row(page, 'Contract'), true)
		await enter(animation, page, row(page, 'Contract'), row(page, '2026'))
		await enter(animation, page, row(page, '2026'), row(page, '2026-10-07 - Credit card agreement [P190].pdf'))
		await animation.hold(3500)
		saveAnimation('move', animation)
		await context.close()
	}

	// The trash: in Paperless, the electricity bill of March goes to the trash, and the next run
	// moves its copy to the deleted folder, below the day on which it went to the trash, in the
	// folders that it had in the archive; restored in Paperless, it comes back. The App Store
	// shows the copy in the deleted folder.
	{
		const bills = `${ARCHIVE}/City Utilities/Invoice/2026`
		const march = '2026-03-31 - Electricity bill March [P140].pdf'
		const { context, page } = await open(browser, owner, 'light', files(bills))
		await row(page, march).waitFor()
		await expandFolderTree(page, [...L.base.split('/'), L.archive])
		const animation = new Animation(page)
		animation.caption(L.captions.trashChange)
		await animation.hold(3500)
		await control('scenario/showcase-trashed')
		await animation.until(synchronize('2026-10-25T08:35:00+02:00'))
		await animation.until(page.reload())
		await animation.until(row(page, '2026-06-30 - Electricity bill June [P141].pdf').waitFor())
		await animation.until(settle(page))
		animation.caption(L.captions.trashRun)
		await animation.hold(2000)
		await enter(animation, page, tree(page, L.deleted), row(page, '2026-10-25'))
		for (const [folder, next] of [['2026-10-25', 'City Utilities'], ['City Utilities', 'Invoice'], ['Invoice', '2026'], ['2026', march]]) {
			await enter(animation, page, row(page, folder), row(page, next))
		}
		await animation.hold(3500)
		saveScreenshot('05-trash', await animation.still())
		animation.caption(L.captions.trashRestore)
		await animation.hold(800)
		await control('scenario/showcase-restored')
		await animation.until(synchronize('2026-10-25T08:40:00+02:00'))
		await animation.until(page.goto(files(bills)))
		await animation.until(row(page, march).waitFor())
		await animation.until(settle(page))
		await animation.hold(4000)
		saveAnimation('trash', animation)
		await context.close()
	}
} finally {
	await browser.close()
}

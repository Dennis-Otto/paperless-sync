// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The menu of the actions of a file in Files, which accessibility.mjs and files.mjs open and
// close again and again: with its button, which a phone has as well, or with a right click on
// the row of the file.
//
// Files of Nextcloud 36 at times drops the click that comes right after Escape closed a menu:
// the button takes the focus, but no menu opens. The canary waited 30 s for it in vain on the
// daily build of 2026-11-15, whose same check had passed five hours before, and opened the
// issue "The coming Nextcloud breaks the app" (#515), although the app was fine. So a click
// after which no item of a menu shows within a few seconds is made once more, as a person
// would, and a menu that never opens still fails the check after the last try.

// How many times openMenu() clicks before it gives up.
export const ATTEMPTS = 3

// How long a click has to open its menu before openMenu() clicks once more, and how long the
// last click has.
export const SETTLE = 5_000
export const LAST = 30_000

// Opens a menu with open(), a click that opens it, and waits for its first item. A click that
// opens nothing within settle ms is made again, up to attempts times; the last one has last ms,
// and its timeout is the error of a menu that never opens. Any other error ends it at once.
// A menu that opened late, after its wait ran out, is left open: the button of a row toggles
// its menu, so one more click would close it again.
export async function openMenu(page, open, { attempts = ATTEMPTS, settle = SETTLE, last = LAST } = {}) {
	const item = page.getByRole('menuitem').first()
	const opened = () => item.isVisible().catch(() => false)
	for (let attempt = 1; attempt < attempts; attempt++) {
		if (attempt > 1 && (await opened())) {
			return
		}
		await open()
		try {
			await item.waitFor({ timeout: settle })
			return
		} catch (error) {
			if (error?.name !== 'TimeoutError') {
				throw error
			}
		}
	}
	if (attempts > 1 && (await opened())) {
		return
	}
	await open()
	await item.waitFor({ timeout: last })
}

// Closes the menu that is open with Escape and waits until its items are gone.
export async function closeMenu(page) {
	await page.keyboard.press('Escape')
	await page.getByRole('menuitem').first().waitFor({ state: 'detached' })
}

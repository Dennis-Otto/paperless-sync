// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The menu of the actions of a file in Files, which accessibility.mjs and files.mjs open and
// close: with its button, which a phone has as well, or with a right click on the row of the
// file.
//
// Files of Nextcloud 36 closes the menu of a file by itself when it opens again on the same page
// after it was closed, by Escape or by an item that ran. Its row sets the menu to closed on the
// event "closed" of the menu, which the popover of @nextcloud/vue sends at the end of a
// transition of the menu; when its closing ran none, the event waits for the next one, the
// next opening: the menu opens, and when it has finished opening, Files closes it again. A
// person sees it flash. Nextcloud 33 to 35 don't do it, and on a page that was loaded since,
// Nextcloud 36 doesn't either. The canary failed on it twice: on 2026-11-15 a click right after Escape
// seemed to open nothing (#515), and on 2026-11-18 the menu closed under the click on its item
// (#540). So a check opens the menu of a file only on a page where that menu wasn't open before
// (accessibility.mjs loads the page again), and openMenu() counts a menu as open only when Files
// has finished opening it and its button still says that it is open, so that a menu that Files
// closes by itself fails at once with what happened, not after 30 s under a later click.

// How long a click has to show its menu.
export const TIMEOUT = 30_000

// Opens a menu with open(), a click that opens it, waits for its first item and until Files has
// finished opening it, and fails when Files closed it again.
export async function openMenu(page, open, { timeout = TIMEOUT } = {}) {
	await open()
	await page.getByRole('menuitem').first().waitFor({ timeout })
	await settled(page)
	if (!(await isOpen(page))) {
		throw new Error('Files closed the menu of the file by itself when it had opened, as Nextcloud 36 does when the menu of a file opens again on the same page: open it on a page loaded since (tests/e2e/file-menu.mjs).')
	}
}

// Closes the menu that is open with Escape and waits until its items are gone.
export async function closeMenu(page) {
	await page.keyboard.press('Escape')
	await page.getByRole('menuitem').first().waitFor({ state: 'detached' })
}

// Waits until every animation of the page that ends has ended, such as the opening of a menu,
// and two more frames are drawn, so that what Files does at their end has happened.
function settled(page) {
	return page.evaluate(async () => {
		await Promise.all(document.getAnimations()
			.filter((animation) => animation.effect?.getComputedTiming().endTime !== Infinity)
			.map((animation) => animation.finished.catch(() => undefined)))
		await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
	})
}

// Whether the button of a menu says that its menu is open, as it says to a screen reader.
async function isOpen(page) {
	return (await page.locator('[aria-haspopup="menu"][aria-expanded="true"]').count()) > 0
}

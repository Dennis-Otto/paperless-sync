// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The language of the pictures of screenshots.mjs: English, for docs/images/ and the
// screenshots of the App Store, or German, for docs/images/de/, which README.de.md and the
// German pages of the website show. screenshots.sh sets Nextcloud up in the same language,
// so that the app, Nextcloud and the folders that a new installation proposes speak it.
// The documents of Paperless stay those of showcase.py, in English, in both.

// What the story of the pictures names in each language: the files that someone puts into
// the inbox and into the folders of the administrator, and the tags of the rule of Flow.
const STORIES = {
	en: {
		receipt: 'Receipts/Hardware store.pdf',
		letter: 'Scans/Letter from the bank.pdf',
		flowTag: 'To Paperless',
		flowTags: ['Letters', 'Bank'],
	},
	de: {
		receipt: 'Belege/Baumarkt.pdf',
		letter: 'Scans/Brief der Bank.pdf',
		flowTag: 'An Paperless',
		flowTags: ['Briefe', 'Bank'],
	},
}

// The texts of Nextcloud itself that the pictures look for, which the catalog of the app
// doesn't have, as Nextcloud translates them.
const NEXTCLOUD = {
	de: {
		'Folder tree': 'Ordnerbaum',
	},
}

/**
 * The language `code`, en or de, with the catalog of the app, the translations of
 * l10n/de.json, which only German needs.
 */
export function pictureLanguage(code, catalog = {}) {
	const story = STORIES[code]
	if (story === undefined) {
		throw new Error(`The pictures come in English (en) or German (de), not in "${code}".`)
	}
	const english = code === 'en'
	const translated = (texts, kind) => (text) => {
		if (english) {
			return text
		}
		const translation = texts[text]
		if (typeof translation !== 'string' || translation === '') {
			throw new Error(`${kind} has no German for "${text}".`)
		}
		return translation
	}
	const t = translated(catalog, 'l10n/de.json')
	return {
		code,
		// The language and the locale of Nextcloud, and the locale of the browser.
		nextcloudLocale: english ? 'en_US' : 'de_DE',
		browserLocale: english ? 'en-US' : 'de-DE',
		// The folder of the pictures of the documentation, and whether the App Store gets
		// screenshots, which it shows in English only.
		images: english ? 'docs/images' : `docs/images/${code}`,
		appStore: english,
		// A text of the app as it shows it, and one of Nextcloud.
		t,
		nextcloud: translated(NEXTCLOUD[code] ?? {}, 'The table of the texts of Nextcloud in tests/e2e/language.mjs'),
		// The folders that a new installation proposes, which screenshots.sh keeps.
		base: t('Documents/Paperless'),
		archive: t('Archive'),
		inbox: t('Inbox'),
		...story,
	}
}

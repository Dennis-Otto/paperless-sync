// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The language of the pictures of screenshots.mjs: English, for docs/images/ and the
// screenshots of the App Store, or German, for docs/images/de/, which README.de.md and the
// German pages of the website show. screenshots.sh sets Nextcloud up in the same language,
// so that the app, Nextcloud and the folders that a new installation proposes speak it.
// The documents of Paperless stay those of showcase.py, in English, in both.

// What the story of the pictures names in each language: the files that someone puts into
// the inbox and into the folders of the administrator, the tags of the rule of Flow, and the
// captions of the animations of the inbox, of a move and of the trash, which say what happens
// where no picture shows it, in Paperless and in a run.
const STORIES = {
	en: {
		receipt: 'Receipts/Hardware store.pdf',
		letter: 'Scans/Letter from the bank.pdf',
		flowTag: 'To Paperless',
		flowTags: ['Letters', 'Bank'],
		captions: {
			inboxArrive: 'Two scans arrive in the folder Inbox, such as from the Nextcloud app on a phone',
			inboxRun: 'The next runs send both to Paperless: a scan leaves the inbox once Paperless has finished its import',
			inboxFailed: 'Paperless could not import the second scan: it waits in the folder Failed imports, next to a text file with the reason',
			moveChange: 'In Paperless, Credit card terms becomes a document of the type Contract, with the new title Credit card agreement',
			moveRun: 'The next run moves the copy into the folder of its new type and gives it its new name',
			trashChange: 'In Paperless, Electricity bill March goes to the trash',
			trashRun: 'The next run moves its copy into _Deleted, below the day on which it went to the trash',
			trashRestore: 'Restored in Paperless, the bill comes back to its place in the archive with the next run',
		},
	},
	de: {
		receipt: 'Belege/Baumarkt.pdf',
		letter: 'Scans/Brief der Bank.pdf',
		flowTag: 'An Paperless',
		flowTags: ['Briefe', 'Bank'],
		captions: {
			inboxArrive: 'Zwei Scans kommen im Ordner Eingang an, etwa aus der Nextcloud-App auf dem Handy',
			inboxRun: 'Die nächsten Läufe schicken beide an Paperless: Ein Scan verlässt den Eingang, sobald Paperless seinen Import beendet hat',
			inboxFailed: 'Den zweiten Scan konnte Paperless nicht importieren: Er wartet im Ordner Fehler, neben einer Textdatei mit dem Grund',
			moveChange: 'In Paperless wird Credit card terms zu einem Dokument vom Typ Contract, mit dem neuen Titel Credit card agreement',
			moveRun: 'Der nächste Lauf verschiebt die Kopie in den Ordner ihres neuen Typs und gibt ihr den neuen Namen',
			trashChange: 'In Paperless kommt Electricity bill March in den Papierkorb',
			trashRun: 'Der nächste Lauf verschiebt ihre Kopie nach _Gelöscht, unter den Tag, an dem sie in den Papierkorb kam',
			trashRestore: 'In Paperless wiederhergestellt, kehrt die Rechnung mit dem nächsten Lauf an ihren Platz im Archiv zurück',
		},
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
		failed: t('Failed imports'),
		deleted: t('_Deleted'),
		...story,
	}
}

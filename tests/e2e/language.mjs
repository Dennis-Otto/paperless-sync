// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The language of the pictures of screenshots.mjs: English, for docs/images/ and the
// screenshots of the App Store, or German, for docs/images/de/, which README.de.md and the
// German pages of the website show. screenshots.sh sets Nextcloud up in the same language,
// so that the app, Nextcloud and the folders that a new installation proposes speak it.
// The documents of Paperless stay those of showcase.py, in English, in both. And every language
// of the app, which languages.mjs checks in the browser at the end of an end-to-end run.

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
			tryIt: 'The first run of the demo has filled the archive in Nextcloud Files: by correspondent, type and year',
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
			tryIt: 'Der erste Lauf der Demo hat das Archiv in Nextcloud Dateien gefüllt: nach Korrespondent, Typ und Jahr',
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

// The forms of a plural that Nextcloud picks in the browser, by a table of its own, getPlural of
// @nextcloud/l10n, and not by the "pluralForm" of a catalog, which PHP follows: the first form for
// one alone, for nothing and one in French, and the only one in Chinese.
const ONE = (count) => (count === 1 ? 0 : 1)
const NONE_OR_ONE = (count) => (count === 0 || count === 1 ? 0 : 1)
const ALWAYS = () => 0

// The languages that the end-to-end tests know, English and one for every catalog of l10n/: the
// language of Nextcloud, its locale, that of the browser and its plurals. Only English and German
// have pictures: the website stays in these two.
const LANGUAGES = {
	en: { name: 'English', nextcloudLocale: 'en_US', browserLocale: 'en-US', plural: ONE },
	de: { name: 'German', nextcloudLocale: 'de_DE', browserLocale: 'de-DE', plural: ONE },
	de_DE: { name: 'formal German', nextcloudLocale: 'de_DE', browserLocale: 'de-DE', plural: ONE },
	fr: { name: 'French', nextcloudLocale: 'fr_FR', browserLocale: 'fr-FR', plural: NONE_OR_ONE },
	es: { name: 'Spanish', nextcloudLocale: 'es_ES', browserLocale: 'es-ES', plural: ONE },
	nl: { name: 'Dutch', nextcloudLocale: 'nl_NL', browserLocale: 'nl-NL', plural: ONE },
	zh_CN: { name: 'Simplified Chinese', nextcloudLocale: 'zh_Hans_CN', browserLocale: 'zh-CN', plural: ALWAYS },
	zh_TW: { name: 'Traditional Chinese', nextcloudLocale: 'zh_Hant_TW', browserLocale: 'zh-TW', plural: ALWAYS },
}

/** The languages of the catalogs of l10n/, which languages.mjs checks in the browser, one by one. */
export const CATALOGS = Object.keys(LANGUAGES).filter((code) => code !== 'en')

// The texts by which languages.mjs tells the catalogs apart, as each translates them in words of
// its own, also German with du and with Sie: one that PHP writes into the settings page, and one
// that the scripts of the page read from the catalog for the browser.
export const MARKS = {
	page: 'Keep synchronization disabled until a dry-run has been reviewed.',
	script: 'Your session has expired. Reload the page and try again.',
}

/**
 * The language `code` of the app, with its catalog, the translations of l10n/<code>.json, which
 * only English doesn't need: its texts and plurals, and the folders that a new installation proposes.
 */
export function appLanguage(code, catalog = {}) {
	const language = LANGUAGES[code]
	if (language === undefined) {
		throw new Error(`The end-to-end tests know the languages ${Object.keys(LANGUAGES).join(', ')}, not "${code}".`)
	}
	const english = code === 'en'
	const translated = (texts, kind) => (text) => {
		if (english) {
			return text
		}
		const translation = texts[text]
		if (typeof translation !== 'string' || translation === '') {
			throw new Error(`${kind} has no ${language.name} for "${text}".`)
		}
		return translation
	}
	const t = translated(catalog, `l10n/${code}.json`)
	// A plural of the app as the browser shows it for a count, in the form that the table of
	// Nextcloud picks, which the catalog must have.
	const n = (singular, plural, count) => {
		const forms = english ? [singular, plural] : catalog[`_${singular}_::_${plural}_`]
		const form = Array.isArray(forms) ? forms[language.plural(count)] : undefined
		if (typeof form !== 'string' || form === '') {
			throw new Error(`l10n/${code}.json has no ${language.name} for "${singular}" with ${count}.`)
		}
		return form.replaceAll('%n', String(count))
	}
	return {
		code,
		name: language.name,
		nextcloudLocale: language.nextcloudLocale,
		browserLocale: language.browserLocale,
		// The language as Nextcloud names it in the attribute lang of the page, such as zh-TW.
		pageLanguage: code.replace('_', '-'),
		// A text of the app as it shows it, a plural of the app, and a text of Nextcloud.
		t,
		n,
		nextcloud: translated(NEXTCLOUD[code] ?? {}, 'The table of the texts of Nextcloud in tests/e2e/language.mjs'),
		// The folders that a new installation proposes, which screenshots.sh keeps.
		base: t('Documents/Paperless'),
		archive: t('Archive'),
		inbox: t('Inbox'),
		failed: t('Failed imports'),
		deleted: t('_Deleted'),
	}
}

/**
 * The language `code` of the pictures, en or de, with the catalog of the app, the translations of
 * l10n/de.json, which only German needs.
 */
export function pictureLanguage(code, catalog = {}) {
	const story = STORIES[code]
	if (story === undefined) {
		throw new Error(`The pictures come in English (en) or German (de), not in "${code}".`)
	}
	return {
		...appLanguage(code, catalog),
		// The folder of the pictures of the documentation, and whether the App Store gets
		// screenshots, which it shows in English only.
		images: code === 'en' ? 'docs/images' : `docs/images/${code}`,
		appStore: code === 'en',
		...story,
	}
}

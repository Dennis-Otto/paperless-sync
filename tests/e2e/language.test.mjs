// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The language of the pictures of screenshots.mjs, which scripts/check-project.sh runs with
// node --test, so that a text of the app that the German pictures look for, and that its
// catalog no longer has, fails here and not halfway through a run of screenshots.sh.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { pictureLanguage } from './language.mjs'

const catalog = JSON.parse(readFileSync(new URL('../../l10n/de.json', import.meta.url), 'utf8')).translations
const script = readFileSync(new URL('screenshots.mjs', import.meta.url), 'utf8')

test('the English pictures go to docs/images/ and the App Store, with the texts as they are', () => {
	const english = pictureLanguage('en')
	assert.equal(english.images, 'docs/images')
	assert.equal(english.appStore, true)
	assert.equal(english.browserLocale, 'en-US')
	assert.equal(english.nextcloudLocale, 'en_US')
	assert.equal(english.t('Send to Paperless'), 'Send to Paperless')
	assert.equal(english.nextcloud('Folder tree'), 'Folder tree')
	assert.deepEqual([english.base, english.archive, english.inbox], ['Documents/Paperless', 'Archive', 'Inbox'])
	assert.deepEqual([english.failed, english.deleted], ['Failed imports', '_Deleted'])
	assert.equal(english.receipt, 'Receipts/Hardware store.pdf')
})

test('the German pictures go to docs/images/de/ only, in the words of the German catalog', () => {
	const german = pictureLanguage('de', catalog)
	assert.equal(german.images, 'docs/images/de')
	assert.equal(german.appStore, false)
	assert.equal(german.browserLocale, 'de-DE')
	assert.equal(german.nextcloudLocale, 'de_DE')
	assert.equal(german.t('Send to Paperless'), catalog['Send to Paperless'])
	assert.equal(german.nextcloud('Folder tree'), 'Ordnerbaum')
	assert.deepEqual([german.base, german.archive, german.inbox], ['Dokumente/Paperless', 'Archiv', 'Eingang'])
	assert.deepEqual([german.failed, german.deleted], ['Fehler', '_Gelöscht'])
	assert.equal(german.letter, 'Scans/Brief der Bank.pdf')
})

test('every text that screenshots.mjs looks for has its German', () => {
	const german = pictureLanguage('de', catalog)
	const texts = [...script.matchAll(/\bL\.t\('((?:[^'\\]|\\.)*)'\)/g)].map((match) => match[1])
	const nextcloud = [...script.matchAll(/\bL\.nextcloud\('((?:[^'\\]|\\.)*)'\)/g)].map((match) => match[1])
	assert.ok(texts.length >= 3, 'screenshots.mjs looks for texts of the app with L.t(…).')
	assert.ok(nextcloud.length >= 1, 'screenshots.mjs looks for texts of Nextcloud with L.nextcloud(…).')
	for (const text of texts) {
		assert.notEqual(german.t(text), text, `The German of "${text}" is the English one.`)
	}
	for (const text of nextcloud) {
		assert.ok(german.nextcloud(text))
	}
})

test('the animations have every caption in both languages, each naming the folders of its language', () => {
	const english = pictureLanguage('en')
	const german = pictureLanguage('de', catalog)
	assert.deepEqual(Object.keys(german.captions), Object.keys(english.captions))
	for (const [key, text] of Object.entries(german.captions)) {
		assert.ok(text.length > 0 && text !== english.captions[key], `The German caption ${key} is the English one.`)
	}
	assert.match(english.captions.inboxFailed, new RegExp(english.failed))
	assert.match(german.captions.inboxFailed, new RegExp(german.failed))
	assert.match(english.captions.trashRun, new RegExp(english.deleted))
	assert.match(german.captions.trashRun, new RegExp(german.deleted))
})

test('a text without German fails, as does a language without pictures', () => {
	const german = pictureLanguage('de', catalog)
	assert.throws(() => german.t('A text that the app never shows'), /l10n\/de\.json has no German for "A text that the app never shows"/)
	assert.throws(() => german.nextcloud('A text of Nextcloud'), /texts of Nextcloud .* has no German for "A text of Nextcloud"/)
	assert.throws(() => pictureLanguage('de', {}), /l10n\/de\.json has no German for "Documents\/Paperless"/)
	assert.throws(() => pictureLanguage('fr'), /English \(en\) or German \(de\), not in "fr"/)
})

// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The language of the pictures of screenshots.mjs, which scripts/check-project.sh runs with
// node --test, so that a text of the app that the German pictures look for, and that its
// catalog no longer has, fails here and not halfway through a run of screenshots.sh, and the
// languages of languages.mjs, so that a catalog of l10n/ without its case in the end-to-end
// test of the languages fails here too.

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { appLanguage, CATALOGS, MARKS, pictureLanguage } from './language.mjs'

const words = (code) => JSON.parse(readFileSync(new URL(`../../l10n/${code}.json`, import.meta.url), 'utf8')).translations
const catalog = words('de')
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

for (const [code, base, archive, inbox, failed, deleted] of [
	['de_DE', 'Dokumente/Paperless', 'Archiv', 'Eingang', 'Fehler', '_Gelöscht'],
	['fr', 'Documents/Paperless', 'Archives', 'Boîte de réception', 'Imports échoués', '_Supprimés'],
	['es', 'Documentos/Paperless', 'Archivo', 'Bandeja de entrada', 'Importaciones fallidas', '_Eliminados'],
	['nl', 'Documenten/Paperless', 'Archief', 'Inbox', 'Mislukte imports', '_Verwijderd'],
	['zh_CN', '文档/Paperless', '归档', '收件箱', '导入失败', '_已删除'],
	['zh_TW', '文件/Paperless', '封存', '收件匣', '匯入失敗', '_已刪除'],
]) {
	test(`the app in ${code} proposes the folders of its catalog, without pictures`, () => {
		const translations = words(code)
		const language = appLanguage(code, translations)
		assert.deepEqual([language.base, language.archive, language.inbox], [base, archive, inbox])
		assert.deepEqual([language.failed, language.deleted], [failed, deleted])
		assert.equal(language.t('Save'), translations.Save)
		assert.notEqual(language.t('Save'), 'Save')
		assert.equal(language.nextcloudLocale.slice(0, 2), code.slice(0, 2))
		assert.equal(language.pageLanguage, code.replace('_', '-'))
		assert.throws(() => pictureLanguage(code, translations), /English \(en\) or German \(de\)/)
		assert.throws(() => appLanguage(code, {}), new RegExp(`l10n/${code}\\.json has no [\\w ]+ for "Documents/Paperless"`))
	})
}

test('every catalog of l10n/ has its language in the end-to-end tests, which languages.mjs checks', () => {
	const catalogs = readdirSync(new URL('../../l10n/', import.meta.url)).filter((name) => name.endsWith('.json')).map((name) => name.slice(0, -'.json'.length))
	assert.deepEqual([...CATALOGS].sort(), catalogs.sort(), 'A catalog of l10n/ needs its language in LANGUAGES of tests/e2e/language.mjs, and a language there its catalog.')
	const languages = readFileSync(new URL('languages.mjs', import.meta.url), 'utf8')
	assert.match(languages, /\[DEFAULT, \.\.\.CATALOGS\.filter\(/, 'languages.mjs checks every language of CATALOGS.')
	for (const code of CATALOGS) {
		assert.doesNotThrow(() => appLanguage(code, words(code)))
	}
})

test('a plural takes the form that Nextcloud picks in the browser, which the catalog must have', () => {
	const minutes = (code) => {
		const language = appLanguage(code, code === 'en' ? {} : words(code))
		return [1, 5, 0].map((count) => language.n('%n minute', '%n minutes', count))
	}
	assert.deepEqual(minutes('en'), ['1 minute', '5 minutes', '0 minutes'])
	assert.deepEqual(minutes('de'), ['1 Minute', '5 Minuten', '0 Minuten'])
	assert.deepEqual(minutes('de_DE'), ['1 Minute', '5 Minuten', '0 Minuten'])
	assert.deepEqual(minutes('fr'), ['1 minute', '5 minutes', '0 minute'])
	assert.deepEqual(minutes('es'), ['1 minuto', '5 minutos', '0 minutos'])
	assert.deepEqual(minutes('nl'), ['1 minuut', '5 minuten', '0 minuten'])
	assert.deepEqual(minutes('zh_CN'), ['1 分钟', '5 分钟', '0 分钟'])
	assert.deepEqual(minutes('zh_TW'), ['1 分鐘', '5 分鐘', '0 分鐘'])
	const one = appLanguage('nl', { ...words('nl'), '_%n minute_::_%n minutes_': ['%n minuut'] })
	assert.equal(one.n('%n minute', '%n minutes', 1), '1 minuut')
	assert.throws(() => one.n('%n minute', '%n minutes', 5), /l10n\/nl\.json has no Dutch for "%n minute" with 5/)
	assert.throws(() => appLanguage('zh_TW', { ...words('zh_TW'), '_%n minute_::_%n minutes_': [''] }).n('%n minute', '%n minutes', 1), /l10n\/zh_TW\.json has no Traditional Chinese for "%n minute" with 1/)
})

test('each catalog says the marks of languages.mjs in words of its own, so that the check tells them apart', () => {
	for (const text of Object.values(MARKS)) {
		const translations = CATALOGS.map((code) => appLanguage(code, words(code)).t(text))
		assert.equal(new Set([text, ...translations]).size, CATALOGS.length + 1, `Every catalog must translate "${text}" in words of its own: ${translations.join(' | ')}`)
	}
})

test('a language that the end-to-end tests do not know fails', () => {
	assert.throws(() => appLanguage('xx'), /know the languages en, de, de_DE, fr, es, nl, zh_CN, zh_TW, not "xx"/)
})

test('languages.mjs checks every name that a new installation proposes, as run.sh takes them away', () => {
	const languages = readFileSync(new URL('languages.mjs', import.meta.url), 'utf8')
	const run = readFileSync(new URL('run.sh', import.meta.url), 'utf8')
	const names = /for name in ([a-z_ ]+); do/.exec(run)
	assert.ok(names, 'run.sh names the names that it takes away.')
	const taken = names[1].trim().split(' ')
	assert.equal(taken.length, 9)
	for (const name of taken) {
		assert.match(languages, new RegExp(`\\b${name}:`), `languages.mjs doesn't check ${name}.`)
	}
})

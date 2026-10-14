/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The German catalog against the code of the app: every text that the code translates is in
// l10n/de.json, in German and with its placeholders, every text there is one that the code
// translates, once, and l10n/de.js, which the browser loads, holds the same as l10n/de.json,
// which PHP loads.
//
//   node scripts/check-l10n.mjs [--fix] [folder]
//
// --fix drops the texts that no code translates and the second of a repeated one, and writes
// l10n/de.js from l10n/de.json; a missing text needs its German words, so the check lists it.
// folder is that of the catalog, l10n/ by default; the self-test in scripts/check-project.sh
// hands it broken copies.
//
// The texts are the literal arguments of the calls that translate them:
// - in js/, translate('…') and translatePlural('…', '…'), and Nextcloud's t('paperless_sync', '…')
//   and n('paperless_sync', '…', '…');
// - in templates/ and lib/, ->t('…') and ->n('…', '…') of IL10N;
// - in lib/, the texts that a class translates later, in the language of the administrator who
//   reads them: the message and the hint of a ConnectionCheck, and the text of an
//   InvalidSettingException.
// A class constant there, self::NAME or self::NAME[…], counts with its text or the texts of its
// array, and a text that an argument only compares, such as in $a === self::NAME ? …, doesn't.

import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { runInNewContext } from 'node:vm'

const appId = 'paperless_sync'
const root = fileURLToPath(new URL('..', import.meta.url))
const fix = process.argv.includes('--fix')
const folder = process.argv.slice(2).find((argument) => argument !== '--fix') ?? join(root, 'l10n')
const jsonPath = join(folder, 'de.json')
const javaScriptPath = join(folder, 'de.js')

/** The calls of PHP whose arguments are texts, by their position and by their name. */
const phpCalls = [
	{ call: /->t\(/g, texts: [[0, 'text']] },
	{ call: /->n\(/g, texts: [[0, 'text_singular'], [1, 'text_plural']], plural: true },
	{ call: /\bConnectionCheck::passed\(/g, texts: [[1, 'message']] },
	{ call: /\bConnectionCheck::(?:warning|failed)\(/g, texts: [[1, 'message'], [3, 'hint']] },
	{ call: /\bnew ConnectionCheck\(/g, texts: [[2, 'message'], [4, 'hint']] },
	{ call: /\bnew InvalidSettingException\(/g, texts: [[0, 'text']] },
]
const phpString = /'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"/y
const jsString = String.raw`('(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*"|\x60(?:[^\x60\\$]|\\.)*\x60)`
const jsCalls = [
	new RegExp(String.raw`\btranslate\(\s*${jsString}`, 'g'),
	new RegExp(String.raw`\btranslatePlural\(\s*${jsString}\s*,\s*${jsString}`, 'g'),
	new RegExp(String.raw`\bt\(\s*'${appId}'\s*,\s*${jsString}`, 'g'),
	new RegExp(String.raw`\bn\(\s*'${appId}'\s*,\s*${jsString}\s*,\s*${jsString}`, 'g'),
]

/** The files below a folder of the app, by their path from the root of the repository. */
function files(name, extension) {
	return readdirSync(join(root, name), { recursive: true, withFileTypes: true })
		.filter((entry) => entry.isFile() && entry.name.endsWith(extension))
		.map((entry) => relative(root, join(entry.parentPath ?? entry.path, entry.name)).replaceAll('\\', '/'))
		.sort()
}

function lineOf(source, offset) {
	return source.slice(0, offset).split('\n').length
}

/** The key of a text in the catalog, and of a plural as Nextcloud writes it. */
function key(singular, plural) {
	return plural === undefined ? singular : `_${singular}_::_${plural}_`
}

/** The value of a PHP string; one in double quotes with a variable has none. */
function phpValue(literal) {
	if (literal.startsWith("'")) {
		return literal.slice(1, -1).replace(/\\([\\'])/g, '$1')
	}
	if (/(?<!\\)\$/.test(literal)) {
		return null
	}
	return literal.slice(1, -1).replace(/\\(["\\$])/g, '$1')
}

/**
 * The arguments of the PHP call whose ( ends before start, up to its ): each with its name, if it
 * has one, and its parts outside of brackets, a string, self::NAME or another word or operator.
 * The arguments of an array, from after its [, as well.
 */
function argumentsOf(source, start) {
	const list = [{ name: null, parts: [] }]
	let depth = 0
	let index = start
	while (index < source.length) {
		if (source.startsWith('//', index) || source.startsWith('/*', index)) {
			const end = source.startsWith('//', index) ? source.indexOf('\n', index) : source.indexOf('*/', index) + 1
			index = end <= 0 ? source.length : end + 1
			continue
		}
		const rest = source.slice(index, index + 200)
		const character = source[index]
		if (character === "'" || character === '"') {
			phpString.lastIndex = index
			const literal = phpString.exec(source)[0]
			if (depth === 0) {
				list.at(-1).parts.push({ string: literal, offset: index })
			}
			index += literal.length
			continue
		}
		if ('([{'.includes(character)) {
			depth++
		} else if (')]}'.includes(character)) {
			if (depth === 0) {
				break
			}
			depth--
		} else if (depth === 0 && character === ',') {
			list.push({ name: null, parts: [] })
		} else if (depth === 0 && /\S/.test(character)) {
			const reference = /^self::([A-Z][A-Z0-9_]*)\b(?!\s*\()/.exec(rest)
			const name = /^([a-zA-Z_]\w*)\s*:(?!:)/.exec(rest)
			if (reference) {
				list.at(-1).parts.push({ constant: reference[1] })
				index += reference[0].length
				continue
			}
			if (name && list.at(-1).parts.length === 0) {
				list.at(-1).name = name[1]
				index += name[0].length
				continue
			}
			const word = /^(?:->|\?->|=>|===|!==|==|!=|<=|>=|[$\w\\]+(?:::\w+)?|[^\s'"([{)\]},])/.exec(rest)
			list.at(-1).parts.push({ word: word[0] })
			index += word[0].length
			continue
		}
		index++
	}
	return list
}

/** The texts of the class constants of a PHP file: each NAME with its string, or the strings of its array. */
function constantsOf(source) {
	const constants = new Map()
	for (const match of source.matchAll(/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*/g)) {
		const start = match.index + match[0].length
		if (source[start] === '[') {
			const parts = argumentsOf(source, start + 1).flatMap((argument) => argument.parts)
			const values = parts.filter((part, index) => part.string !== undefined && parts[index + 1]?.word !== '=>')
			constants.set(match[1], values.map((part) => phpValue(part.string)))
		} else if (source[start] === "'" || source[start] === '"') {
			phpString.lastIndex = start
			constants.set(match[1], [phpValue(phpString.exec(source)[0])])
		}
	}
	return constants
}

/** The texts of an argument: its strings and constants, but not those that it compares. */
function textsOf(parts, constants) {
	const comparison = (part) => /^(?:===|!==|==|!=|<=|>=|<|>)$/.test(part?.word ?? '')
	return parts.flatMap((part, index) => {
		if (comparison(parts[index - 1]) || comparison(parts[index + 1])) {
			return []
		}
		if (part.string !== undefined) {
			return [phpValue(part.string)]
		}
		return part.constant === undefined ? [] : (constants.get(part.constant) ?? [])
	}).filter((text) => text !== null)
}

/** Each text that the code translates, with the first place that does. */
const used = new Map()
function use(text, file, line) {
	if (!used.has(text)) {
		used.set(text, `${file}:${line}`)
	}
}

for (const file of files('js', '.js')) {
	const source = readFileSync(join(root, file), 'utf8')
	for (const call of jsCalls) {
		for (const match of source.matchAll(call)) {
			const [singular, plural] = match.slice(1).map((literal) => runInNewContext(literal))
			use(key(singular, plural), file, lineOf(source, match.index))
		}
	}
}
for (const file of [...files('templates', '.php'), ...files('lib', '.php')]) {
	const source = readFileSync(join(root, file), 'utf8')
	const constants = constantsOf(source)
	for (const { call, texts, plural } of phpCalls) {
		for (const match of source.matchAll(call)) {
			const list = argumentsOf(source, match.index + match[0].length)
			const found = texts.map(([position, name]) => {
				const argument = list.find((candidate) => candidate.name === name) ??
					(list[position]?.name === null ? list[position] : undefined)
				return argument === undefined ? [] : textsOf(argument.parts, constants)
			})
			const line = lineOf(source, match.index)
			if (!plural) {
				found.flat().forEach((text) => use(text, file, line))
			} else if (found[0].length === 1 && found[1].length === 1) {
				use(key(found[0][0], found[1][0]), file, line)
			}
		}
	}
}

// The catalog, l10n/de.json, with each text once, and its copy l10n/de.js, which registers the same.
const jsonText = readFileSync(jsonPath, 'utf8')
const catalog = JSON.parse(jsonText)
const translations = catalog.translations
const seen = new Set()
const repeated = []
for (const match of jsonText.matchAll(/^\t\t("(?:[^"\\]|\\.)*"):/gm)) {
	const text = JSON.parse(match[1])
	if (seen.has(text)) {
		repeated.push(text)
	}
	seen.add(text)
}
let registered = null
runInNewContext(readFileSync(javaScriptPath, 'utf8'), {
	OC: {
		L10N: {
			register(id, registeredTranslations, pluralForm) {
				if (id !== appId) {
					throw new Error(`Unexpected translation app ID: ${id}`)
				}
				registered = { translations: registeredTranslations, pluralForm }
			},
		},
	},
})

/** The placeholders of a text: {name} of JavaScript, %n of a plural, and the arguments of %s, %1$s and %d. */
function placeholders(text) {
	const found = new Set(text.match(/\{\w+\}|%n/g) ?? [])
	let next = 0
	for (const match of text.matchAll(/%(?:(\d+)\$)?[sd]/g)) {
		found.add(`argument ${match[1] ?? ++next}`)
	}
	return [...found].sort().join(', ') || 'none'
}

// What --fix can't do: the German words of a text.
const problems = []
for (const text of used.keys()) {
	if (!Object.hasOwn(translations, text)) {
		problems.push(`${used.get(text)} translates a text that l10n/de.json lacks: ${JSON.stringify(text)}`)
	}
}
for (const [text, translation] of Object.entries(translations)) {
	const plural = /^_.*_::_.*_$/s.test(text)
	const sources = plural ? text.slice(1, -1).split('_::_') : [text]
	const forms = plural ? translation : [translation]
	if (!Array.isArray(forms) || forms.length !== sources.length || forms.some((form) => typeof form !== 'string' || form.trim() === '')) {
		problems.push(`l10n/de.json has no German ${plural ? 'singular and plural' : 'words'} for ${JSON.stringify(text)}`)
		continue
	}
	forms.forEach((form, index) => {
		if (placeholders(form) !== placeholders(sources[index])) {
			problems.push(`The German ${JSON.stringify(form)} has the placeholders ${placeholders(form)}, but ${JSON.stringify(sources[index])} has ${placeholders(sources[index])}.`)
		}
	})
}

// What --fix does: drop the texts that no code translates and the second of a repeated one, and
// write the catalog and its copy for the browser in the format of Nextcloud.
const unused = Object.keys(translations).filter((text) => !used.has(text))
const fixedTranslations = Object.fromEntries(Object.entries(translations).filter(([text]) => used.has(text)))
const fixedJson = `${JSON.stringify({ translations: fixedTranslations, pluralForm: catalog.pluralForm }, null, '\t')}\n`
const fixedJavaScript = `OC.L10N.register(\n\t${JSON.stringify(appId)},\n${JSON.stringify(fixedTranslations, null, '\t').replace(/^/gm, '\t')},\n\t${JSON.stringify(catalog.pluralForm)}\n)\n`
if (fix) {
	writeFileSync(jsonPath, fixedJson)
	writeFileSync(javaScriptPath, fixedJavaScript)
	process.stdout.write(`Wrote the catalog without ${unused.length} unused and ${repeated.length} repeated text(s), and l10n/de.js from l10n/de.json.\n`)
} else {
	for (const text of unused) {
		problems.push(`l10n/de.json translates a text that no code translates: ${JSON.stringify(text)}`)
	}
	for (const text of repeated) {
		problems.push(`l10n/de.json has a text twice, and Nextcloud reads only the last one: ${JSON.stringify(text)}`)
	}
	if (registered === null || JSON.stringify(registered) !== JSON.stringify(catalog)) {
		problems.push('l10n/de.js, which the browser loads, and l10n/de.json, which PHP loads, differ.')
	}
}

if (problems.length > 0) {
	process.stdout.write(`${problems.join('\n')}\n\n`)
	process.stdout.write(`${problems.length} problem(s) in the German catalog: an administrator who reads German sees the English text of a missing one, and a wrong placeholder loses a value or breaks the message.\n`)
	process.stdout.write('Fix: add each missing text with its German words and the same placeholders to l10n/de.json, then run node scripts/check-l10n.mjs --fix, which drops the unused and repeated texts and writes l10n/de.js. docs/development.md#translations explains the check.\n')
	process.exit(1)
}
process.stdout.write(`The ${used.size} texts that the app translates are all in l10n/de.json, in German and with their placeholders, once each, and l10n/de.js holds the same.\n`)

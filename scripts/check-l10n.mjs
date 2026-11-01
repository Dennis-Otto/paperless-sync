/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The catalogs against the code of the app: every text that the code translates is in each
// l10n/<language>.json, with its words and its placeholders, in as many forms as a plural of that
// language has, every text there is one that the code translates, once, l10n/<language>.js, which
// the browser loads, holds the same as l10n/<language>.json, which PHP loads, and appinfo/info.xml
// gives the App Store a summary and a description in each language.
//
//   node scripts/check-l10n.mjs [--fix] [folder]
//   node scripts/check-l10n.mjs --new <language> [--plural-form <rule>] [folder]
//
// --fix drops the texts that no code translates and the second of a repeated one, and writes each
// l10n/<language>.js from its l10n/<language>.json; a missing text needs its words, so the check
// lists it. --new writes the catalogs of a new language with an empty translation of every text,
// which the check then lists, and takes the rule of its plurals from the table below or from
// --plural-form, as core/l10n/<language>.json of Nextcloud names it. folder is that of the
// catalogs, l10n/ by default; the self-test in scripts/check-project.sh hands it broken copies.
//
// The texts are the literal arguments of the calls that translate them:
// - in js/, translate('…') and translatePlural('…', '…'), and Nextcloud's t('paperless_sync', '…')
//   and n('paperless_sync', '…', '…');
// - in templates/ and lib/, ->t('…') and ->n('…', '…') of IL10N;
// - in lib/, the texts that a class translates later, in the language of the administrator who
//   reads them: the message and the hint of a ConnectionCheck, the text of an
//   InvalidSettingException, a RunsOffException, a RunException or a FullStorageException, and
//   the text of a RunError, an error of a run that its status and its report keep in English.
// A class constant there, self::NAME or self::NAME[…], counts with its text or the texts of its
// array, and a text that an argument only compares, such as in $a === self::NAME ? …, doesn't.
// Any other argument, such as a variable, fails the check with its place, and --fix then writes
// nothing: its translation would look unused, and --fix would drop it.

import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parseArgs } from 'node:util'
import { runInNewContext } from 'node:vm'

const appId = 'paperless_sync'
const root = fileURLToPath(new URL('..', import.meta.url))
const { values: options, positionals } = parseArgs({
	options: {
		fix: { type: 'boolean', default: false },
		new: { type: 'string' },
		'plural-form': { type: 'string' },
	},
	allowPositionals: true,
})
const fix = options.fix
const folder = positionals[0] ?? join(root, 'l10n')

/** The rules of the plurals of the largest languages that the app doesn't speak yet, from core/l10n/ of Nextcloud. */
const pluralRules = {
	it: 'nplurals=3; plural=n == 1 ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;',
	nl: 'nplurals=2; plural=(n != 1);',
	pl: 'nplurals=4; plural=(n==1 ? 0 : (n%10>=2 && n%10<=4) && (n%100<12 || n%100>14) ? 1 : n!=1 && (n%10>=0 && n%10<=1) || (n%10>=5 && n%10<=9) || (n%100>=12 && n%100<=14) ? 2 : 3);',
	pt_BR: 'nplurals=3; plural=(n == 0 || n == 1) ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;',
	ru: 'nplurals=4; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : n%10==0 || (n%10>=5 && n%10<=9) || (n%100>=11 && n%100<=14)? 2 : 3);',
}

/** The calls of PHP whose arguments are texts, by their position and by their name. */
const phpCalls = [
	{ call: /->t\(/g, texts: [[0, 'text']] },
	{ call: /->n\(/g, texts: [[0, 'text_singular'], [1, 'text_plural']], plural: true },
	{ call: /\bConnectionCheck::passed\(/g, texts: [[1, 'message']] },
	{ call: /\bConnectionCheck::(?:warning|failed)\(/g, texts: [[1, 'message'], [3, 'hint']] },
	{ call: /\bnew ConnectionCheck\(/g, texts: [[2, 'message'], [4, 'hint']] },
	{ call: /\bnew (?:InvalidSetting|RunsOff|Run|FullStorage)Exception\(/g, texts: [[0, 'text']] },
	{ call: /\bnew RunError\(/g, texts: [[0, 'text']] },
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
/** The calls whose text the check can't read, which would make its translation look unused. */
const unreadable = []
/**
 * The calls that translate a text which their class holds, each counted where it is written: the
 * message and the hint of a ConnectionCheck and the text of an InvalidSettingException, a
 * RunException, a FullStorageException or a RunError, at the calls above, and the advice of a
 * PaperlessLocalNetworkException, in its constants.
 */
const generic = new Set([
	'lib/Model/ConnectionCheck.php: $this->message',
	'lib/Model/ConnectionCheck.php: $this->hint',
	'lib/Model/RunError.php: $this->text',
	'lib/Service/InvalidSettingException.php: $this->text',
	'lib/Service/PaperlessApiService.php: $exception->advice',
	'lib/Service/PaperlessLocalNetworkException.php: $this->advice',
	'lib/Service/FullStorageException.php: $text',
	'lib/Service/RunException.php: $text',
])
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
			texts.forEach(([position, name], index) => {
				const argument = list.find((candidate) => candidate.name === name) ??
					(list[position]?.name === null ? list[position] : undefined)
				const spelled = argument?.parts.map((part) => part.word ?? part.string ?? `self::${part.constant}`).join('')
				if (found[index].length === 0 && spelled !== undefined && !generic.has(`${file}: ${spelled}`)) {
					unreadable.push(`${file}:${line} passes a text that the check can't read, ${spelled}; write it as a string or a self:: constant.`)
				}
			})
			if (!plural) {
				found.flat().forEach((text) => use(text, file, line))
			} else if (found[0].length === 1 && found[1].length === 1) {
				use(key(found[0][0], found[1][0]), file, line)
			}
		}
	}
}

/** The catalog for the browser, in the format of Nextcloud. */
function browserCatalog(translations, pluralForm) {
	return `OC.L10N.register(\n\t${JSON.stringify(appId)},\n${JSON.stringify(translations, null, '\t').replace(/^/gm, '\t')},\n\t${JSON.stringify(pluralForm)}\n)\n`
}

/** The catalog for PHP, in the format of Nextcloud. */
function phpCatalog(translations, pluralForm) {
	return `${JSON.stringify({ translations, pluralForm }, null, '\t')}\n`
}

/**
 * The number of forms of a plural that a rule such as "nplurals=2; plural=(n != 1);" names, or
 * null when it names none, or a form beyond that number for some count. The pattern lets only
 * the operators of a rule through, which Nextcloud evaluates in PHP as well.
 */
function formsOf(rule) {
	const match = /^\s*nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*([n\d\s()?:|&=!<>+\-*/%]+?)\s*;?\s*$/.exec(rule ?? '')
	if (match === null || Number(match[1]) < 1) {
		return null
	}
	try {
		const form = runInNewContext(`(n) => Number(${match[2]})`)
		const counts = [0, 1, 2, 3, 4, 5, 10, 11, 12, 21, 22, 25, 100, 101, 102, 1000, 1000000]
		return counts.every((count) => Number.isInteger(form(count)) && form(count) >= 0 && form(count) < Number(match[1])) ? Number(match[1]) : null
	} catch {
		return null
	}
}

/** The singular and the plural of a text that is a plural in the keys of a catalog, or null for any other text. */
function pluralSources(text) {
	return /^_.*_::_.*_$/s.test(text) ? text.slice(1, -1).split('_::_') : null
}

if (options.new !== undefined) {
	const language = options.new
	const rule = options['plural-form'] ?? pluralRules[language]
	const forms = formsOf(rule)
	let refusal = null
	if (!/^[a-z]{2,3}(?:_[A-Za-z0-9]+)?(?:@[a-z]+)?$/.test(language)) {
		refusal = `"${language}" is no code of a language in Nextcloud, such as it, pt_BR, es_419 or sr@latin.`
	} else if (existsSync(join(folder, `${language}.json`))) {
		refusal = `l10n/${language}.json exists already; --new starts a language with empty texts, so it leaves that one alone.`
	} else if (forms === null) {
		refusal = `The plurals of ${language} need their rule: copy "pluralForm" from core/l10n/${language}.json of Nextcloud into --plural-form "nplurals=…; plural=…;".`
	}
	if (refusal !== null || unreadable.length > 0) {
		process.stdout.write(`${[refusal, ...unreadable].filter((line) => line !== null).join('\n')}\n`)
		process.exit(1)
	}
	const empty = Object.fromEntries([...used.keys()].map((text) => [text, pluralSources(text) === null ? '' : Array(forms).fill('')]))
	writeFileSync(join(folder, `${language}.json`), phpCatalog(empty, rule))
	writeFileSync(join(folder, `${language}.js`), browserCatalog(empty, rule))
	process.stdout.write(`Wrote l10n/${language}.json and l10n/${language}.js with ${used.size} empty texts. Translate l10n/${language}.json, add the summary and the description of the language to appinfo/info.xml and run node scripts/check-l10n.mjs --fix. docs/development.md#translate-paperless-sync has the steps.\n`)
	process.exit(0)
}

/** The placeholders of a text: {name} of JavaScript, %n of a plural, the arguments of %s, %1$s and %d, and a lone %. */
function placeholders(text) {
	const found = new Set(text.match(/\{\w+\}|%n/g) ?? [])
	let next = 0
	for (const match of text.matchAll(/%(?:(\d+)\$)?[sd]/g)) {
		found.add(`argument ${match[1] ?? ++next}`)
	}
	// PHP reads the text as a format, and stops at a % that starts no placeholder, such as in 100 %.
	if (/%(?!(?:\d+\$)?[sd]|n)/.test(text)) {
		found.add('a lone %')
	}
	return [...found].sort().join(', ') || 'none'
}

/** The languages in which appinfo/info.xml has an element with a text, or null without the file. */
function storeLanguages(element) {
	const path = join(root, 'appinfo', 'info.xml')
	if (!existsSync(path)) {
		return null
	}
	const pattern = new RegExp(`<${element}\\s+lang="([^"]+)"\\s*>([\\s\\S]*?)</${element}>`, 'g')
	return [...readFileSync(path, 'utf8').matchAll(pattern)]
		.filter((match) => match[2].replace(/<!\[CDATA\[|\]\]>/g, '').trim() !== '')
		.map((match) => match[1])
}

// What --fix can't do: the words of a text, and a text that the check can't read, whose translation
// would look unused.
const problems = [...unreadable]
const entries = readdirSync(folder)
const languages = entries.filter((name) => name.endsWith('.json')).map((name) => name.slice(0, -'.json'.length)).sort()
if (languages.length === 0) {
	problems.push(`${folder} holds no catalog, such as de.json.`)
}
for (const name of entries.filter((entry) => entry.endsWith('.js'))) {
	if (!languages.includes(name.slice(0, -'.js'.length))) {
		problems.push(`l10n/${name} has no l10n/${name.slice(0, -'.js'.length)}.json. Delete it, or add the catalog that it was written from.`)
	}
}
const fixedLanguages = []
for (const language of languages) {
	const json = `l10n/${language}.json`
	const browser = `l10n/${language}.js`
	const jsonPath = join(folder, `${language}.json`)
	const javaScriptPath = join(folder, `${language}.js`)

	// The catalog, with each text once, and its copy for the browser, which registers the same.
	const jsonText = readFileSync(jsonPath, 'utf8')
	let catalog
	try {
		catalog = JSON.parse(jsonText)
	} catch (error) {
		problems.push(`${json} is no valid JSON: ${error.message}`)
		continue
	}
	const translations = catalog.translations ?? {}
	const seen = new Set()
	const repeated = []
	for (const match of jsonText.matchAll(/^[ \t]+("(?:[^"\\]|\\.)*")\s*:/gm)) {
		const text = JSON.parse(match[1])
		if (seen.has(text)) {
			repeated.push(text)
		}
		seen.add(text)
	}
	let registered = null
	try {
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
	} catch {
		// A copy that is missing or that doesn't run differs from the catalog, which --fix mends.
		registered = null
	}

	const forms = formsOf(catalog.pluralForm)
	if (forms === null) {
		problems.push(`${json} has no "pluralForm" that names the number of its forms and picks only those, such as "nplurals=2; plural=(n != 1);": copy it from core/l10n/${language}.json of Nextcloud.`)
	}
	// Nextcloud picks the form of a plural in PHP by "pluralForm", but in the browser by a table of
	// its own, which has only one and other for French and Spanish. Where "pluralForm" has a form for
	// millions, it comes second, so the browser shows that one for every count but one.
	const millions = (catalog.pluralForm ?? '').replace(/\s/g, '').includes('n%1000000==0')
	for (const text of used.keys()) {
		if (!Object.hasOwn(translations, text)) {
			problems.push(`${used.get(text)} translates a text that ${json} lacks: ${JSON.stringify(text)}`)
		}
	}
	for (const [text, translation] of Object.entries(translations)) {
		const sources = pluralSources(text)
		const translated = sources === null ? [translation] : translation
		if (sources !== null && (!Array.isArray(translated) || (forms !== null && translated.length !== forms))) {
			problems.push(`${json} has ${Array.isArray(translated) ? translated.length : 'no list of'} form(s) for ${JSON.stringify(text)}, but its "pluralForm" names ${forms}.`)
			continue
		}
		if (translated.some((form) => typeof form !== 'string' || form.trim() === '')) {
			problems.push(`${json} has no words for ${JSON.stringify(text)}`)
			continue
		}
		translated.forEach((form, index) => {
			const source = sources === null ? text : sources[index === 0 && translated.length > 1 ? 0 : 1]
			if (placeholders(form) !== placeholders(source)) {
				problems.push(`${JSON.stringify(form)} in ${json} has the placeholders ${placeholders(form)}, but ${JSON.stringify(source)} has ${placeholders(source)}.`)
			}
		})
		// A message names a setting by its label in quotes, so it must name it in the words of the page.
		for (const [, label] of text.matchAll(/"([^"]+)"/g)) {
			const words = translations[label]
			if (typeof words === 'string' && words.trim() !== '' && !translated.every((form) => form.includes(words))) {
				problems.push(`${json} names ${JSON.stringify(label)} in quotes, but its translation doesn't hold ${JSON.stringify(words)}: ${JSON.stringify(translated[0])}`)
			}
		}
		if (sources !== null && millions && translated.length === 3 && translated[1] !== translated[2]) {
			problems.push(`${json} has the form ${JSON.stringify(translated[1])} for millions of ${JSON.stringify(text)}, which the browser shows for every count but one: repeat ${JSON.stringify(translated[2])} there.`)
		}
	}
	for (const element of ['summary', 'description']) {
		const store = storeLanguages(element)
		const storeLanguage = language.split(/[_@]/)[0]
		if (store !== null && !store.includes(storeLanguage)) {
			problems.push(`appinfo/info.xml has no <${element} lang="${storeLanguage}">, so the App Store shows its English one to whoever reads the app in ${language}.`)
		}
	}

	// What --fix does: drop the texts that no code translates and the second of a repeated one, and
	// write the catalog and its copy for the browser in the format of Nextcloud.
	const unused = Object.keys(translations).filter((text) => !used.has(text))
	const fixedTranslations = Object.fromEntries(Object.entries(translations).filter(([text]) => used.has(text)))
	if (fix && unreadable.length === 0) {
		writeFileSync(jsonPath, phpCatalog(fixedTranslations, catalog.pluralForm))
		writeFileSync(javaScriptPath, browserCatalog(fixedTranslations, catalog.pluralForm))
		fixedLanguages.push(`${language} without ${unused.length} unused and ${repeated.length} repeated text(s)`)
	} else {
		for (const text of unused) {
			problems.push(`${json} translates a text that no code translates: ${JSON.stringify(text)}`)
		}
		for (const text of repeated) {
			problems.push(`${json} has a text twice, and Nextcloud reads only the last one: ${JSON.stringify(text)}`)
		}
		if (registered === null || JSON.stringify(registered) !== JSON.stringify(catalog)) {
			problems.push(`${browser}, which the browser loads, and ${json}, which PHP loads, differ.`)
		}
	}
}
if (fixedLanguages.length > 0) {
	process.stdout.write(`Wrote the catalogs of ${fixedLanguages.join(', ')}, and each l10n/<language>.js from its l10n/<language>.json.\n`)
}

// No process.exit() here: it drops what a pipe hasn't taken yet, on Linux all after the first 64 KB.
if (problems.length > 0) {
	process.stdout.write(`${problems.join('\n')}\n\n`)
	process.stdout.write(`${problems.length} problem(s) in the catalogs: an administrator who reads the language sees the English text of a missing one, and a wrong placeholder loses a value or breaks the message.\n`)
	process.stdout.write('Fix: add each missing text with its words and the same placeholders to its l10n/<language>.json, then run node scripts/check-l10n.mjs --fix, which drops the unused and repeated texts and writes each l10n/<language>.js. docs/development.md#translations explains the check.\n')
	process.exitCode = 1
} else {
	process.stdout.write(`The ${used.size} texts that the app translates are all in ${languages.map((language) => `l10n/${language}.json`).join(', ')}, with their words, placeholders and plural forms, once each, each l10n/<language>.js holds the same, and appinfo/info.xml has a summary and a description in each language.\n`)
}

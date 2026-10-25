// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The coverage of the JavaScript of the app in the checks in the browser: which lines,
// branches and functions of js/*.js they run. browser.mjs records the coverage of V8 of every
// page of a check in FOLDER/v8/<check>.json, and run.sh runs this in the container of
// Playwright after the checks, on the newest Nextcloud:
//
//   node coverage.mjs FOLDER [FLOOR]
//
// It merges the coverage of each script over every page of every check, as c8 does, with
// @bcoe/v8-coverage, and turns it into that of Istanbul with v8-to-istanbul. It writes
// FOLDER/coverage-final.json, the coverage of Istanbul, FOLDER/lcov.info, which an editor
// or genhtml shows line by line, and FOLDER/summary.md, the table of each script with the
// lines that no check runs, which it prints too and the CI shows in the summary of the job.
// With FLOOR, tests/e2e/coverage-floor.json, it fails when a script of the floor runs a
// smaller share of its lines or branches than its floor, or doesn't run at all, and names a
// floor that the checks pass by more than a point, which may then rise. Without FLOOR it only
// reports, as run.sh has it after a check that failed. coverage.test.mjs checks it.

import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'

// The share of a part, such as the lines, that the checks run: covered of total, in percent.
// A script without branches runs all of its none.
export function percent({ covered, total }) {
	return total === 0 ? 100 : (100 * covered) / total
}

// A share as the summary shows it: one decimal, without a decimal that is 0.
function shown(value) {
	return `${Math.floor(value * 10) / 10} %`
}

// Numbers of lines as ranges, such as 3-5, 9.
export function ranges(lines) {
	const parts = []
	for (const line of [...lines].sort((a, b) => a - b)) {
		const last = parts.at(-1)
		if (last && line === last[1] + 1) {
			last[1] = line
		} else {
			parts.push([line, line])
		}
	}
	return parts.map(([first, last]) => (first === last ? `${first}` : `${first}-${last}`)).join(', ')
}

// The entries of browser.mjs that the coverage can count, and the source of each script. An
// entry without its source, which Chromium couldn't give, and one with another source of its
// script than the first, such as after a deploy during the run, are left out, as their offsets
// name code that the report doesn't have.
export function usable(entries) {
	const sources = new Map()
	const kept = []
	for (const entry of entries) {
		if (!entry.source) {
			console.error(`A page has no source of ${entry.file} (${entry.url}); its coverage is left out.`)
			continue
		}
		if (!sources.has(entry.file)) {
			sources.set(entry.file, entry.source)
		} else if (sources.get(entry.file) !== entry.source) {
			console.error(`A page got another source of ${entry.file} (${entry.url}); its coverage is left out.`)
			continue
		}
		kept.push(entry)
	}
	return { kept, sources }
}

// The coverage of V8 of each script of the app over every page: the entries of browser.mjs,
// merged per script, each turned into the coverage of Istanbul of its file, keyed by its path,
// such as js/settings.js.
export async function convert(entries) {
	const { mergeProcessCovs } = await import('@bcoe/v8-coverage')
	const { default: V8ToIstanbul } = await import('v8-to-istanbul')
	const { kept, sources } = usable(entries)
	const processes = kept.map((entry) => ({ result: [{ scriptId: '0', url: entry.file, functions: entry.functions }] }))
	const coverage = {}
	for (const script of mergeProcessCovs(processes).result) {
		const converter = new V8ToIstanbul(script.url, 0, { source: sources.get(script.url) })
		await converter.load()
		converter.applyCoverage(script.functions)
		for (const file of Object.values(converter.toIstanbul())) {
			coverage[script.url] = { ...file, path: script.url }
		}
	}
	return coverage
}

// How often the checks run each line of a file of the coverage of Istanbul: as often as the
// most of its statements; v8-to-istanbul makes each line a statement.
function lineCounts(file) {
	const lines = new Map()
	for (const [id, statement] of Object.entries(file.statementMap)) {
		const line = statement.start.line
		lines.set(line, Math.max(lines.get(line) ?? 0, file.s[id] ?? 0))
	}
	return lines
}

// The lines, branches and functions of a file of the coverage of Istanbul that the checks run,
// and the lines that they don't run.
export function summarize(file) {
	const lines = lineCounts(file)
	const branches = Object.values(file.b).flat()
	const functions = Object.values(file.f)
	const missed = [...lines].filter(([, count]) => count === 0).map(([line]) => line)
	return {
		file: file.path,
		lines: { covered: lines.size - missed.length, total: lines.size },
		branches: { covered: branches.filter((count) => count > 0).length, total: branches.length },
		functions: { covered: functions.filter((count) => count > 0).length, total: functions.length },
		missed,
	}
}

// What the floor says of the summaries: each script of the floor that runs a smaller share of
// its lines or branches than its floor, or doesn't run at all, fails, and a floor that the
// share passes by more than a point may rise to the share less a point, rounded down.
export function judge(summaries, floor) {
	const failures = []
	const raises = []
	for (const [file, parts] of Object.entries(floor)) {
		const summary = summaries.find((each) => each.file === file)
		if (!summary) {
			failures.push(`${file} runs in no check in the browser, but has a floor in tests/e2e/coverage-floor.json`)
			continue
		}
		for (const part of ['lines', 'branches']) {
			if (typeof parts[part] !== 'number') {
				continue
			}
			const share = percent(summary[part])
			if (share < parts[part]) {
				failures.push(`${file} runs ${shown(share)} of its ${part}, below its floor of ${parts[part]} %`)
			} else if (Math.floor(share) - 1 > parts[part]) {
				raises.push(`${file} runs ${shown(share)} of its ${part}; its floor of ${parts[part]} % may rise to ${Math.floor(share) - 1} %`)
			}
		}
	}
	return { failures, raises }
}

// The summary of the job: a table of the scripts with their floors, the lines that no check
// runs, and what the floor says.
export function markdown(summaries, floor, { failures, raises }) {
	const cell = (part) => `${shown(percent(part))} (${part.covered} of ${part.total})`
	const rows = summaries.map((summary) => {
		const parts = floor?.[summary.file] ?? {}
		const floors = ['lines', 'branches'].filter((part) => typeof parts[part] === 'number').map((part) => `${part} ${parts[part]} %`)
		return `| \`${summary.file}\` | ${cell(summary.lines)} | ${cell(summary.branches)} | ${cell(summary.functions)} | ${floors.join(', ') || '–'} |`
	})
	const missed = summaries.filter((summary) => summary.missed.length > 0).map((summary) => `- \`${summary.file}\`: ${ranges(summary.missed)}`)
	const text = [
		'### Coverage of the JavaScript in the browser',
		'',
		'The share of the scripts of the app that the checks in the browser run, merged over every page of every check (V8 in Chromium).',
		'',
		'| Script | Lines | Branches | Functions | Floor |',
		'| --- | ---: | ---: | ---: | --- |',
		...rows,
	]
	if (missed.length > 0) {
		text.push('', '<details><summary>Lines that no check in the browser runs</summary>', '', ...missed, '', '</details>')
	}
	if (failures.length > 0) {
		text.push('', '**Below the floor of `tests/e2e/coverage-floor.json`:**', '', ...failures.map((failure) => `- ${failure}.`))
	}
	if (raises.length > 0) {
		text.push('', 'The checks pass the floor of `tests/e2e/coverage-floor.json` by more than a point:', '', ...raises.map((raise) => `- ${raise}.`))
	}
	return `${text.join('\n')}\n`
}

// The coverage of Istanbul as LCOV, which editors and genhtml read: per file its functions,
// the count of each line and of each branch.
export function lcov(coverage) {
	const records = []
	for (const file of Object.values(coverage).sort((a, b) => a.path.localeCompare(b.path))) {
		const summary = summarize(file)
		const record = ['TN:', `SF:${file.path}`]
		for (const [id, fn] of Object.entries(file.fnMap)) {
			record.push(`FN:${fn.decl?.start?.line ?? fn.loc.start.line},${fn.name}`)
			record.push(`FNDA:${file.f[id]},${fn.name}`)
		}
		record.push(`FNF:${summary.functions.total}`, `FNH:${summary.functions.covered}`)
		for (const [id, branch] of Object.entries(file.branchMap)) {
			file.b[id].forEach((count, index) => record.push(`BRDA:${branch.loc?.start?.line ?? branch.line},${id},${index},${count}`))
		}
		record.push(`BRF:${summary.branches.total}`, `BRH:${summary.branches.covered}`)
		for (const [line, count] of [...lineCounts(file)].sort(([a], [b]) => a - b)) {
			record.push(`DA:${line},${count}`)
		}
		record.push(`LF:${summary.lines.total}`, `LH:${summary.lines.covered}`, 'end_of_record')
		records.push(record.join('\n'))
	}
	return records.length > 0 ? `${records.join('\n')}\n` : ''
}

// Reads the coverage of the checks in FOLDER/v8/, writes the reports into FOLDER, prints the
// summary and tells whether the scripts reach the floor of the file FLOOR, if it names one:
// 0 when they do or when it names none, 1 when they don't.
export async function report(folder, floorFile = '', { converter = convert } = {}) {
	const dumps = join(folder, 'v8')
	let names = []
	try {
		names = readdirSync(dumps).filter((name) => name.endsWith('.json')).sort()
	} catch {
		// No check in the browser recorded anything.
	}
	const entries = names.flatMap((name) => JSON.parse(readFileSync(join(dumps, name), 'utf8')))
	const coverage = await converter(entries)
	const summaries = Object.values(coverage).map(summarize).sort((a, b) => a.file.localeCompare(b.file))
	const floor = floorFile ? JSON.parse(readFileSync(floorFile, 'utf8')) : null
	const verdict = floor ? judge(summaries, floor) : { failures: [], raises: [] }
	const summary = markdown(summaries, floor, verdict)
	mkdirSync(folder, { recursive: true })
	writeFileSync(join(folder, 'coverage-final.json'), `${JSON.stringify(coverage)}\n`)
	writeFileSync(join(folder, 'lcov.info'), lcov(coverage))
	writeFileSync(join(folder, 'summary.md'), summary)
	console.log(summary)
	console.log(`The checks in the browser ran ${entries.length} copies of the scripts of the app in ${names.length} check(s).`)
	if (verdict.failures.length > 0) {
		// The last line before run.sh fails, which failure.py names as the first error of the run.
		console.log(`The coverage of the JavaScript in the browser falls below its floor: ${verdict.failures.join('; ')}.`)
		return 1
	}
	return 0
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	const [folder, floorFile = ''] = process.argv.slice(2)
	if (!folder) {
		console.error('Usage: node coverage.mjs FOLDER [FLOOR]')
		process.exit(2)
	}
	process.exitCode = await report(folder, floorFile)
}

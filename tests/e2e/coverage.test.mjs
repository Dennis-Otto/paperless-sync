// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The coverage of the JavaScript in the browser, coverage.mjs, which scripts/check-project.sh
// runs with node --test: the share of the lines, branches and functions of a script, its floor,
// the summary of the job and the LCOV of the editors, with coverage of Istanbul as
// v8-to-istanbul makes it. The conversion of V8 itself runs in the container of Playwright,
// where its packages are, on every run of the end-to-end tests on the newest Nextcloud.

import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, test } from 'node:test'
import { judge, lcov, markdown, percent, ranges, report, summarize, usable } from './coverage.mjs'

let folder
let printed

beforeEach(() => {
	folder = mkdtempSync(join(tmpdir(), 'coverage-'))
	printed = []
	console.log = (line) => printed.push(line)
})

afterEach(() => {
	rmSync(folder, { recursive: true, force: true })
})

// The coverage of Istanbul of a script of four lines, as v8-to-istanbul makes it: a statement
// for each line, the blocks of V8 as branches, and its functions, of which the second never runs.
function scriptCoverage(path = 'js/flow.js') {
	const at = (line) => ({ start: { line, column: 0 }, end: { line, column: 10 } })
	return {
		path,
		statementMap: { 0: at(1), 1: at(2), 2: at(3), 3: at(4), 4: at(4) },
		s: { 0: 3, 1: 3, 2: 0, 3: 0, 4: 2 },
		branchMap: { 0: { type: 'branch', line: 1, loc: at(1), locations: [at(1)] }, 1: { type: 'branch', line: 3, loc: at(3), locations: [at(3)] } },
		b: { 0: [3], 1: [0] },
		fnMap: { 0: { name: 'translate', decl: at(1), loc: at(1), line: 1 }, 1: { name: 'unused', decl: at(3), loc: at(3), line: 3 } },
		f: { 0: 3, 1: 0 },
	}
}

test('a share is covered of total in percent, and nothing of nothing is all', () => {
	assert.equal(percent({ covered: 3, total: 4 }), 75)
	assert.equal(percent({ covered: 0, total: 0 }), 100)
})

test('the lines that no check runs read as ranges', () => {
	assert.equal(ranges([9, 3, 4, 5, 12, 11]), '3-5, 9, 11-12')
	assert.equal(ranges([]), '')
})

test('a line counts as often as the most of its statements, and the blocks of V8 count as branches', () => {
	assert.deepEqual(summarize(scriptCoverage()), {
		file: 'js/flow.js',
		lines: { covered: 3, total: 4 },
		branches: { covered: 1, total: 2 },
		functions: { covered: 1, total: 2 },
		missed: [3],
	})
})

test('the floor fails a script below it or that no check runs, and names one that may rise', () => {
	const summaries = [summarize(scriptCoverage('js/flow.js')), summarize(scriptCoverage('js/settings.js'))]
	assert.deepEqual(judge(summaries, { 'js/flow.js': { lines: 75, branches: 50 }, 'js/settings.js': { lines: 73, branches: 51 }, 'js/files.js': { lines: 40 } }), {
		failures: [
			'js/settings.js runs 50 % of its branches, below its floor of 51 %',
			'js/files.js runs in no check in the browser, but has a floor in tests/e2e/coverage-floor.json',
		],
		raises: ['js/settings.js runs 75 % of its lines; its floor of 73 % may rise to 74 %'],
	})
	// A share a point above its floor is the floor as it should be; a part without a floor has none.
	assert.deepEqual(judge(summaries, { 'js/flow.js': { lines: 74 } }), { failures: [], raises: [] })
})

test('the summary of the job shows each script with its floor, the lines that no check runs and what the floor says', () => {
	const summaries = [summarize(scriptCoverage('js/flow.js')), summarize({ ...scriptCoverage('js/files.js'), s: { 0: 1, 1: 1, 2: 1, 3: 1, 4: 1 } })]
	const floor = { 'js/flow.js': { lines: 80, branches: 40 } }
	const text = markdown(summaries, floor, judge(summaries, floor))
	assert.match(text, /^### Coverage of the JavaScript in the browser\n/)
	assert.match(text, /\n\| `js\/flow\.js` \| 75 % \(3 of 4\) \| 50 % \(1 of 2\) \| 50 % \(1 of 2\) \| lines 80 %, branches 40 % \|\n/)
	assert.match(text, /\n\| `js\/files\.js` \| 100 % \(4 of 4\) \| 50 % \(1 of 2\) \| 50 % \(1 of 2\) \| – \|\n/)
	assert.match(text, /<details><summary>Lines that no check in the browser runs<\/summary>\n\n- `js\/flow\.js`: 3\n\n<\/details>/)
	assert.doesNotMatch(text, /`js\/files\.js`: /)
	assert.match(text, /\*\*Below the floor of `tests\/e2e\/coverage-floor\.json`:\*\*\n\n- js\/flow\.js runs 75 % of its lines, below its floor of 80 %\.\n/)
	assert.match(text, /- js\/flow\.js runs 50 % of its branches; its floor of 40 % may rise to 49 %\.\n$/)
	// Without a floor, and when every line runs, the summary is the table alone.
	const complete = markdown(summaries.slice(1).map((summary) => ({ ...summary, missed: [] })), null, { failures: [], raises: [] })
	assert.doesNotMatch(complete, /details|floor/)
})

test('a share just below its floor shows below it, not rounded up to it', () => {
	const file = { ...scriptCoverage(), statementMap: Object.fromEntries(Array.from({ length: 10_000 }, (_, id) => [id, { start: { line: id + 1, column: 0 } }])), s: Object.fromEntries(Array.from({ length: 10_000 }, (_, id) => [id, id < 7_999 ? 1 : 0])) }
	const summaries = [summarize(file)]
	const verdict = judge(summaries, { 'js/flow.js': { lines: 80 } })
	assert.deepEqual(verdict.failures, ['js/flow.js runs 79.9 % of its lines, below its floor of 80 %'])
})

test('LCOV names the functions, branches and lines of each script with their counts', () => {
	assert.equal(lcov({ 'js/flow.js': scriptCoverage() }), [
		'TN:',
		'SF:js/flow.js',
		'FN:1,translate',
		'FNDA:3,translate',
		'FN:3,unused',
		'FNDA:0,unused',
		'FNF:2',
		'FNH:1',
		'BRDA:1,0,0,3',
		'BRDA:3,1,0,0',
		'BRF:2',
		'BRH:1',
		'DA:1,3',
		'DA:2,3',
		'DA:3,0',
		'DA:4,2',
		'LF:4',
		'LH:3',
		'end_of_record',
		'',
	].join('\n'))
	assert.equal(lcov({}), '')
})

test('the report reads the coverage of every check, writes the reports and fails below the floor', async () => {
	mkdirSync(join(folder, 'v8'))
	writeFileSync(join(folder, 'v8', 'settings.json'), JSON.stringify([{ file: 'js/flow.js', url: 'a', source: 's', functions: [] }]))
	writeFileSync(join(folder, 'v8', 'flow.json'), JSON.stringify([{ file: 'js/flow.js', url: 'b', source: 's', functions: [] }]))
	writeFileSync(join(folder, 'v8', 'notes.txt'), 'not coverage')
	const floor = join(folder, 'coverage-floor.json')
	writeFileSync(floor, JSON.stringify({ 'js/flow.js': { lines: 76 } }))
	let converted
	const converter = async (entries) => {
		converted = entries
		return { 'js/flow.js': scriptCoverage() }
	}
	assert.equal(await report(folder, floor, { converter }), 1)
	assert.deepEqual(converted.map((entry) => entry.url), ['b', 'a'])
	assert.deepEqual(JSON.parse(readFileSync(join(folder, 'coverage-final.json'), 'utf8')), { 'js/flow.js': scriptCoverage() })
	assert.match(readFileSync(join(folder, 'lcov.info'), 'utf8'), /^TN:\nSF:js\/flow\.js\n/)
	assert.equal(readFileSync(join(folder, 'summary.md'), 'utf8'), printed[0])
	assert.deepEqual(printed.slice(1), [
		'The checks in the browser ran 2 copies of the scripts of the app in 2 check(s).',
		'The coverage of the JavaScript in the browser falls below its floor: js/flow.js runs 75 % of its lines, below its floor of 76 %.',
	])
	// Without a floor it only reports.
	printed = []
	assert.equal(await report(folder, '', { converter }), 0)
	assert.doesNotMatch(printed.join('\n'), /floor/)
})

test('a report without any coverage says so, and fails every script of the floor', async () => {
	const floor = join(folder, 'coverage-floor.json')
	writeFileSync(floor, JSON.stringify({ 'js/settings.js': { lines: 50, branches: 40 } }))
	assert.equal(await report(join(folder, 'none'), floor, { converter: async () => ({}) }), 1)
	assert.equal(printed[1], 'The checks in the browser ran 0 copies of the scripts of the app in 0 check(s).')
	assert.match(printed[2], /js\/settings\.js runs in no check in the browser/)
})

test('every script of the app has a floor, a whole share of its lines and its branches', () => {
	const floor = JSON.parse(readFileSync(new URL('./coverage-floor.json', import.meta.url), 'utf8'))
	const scripts = readdirSync(new URL('../../js/', import.meta.url)).filter((name) => name.endsWith('.js'))
	assert.deepEqual(Object.keys(floor).sort(), scripts.map((name) => `js/${name}`).sort())
	for (const [file, parts] of Object.entries(floor)) {
		assert.deepEqual(Object.keys(parts).sort(), ['branches', 'lines'], file)
		for (const share of Object.values(parts)) {
			assert.ok(Number.isInteger(share) && share >= 0 && share <= 100, `${file}: ${share}`)
		}
	}
})

test('the coverage leaves out a page without the source of a script, or with another source than the first', () => {
	const errors = []
	console.error = (line) => errors.push(line)
	const entry = (url, source) => ({ file: 'js/flow.js', url, source, functions: [] })
	const { kept, sources } = usable([entry('a', 'one'), entry('b', ''), entry('c', 'two'), entry('d', 'one'), { ...entry('e', 'x'), file: 'js/files.js' }])
	assert.deepEqual(kept.map((each) => each.url), ['a', 'd', 'e'])
	assert.deepEqual([...sources], [['js/flow.js', 'one'], ['js/files.js', 'x']])
	assert.deepEqual(errors, [
		'A page has no source of js/flow.js (b); its coverage is left out.',
		'A page got another source of js/flow.js (c); its coverage is left out.',
	])
})

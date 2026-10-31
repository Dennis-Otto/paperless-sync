// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The tests of the minimatch under ESLint, which scripts/check-project.sh runs with node --test
// once scripts/eslint.sh has installed the packages. ESLint matches every glob of its config and
// of its command line with minimatch and asks for version 10, but only 11.0.1 and newer are free
// of the advisory GHSA-g3hv-75wg-m249, so "overrides" in package.json gives it a newer major
// version than it asks for: the first test keeps the fixed version in the lock file, which no
// update of the lock file would find by itself, and the second shows that ESLint still works
// with it. Drop the override, but not the tests, when ESLint asks for a fixed minimatch itself.

import assert from 'node:assert/strict'
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, relative, sep } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { ESLint } from 'eslint'

const CONFIG = fileURLToPath(new URL('eslint.config.mjs', import.meta.url))
const FIXED = [11, 0, 1]

// Whether a version x.y.z is FIXED or newer.
function isFixed(version) {
	const parts = version.split('.').map(Number)
	const differs = FIXED.findIndex((part, index) => part !== parts[index])
	return differs === -1 || parts[differs] > FIXED[differs]
}

test('isFixed tells the versions before the fix from the fix and those after it', () => {
	for (const version of ['9.0.9', '10.2.6', '11.0.0']) {
		assert.equal(isFixed(version), false, version)
	}
	for (const version of ['11.0.1', '11.0.3', '11.1.0', '12.0.0']) {
		assert.equal(isFixed(version), true, version)
	}
})

test('every minimatch of the lock file has the fix of GHSA-g3hv-75wg-m249', async () => {
	const lock = JSON.parse(await readFile(new URL('package-lock.json', import.meta.url), 'utf8'))
	const copies = Object.entries(lock.packages).filter(([path]) => path.endsWith('node_modules/minimatch'))
	assert.notEqual(copies.length, 0, 'no minimatch in the lock file: remove this test with it')
	for (const [path, { version }] of copies) {
		assert.ok(isFixed(version), `${path} is ${version}, older than ${FIXED.join('.')}: keep "overrides" in scripts/eslint/package.json`)
	}
})

// A script that breaks the rule no-implicit-globals, which fires only where the config of
// the browser matches, because a module has no globals of the page.
const SEEDED = 'function leaked() {}\nleaked()\n'

// The files of the folder that ESLint lints for the patterns, each with the rules that it
// reports, in the order of their paths.
async function linted(cwd, patterns, options = {}) {
	const eslint = new ESLint({ cwd, overrideConfigFile: CONFIG, ...options })
	const results = await eslint.lintFiles(patterns)
	return Object.fromEntries(results.map(({ filePath, messages }) => [
		relative(cwd, filePath).split(sep).join('/'),
		messages.map(({ ruleId }) => ruleId),
	]).sort(([one], [other]) => one.localeCompare(other)))
}

test('ESLint matches the globs of its config and of its command line with this minimatch', async (t) => {
	const folder = await mkdtemp(join(tmpdir(), 'eslint-globs-'))
	t.after(() => rm(folder, { recursive: true, force: true }))
	for (const script of ['js/seeded.js', 'js/deep/seeded.js', 'node_modules/seeded/index.js', '.github/seeded.js']) {
		await mkdir(dirname(join(folder, script)), { recursive: true })
		await writeFile(join(folder, script), SEEDED)
	}
	const top = { 'js/seeded.js': ['no-implicit-globals'] }
	const deep = { 'js/deep/seeded.js': ['no-implicit-globals'] }

	// The globs of the config: files of the browser in every depth of js/, without the
	// folders that it ignores, one of them with **/ and one with a leading dot.
	assert.deepEqual(await linted(folder, ['.']), { ...deep, ...top })
	// The globs of the command line, with *, **, braces and an extglob.
	assert.deepEqual(await linted(folder, ['js/*.js']), top)
	assert.deepEqual(await linted(folder, ['js/**/*.js']), { ...deep, ...top })
	assert.deepEqual(await linted(folder, ['js/{seeded,none}.js']), top)
	assert.deepEqual(await linted(folder, ['js/+(seeded).js']), top)
	// A glob of what to ignore, as --ignore-pattern gives it.
	assert.deepEqual(await linted(folder, ['.'], { ignorePatterns: ['js/deep/'] }), top)
	// And a glob that matches nothing says so, rather than passing.
	await assert.rejects(linted(folder, ['js/*.none']), /No files matching/)
})

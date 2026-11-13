// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The report of the visual check, which scripts/check-project.sh runs with node --test: the
// pictures of the differences that `screenshots keep` drew, the share and the table of the
// pictures that look different, the artifact of a language, the comment of
// visual-comment.yml on the reports of the check, and the paths on which the visual check of
// a pull request renders, which are those of the screenshot bot.

import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, relative, sep } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { change, compare, comparisonPath, diffPath, PICTURES, report, shownShare, summary } from './compare.mjs'

const ROOT = new URL('../../', import.meta.url)
const SCRIPT = fileURLToPath(new URL('compare.mjs', import.meta.url))

// A folder of its own for a test, which goes away when the test ends.
function folder(t) {
	const path = mkdtempSync(join(tmpdir(), 'compare-'))
	t.after(() => rmSync(path, { recursive: true, force: true }))
	return path
}

function put(path, data) {
	mkdirSync(dirname(path), { recursive: true })
	writeFileSync(path, data)
}

// The paths of the files below path, relative to it and sorted.
function files(path) {
	return readdirSync(path, { recursive: true, withFileTypes: true })
		.filter((entry) => entry.isFile())
		.map((entry) => relative(path, join(entry.parentPath, entry.name)).split(sep).join('/'))
		.sort()
}

// The folder of `screenshots keep`: its report.json and each file at its path below it.
function kept(t, pictures, written) {
	const path = folder(t)
	put(join(path, 'report.json'), JSON.stringify({ pictures, ignored: [] }))
	for (const [file, data] of Object.entries(written)) {
		put(join(path, file), data)
	}
	return path
}

test('the difference of a picture is a PNG under diff/ at its path, as keep names it', () => {
	assert.equal(diffPath('docs/images/settings-light.png'), 'diff/docs/images/settings-light.png')
	assert.equal(diffPath('docs/images/archive.gif'), 'diff/docs/images/archive.gif.png')
	assert.equal(diffPath('docs/images/de/archive.gif'), 'diff/docs/images/de/archive.gif.png')
	assert.equal(diffPath('screenshots/LOUD.PNG'), 'diff/screenshots/LOUD.PNG')
})

test('the comparison of a picture is a PNG under comparison/ at its path, as keep names it', () => {
	assert.equal(comparisonPath('docs/images/settings-light.png'), 'comparison/docs/images/settings-light.png')
	assert.equal(comparisonPath('docs/images/archive.gif'), 'comparison/docs/images/archive.gif.png')
	assert.equal(comparisonPath('docs/images/de/archive.gif'), 'comparison/docs/images/de/archive.gif.png')
	assert.equal(comparisonPath('screenshots/LOUD.PNG'), 'comparison/screenshots/LOUD.PNG')
})

test('the change of an animation names the frame of its comparison, as the screenshot bot does', () => {
	assert.equal(change({ status: 'changed', frame: 3, frames: 4 }), 'changed, most in frame 3 of 4')
	assert.equal(change({ status: 'changed', frame: 1, frames: 1 }), 'changed')
	assert.equal(change({ status: 'changed', frame: null, frames: null }), 'changed')
	assert.equal(change({ status: 'new', frame: null, frames: null }), 'new')
})

test('the share of the changed pixels reads as in the pull request of the screenshot bot', () => {
	assert.equal(shownShare(null), 'another size or frames')
	assert.equal(shownShare(1), '100 %')
	assert.equal(shownShare(0.00009), '< 0.01 %')
	assert.equal(shownShare(0.0123), '1.23 %')
})

test('the report of a language lists its pictures that look different, and counts the others', () => {
	const tracked = [
		'docs/images/settings-light.png',
		'docs/images/dry-run.gif',
		'docs/images/app.svg',
		'docs/images/diagrams/how-paperless-sync-moves-documents.svg',
		'docs/images/de/settings-light.png',
		'docs/images/de/report-dark.png',
		'screenshots/02-admin-settings.png',
		'screenshots/02-admin-settings-small.png',
	]
	const found = {
		pictures: [
			{ path: 'docs/images/de/report-dark.png', status: 'changed', share: 0.25, diff: 'diff/docs/images/de/report-dark.png' },
			{
				path: 'docs/images/dry-run.gif',
				status: 'changed',
				share: 0.0011,
				diff: 'diff/docs/images/dry-run.gif.png',
				comparison: 'comparison/docs/images/dry-run.gif.png',
				frame: 3,
				frames: 4,
			},
			{ path: 'docs/images/new-picture.png', status: 'new', share: null, diff: null, comparison: null, frame: null, frames: null },
			{ path: 'docs/images/settings-light.png', status: 'changed', share: 0.5 },
			{ path: 'screenshots/02-admin-settings-small.png', status: 'deleted' },
			{ path: '.github/social-preview.png', status: 'changed', share: 0.1, diff: 'diff/.github/social-preview.png' },
		],
		ignored: [],
	}
	assert.deepEqual(report('en', found, tracked), {
		language: 'en',
		images: [
			// A GIF has its difference and its comparison as a PNG, as a PNG has, of the
			// frame with the most changes, here the third of four.
			{
				path: 'docs/images/dry-run.gif',
				status: 'changed',
				share: 0.0011,
				diff: 'diff/docs/images/dry-run.gif.png',
				comparison: 'comparison/docs/images/dry-run.gif.png',
				frame: 3,
				frames: 4,
			},
			{ path: 'docs/images/new-picture.png', status: 'new', share: null, diff: null, comparison: null, frame: null, frames: null },
			// A report of a keep from before the differences names none.
			{ path: 'docs/images/settings-light.png', status: 'changed', share: 0.5, diff: null, comparison: null, frame: null, frames: null },
			{ path: 'screenshots/02-admin-settings-small.png', status: 'deleted', share: null, diff: null, comparison: null, frame: null, frames: null },
		],
		unchanged: 1,
	})
	assert.deepEqual(report('de', found, tracked), {
		language: 'de',
		images: [{ path: 'docs/images/de/report-dark.png', status: 'changed', share: 0.25, diff: 'diff/docs/images/de/report-dark.png', comparison: null, frame: null, frames: null }],
		unchanged: 1,
	})
	// Without report.json, no picture changed.
	assert.deepEqual(report('de', null, tracked), { language: 'de', images: [], unchanged: 2 })
	assert.throws(() => report('fr', null, tracked), /English \(en\) or German \(de\)/)
})

test('a difference counts only at the place where keep puts that of a changed picture', () => {
	const pictures = [
		{ path: 'docs/images/archive.gif', status: 'changed', share: 0.1, diff: 'diff/docs/images/archive.gif' },
		{ path: 'docs/images/dry-run.gif', status: 'changed', share: 0.1, diff: 'diff/docs/images/../../../dry-run.gif.png' },
		{ path: 'docs/images/report-dark.png', status: 'changed', share: 0.1, diff: 'diff/docs/images/report-light.png' },
		{ path: 'docs/images/report-light.png', status: 'changed', share: 0.1, diff: 'docs/images/report-light.png' },
		{ path: 'docs/images/settings-dark.png', status: 'new', share: null, diff: 'diff/docs/images/settings-dark.png' },
		{ path: 'docs/images/settings-light.png', status: 'deleted', share: null, diff: 'diff/docs/images/settings-light.png' },
		{ path: 'docs/images/status-error.png', status: 'changed', share: 0.1, diff: true },
	]
	const { images } = report('en', { pictures, ignored: [] }, [])
	assert.deepEqual(images.map(({ diff }) => diff), [null, null, null, null, null, null, null])
})

test('a comparison counts only at the place where keep puts that of a changed picture, with one of its frames', () => {
	const at = (path) => comparisonPath(path)
	const pictures = [
		{ path: 'docs/images/archive.gif', status: 'changed', share: 0.1, comparison: 'comparison/docs/images/archive.gif', frame: 1, frames: 2 },
		{ path: 'docs/images/dry-run.gif', status: 'changed', share: 0.1, comparison: 'comparison/docs/images/../../../dry-run.gif.png', frame: 1, frames: 2 },
		{ path: 'docs/images/report-dark.png', status: 'changed', share: 0.1, comparison: 'diff/docs/images/report-dark.png', frame: 1, frames: 1 },
		{ path: 'docs/images/settings-dark.png', status: 'new', share: null, comparison: at('docs/images/settings-dark.png'), frame: 1, frames: 1 },
		// A frame that is not one of the frames, or no whole number from 1, or missing.
		{ path: 'docs/images/sync.gif', status: 'changed', share: 0.1, comparison: at('docs/images/sync.gif'), frame: 5, frames: 4 },
		{ path: 'docs/images/sync-2.gif', status: 'changed', share: 0.1, comparison: at('docs/images/sync-2.gif'), frame: 0, frames: 4 },
		{ path: 'docs/images/sync-3.gif', status: 'changed', share: 0.1, comparison: at('docs/images/sync-3.gif'), frame: 1.5, frames: 4 },
		{ path: 'docs/images/sync-4.gif', status: 'changed', share: 0.1, comparison: at('docs/images/sync-4.gif'), frame: true, frames: 4 },
		{ path: 'docs/images/sync-5.gif', status: 'changed', share: 0.1, comparison: at('docs/images/sync-5.gif') },
		// As keep names it.
		{ path: 'docs/images/sync-6.gif', status: 'changed', share: 0.1, comparison: at('docs/images/sync-6.gif'), frame: 4, frames: 4 },
	]
	const { images } = report('en', { pictures, ignored: [] }, [])
	assert.deepEqual(images.map(({ comparison, frame, frames }) => [comparison, frame, frames]), [
		...Array(9).fill([null, null, null]),
		['comparison/docs/images/sync-6.gif.png', 4, 4],
	])
})

test('the summary is a table of the pictures that look different, or says that none does', () => {
	assert.equal(summary('de', { images: [], unchanged: 36 }), 'Every German picture looks as committed (36 pictures).\n')
	assert.equal(summary('en', {
		images: [
			{
				path: 'docs/images/archive.gif',
				status: 'changed',
				share: 0.0011,
				diff: 'diff/docs/images/archive.gif.png',
				comparison: 'comparison/docs/images/archive.gif.png',
				frame: 3,
				frames: 4,
			},
			{
				path: 'docs/images/report-light.png',
				status: 'changed',
				share: 0.0123,
				diff: 'diff/docs/images/report-light.png',
				comparison: 'comparison/docs/images/report-light.png',
				frame: 1,
				frames: 1,
			},
			{ path: 'docs/images/settings-light.png', status: 'changed', share: null, diff: null, comparison: null, frame: null, frames: null },
			{ path: 'docs/images/new-picture.png', status: 'new', share: null, diff: null, comparison: null, frame: null, frames: null },
		],
		unchanged: 40,
	}), [
		'4 English pictures look different; 40 look as committed.',
		'',
		'| Picture | Change | Pixels changed | Before and after | Difference |',
		'| --- | --- | ---: | --- | --- |',
		'| `docs/images/archive.gif` | changed, most in frame 3 of 4 | 0.11 % | `comparison/docs/images/archive.gif.png` | `diff/docs/images/archive.gif.png` |',
		'| `docs/images/report-light.png` | changed | 1.23 % | `comparison/docs/images/report-light.png` | `diff/docs/images/report-light.png` |',
		'| `docs/images/settings-light.png` | changed | another size or frames |  |  |',
		'| `docs/images/new-picture.png` | new |  |  |  |',
		'',
		'The artifact of the run has each one before and after, and each comparison that the table names '
			+ 'under *Before and after*: the place of the change before and after in one picture, in its own colors '
			+ 'and cut to the changes, with a red frame around each change on both sides. '
			+ 'Each difference that the table names shows the whole picture with every changed pixel in red. '
			+ 'Both show the same frame: of a GIF the one with the most changes, which *Change* names.',
		'',
	].join('\n'))
	// A keep from before the comparisons drew only the differences.
	assert.equal(summary('en', {
		images: [
			{ path: 'docs/images/archive.gif', status: 'changed', share: 0.0011, diff: 'diff/docs/images/archive.gif.png', comparison: null, frame: null, frames: null },
		],
		unchanged: 40,
	}), [
		'1 English pictures look different; 40 look as committed.',
		'',
		'| Picture | Change | Pixels changed | Before and after | Difference |',
		'| --- | --- | ---: | --- | --- |',
		'| `docs/images/archive.gif` | changed | 0.11 % |  | `diff/docs/images/archive.gif.png` |',
		'',
		'The artifact of the run has each one before and after, and each difference that the table names: '
			+ 'the changed pixels in red, framed in red so that a small change shows on a phone too, '
			+ 'and of a GIF its frame with the most changes.',
		'',
	].join('\n'))
	// A comparison without the picture of its difference.
	assert.match(summary('en', {
		images: [
			{
				path: 'docs/images/archive.gif',
				status: 'changed',
				share: 0.0011,
				diff: null,
				comparison: 'comparison/docs/images/archive.gif.png',
				frame: 2,
				frames: 4,
			},
		],
		unchanged: 40,
	}), /on both sides\. Both show the same frame/)
	assert.equal(summary('de', {
		images: [
			{ path: 'docs/images/de/settings-light.png', status: 'changed', share: null, diff: null, comparison: null, frame: null, frames: null },
			{ path: 'docs/images/de/archive.gif', status: 'deleted', share: null, diff: null, comparison: null, frame: null, frames: null },
		],
		unchanged: 30,
	}), [
		'2 German pictures look different; 30 look as committed.',
		'',
		'| Picture | Change | Pixels changed | Before and after | Difference |',
		'| --- | --- | ---: | --- | --- |',
		'| `docs/images/de/settings-light.png` | changed | another size or frames |  |  |',
		'| `docs/images/de/archive.gif` | deleted |  |  |  |',
		'',
		'The artifact of the run has each one before and after. None has a comparison or a picture of its '
			+ 'difference, which a new or deleted picture, or one of another size or frames, lacks.',
		'',
	].join('\n'))
})

test('the artifact of a language holds each picture before and after, and the comparison and the difference that keep drew', (t) => {
	const committed = {
		'docs/images/archive.gif': 'the GIF before',
		'docs/images/report-dark.png': 'the PNG of another size before',
		'docs/images/settings-light.png': 'the PNG before',
		'docs/images/status-error.png': 'the PNG without its difference before',
		'screenshots/02-admin-settings-small.png': 'the deleted PNG',
	}
	const tracked = [...Object.keys(committed), 'docs/images/de/archive.gif', 'screenshots/02-admin-settings.png']
	const folderOfKeep = kept(t, [
		// An animation whose change is in its third frame of four.
		{
			path: 'docs/images/archive.gif',
			status: 'changed',
			share: 0.0011,
			before: null,
			diff: 'diff/docs/images/archive.gif.png',
			comparison: 'comparison/docs/images/archive.gif.png',
			frame: 3,
			frames: 4,
		},
		{ path: 'docs/images/de/archive.gif', status: 'changed', share: 0.5, before: null, diff: 'diff/docs/images/de/archive.gif.png' },
		{ path: 'docs/images/new-picture.png', status: 'new', share: null, before: null, diff: null },
		{ path: 'docs/images/report-dark.png', status: 'changed', share: null, before: [10, 20], diff: null },
		{
			path: 'docs/images/settings-light.png',
			status: 'changed',
			share: 0.0123,
			before: null,
			diff: 'diff/docs/images/settings-light.png',
			comparison: 'comparison/docs/images/settings-light.png',
			frame: 1,
			frames: 1,
		},
		// keep names a difference and a comparison that its folder lacks: the artifact names
		// neither, nor the frame.
		{
			path: 'docs/images/status-error.png',
			status: 'changed',
			share: 0.2,
			before: null,
			diff: 'diff/docs/images/status-error.png',
			comparison: 'comparison/docs/images/status-error.png',
			frame: 1,
			frames: 1,
		},
		{ path: 'screenshots/02-admin-settings-small.png', status: 'deleted', share: null, before: null, diff: null },
	], {
		'files/docs/images/archive.gif': 'the GIF after',
		'files/docs/images/de/archive.gif': 'the German GIF after',
		'files/docs/images/new-picture.png': 'the new PNG',
		'files/docs/images/report-dark.png': 'the PNG of another size after',
		'files/docs/images/settings-light.png': 'the PNG after',
		'files/docs/images/status-error.png': 'the PNG without its difference after',
		'diff/docs/images/archive.gif.png': 'the red of the GIF',
		'diff/docs/images/de/archive.gif.png': 'the red of the German GIF',
		'diff/docs/images/settings-light.png': 'the red of the PNG',
		'comparison/docs/images/archive.gif.png': 'the third frame of the GIF before and after',
		'comparison/docs/images/settings-light.png': 'the PNG before and after',
	})
	const output = folder(t)
	const calls = []
	const git = (args) => {
		calls.push(args.join(' '))
		return Buffer.from(args[0] === 'ls-files' ? `${tracked.join('\0')}\0` : committed[args[1].replace(/^HEAD:/, '')])
	}
	const text = compare('en', folderOfKeep, output, git)
	assert.deepEqual(files(output), [
		'after/docs/images/archive.gif',
		'after/docs/images/new-picture.png',
		'after/docs/images/report-dark.png',
		'after/docs/images/settings-light.png',
		'after/docs/images/status-error.png',
		'before/docs/images/archive.gif',
		'before/docs/images/report-dark.png',
		'before/docs/images/settings-light.png',
		'before/docs/images/status-error.png',
		'before/screenshots/02-admin-settings-small.png',
		'comparison/docs/images/archive.gif.png',
		'comparison/docs/images/settings-light.png',
		'diff/docs/images/archive.gif.png',
		'diff/docs/images/settings-light.png',
		'report.json',
		'summary.md',
	])
	const read = (path) => readFileSync(join(output, path), 'utf8')
	assert.equal(read('before/docs/images/archive.gif'), 'the GIF before')
	assert.equal(read('after/docs/images/archive.gif'), 'the GIF after')
	assert.equal(read('diff/docs/images/archive.gif.png'), 'the red of the GIF')
	assert.equal(read('diff/docs/images/settings-light.png'), 'the red of the PNG')
	assert.equal(read('comparison/docs/images/archive.gif.png'), 'the third frame of the GIF before and after')
	assert.equal(read('comparison/docs/images/settings-light.png'), 'the PNG before and after')
	assert.equal(read('before/screenshots/02-admin-settings-small.png'), 'the deleted PNG')
	const written = JSON.parse(read('report.json'))
	assert.deepEqual(written, {
		language: 'en',
		images: [
			{
				path: 'docs/images/archive.gif',
				status: 'changed',
				share: 0.0011,
				diff: 'diff/docs/images/archive.gif.png',
				comparison: 'comparison/docs/images/archive.gif.png',
				frame: 3,
				frames: 4,
			},
			{ path: 'docs/images/new-picture.png', status: 'new', share: null, diff: null, comparison: null, frame: null, frames: null },
			{ path: 'docs/images/report-dark.png', status: 'changed', share: null, diff: null, comparison: null, frame: null, frames: null },
			{
				path: 'docs/images/settings-light.png',
				status: 'changed',
				share: 0.0123,
				diff: 'diff/docs/images/settings-light.png',
				comparison: 'comparison/docs/images/settings-light.png',
				frame: 1,
				frames: 1,
			},
			{ path: 'docs/images/status-error.png', status: 'changed', share: 0.2, diff: null, comparison: null, frame: null, frames: null },
			{ path: 'screenshots/02-admin-settings-small.png', status: 'deleted', share: null, diff: null, comparison: null, frame: null, frames: null },
		],
		unchanged: 1,
	})
	assert.equal(text, summary('en', written))
	assert.match(text, /\| `docs\/images\/archive\.gif` \| changed, most in frame 3 of 4 \| 0\.11 % \| `comparison\/docs\/images\/archive\.gif\.png` \|/)
	assert.equal(read('summary.md'), text)
	// The committed pictures come from the commit, a new one has none.
	assert.deepEqual(calls, [
		'ls-files -z docs/images screenshots',
		'show HEAD:docs/images/archive.gif',
		'show HEAD:docs/images/report-dark.png',
		'show HEAD:docs/images/settings-light.png',
		'show HEAD:docs/images/status-error.png',
		'show HEAD:screenshots/02-admin-settings-small.png',
	])
})

test('without a report of keep, the artifact says that every picture looks as committed', (t) => {
	const output = folder(t)
	const text = compare('de', folder(t), output, () => Buffer.from('docs/images/de/archive.gif\0docs/images/archive.gif\0'))
	assert.equal(text, 'Every German picture looks as committed (1 pictures).\n')
	assert.deepEqual(files(output), ['report.json', 'summary.md'])
	assert.deepEqual(JSON.parse(readFileSync(join(output, 'report.json'), 'utf8')), { language: 'de', images: [], unchanged: 1 })
})

test('the command writes the artifact from the commit of the repository that it runs in', (t) => {
	const repository = folder(t)
	// Git of the test alone: no variable of a hook that points it elsewhere, no settings of
	// the machine, such as signing.
	const env = Object.fromEntries(Object.entries(process.env).filter(([name]) => !name.startsWith('GIT_')))
	Object.assign(env, {
		GIT_CONFIG_GLOBAL: join(folder(t), 'gitconfig'),
		GIT_CONFIG_NOSYSTEM: '1',
		GIT_AUTHOR_NAME: 'Test',
		GIT_AUTHOR_EMAIL: 'test@example.com',
		GIT_COMMITTER_NAME: 'Test',
		GIT_COMMITTER_EMAIL: 'test@example.com',
	})
	const git = (...args) => execFileSync('git', args, { cwd: repository, env })
	put(join(repository, 'docs/images/archive.gif'), 'the GIF before')
	git('init', '--quiet')
	git('add', '.')
	git('commit', '--quiet', '--message', 'The pictures')
	const folderOfKeep = kept(t, [
		{
			path: 'docs/images/archive.gif',
			status: 'changed',
			share: 0.25,
			before: null,
			diff: 'diff/docs/images/archive.gif.png',
			comparison: 'comparison/docs/images/archive.gif.png',
			frame: 2,
			frames: 3,
		},
	], {
		'files/docs/images/archive.gif': 'the GIF after',
		'diff/docs/images/archive.gif.png': 'the red of the GIF',
		'comparison/docs/images/archive.gif.png': 'the GIF before and after',
	})
	const output = folder(t)
	const run = spawnSync(process.execPath, [SCRIPT, 'en', folderOfKeep, output], { cwd: repository, env, encoding: 'utf8' })
	assert.equal(run.status, 0, run.stderr)
	assert.equal(run.stdout, readFileSync(join(output, 'summary.md'), 'utf8'))
	assert.match(run.stdout, /\| `docs\/images\/archive\.gif` \| changed, most in frame 2 of 3 \| 25\.00 % \| `comparison\/docs\/images\/archive\.gif\.png` \| `diff\/docs\/images\/archive\.gif\.png` \|/)
	assert.equal(readFileSync(join(output, 'before/docs/images/archive.gif'), 'utf8'), 'the GIF before')
	assert.equal(readFileSync(join(output, 'diff/docs/images/archive.gif.png'), 'utf8'), 'the red of the GIF')
	assert.equal(readFileSync(join(output, 'comparison/docs/images/archive.gif.png'), 'utf8'), 'the GIF before and after')
	const usage = spawnSync(process.execPath, [SCRIPT, 'en'], { cwd: repository, env, encoding: 'utf8' })
	assert.equal(usage.status, 2)
	assert.match(usage.stderr, /Usage: node tests\/e2e\/compare\.mjs en\|de FOLDER OUTPUT/)
})

// A Python 3 of the machine, as scripts/check-project.sh finds one: python3, or python on
// Windows, where Python installs no python3.
const PYTHON = ['python3', 'python'].find((name) =>
	spawnSync(name, ['-c', 'import sys; sys.exit(sys.version_info < (3, 8))']).status === 0) ?? null

// The script of visual-comment.yml that writes the comment, as the step runs it.
function commentScript() {
	const text = readFileSync(new URL('.github/workflows/visual-comment.yml', ROOT), 'utf8')
	const block = text.match(/<<'PYTHON'\n([\s\S]*?)\n {10}PYTHON\n/)
	assert.ok(block, 'visual-comment.yml has no script that writes the comment')
	return block[1].split('\n').map((line) => line.replace(/^ {10}/, '')).join('\n')
}

// The comment and whether to post it, from the reports of the check by language and the
// artifacts of its run.
function comment(t, reports, artifacts, conclusion = 'success') {
	const path = folder(t)
	writeFileSync(join(path, 'artifacts.txt'), artifacts.map((line) => `${line}\n`).join(''))
	for (const [code, value] of Object.entries(reports)) {
		put(join(path, `visual-${code}`, 'report.json'), JSON.stringify(value))
	}
	const post = execFileSync(PYTHON, ['-', path], {
		input: commentScript(),
		encoding: 'utf8',
		env: {
			...process.env,
			HEAD_SHA: '0123456789abcdef',
			RUN_URL: 'https://github.com/Dennis-Otto/paperless-sync/actions/runs/42',
			GITHUB_SERVER_URL: 'https://github.com',
			GH_REPO: 'Dennis-Otto/paperless-sync',
			RUN_ID: '42',
			CONCLUSION: conclusion,
			// As on the runner, whose Python writes UTF-8.
			PYTHONUTF8: '1',
		},
	})
	// Python on Windows ends its lines with CRLF.
	return { post: post.trim(), text: readFileSync(join(path, 'comment.md'), 'utf8').replaceAll('\r\n', '\n') }
}

const ARTIFACTS = 'https://github.com/Dennis-Otto/paperless-sync/actions/runs/42/artifacts'
const SKIP = PYTHON === null && !process.env.CI ? 'no Python 3 here; the CI writes the comment' : false

test('the comment marks each picture whose difference the artifact holds in red', { skip: SKIP }, (t) => {
	const { post, text } = comment(t, {
		en: {
			language: 'en',
			images: [
				{ path: 'docs/images/archive.gif', status: 'changed', share: 0.0011, diff: 'diff/docs/images/archive.gif.png' },
				{ path: 'docs/images/report-light.png', status: 'changed', share: 0.0123, diff: 'diff/docs/images/report-light.png' },
				{ path: 'docs/images/settings-light.png', status: 'changed', share: null, diff: null },
				// A difference only at its place, and only of a changed picture.
				{ path: 'docs/images/status-error.png', status: 'changed', share: 0.2, diff: 'diff/../status-error.png' },
				{ path: 'docs/images/new-picture.png', status: 'new', share: null, diff: 'diff/docs/images/new-picture.png' },
			],
			unchanged: 40,
		},
	}, ['visual-en 7'], 'failure')
	assert.equal(post, 'always')
	assert.equal(text, [
		'<!-- visual-check -->',
		'### Visual check',
		'',
		'Rendered from the Paperless mock with its frozen story for 0123456: 5 of 45 English pictures look different from the committed ones.',
		'',
		'| Picture | Change | Pixels changed | Before and after | Difference |',
		'| --- | --- | ---: | --- | --- |',
		'| `docs/images/archive.gif` | changed | 0.11 % |  | in red |',
		'| `docs/images/report-light.png` | changed | 1.23 % |  | in red |',
		'| `docs/images/settings-light.png` | changed | another size or frames |  |  |',
		'| `docs/images/status-error.png` | changed | 20.00 % |  |  |',
		'| `docs/images/new-picture.png` | new |  |  |  |',
		'',
		`The pictures before and after, and the changed pixels of each one marked in red: [English](${ARTIFACTS}/7).`,
		'This check only reports. When the change is intended, the screenshot bot renews the pictures after the merge, in a pull request that merges itself.',
		'',
		'The German pictures could not be rendered for 0123456; the [run](https://github.com/Dennis-Otto/paperless-sync/actions/runs/42) shows why.',
		'',
	].join('\n'))
})

test('the comment promises no red when no picture has a difference', { skip: SKIP }, (t) => {
	const { post, text } = comment(t, {
		en: {
			language: 'en',
			images: [{ path: 'docs/images/settings-light.png', status: 'changed', share: null, diff: null }],
			unchanged: 40,
		},
		de: {
			language: 'de',
			images: [{ path: 'docs/images/de/archive.gif', status: 'deleted', share: null, diff: null }],
			unchanged: 30,
		},
	}, ['visual-en 7', 'visual-de 8'])
	assert.equal(post, 'always')
	assert.equal(text, [
		'<!-- visual-check -->',
		'### Visual check',
		'',
		'Rendered from the Paperless mock with its frozen story for 0123456: 2 of 72 pictures look different from the committed ones.',
		'',
		'| Picture | Change | Pixels changed | Before and after | Difference |',
		'| --- | --- | ---: | --- | --- |',
		'| `docs/images/settings-light.png` | changed | another size or frames |  |  |',
		'| `docs/images/de/archive.gif` | deleted |  |  |  |',
		'',
		`The pictures before and after: [German](${ARTIFACTS}/8) · [English](${ARTIFACTS}/7).`,
		'This check only reports. When the change is intended, the screenshot bot renews the pictures after the merge, in a pull request that merges itself.',
		'',
	].join('\n'))
})

test('the comment names the comparison of each changed picture and the frame that it shows of an animation', { skip: SKIP }, (t) => {
	const at = (path) => comparisonPath(path)
	const { post, text } = comment(t, {
		en: {
			language: 'en',
			images: [
				// An animation whose change is in its third frame of four.
				{
					path: 'docs/images/archive.gif',
					status: 'changed',
					share: 0.0011,
					diff: 'diff/docs/images/archive.gif.png',
					comparison: at('docs/images/archive.gif'),
					frame: 3,
					frames: 4,
				},
				{ path: 'docs/images/report-light.png', status: 'changed', share: 0.0123, diff: null, comparison: at('docs/images/report-light.png'), frame: 1, frames: 1 },
				// A comparison only at its place, and a frame only when it is one of the frames.
				{ path: 'docs/images/status-error.png', status: 'changed', share: 0.2, comparison: 'comparison/../status-error.png', frame: 1, frames: 1 },
				{ path: 'docs/images/sync.gif', status: 'changed', share: 0.2, comparison: at('docs/images/sync.gif'), frame: 5, frames: 4 },
				{ path: 'docs/images/sync-2.gif', status: 'changed', share: 0.2, comparison: at('docs/images/sync-2.gif'), frame: '2', frames: 4 },
				{ path: 'docs/images/new-picture.png', status: 'new', share: null, comparison: at('docs/images/new-picture.png'), frame: 1, frames: 1 },
			],
			unchanged: 40,
		},
		de: { language: 'de', images: [], unchanged: 30 },
	}, ['visual-en 7', 'visual-de 8'])
	assert.equal(post, 'always')
	assert.equal(text, [
		'<!-- visual-check -->',
		'### Visual check',
		'',
		'Rendered from the Paperless mock with its frozen story for 0123456: 6 of 76 pictures look different from the committed ones.',
		'',
		'| Picture | Change | Pixels changed | Before and after | Difference |',
		'| --- | --- | ---: | --- | --- |',
		'| `docs/images/archive.gif` | changed, most in frame 3 of 4 | 0.11 % | `comparison/docs/images/archive.gif.png` | in red |',
		'| `docs/images/report-light.png` | changed | 1.23 % | `comparison/docs/images/report-light.png` |  |',
		'| `docs/images/status-error.png` | changed | 20.00 % |  |  |',
		'| `docs/images/sync.gif` | changed | 20.00 % | `comparison/docs/images/sync.gif.png` |  |',
		'| `docs/images/sync-2.gif` | changed | 20.00 % | `comparison/docs/images/sync-2.gif.png` |  |',
		'| `docs/images/new-picture.png` | new |  |  |  |',
		'',
		'The pictures before and after, with the comparison that *Before and after* names: the place of the change '
			+ 'before and after in one picture, in its own colors, with a red frame around each change on both sides, '
			+ 'of the frame with the most changes of an animation, which *Change* names: '
			+ `[German](${ARTIFACTS}/8) · [English](${ARTIFACTS}/7).`,
		'This check only reports. When the change is intended, the screenshot bot renews the pictures after the merge, in a pull request that merges itself.',
		'',
	].join('\n'))
})

test('the pictures of each language are those that screenshots.sh renders', () => {
	assert.ok(PICTURES.en.test('docs/images/archive.gif'))
	assert.ok(PICTURES.en.test('screenshots/03-dry-run-report-small.png'))
	assert.ok(!PICTURES.en.test('docs/images/de/archive.gif'))
	assert.ok(!PICTURES.en.test('docs/images/app.svg'))
	assert.ok(PICTURES.de.test('docs/images/de/archive.gif'))
	assert.ok(!PICTURES.de.test('screenshots/02-admin-settings.png'))
})

// The paths of a trigger of a workflow, one per line below "paths:".
function workflowPaths(text) {
	const block = text.match(/^ {4}paths:\n((?: {6}- .+\n)+)/m)
	assert.ok(block, 'the workflow has no paths')
	return block[1].trim().split('\n').map((line) => line.replace(/^\s*- /, '').replace(/^"(.*)"$/, '$1'))
}

test('the visual check renders on the changes on which the screenshot bot renders, but the social preview', () => {
	const listed = readFileSync(new URL('.github/screenshots-paths.txt', ROOT), 'utf8')
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '' && !line.startsWith('#'))
	const visual = workflowPaths(readFileSync(new URL('.github/workflows/visual.yml', ROOT), 'utf8'))
	// The visual check leaves out the social preview, which no page of the app shows.
	const pictures = listed.filter((path) => !/social-preview/.test(path))
	assert.deepEqual(pictures.filter((path) => !visual.includes(path)), [], 'the visual check misses these paths of .github/screenshots-paths.txt')
	const own = ['.github/workflows/visual.yml', '.github/screenshots-requirements.txt', 'tests/e2e/compare.mjs']
	assert.deepEqual(visual.filter((path) => !pictures.includes(path) && !own.includes(path)), [], 'the visual check renders on paths that the screenshot bot ignores')
})

// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The report of the visual check (.github/workflows/visual.yml) of one language: after
// screenshots.sh rendered the pictures of a pull request in place, and
// `python3 .github/blueprint.py screenshots keep FOLDER` gave every picture whose pixels
// stayed the same its committed file back and copied the others into FOLDER, this script
// puts each picture that looks different before and after the change side by side, with
// the comparison and the picture of its difference that keep drew, PNG and GIF alike. The
// comparison is the place of the change before and after in one picture, in the
// picture's own colors and cut to the changes, with a red frame around each change on
// both sides; the difference is the whole new picture with each changed pixel in red,
// behind a thin veil, with a red frame around each place of change that stays visible
// when a phone shows the picture scaled down. Both show the same frame: of a GIF the one
// with the most changed pixels, which the report names as `frame` of `frames`. A picture
// of another size or frames, and a new or a deleted one, has neither; the share of the
// changed pixels of a GIF counts every frame.
//
//   node tests/e2e/compare.mjs en|de FOLDER OUTPUT
//
// OUTPUT gets before/, after/, comparison/ and diff/, each with the paths of the
// pictures, a comparison and a difference at its path with .png added unless the path
// ends in it, as keep names them; report.json, which visual-comment.yml and the pager
// read; and summary.md for the summary of the check.

import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { pathToFileURL } from 'node:url'

// The pictures that screenshots.sh renders in each language, by their paths.
export const PICTURES = {
	en: /^(?:docs\/images\/[a-z0-9][a-z0-9-]*\.(?:png|gif)|screenshots\/[a-z0-9][a-z0-9-]*\.png)$/,
	de: /^docs\/images\/de\/[a-z0-9][a-z0-9-]*\.(?:png|gif)$/,
}

// Where keep puts the picture of the difference of the picture at path, in its folder,
// and this script in the artifact: under diff/ at its path, as a PNG, whose name ends in
// .png (diff_path of .github/blueprint/screenshots.py).
export function diffPath(path) {
	return `diff/${path}${path.toLowerCase().endsWith('.png') ? '' : '.png'}`
}

// Where keep puts the comparison of the picture at path, before and after in one picture,
// in its folder, and this script in the artifact: under comparison/ at its path, as a
// PNG, whose name ends in .png (comparison_path of .github/blueprint/screenshots.py).
export function comparisonPath(path) {
	return `comparison/${diffPath(path).slice('diff/'.length)}`
}

// A count of keep's report, such as the number of a frame: a whole number from 1.
function counted(value) {
	return Number.isSafeInteger(value) && value >= 1
}

// How a picture changed, and for an animation which of its frames the comparison shows,
// as the screenshot bot names it: "changed, most in frame 4 of 7".
export function change({ status, frame, frames }) {
	return frames > 1 ? `${status}, most in frame ${frame} of ${frames}` : status
}

// The share of the changed pixels, as the comment shows it.
export function shownShare(share) {
	if (typeof share !== 'number') {
		return 'another size or frames'
	}
	if (share >= 1) {
		return '100 %'
	}
	return share < 0.0001 ? '< 0.01 %' : `${(share * 100).toFixed(2)} %`
}

// What the artifact holds besides each picture before and after, as the summary says it:
// the comparisons and the differences that the table names, or that it holds none.
function held(compared, drawn) {
	if (compared) {
		return 'The artifact of the run has each one before and after, and each comparison that the table names '
			+ 'under *Before and after*: the place of the change before and after in one picture, in its own colors '
			+ 'and cut to the changes, with a red frame around each change on both sides. '
			+ (drawn ? 'Each difference that the table names shows the whole picture with every changed pixel in red. ' : '')
			+ 'Both show the same frame: of a GIF the one with the most changes, which *Change* names.'
	}
	if (drawn) {
		return 'The artifact of the run has each one before and after, and each difference that the table names: '
			+ 'the changed pixels in red, framed in red so that a small change shows on a phone too, '
			+ 'and of a GIF its frame with the most changes.'
	}
	return 'The artifact of the run has each one before and after. None has a comparison or a picture of its '
		+ 'difference, which a new or deleted picture, or one of another size or frames, lacks.'
}

// The summary of the check: a table of the pictures that look different, with the
// comparison and the picture of the difference of each one that has them, and the frame
// that they show of an animation, or that none has them.
export function summary(language, report) {
	const name = language === 'de' ? 'German' : 'English'
	if (report.images.length === 0) {
		return `Every ${name} picture looks as committed (${report.unchanged} pictures).\n`
	}
	const named = (file) => (file ? `\`${file}\`` : '')
	const rows = report.images.map((image) => `| \`${image.path}\` | ${change(image)} | `
		+ `${image.status === 'changed' ? shownShare(image.share) : ''} | ${named(image.comparison)} | ${named(image.diff)} |`)
	return [
		`${report.images.length} ${name} pictures look different; ${report.unchanged} look as committed.`,
		'',
		'| Picture | Change | Pixels changed | Before and after | Difference |',
		'| --- | --- | ---: | --- | --- |',
		...rows,
		'',
		held(report.images.some(({ comparison }) => comparison), report.images.some(({ diff }) => diff)),
		'',
	].join('\n')
}

// The report of the pictures of one language, from the folder of `screenshots keep`, which
// holds report.json only when a picture changed, and the pictures that Git tracks. A
// changed picture has the difference and the comparison that keep names for it, where
// keep puts them, and the comparison the frame that it shows, from 1, of the frames of
// the picture, as keep names both together with it. A comparison whose frame is not one
// of the frames is not as keep writes it and counts as none; a report of a keep from
// before the comparisons names none.
export function report(language, kept, tracked) {
	const pattern = PICTURES[language]
	if (pattern === undefined) {
		throw new Error(`The pictures come in English (en) or German (de), not in "${language}"`)
	}
	const pictures = kept === null ? [] : kept.pictures
	const images = pictures
		.filter(({ path }) => pattern.test(path))
		.map(({ path, status, share, diff, comparison, frame, frames }) => {
			const compared = status === 'changed' && comparison === comparisonPath(path)
				&& counted(frame) && counted(frames) && frame <= frames
			return {
				path,
				status,
				share: share ?? null,
				diff: status === 'changed' && diff === diffPath(path) ? diff : null,
				comparison: compared ? comparison : null,
				frame: compared ? frame : null,
				frames: compared ? frames : null,
			}
		})
	const changed = new Set(images.filter(({ status }) => status !== 'new').map(({ path }) => path))
	const unchanged = tracked.filter((path) => pattern.test(path) && !changed.has(path)).length
	return { language, images, unchanged }
}

function write(path, data) {
	mkdirSync(dirname(path), { recursive: true })
	writeFileSync(path, data)
}

// Writes the report of one language into output, from the folder of keep, with the
// committed pictures that git gives for their paths.
export function compare(language, folder, output, git) {
	const keptReport = join(folder, 'report.json')
	const kept = existsSync(keptReport) ? JSON.parse(readFileSync(keptReport, 'utf8')) : null
	const tracked = git(['ls-files', '-z', 'docs/images', 'screenshots']).toString('utf8').split('\0').filter(Boolean)
	const result = report(language, kept, tracked)
	for (const image of result.images) {
		const { path, status, diff, comparison } = image
		if (status !== 'new') {
			write(join(output, 'before', path), git(['show', `HEAD:${path}`]))
		}
		if (status !== 'deleted') {
			write(join(output, 'after', path), readFileSync(join(folder, 'files', path)))
		}
		// keep writes each difference and comparison that it names; the summary names none
		// it lacks, and no frame of a comparison it lacks.
		if (diff !== null && existsSync(join(folder, diff))) {
			write(join(output, diff), readFileSync(join(folder, diff)))
		} else {
			image.diff = null
		}
		if (comparison !== null && existsSync(join(folder, comparison))) {
			write(join(output, comparison), readFileSync(join(folder, comparison)))
		} else {
			Object.assign(image, { comparison: null, frame: null, frames: null })
		}
	}
	const text = summary(language, result)
	write(join(output, 'report.json'), `${JSON.stringify(result, null, 1)}\n`)
	write(join(output, 'summary.md'), text)
	return text
}

function main([language, folder, output]) {
	if (!output) {
		console.error('Usage: node tests/e2e/compare.mjs en|de FOLDER OUTPUT')
		return 2
	}
	const git = (args) => execFileSync('git', args, { maxBuffer: 64 * 1024 * 1024 })
	process.stdout.write(compare(language, folder, output, git))
	return 0
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
	process.exitCode = main(process.argv.slice(2))
}

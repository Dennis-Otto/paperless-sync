// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The report of the visual check (.github/workflows/visual.yml) of one language: after
// screenshots.sh rendered the pictures of a pull request in place, and
// `python3 .github/blueprint.py screenshots keep FOLDER` gave every picture whose pixels
// stayed the same its committed file back and copied the others into FOLDER, this script
// puts each picture that looks different before and after the change side by side, with a
// third PNG that shows every changed pixel in red on a pale copy of the new picture. A GIF
// gets no such picture; the share of its changed pixels counts every frame.
//
//   node tests/e2e/compare.mjs en|de FOLDER OUTPUT
//
// OUTPUT gets before/, after/ and diff/, each with the paths of the pictures, report.json,
// which visual-comment.yml reads, and summary.md for the summary of the check.

import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { pathToFileURL } from 'node:url'

// The pictures that screenshots.sh renders in each language, by their paths.
export const PICTURES = {
	en: /^(?:docs\/images\/[a-z0-9][a-z0-9-]*\.(?:png|gif)|screenshots\/[a-z0-9][a-z0-9-]*\.png)$/,
	de: /^docs\/images\/de\/[a-z0-9][a-z0-9-]*\.(?:png|gif)$/,
}

// The changed pixels of two pictures of the same size, as RGBA in rows: the new picture,
// pale, with each pixel that changed in red, and how many changed. A pixel changed when
// one of its channels did; a pixel that is fully transparent in both counts as the same.
export function difference(before, after) {
	if (before.width !== after.width || before.height !== after.height) {
		throw new Error('Only pictures of the same size have a difference')
	}
	const data = Buffer.alloc(after.data.length)
	let changed = 0
	for (let offset = 0; offset < data.length; offset += 4) {
		const same = (before.data[offset + 3] === 0 && after.data[offset + 3] === 0)
			|| (before.data[offset] === after.data[offset]
				&& before.data[offset + 1] === after.data[offset + 1]
				&& before.data[offset + 2] === after.data[offset + 2]
				&& before.data[offset + 3] === after.data[offset + 3])
		if (same) {
			// A fifth of the gray of the pixel on white, so that the red stands out.
			const gray = Math.round(0.299 * after.data[offset] + 0.587 * after.data[offset + 1] + 0.114 * after.data[offset + 2])
			const pale = Math.round(255 - (255 - gray) * 0.2 * (after.data[offset + 3] / 255))
			data.fill(pale, offset, offset + 3)
		} else {
			changed++
			data[offset] = 230
			data[offset + 1] = 0
			data[offset + 2] = 0
		}
		data[offset + 3] = 255
	}
	return { image: { width: after.width, height: after.height, data }, changed }
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

// The summary of the check: a table of the pictures that look different, or that none does.
export function summary(language, report) {
	const name = language === 'de' ? 'German' : 'English'
	if (report.images.length === 0) {
		return `Every ${name} picture looks as committed (${report.unchanged} pictures).\n`
	}
	const rows = report.images.map(({ path, status, share }) =>
		`| \`${path}\` | ${status} | ${status === 'changed' ? shownShare(share) : ''} |`)
	return [
		`${report.images.length} ${name} pictures look different; ${report.unchanged} look as committed.`,
		'',
		'| Picture | Change | Pixels changed |',
		'| --- | --- | ---: |',
		...rows,
		'',
		'The artifact of the run has each one before and after, and the changed pixels in red.',
		'',
	].join('\n')
}

// The report of the pictures of one language, from the folder of `screenshots keep`, which
// holds report.json only when a picture changed, and the pictures that Git tracks.
export function report(language, kept, tracked) {
	const pattern = PICTURES[language]
	if (pattern === undefined) {
		throw new Error(`The pictures come in English (en) or German (de), not in "${language}"`)
	}
	const pictures = kept === null ? [] : kept.pictures
	const images = pictures
		.filter(({ path }) => pattern.test(path))
		.map(({ path, status, share }) => ({ path, status, share: share ?? null }))
	const changed = new Set(images.filter(({ status }) => status !== 'new').map(({ path }) => path))
	const unchanged = tracked.filter((path) => pattern.test(path) && !changed.has(path)).length
	return { language, images, unchanged }
}

function write(path, data) {
	mkdirSync(dirname(path), { recursive: true })
	writeFileSync(path, data)
}

async function main([language, folder, output]) {
	if (!output) {
		console.error('Usage: node tests/e2e/compare.mjs en|de FOLDER OUTPUT')
		return 2
	}
	const { PNG } = await import('pngjs')
	const keptReport = join(folder, 'report.json')
	const kept = existsSync(keptReport) ? JSON.parse(readFileSync(keptReport, 'utf8')) : null
	const tracked = execFileSync('git', ['ls-files', '-z', 'docs/images', 'screenshots'], { encoding: 'utf8' }).split('\0').filter(Boolean)
	const result = report(language, kept, tracked)
	for (const { path, status } of result.images) {
		const before = status === 'new' ? null : execFileSync('git', ['show', `HEAD:${path}`], { maxBuffer: 64 * 1024 * 1024 })
		const after = status === 'deleted' ? null : readFileSync(join(folder, 'files', path))
		if (before !== null) {
			write(join(output, 'before', path), before)
		}
		if (after !== null) {
			write(join(output, 'after', path), after)
		}
		if (before !== null && after !== null && path.endsWith('.png')) {
			const old = PNG.sync.read(before)
			const now = PNG.sync.read(after)
			if (old.width === now.width && old.height === now.height) {
				write(join(output, 'diff', path), PNG.sync.write(difference(old, now).image, { colorType: 2 }))
			}
		}
	}
	write(join(output, 'report.json'), `${JSON.stringify(result, null, 1)}\n`)
	write(join(output, 'summary.md'), summary(language, result))
	process.stdout.write(summary(language, result))
	return 0
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
	process.exitCode = await main(process.argv.slice(2))
}

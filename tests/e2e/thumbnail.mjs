// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The small thumbnail of a screenshot of the App Store, which the list of the apps shows in
// place of the whole picture (the attribute small-thumbnail of <screenshot> in
// appinfo/info.xml). screenshots.mjs writes one next to each screenshot, as
// screenshots/<name>-small.png.
//
// The list shows the first screenshot of an app at most 200 pixels high, so the thumbnail
// shrinks to fit THUMBNAIL, twice that on a screen of twice the density, at the aspect ratio
// of the screenshot: each of its pixels is the average of the pixels of the screenshot that it
// covers, weighted by how much of each it covers and by their opacity, so that the same
// screenshot gives the same thumbnail on every machine. It takes and returns the pixels as
// pngjs reads them: width, height and the bytes of their red, green, blue and alpha.

export const THUMBNAIL = { width: 640, height: 400 }

// For each pixel of a row (or a column) of the thumbnail, the pixels of the screenshot that it
// covers, with the share of each that it covers.
function spans(source, target) {
	const scale = source / target
	return Array.from({ length: target }, (_, index) => {
		const start = index * scale
		const end = Math.min(source, start + scale)
		const covered = []
		for (let pixel = Math.floor(start); pixel < end; pixel++) {
			const share = Math.min(end, pixel + 1) - Math.max(start, pixel)
			if (share > 1e-9) {
				covered.push([pixel, share])
			}
		}
		return covered
	})
}

export function thumbnail({ width, height, data }, box = THUMBNAIL) {
	const scale = Math.min(box.width / width, box.height / height)
	if (scale >= 1) {
		throw new Error(`A screenshot of ${width} × ${height} pixels already fits into its thumbnail of ${box.width} × ${box.height}.`)
	}
	const targetWidth = Math.max(1, Math.round(width * scale))
	const targetHeight = Math.max(1, Math.round(height * scale))
	const columns = spans(width, targetWidth)
	const rows = spans(height, targetHeight)
	// The colors weighted by their opacity, so that a transparent pixel lends none of its
	// color, first across each row of the screenshot, then down each column.
	const across = new Float64Array(targetWidth * height * 4)
	for (let y = 0; y < height; y++) {
		for (let x = 0; x < targetWidth; x++) {
			const sum = (y * targetWidth + x) * 4
			for (const [pixel, share] of columns[x]) {
				const source = (y * width + pixel) * 4
				const alpha = data[source + 3] * share
				across[sum] += data[source] * alpha
				across[sum + 1] += data[source + 1] * alpha
				across[sum + 2] += data[source + 2] * alpha
				across[sum + 3] += alpha
			}
		}
	}
	const area = (width / targetWidth) * (height / targetHeight)
	const pixels = Buffer.alloc(targetWidth * targetHeight * 4)
	for (let y = 0; y < targetHeight; y++) {
		for (let x = 0; x < targetWidth; x++) {
			let red = 0
			let green = 0
			let blue = 0
			let alpha = 0
			for (const [row, share] of rows[y]) {
				const sum = (row * targetWidth + x) * 4
				red += across[sum] * share
				green += across[sum + 1] * share
				blue += across[sum + 2] * share
				alpha += across[sum + 3] * share
			}
			const target = (y * targetWidth + x) * 4
			if (alpha > 0) {
				pixels[target] = Math.round(red / alpha)
				pixels[target + 1] = Math.round(green / alpha)
				pixels[target + 2] = Math.round(blue / alpha)
			}
			pixels[target + 3] = Math.round(alpha / area)
		}
	}
	return { width: targetWidth, height: targetHeight, data: pixels }
}

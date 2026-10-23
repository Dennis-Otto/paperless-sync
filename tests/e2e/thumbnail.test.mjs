// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The tests of thumbnail.mjs, which scripts/check-project.sh runs with node --test, without
// the packages of the end-to-end tests.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { THUMBNAIL, thumbnail } from './thumbnail.mjs'

// A picture of width × height pixels, each of the color that pixel(x, y) gives.
function picture(width, height, pixel) {
	const data = Buffer.alloc(width * height * 4)
	for (let y = 0; y < height; y++) {
		for (let x = 0; x < width; x++) {
			data.set(pixel(x, y), (y * width + x) * 4)
		}
	}
	return { width, height, data }
}

function pixels({ width, height, data }) {
	return Array.from({ length: width * height }, (_, index) => [...data.subarray(index * 4, index * 4 + 4)])
}

test('a screenshot of the App Store shrinks to fit 640 × 400 pixels at its aspect ratio', () => {
	assert.deepEqual(THUMBNAIL, { width: 640, height: 400 })
	const white = () => [255, 255, 255, 255]
	const sizes = [[1280, 800], [936, 746], [1600, 600]].map(([width, height]) => {
		const { width: across, height: down } = thumbnail(picture(width, height, white))
		return [across, down]
	})
	assert.deepEqual(sizes, [[640, 400], [502, 400], [640, 240]])
})

test('each pixel of the thumbnail is the average of the pixels that it covers', () => {
	// Black and white columns, a grey row below them: two to one gives grey, then darker grey.
	const stripes = picture(4, 2, (x, y) => (y === 1 ? [100, 100, 100, 255] : x % 2 === 0 ? [0, 0, 0, 255] : [255, 255, 255, 255]))
	assert.deepEqual(pixels(thumbnail(stripes, { width: 2, height: 1 })), [
		[114, 114, 114, 255],
		[114, 114, 114, 255],
	])
	// Three pixels to two: the middle one counts half for each.
	const ramp = picture(3, 1, (x) => [[0, 0, 0, 255], [90, 90, 90, 255], [180, 180, 180, 255]][x])
	assert.deepEqual(pixels(thumbnail(ramp, { width: 2, height: 1 })), [
		[30, 30, 30, 255],
		[150, 150, 150, 255],
	])
})

test('a transparent pixel lends the thumbnail none of its color', () => {
	const half = picture(2, 1, (x) => (x === 0 ? [255, 0, 0, 255] : [0, 0, 255, 0]))
	assert.deepEqual(pixels(thumbnail(half, { width: 1, height: 1 })), [[255, 0, 0, 128]])
	const clear = picture(2, 1, () => [0, 0, 255, 0])
	assert.deepEqual(pixels(thumbnail(clear, { width: 1, height: 1 })), [[0, 0, 0, 0]])
})

test('a screenshot that already fits gets no thumbnail', () => {
	assert.throws(
		() => thumbnail(picture(640, 400, () => [0, 0, 0, 255])),
		/A screenshot of 640 × 400 pixels already fits into its thumbnail of 640 × 400\./,
	)
})

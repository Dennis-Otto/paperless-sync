// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The rules of ESLint for the JavaScript of this app, which scripts/eslint.sh runs from the
// root of the repository: the recommended rules of ESLint, and five more against the
// mistakes that a page without a build step makes quietly. The app has no build step
// (docs/decisions/0023-file-actions-in-files-without-a-build.md), so js/*.js and l10n/*.js
// run in the browser as they are written, as classic scripts beside the globals of
// Nextcloud; the tests and the scripts are modules of Node, whose callbacks of
// page.evaluate() run in the page as well. The config lives in scripts/eslint/, beside its
// packages, because krankerl would pack a file at the root of the repository into the
// package of the app. https://eslint.org/docs/latest/use/configure/

import { fileURLToPath } from 'node:url'
import js from '@eslint/js'
import { includeIgnoreFile } from 'eslint/config'
import globals from 'globals'

// What Nextcloud defines for the scripts of an app: OC and OCA, and t() and n(), which
// translate a text and the plural of one.
const NEXTCLOUD = {
	OC: 'readonly',
	OCA: 'readonly',
	t: 'readonly',
	n: 'readonly',
}

export default [
	// What Git ignores, ESLint skips too, such as the PHP tools in vendor/, the website and
	// its cache and the reports in build/; a folder that .gitignore gains needs no change here.
	// https://eslint.org/docs/latest/use/configure/ignore#including-gitignore-files
	includeIgnoreFile(fileURLToPath(new URL('../../.gitignore', import.meta.url)), 'What Git ignores'),
	{
		name: 'paperless-sync/ignores',
		// The packages of npm in any folder, such as those of this config, and the scripts in
		// .github/, which belong to the blueprint, which checks them.
		ignores: ['**/node_modules/', '.github/'],
	},
	js.configs.recommended,
	{
		name: 'paperless-sync/rules',
		linterOptions: {
			// A comment that turns off a rule that no longer fires goes.
			reportUnusedDisableDirectives: 'error',
		},
		rules: {
			// == and != convert their operands first, so that '0' == 0 and null == undefined.
			eqeqeq: ['error', 'always'],
			// A name that starts with _ is unused on purpose, and so is what a rest pattern
			// leaves out of an object.
			'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_', ignoreRestSiblings: true }],
			// A classic script that declares a function or a var at its top adds it to
			// window, where another script of the page may overwrite it.
			'no-implicit-globals': 'error',
			'prefer-const': 'error',
			'no-var': 'error',
		},
	},
	{
		name: 'paperless-sync/browser',
		files: ['js/**/*.js', 'l10n/**/*.js'],
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'script',
			globals: { ...globals.browser, ...NEXTCLOUD },
		},
	},
	{
		name: 'paperless-sync/node',
		files: ['**/*.mjs'],
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'module',
			globals: { ...globals.node, ...globals.browser },
		},
	},
]

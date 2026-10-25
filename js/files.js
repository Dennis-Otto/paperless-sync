/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The actions of Paperless Sync in the menu of a file or a folder in Files, and of a selection:
//
// - "Send to Paperless" queues the files, and the files below the folders, for the background
//   job of the app, for the members of the group that the settings allow; a notification tells
//   the sender how each import ended. The files stay where they are.
// - "Open in Paperless" opens the document of Paperless that the name of a file names with its
//   marker, such as [P123], which every copy of the archive keeps.
//
// Like settings.js, it needs no build: it registers each action in the registry of file actions
// of @nextcloud/files 4, which Files of Nextcloud 33 to 35 reads, as registerFileAction() of that
// library does. docs/decisions/0023-file-actions-in-files-without-a-build.md says why.
(function() {
	'use strict'

	function translate(text, parameters) {
		if (window.t) {
			return window.t('paperless_sync', text, parameters, undefined, { escape: false })
		}
		return text.replace(/{(\w+)}/g, function(placeholder, name) {
			return parameters && name in parameters ? String(parameters[name]) : placeholder
		})
	}

	// What the server put into the page, as @nextcloud/initial-state reads it: JSON in Base64, in
	// a hidden input. Whether the user may send files, and the address of Paperless.
	function initialState(key) {
		const input = document.getElementById('initial-state-paperless_sync-' + key)
		if (!input) {
			return null
		}
		try {
			const bytes = Uint8Array.from(window.atob(input.value), function(character) {
				return character.charCodeAt(0)
			})
			return JSON.parse(new TextDecoder().decode(bytes))
		} catch {
			return null
		}
	}

	const state = initialState('files') || {}
	const maySend = state.send === true
	const paperlessUrl = typeof state.paperlessUrl === 'string' ? state.paperlessUrl.replace(/\/+$/, '') : ''
	// The permission of a node of @nextcloud/files to read it.
	const READ = 1
	// The icons, drawn for these actions, in the color of the text.
	const SEND_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11 16V7.8l-3.6 3.6L6 10l6-6 6 6-1.4 1.4L13 7.8V16h-2zm-6 2h14v2H5v-2z"/></svg>'
	const OPEN_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M14 3h7v7h-2V6.4l-8.3 8.3-1.4-1.4L17.6 5H14V3zM5 5h6v2H5v12h12v-6h2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>'

	// The ID of the document of Paperless that the name names with its marker, such as 123 for
	// "2026-03-01 - Invoice [P123].pdf", the last one when it has several, or null.
	function documentId(name) {
		const markers = [...String(name || '').matchAll(/\[P([1-9][0-9]{0,17})\]/g)]
		return markers.length === 0 ? null : markers[markers.length - 1][1]
	}

	// Whether the share that the node reaches the user through allows downloads, as the Download
	// action of Files tests it: a share whose owner allows viewing only, which a copy in Paperless
	// would get around, doesn't.
	function downloadable(node) {
		let attributes = node.attributes ? node.attributes['share-attributes'] : null
		if (typeof attributes === 'string') {
			try {
				attributes = JSON.parse(attributes)
			} catch {
				attributes = null
			}
		}
		return !Array.isArray(attributes) || !attributes.some(function(attribute) {
			return attribute !== null && typeof attribute === 'object' && attribute.scope === 'permissions' && attribute.key === 'download' && attribute.value === false
		})
	}

	function readable(node) {
		return typeof node.fileid === 'number' && (node.permissions & READ) !== 0 && downloadable(node)
	}

	function toast(kind, text) {
		const toasts = window.OCP && window.OCP.Toast
		if (toasts && typeof toasts[kind] === 'function') {
			toasts[kind](text)
		}
	}

	// The message of an answer of the app, or null when it has none, as from a proxy in between.
	async function answerMessage(response) {
		try {
			const data = await response.json()
			return data !== null && typeof data === 'object' && typeof data.message === 'string' && data.message !== '' ? data.message : null
		} catch {
			return null
		}
	}

	// Queues the nodes and says how it went; the app answers in the language of the user.
	async function send(nodes) {
		let response
		try {
			response = await window.fetch(window.OC.generateUrl('/apps/paperless_sync/api/v1/send'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: window.OC.requestToken,
				},
				body: JSON.stringify({ fileIds: nodes.map(function(node) {
					return node.fileid
				}) }),
			})
		} catch {
			toast('error', translate('Nextcloud could not be reached. Check the connection and try again.'))
			return
		}
		const message = await answerMessage(response)
		if (response.ok && message !== null) {
			toast('success', message)
		} else if (response.status === 401 || response.status === 412) {
			toast('error', translate('Your session has expired. Reload the page and try again.'))
		} else if (response.status === 429) {
			toast('error', translate('Too many files were sent in a short time. Wait a minute and try again.'))
		} else {
			toast('error', message !== null ? message : translate('Paperless Sync could not send the files (HTTP {status}).', { status: response.status }))
		}
	}

	const actions = []
	if (maySend) {
		actions.push({
			id: 'paperless_sync-send',
			displayName: function() {
				return translate('Send to Paperless')
			},
			iconSvgInline: function() {
				return SEND_ICON
			},
			order: 60,
			// Files and folders that the user may read, outside the trash bin, which Paperless doesn't
			// have yet by their names.
			enabled: function(context) {
				return context.view.id !== 'trashbin' && context.nodes.length > 0 && context.nodes.every(function(node) {
					return readable(node) && documentId(node.basename) === null
				})
			},
			exec: async function(context) {
				await send(context.nodes)
				return null
			},
			execBatch: async function(context) {
				await send(context.nodes)
				return context.nodes.map(function() {
					return null
				})
			},
		})
	}
	if (paperlessUrl !== '') {
		actions.push({
			id: 'paperless_sync-open',
			displayName: function() {
				return translate('Open in Paperless')
			},
			iconSvgInline: function() {
				return OPEN_ICON
			},
			order: 61,
			enabled: function(context) {
				return context.view.id !== 'trashbin' && context.nodes.length === 1 && context.nodes[0].type === 'file' && documentId(context.nodes[0].basename) !== null
			},
			exec: async function(context) {
				window.open(paperlessUrl + '/documents/' + documentId(context.nodes[0].basename) + '/details', '_blank', 'noopener,noreferrer')
				return null
			},
		})
	}

	// The registry of @nextcloud/files 4, which Files creates when this script was first, and the
	// event by which Files learns of an action that comes after it started.
	window._nc_files_scope = window._nc_files_scope || {}
	const scope = window._nc_files_scope.v4_0 = window._nc_files_scope.v4_0 || {}
	scope.fileActions = scope.fileActions || new Map()
	actions.forEach(function(action) {
		if (scope.fileActions.has(action.id)) {
			return
		}
		scope.fileActions.set(action.id, action)
		if (scope.registry && typeof scope.registry.dispatchEvent === 'function') {
			scope.registry.dispatchEvent(new CustomEvent('register:action', { detail: action }))
		}
	})
})()

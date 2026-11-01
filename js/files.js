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
// And a note above the list of a folder of the app, in the archive and below it, the inbox and the
// failed imports, which says what that folder does with its files, as people who reach it through a
// share never read the documentation. Each user can hide each kind of it.
//
// Like settings.js, it needs no build: it registers each action in the registry of file actions
// of @nextcloud/files 4, which Files of Nextcloud 33 to 35 reads, as registerFileAction() of that
// library does, and the note as registerFileListHeader() does.
// docs/decisions/0023-file-actions-in-files-without-a-build.md and
// docs/decisions/0033-a-note-in-files-says-what-a-folder-of-the-app-does.md say why.
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
	const INFO_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm-1-9h2v6h-2v-6zm0-4h2v2h-2V7z"/></svg>'
	const CLOSE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.4 5 12 10.6 17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4z"/></svg>'

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

	// How often the note asks the app again: a page of Files stays open for hours, and the times of
	// the runs and the folders of the user change meanwhile.
	const FOLDER_INFO_MAX_AGE = 5 * 60 * 1000
	// What the app answers: the folders of the app that the user sees, the times of the runs and the
	// kinds of the note that the user hid. Null until it has answered.
	let folderInfo = null
	let folderInfoTime = 0
	// The element of Files that holds the note, and the folder that Files shows.
	let noteMount = null
	let noteFolder = null

	// The language of Nextcloud for the times of the note: they stand in sentences of that language,
	// so it comes before the locale, unlike the dates of settings.js.
	function nextcloudLanguage() {
		const html = document.documentElement
		for (const name of [html.lang, html.dataset.locale]) {
			const tag = (name || '').replace(/@.*$/, '').replace(/_/g, '-')
			try {
				if (tag !== '' && Intl.DateTimeFormat.supportedLocalesOf([tag]).length > 0) {
					return tag
				}
			} catch {
				// Not a language tag, such as a name of Nextcloud that the browser can't read.
			}
		}
		return undefined
	}

	// A span of time in the largest unit that reads well, at least a minute.
	function timeSpan(seconds) {
		const minutes = Math.max(1, Math.round(Math.abs(seconds) / 60))
		if (minutes < 120) {
			return { value: minutes, unit: 'minute' }
		}
		const hours = Math.round(minutes / 60)
		return hours < 48 ? { value: hours, unit: 'hour' } : { value: Math.round(hours / 24), unit: 'day' }
	}

	// From the moment of the answer, so that the note changes only with a new answer, at most every
	// FOLDER_INFO_MAX_AGE, and the same answer always draws the same note.
	function secondsSince(moment) {
		return folderInfoTime / 1000 - moment
	}

	// "5 minutes ago", in the words of the browser for the language.
	function timeAgo(moment) {
		const { value, unit } = timeSpan(secondsSince(moment))
		return new Intl.RelativeTimeFormat(nextcloudLanguage()).format(-value, unit)
	}

	// "5 minutes".
	function timeUntil(moment) {
		const { value, unit } = timeSpan(secondsSince(moment))
		return new Intl.NumberFormat(nextcloudLanguage(), { style: 'unit', unit, unitDisplay: 'long' }).format(value)
	}

	function element(tag, className, text) {
		const node = document.createElement(tag)
		node.className = className
		if (text !== undefined) {
			node.textContent = text
		}
		return node
	}

	function folderAddress(folder) {
		return window.OC.generateUrl('/apps/files/files/{id}?dir={dir}', { id: folder.id, dir: '/' + folder.path })
	}

	// What the note says in a folder of a kind: its first line, which starts in bold, the line below
	// it, and the link at the end of that one, if it has one.
	function noteContent(kind) {
		if (kind === 'archive') {
			return {
				lead: translate('Mirror of Paperless'),
				detail: folderInfo.lastSuccess > 0 ? translate('Updated {time}', { time: timeAgo(folderInfo.lastSuccess) }) : translate('Not updated yet'),
				text: translate('Changes here don\'t reach Paperless, and the next runs may undo them. Edit the document in Paperless.'),
				link: { text: translate('Open Paperless'), address: paperlessUrl, external: true },
			}
		}
		if (kind === 'inbox') {
			const errors = folderInfo.folders.find(function(folder) {
				return folder.kind === 'errors'
			})
			let lead = translate('Files here go to Paperless at the next run. Scheduled synchronization is off, so an administrator starts it.')
			if (folderInfo.nextRun !== null) {
				lead = secondsSince(folderInfo.nextRun) >= 0
					? translate('Files here go to Paperless at the next run, with the next background job.')
					: translate('Files here go to Paperless at the next run, in about {time}.', { time: timeUntil(folderInfo.nextRun) })
			}
			return {
				lead,
				text: translate('Videos, programs and archives stay here.'),
				// The name that the folder has for this user, which the settings choose.
				link: errors ? { text: errors.path.split('/').pop(), address: folderAddress(errors), external: false } : null,
			}
		}
		return {
			lead: translate('These files did not reach Paperless.'),
			text: translate('The reason of each failure is in the .error.txt next to the file.'),
		}
	}

	function noteElement(kind) {
		const content = noteContent(kind)
		const note = element('div', 'paperless-sync-folder-note')
		note.setAttribute('role', 'note')
		note.setAttribute('aria-label', translate('About this folder'))
		note.dataset.kind = kind
		const icon = element('span', 'paperless-sync-folder-note__icon')
		icon.innerHTML = INFO_ICON
		const text = element('div', 'paperless-sync-folder-note__text')
		const first = document.createElement('p')
		first.append(element('strong', '', content.lead))
		if (content.detail) {
			first.append(' · ' + content.detail)
		}
		const second = element('p', '', content.text)
		if (content.link) {
			const link = element('a', '', content.link.text)
			link.href = content.link.address
			if (content.link.external) {
				link.target = '_blank'
				link.rel = 'noopener noreferrer'
			}
			second.append(' ', link)
		}
		text.append(first, second)
		const dismiss = element('button', 'paperless-sync-folder-note__dismiss')
		dismiss.type = 'button'
		dismiss.title = translate('Hide this note')
		dismiss.setAttribute('aria-label', dismiss.title)
		dismiss.innerHTML = CLOSE_ICON
		dismiss.addEventListener('click', function() {
			setDismissed(kind, true)
		})
		note.append(icon, text, dismiss)
		return note
	}

	// What is left of a note that the user hid.
	function questionElement(kind) {
		const question = element('div', 'paperless-sync-folder-question')
		question.dataset.kind = kind
		const button = element('button', 'paperless-sync-folder-question__button', translate('What is this folder?'))
		button.type = 'button'
		button.addEventListener('click', function() {
			setDismissed(kind, false)
		})
		question.append(button)
		return question
	}

	// The folder of the app that the folder of Files is, or is below, the innermost when one holds
	// another: only among the files of the user, not in the trash bin, whose paths are others.
	function folderOfTheApp(folder) {
		if (folderInfo === null || !String(folder.root).startsWith('/files/')) {
			return null
		}
		const path = String(folder.path).replace(/^\/+|\/+$/g, '')
		let found = null
		for (const candidate of folderInfo.folders) {
			const here = candidate.id === folder.fileid || path === candidate.path || path.startsWith(candidate.path + '/')
			if (here && (found === null || candidate.path.length > found.path.length)) {
				found = candidate
			}
		}
		return found
	}

	function drawNote() {
		const folder = folderOfTheApp(noteFolder)
		if (folder === null) {
			noteMount.replaceChildren()
		} else {
			noteMount.replaceChildren(folderInfo.dismissed.includes(folder.kind) ? questionElement(folder.kind) : noteElement(folder.kind))
		}
	}

	// Hides a kind of the note or brings it back at once, with the focus on the button that takes
	// the place of the one that the click removed, and tells the app. When that fails, it stays so
	// on this page, and the message says that it may not on the next.
	async function setDismissed(kind, dismissed) {
		folderInfo.dismissed = folderInfo.dismissed.filter(function(other) {
			return other !== kind
		}).concat(dismissed ? [kind] : [])
		drawNote()
		noteMount.querySelector('button').focus()
		let saved = false
		try {
			const response = await window.fetch(window.OC.generateUrl('/apps/paperless_sync/api/v1/folder-info/dismissed'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: window.OC.requestToken,
				},
				body: JSON.stringify({ kind, dismissed }),
			})
			saved = response.ok
		} catch {
			// The message below says it.
		}
		if (!saved) {
			toast('error', translate('Your choice could not be saved, so the note may come back.'))
		}
	}

	// Asks the app. A note is a hint: without an answer there is none, and Files goes on.
	async function loadFolderInfo() {
		folderInfoTime = Date.now()
		try {
			const response = await window.fetch(window.OC.generateUrl('/apps/paperless_sync/api/v1/folder-info'), {
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					requesttoken: window.OC.requestToken,
				},
			})
			if (response.ok) {
				folderInfo = await response.json()
			}
		} catch {
			// The next folder asks again, after FOLDER_INFO_MAX_AGE.
		}
	}

	async function showNote(folder) {
		noteFolder = folder
		if (Date.now() - folderInfoTime > FOLDER_INFO_MAX_AGE) {
			await loadFolderInfo()
		}
		drawNote()
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

	// The note, for an app that is connected to Paperless, in the registry of the headers of the list
	// of files. Files calls render() as it opens its list and updated() with every other folder, each
	// after the one before it has ended.
	scope.fileListHeaders = scope.fileListHeaders || new Map()
	const header = {
		id: 'paperless_sync-folder-note',
		order: 10,
		render: async function(mount, folder) {
			noteMount = mount
			await showNote(folder)
		},
		updated: showNote,
	}
	if (paperlessUrl !== '' && !scope.fileListHeaders.has(header.id)) {
		scope.fileListHeaders.set(header.id, header)
		if (scope.registry && typeof scope.registry.dispatchEvent === 'function') {
			scope.registry.dispatchEvent(new CustomEvent('register:listHeader', { detail: header }))
		}
	}
})()

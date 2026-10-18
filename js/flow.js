/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// "Send to Paperless" in Flow (Administration → Flow): the operation of the app among those of
// Flow, and the form of a rule, the tags of Paperless that its files get besides the tags for
// every import of the inbox. Flow keeps them as the operation of the rule, {"tags": ["Scans",
// "Family"]}, and OCA\PaperlessSync\Operation\SendToPaperless checks them when the rule is saved.
//
// Like files.js, it needs no build: Flow of Nextcloud 33 to 35 shows the form of an operation
// that names a custom element, gives it the operation of the rule as its attribute model-value
// and takes a changed one from its event update:model-value, whose detail holds it first.
// docs/decisions/0023-file-actions-in-files-without-a-build.md says why the app has no build.
(function() {
	'use strict'

	const OPERATION = 'OCA\\PaperlessSync\\Operation\\SendToPaperless'
	const ELEMENT = 'oca-paperless_sync-send-to-paperless'
	// The tags of Paperless at most that the field suggests for the name being typed.
	const MAX_SUGGESTIONS = 50

	function translate(text, parameters) {
		if (window.t) {
			return window.t('paperless_sync', text, parameters, undefined, { escape: false })
		}
		return text.replace(/{(\w+)}/g, function(placeholder, name) {
			return parameters && name in parameters ? String(parameters[name]) : placeholder
		})
	}

	// The names of the tags of the operation of a rule, or none for '' and for every other shape.
	function tagsOf(operation) {
		try {
			const value = JSON.parse(operation)
			return value !== null && typeof value === 'object' && Array.isArray(value.tags)
				? value.tags.filter(function(tag) {
					return typeof tag === 'string'
				})
				: []
		} catch (error) {
			return []
		}
	}

	// The names of a text that separates them with commas, each once whatever its case, as a run
	// compares them.
	function splitTags(text) {
		const seen = new Set()
		return String(text).split(/[,\r\n]+/).map(function(name) {
			return name.trim()
		}).filter(function(name) {
			const key = name.toLowerCase()
			if (name === '' || seen.has(key)) {
				return false
			}
			seen.add(key)
			return true
		})
	}

	// The names of the tags of Paperless, which the settings of the app read with its token, once
	// for every form of the page. An administrator may ask; a failure says why.
	let paperlessTags = null
	function loadTags() {
		if (paperlessTags === null) {
			const url = window.OC.generateUrl('/apps/paperless_sync/settings/paperless/tags')
			paperlessTags = window.fetch(url, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json', requesttoken: window.OC.requestToken },
			}).then(async function(response) {
				const answer = await response.json().catch(function() {
					return {}
				})
				if (!response.ok) {
					throw new Error(typeof answer.message === 'string' ? answer.message : translate('Paperless Sync could not list the tags of Paperless.'))
				}
				return Array.isArray(answer.tags) ? answer.tags.filter(function(tag) {
					return typeof tag === 'string'
				}) : []
			})
		}
		return paperlessTags
	}

	let forms = 0

	// The form of a rule: a field for the names of its tags, separated by commas, which suggests
	// the tags of Paperless for the name being typed, and what the tags do.
	class SendToPaperlessForm extends HTMLElement {
		static get observedAttributes() {
			return ['model-value']
		}

		constructor() {
			super()
			// The operation that the form last gave Flow, which Flow gives back as its attribute.
			this.operation = null
			this.tags = []
			this.known = []
		}

		connectedCallback() {
			if (this.input) {
				return
			}
			const id = ELEMENT + '-' + (++forms)
			const label = document.createElement('label')
			label.htmlFor = id
			label.textContent = translate('Tags in Paperless')
			label.style.display = 'block'
			this.input = document.createElement('input')
			this.input.type = 'text'
			this.input.id = id
			this.input.setAttribute('list', id + '-tags')
			this.input.setAttribute('aria-describedby', id + '-hint ' + id + '-status')
			this.input.placeholder = translate('Scans, Family')
			this.input.autocomplete = 'off'
			this.input.style.width = '100%'
			this.suggestions = document.createElement('datalist')
			this.suggestions.id = id + '-tags'
			const hint = document.createElement('p')
			hint.id = id + '-hint'
			hint.textContent = translate('Separate the tags with commas. The files get the tags for every import of the inbox as well. A tag that Paperless lacks is created with "Create missing tags" in the settings of Paperless Sync, and else left out.')
			this.status = document.createElement('p')
			this.status.id = id + '-status'
			this.status.setAttribute('aria-live', 'polite')
			for (const text of [hint, this.status]) {
				text.style.color = 'var(--color-text-maxcontrast)'
			}
			this.replaceChildren(label, this.input, this.suggestions, hint, this.status)
			this.input.addEventListener('input', () => {
				this.suggest()
				this.emit()
			})
			this.show()
			loadTags().then((tags) => {
				this.known = tags
				this.status.textContent = tags.length === 0 ? translate('Paperless has no tags yet.') : ''
				this.suggest()
			}, (error) => {
				this.status.textContent = error.message
			})
		}

		attributeChangedCallback(name, previous, value) {
			this.modelValue = value
		}

		get modelValue() {
			return JSON.stringify({ tags: this.tags })
		}

		// Flow sets the operation of the rule: the one that the form gave it changes nothing, so
		// that the field keeps what is being typed; another one, as after "Cancel", shows its tags.
		set modelValue(value) {
			const operation = typeof value === 'string' ? value : ''
			if (operation === this.operation) {
				return
			}
			this.operation = operation
			this.tags = tagsOf(operation)
			this.show()
		}

		show() {
			if (this.input) {
				this.input.value = this.tags.join(', ')
				this.suggest()
			}
		}

		emit() {
			this.tags = splitTags(this.input.value)
			this.operation = this.modelValue
			this.dispatchEvent(new CustomEvent('update:model-value', { detail: [this.operation] }))
		}

		// The tags of Paperless that complete the name being typed, after the names before it,
		// which the browser offers as the whole text of the field.
		suggest() {
			const text = this.input.value
			const comma = text.lastIndexOf(',')
			const typed = text.slice(comma + 1)
			const before = text.slice(0, comma + 1 + typed.length - typed.trimStart().length)
			const chosen = new Set(splitTags(text.slice(0, comma + 1)).map(function(name) {
				return name.toLowerCase()
			}))
			const prefix = typed.trim().toLowerCase()
			const options = this.known.filter(function(tag) {
				return !chosen.has(tag.toLowerCase()) && tag.toLowerCase().includes(prefix)
			}).slice(0, MAX_SUGGESTIONS).map(function(tag) {
				const option = document.createElement('option')
				option.value = before + tag
				return option
			})
			this.suggestions.replaceChildren(...options)
		}
	}

	if (!window.customElements.get(ELEMENT)) {
		window.customElements.define(ELEMENT, SendToPaperlessForm)
	}

	function register() {
		window.OCA.WorkflowEngine.registerOperator({
			id: OPERATION,
			operation: '',
			color: '#1f8f72',
			element: ELEMENT,
		})
	}

	if (document.readyState === 'loading') {
		window.addEventListener('DOMContentLoaded', register)
	} else {
		register()
	}
})()

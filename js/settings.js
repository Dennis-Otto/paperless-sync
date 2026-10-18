/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

(function() {
	'use strict'

	function translate(text, parameters) {
		// The text goes into textContent, so Nextcloud need not escape the parameters for HTML.
		if (window.t) {
			return window.t('paperless_sync', text, parameters, undefined, { escape: false })
		}
		return text.replace(/{(\w+)}/g, function(placeholder, name) {
			return parameters && name in parameters ? String(parameters[name]) : placeholder
		})
	}

	function translatePlural(singular, plural, count, parameters) {
		if (window.n) {
			return window.n('paperless_sync', singular, plural, count, parameters, { escape: false })
		}
		return translate(count === 1 ? singular : plural, parameters).replace('%n', String(count))
	}

	// The locale of Nextcloud for dates and times, which the administrator chose in the personal
	// settings and the page names on its html element, such as de_DE, or else its language: that of
	// the browser may be another one. A name that the browser doesn't know leaves it to the browser.
	function nextcloudLocale() {
		const html = document.documentElement
		for (const name of [html.dataset.locale, html.lang]) {
			const tag = (name || '').replace(/@.*$/, '').replace(/_/g, '-')
			try {
				if (tag !== '' && Intl.DateTimeFormat.supportedLocalesOf([tag]).length > 0) {
					return tag
				}
			} catch (error) {
				// Not a language tag, such as a name of Nextcloud that the browser can't read.
			}
		}
		return undefined
	}

	// What the server put into the page, as @nextcloud/initial-state reads it: JSON in Base64, in
	// a hidden input. The status of the last run, and the stored configuration without the token.
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
		} catch (error) {
			return null
		}
	}

	// The answer of Nextcloud to a request to a route with #[PasswordConfirmationRequired] while the
	// password of the administrator wasn't confirmed in the last 30 minutes.
	const PASSWORD_CONFIRMATION_REQUIRED = 'Password confirmation is required'

	// Nextcloud's own dialog asks for the password and confirms it with the server.
	function confirmPassword() {
		return new Promise(function(resolve, reject) {
			const confirmation = window.OC.PasswordConfirmation
			const notConfirmed = function() {
				reject(new Error(translate('Your password was not confirmed, so nothing was changed.')))
			}
			if (!confirmation) {
				notConfirmed()
				return
			}
			confirmation.requirePasswordConfirmation(resolve, {}, notConfirmed)
		})
	}

	// An error with the text for the page, the HTTP status of the answer, 0 without one, the
	// setting that the text names as the way out, if any, and the lines of the connection
	// checklist that the answer had, if any.
	function requestError(text, status, setting, checks) {
		const error = new Error(text)
		error.status = status
		error.setting = setting
		error.checks = checks
		return error
	}

	// The data of an answer, or null when it has none. The app answers with JSON; a proxy, the web
	// server or a login page in between answer with HTML, which the page never shows.
	async function answerData(response) {
		if (!(response.headers.get('Content-Type') || '').includes('application/json')) {
			return null
		}
		try {
			const data = await response.json()
			return data !== null && typeof data === 'object' ? data : null
		} catch (error) {
			return null
		}
	}

	// A request to Nextcloud with the request token of the page. options.headers adds headers, such
	// as the one that the OCS API of Nextcloud asks for; options.confirmed tells the request again
	// after the password was confirmed.
	async function request(url, method, body, options = {}) {
		let response
		try {
			response = await window.fetch(url, {
				method,
				credentials: 'same-origin',
				headers: Object.assign({
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: window.OC.requestToken,
				}, options.headers),
				body: body === undefined ? undefined : JSON.stringify(body),
			})
		} catch (error) {
			// fetch() fails only when no answer arrives: the network or Nextcloud is down.
			throw requestError(translate('Nextcloud could not be reached. Check the connection and try again.'), 0)
		}
		const data = await answerData(response)
		if (response.status === 403 && data !== null && data.message === PASSWORD_CONFIRMATION_REQUIRED && !options.confirmed) {
			await confirmPassword()
			return request(url, method, body, Object.assign({}, options, { confirmed: true }))
		}
		if (response.ok && data !== null) {
			return data
		}
		// Nextcloud's answers when the session ended, "Current user is not logged in", and when the
		// request token of the page is no longer valid, "CSRF check failed".
		if (response.status === 401 || response.status === 412) {
			throw requestError(translate('Your session has expired. Reload the page and try again.'), response.status)
		}
		if (data !== null && typeof data.message === 'string' && data.message !== '') {
			throw requestError(data.message, response.status, data.setting, data.checks)
		}
		throw requestError(translate('The server answered with status {status}.', { status: response.status }), response.status)
	}

	// A text field that offers the options of a list below it while the administrator types, as the
	// combobox of WAI-ARIA 1.2 with a list does. The focus stays in the field, and the option that
	// the arrow keys reach is its active descendant: Enter or a click picks it, Escape closes the
	// list. A click into the field or the down arrow opens the list as well. search(text) answers
	// the options for the text of the field, each with its value, its label and a note, which may
	// be empty; pick(option) takes the one that was picked.
	function combobox(input, listbox, search, pick) {
		let options = []
		let active = -1
		// Each search counts up, so that the answer of an earlier one, which may arrive later,
		// changes nothing; closing the list counts up as well.
		let generation = 0
		let timer = 0

		function isOpen() {
			return !listbox.hidden
		}

		function activate(index) {
			active = index
			Array.from(listbox.children).forEach(function(item, position) {
				item.setAttribute('aria-selected', String(position === index))
			})
			if (index < 0) {
				input.removeAttribute('aria-activedescendant')
				return
			}
			const item = listbox.children[index]
			input.setAttribute('aria-activedescendant', item.id)
			item.scrollIntoView({ block: 'nearest' })
		}

		function close() {
			window.clearTimeout(timer)
			generation++
			listbox.hidden = true
			input.setAttribute('aria-expanded', 'false')
			activate(-1)
		}

		function show(found, first) {
			options = found
			if (found.length === 0) {
				close()
				return
			}
			listbox.replaceChildren(...found.map(function(option, index) {
				const item = document.createElement('li')
				item.id = listbox.id + '-' + index
				item.className = 'paperless-sync-option'
				item.setAttribute('role', 'option')
				const label = document.createElement('span')
				label.textContent = option.label
				item.append(label)
				if (option.note) {
					const note = document.createElement('span')
					note.className = 'paperless-sync-option-note'
					note.textContent = option.note
					item.append(' ', note)
				}
				return item
			}))
			listbox.hidden = false
			input.setAttribute('aria-expanded', 'true')
			activate(first ? 0 : -1)
		}

		// Searches after a short pause of the typing, and shows the options while the field has the
		// focus; first activates the first option, as the down arrow does when it opens the list.
		function update(delay, first) {
			window.clearTimeout(timer)
			const current = ++generation
			timer = window.setTimeout(async function() {
				const found = await search(input.value.trim())
				if (current === generation && document.activeElement === input) {
					show(found, first)
				}
			}, delay)
		}

		function choose(index) {
			const option = options[index]
			close()
			pick(option)
		}

		input.addEventListener('keydown', function(event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				if (!isOpen()) {
					update(0, event.key === 'ArrowDown')
					return
				}
				const count = options.length
				activate(event.key === 'ArrowDown' ? (active + 1) % count : (active <= 0 ? count : active) - 1)
			} else if (event.key === 'Enter' && isOpen() && active >= 0) {
				// Picks the option instead of submitting the form.
				event.preventDefault()
				choose(active)
			} else if (event.key === 'Escape' && isOpen()) {
				event.preventDefault()
				event.stopPropagation()
				close()
			}
		})
		input.addEventListener('input', function() {
			update(200, false)
		})
		input.addEventListener('click', function() {
			if (!isOpen()) {
				update(0, false)
			}
		})
		input.addEventListener('blur', close)
		// A press on the list keeps the focus in the field, so that the field doesn't lose it before
		// the click picks the option.
		listbox.addEventListener('mousedown', function(event) {
			event.preventDefault()
		})
		listbox.addEventListener('click', function(event) {
			const item = event.target.closest('[role="option"]')
			if (item !== null) {
				choose(Array.prototype.indexOf.call(listbox.children, item))
			}
		})
		return { close }
	}

	document.addEventListener('DOMContentLoaded', function() {
		const root = document.getElementById('paperless-sync-settings')
		if (!root) {
			return
		}
		// The elements of the page, only inside its root.
		function element(name) {
			return root.querySelector('#paperless-sync-' + name)
		}
		const form = element('form')
		const paperlessUrl = element('paperless-url')
		const token = element('token')
		const schedule = root.querySelector('[data-setting="enabled"]')
		const save = element('save')
		const test = element('test')
		const checks = element('checks')
		const checksList = element('checks-list')
		const dryRun = element('dry-run')
		const run = element('run')
		const reset = element('reset')
		const refresh = element('refresh-status')
		const unsaved = element('unsaved')
		const message = element('message')
		const report = element('report')
		const reportTitle = element('report-title')
		const reportCopy = element('report-copy')
		const reportSummary = element('report-summary')
		const reportRest = element('report-rest')
		const reportFilters = element('report-filters')
		const reportEntries = element('report-entries')
		const reportMeta = element('report-meta')
		const reportOmitted = element('report-omitted')
		const lastState = element('last-state')
		const running = element('running')
		const lastEnded = element('last-ended')
		const lastSuccess = element('last-success')
		const nextRun = element('next-run')
		const paperlessVersion = element('paperless-version')
		const errors = element('errors')
		const errorsTitle = element('errors-title')
		const errorsList = element('errors-list')
		const pathTemplate = element('path-template')
		const preview = element('path-preview')
		const previewStatus = element('path-preview-status')
		const previewList = element('path-preview-list')
		const targetUser = element('target-user')
		const targetUserHint = element('target-user-hint')
		const webhookCreate = element('webhook-create')
		const webhookRemove = element('webhook-remove')
		const webhookState = element('webhook-state')
		const webhookLastCall = element('webhook-last-call')
		const webhookSecretField = element('webhook-secret-field')
		const webhookSecret = element('webhook-secret')
		const buttons = [save, test, dryRun, run, reset, refresh, webhookCreate, webhookRemove]
		// The settings that the connection checklist depends on: a change of one outdates its lines.
		const checkedSettings = ['paperless_url', 'paperless_local_network', 'target_user', 'base_path', 'export_enabled', 'inbox_enabled', 'inbox_folder_tags', 'inbox_tags', 'inbox_create_tags']
		// While a run is queued or active, the page asks for its status: after a change of the state
		// three times every second, so that a run that a worker of Nextcloud starts at once shows at
		// once, then every five seconds, and after five minutes in the same state every thirty
		// seconds, as long as the run takes.
		const quickPolls = 3
		const quickPollInterval = 1000
		const pollInterval = 5000
		const slowPollInterval = 30000
		const pollLimit = 60
		let pollTimer = 0
		let polls = 0
		let watchedState = ''
		// "Synchronize now" queued a run in a background job; the page says how it ended, once the
		// status shows its end.
		let awaitedRun = false
		let busy = false
		// The settings that the server stored, as settings() reads them from the form, to tell
		// unsaved changes.
		let savedSettings = ''
		// Whether the server holds a token, and the URL that it belongs to. The server sends the
		// stored token only there, so another URL needs the token again.
		let tokenConfigured = false
		let savedUrl = ''
		// The saved target user: the report opens the folder of a file in the Files app when the
		// administrator who reads it is that user, the owner of the archive.
		let savedTargetUser = ''
		// The report on the page, which "Copy report" copies, the kind of changes that its list
		// shows, or '' for all, and what it was drawn from: the status of an active run, which the
		// page asks for again and again, draws the same report only once, so that a filter keeps
		// the keyboard focus.
		let shownReport = null
		let reportFilter = ''
		let drawnReport = ''
		// The state of the last run as the server last sent it. A run shows that it is running
		// before the server answers; when the status can't be refreshed after it, the status
		// shows this state again, not a run that never ends.
		let serverState = lastState.dataset.state || 'never-run'
		// The settings that decide the path of a document, or whether a run skips it, which the
		// preview of the path template sends, as SettingsController::preview() takes them. A change
		// of one of them shows the paths anew, once the typing pauses for previewDelay.
		const previewSettings = ['path_template', 'prefer_archive', 'skip_inbox', 'excluded_tags', 'empty_correspondent', 'empty_document_type', 'empty_date', 'untitled']
		const previewDelay = 400
		let previewTimer = 0
		// The number of the last request of the preview: an answer to an earlier one, which may
		// arrive after it, is not shown.
		let previewRequest = 0
		// Where the cursor was in the path template when it lost the focus, such as to the button
		// of a variable; null until it had the focus, and a variable then goes to its end.
		let templateSelection = null

		// The settings that the form shows, or a copy of it, as the page sends them.
		function settings(scope = root) {
			const values = {}
			scope.querySelectorAll('[data-setting]').forEach(function(input) {
				if (input.type === 'checkbox') {
					values[input.dataset.setting] = input.checked
				} else if (input.type === 'number') {
					values[input.dataset.setting] = Number.parseInt(input.value, 10)
				} else {
					values[input.dataset.setting] = input.value
				}
			})
			return values
		}

		// The name of a setting in the configuration of the server: paperless_url is paperlessUrl.
		function property(setting) {
			return setting.replace(/_([a-z])/g, function(underscore, letter) {
				return letter.toUpperCase()
			})
		}

		// The URL as the server compares it with the stored one.
		function normalizeUrl(url) {
			return url.trim().replace(/\/+$/, '')
		}

		// Shows the configuration in the inputs of the form, or of a copy of it.
		function fill(scope, config) {
			scope.querySelectorAll('[data-setting]').forEach(function(input) {
				const value = config[property(input.dataset.setting)]
				if (value === undefined) {
					return
				}
				if (input.type === 'checkbox') {
					input.checked = value === true
				} else {
					input.value = String(value)
				}
			})
		}

		// What the form holds when a save or a disconnect starts, to tell the changes that the
		// administrator makes while the server answers.
		function snapshot() {
			return { settings: settings(), token: token.value }
		}

		// Shows the configuration that the server stored, normalized, as on load and in its answer
		// to a save or a disconnect, and takes it as the saved state of the form. A change made
		// while the server answered, since the snapshot that the request started with, stays in
		// the form as an unsaved change: a copy of the form shows the configuration instead.
		function applyConfig(config, sent) {
			const changed = sent !== undefined && JSON.stringify(settings()) !== JSON.stringify(sent.settings)
			const tokenChanged = sent !== undefined && token.value !== sent.token
			if (changed) {
				const copy = form.cloneNode(true)
				fill(copy, config)
				savedSettings = JSON.stringify(settings(copy))
			} else {
				fill(root, config)
				savedSettings = JSON.stringify(settings())
			}
			if (!tokenChanged) {
				token.value = ''
			}
			tokenConfigured = config.tokenConfigured === true
			savedUrl = normalizeUrl(String(config.paperlessUrl || ''))
			savedTargetUser = String(config.targetUser || '')
			// The tags of Paperless come with the saved connection, which may be another one now.
			if (tokenConfigured) {
				reloadTags()
			} else {
				forgetTags()
			}
			tagPickers.forEach(function(picker) {
				picker.show()
			})
			checkTargetUser()
			updateForm()
		}

		// A name of a tag that is typed but not yet added counts as a change too.
		function isDirty() {
			return token.value !== '' || tagPickers.some(function(picker) {
				return picker.typed()
			}) || JSON.stringify(settings()) !== savedSettings
		}

		function updateToken() {
			const newUrl = tokenConfigured && normalizeUrl(paperlessUrl.value) !== savedUrl
			token.required = !tokenConfigured || newUrl
			if (!tokenConfigured) {
				token.placeholder = translate('Required')
			} else if (newUrl) {
				token.placeholder = translate('Required again for a new URL')
			} else {
				token.placeholder = translate('Configured — leave blank to keep it')
			}
		}

		// A dry-run uses the settings of the form, so that it shows what the changes do before
		// they are saved; "Synchronize now" uses the saved settings and waits for the save, so
		// that it never applies settings that the page doesn't show.
		function updateButtons() {
			const dirty = isDirty()
			unsaved.hidden = !dirty
			buttons.forEach(function(button) {
				button.disabled = busy || (button === run && dirty)
			})
		}

		// Saving tests the connection when the schedule will run or a token was entered, as
		// SettingsController::save() decides, and its button says whether it will.
		function willTest() {
			return schedule.checked || token.value.trim() !== ''
		}

		function updateForm() {
			updateToken()
			updateButtons()
			setText(save, willTest() ? translate('Test connection and save') : translate('Save'))
		}

		function setBusy(value) {
			busy = value
			updateButtons()
		}

		// The tone is "progress" while an action runs, "success", or "error", which a screen reader
		// reads out at once.
		function showMessage(text, tone) {
			const error = tone === 'error'
			message.setAttribute('role', error ? 'alert' : 'status')
			message.setAttribute('aria-live', error ? 'assertive' : 'polite')
			message.classList.toggle('paperless-sync-message--error', error)
			message.classList.toggle('paperless-sync-message--success', tone === 'success')
			message.textContent = text
		}

		// Says why an action failed and moves the focus there, or to the setting that the message
		// names as the way out, such as "Paperless runs in my local network", so that it is one
		// key press away; a screen reader reads its hint with it.
		function showError(error, fallback) {
			showMessage(error.message || fallback, 'error')
			const setting = error.setting ? root.querySelector('[data-setting="' + window.CSS.escape(error.setting) + '"]') : null
			if (setting === null) {
				message.focus()
				return
			}
			focusSetting(setting)
		}

		// Moves the focus to a setting, in view. The bar of the buttons sticks to the bottom of the
		// window and may cover the setting, where focus() would leave it: it scrolls only to an
		// element outside the window.
		function focusSetting(setting) {
			const target = control(setting)
			target.scrollIntoView({ block: 'center' })
			target.focus({ preventScroll: true })
		}

		// The visible control of a setting, which the page moves the focus to: the field of a tag
		// picker for its hidden setting.
		function control(setting) {
			const picker = setting.type === 'hidden' ? setting.closest('[data-tag-picker]') : null
			return picker === null ? setting : picker.querySelector('[role="combobox"]')
		}

		// The sign of a line of the connection checklist, which a screen reader skips, and the word
		// for its state, which everyone reads.
		const checkStates = {
			passed: { sign: '✓', label: translate('Passed') },
			warning: { sign: '!', label: translate('Note') },
			failed: { sign: '✗', label: translate('Failed') },
		}

		function paragraph(className, text) {
			const item = document.createElement('p')
			item.className = className
			item.textContent = text
			return item
		}

		// Shows the lines of the connection checklist in the connection settings: what each check
		// found and, when it failed or has a note, what to do. Answers how many failed and how
		// many have a note.
		function showChecks(lines) {
			const counts = { passed: 0, warning: 0, failed: 0 }
			if (!Array.isArray(lines) || lines.length === 0) {
				return counts
			}
			checksList.replaceChildren(...lines.map(function(line) {
				const status = line.status in checkStates ? line.status : 'failed'
				counts[status]++
				const item = document.createElement('li')
				item.className = 'paperless-sync-check paperless-sync-check--' + status
				item.dataset.check = line.id
				const sign = document.createElement('span')
				sign.className = 'paperless-sync-check-sign'
				sign.setAttribute('aria-hidden', 'true')
				sign.textContent = checkStates[status].sign
				// The label, the state and what the check found, on one line.
				const title = paragraph('paperless-sync-check-title', '')
				const label = document.createElement('strong')
				label.textContent = line.label
				const state = document.createElement('span')
				state.className = 'paperless-sync-check-state'
				state.textContent = checkStates[status].label
				const found = document.createElement('span')
				found.className = 'paperless-sync-check-message'
				found.textContent = line.message
				title.append(label, ' ', state, ' ', found)
				const body = document.createElement('div')
				body.append(title)
				if (line.hint) {
					body.append(paragraph('paperless-sync-check-hint', line.hint))
				}
				item.append(sign, body)
				return item
			}))
			checks.hidden = false
			return counts
		}

		// Shows the checklist of a test or a save, and scrolls to it when a check failed, as the
		// message next to the buttons says.
		function revealChecks(lines) {
			const counts = showChecks(lines)
			if (counts.failed > 0) {
				checks.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
			}
			return counts
		}

		// After a change of a setting that the checklist depends on, its lines no longer apply.
		function outdateChecks(event) {
			if (event.target === token || checkedSettings.includes(event.target.dataset.setting)) {
				checks.hidden = true
			}
		}

		function formatTime(timestamp) {
			if (!timestamp) {
				return '—'
			}
			return new Date(timestamp * 1000).toLocaleString(nextcloudLocale(), { dateStyle: 'medium', timeStyle: 'short' })
		}

		// Changes a text only when it differs, so that a screen reader doesn't read it out again
		// with every refresh.
		function setText(target, text) {
			if (target.textContent !== text) {
				target.textContent = text
			}
		}

		// Null when scheduled runs are off; a time that has passed, or 0, means the next background job.
		function formatNextRun(timestamp) {
			if (timestamp === null || timestamp === undefined) {
				return translate('Off')
			}
			if (timestamp * 1000 <= Date.now()) {
				return translate('With the next background job')
			}
			return translate('≈ {time}', { time: formatTime(timestamp) })
		}

		// The Paperless that the last run reached: its version, as it names itself, and the version
		// of its API that the app uses; a line below the times, hidden before the first run.
		function showPaperless(status) {
			paperlessVersion.hidden = !status.paperlessApiVersion
			if (paperlessVersion.hidden) {
				return
			}
			setText(paperlessVersion, status.paperlessVersion
				? translate('The last run reached Paperless-ngx {version} through version {api} of its API.', { version: status.paperlessVersion, api: status.paperlessApiVersion })
				: translate('The last run reached Paperless-ngx, which named no version, through version {api} of its API.', { api: status.paperlessApiVersion }))
		}

		// The state of the last run as StatusService stores it, and its label; templates/settings.php has the same.
		const stateLabels = {
			'never-run': translate('Never run'),
			queued: translate('Queued'),
			running: translate('Running'),
			'dry-run-running': translate('Dry-run running'),
			completed: translate('Completed'),
			'dry-run-completed': translate('Dry-run completed'),
			'completed-with-errors': translate('Completed with errors'),
			failed: translate('Failed'),
			interrupted: translate('Interrupted'),
		}

		// A run waits for its background job or runs.
		function active(state) {
			return state === 'queued' || state.endsWith('running')
		}

		function showState(state) {
			setText(lastState, stateLabels[state] || state)
			lastState.dataset.state = state
			running.hidden = !active(state)
		}

		// The error of a run that failed and the errors of single documents, with the count of
		// those that the report leaves out.
		function showErrors(status, lastReport) {
			const messages = []
			if (status.error) {
				messages.push(status.error)
			}
			const errorMessages = Array.isArray(lastReport.errorMessages) ? lastReport.errorMessages : []
			messages.push(...errorMessages)
			const more = (lastReport.errors || 0) - errorMessages.length
			if (more > 0) {
				messages.push(translatePlural('… and %n more error', '… and %n more errors', more))
			}
			errors.hidden = messages.length === 0
			if (messages.length === 0) {
				return
			}
			setText(errorsTitle, status.state === 'failed' ? translate('The last run failed') : translate('Errors of the last run'))
			const shown = Array.from(errorsList.children, function(item) {
				return item.textContent
			})
			if (shown.length === messages.length && shown.every(function(text, index) { return text === messages[index] })) {
				return
			}
			errorsList.replaceChildren(...messages.map(function(text) {
				const item = document.createElement('li')
				item.textContent = text
				return item
			}))
		}

		function updateStatus(status) {
			const state = status.state || 'never-run'
			const lastReport = status.report && typeof status.report === 'object' ? status.report : {}
			serverState = state
			showState(state)
			setText(lastEnded, formatTime(status.lastEnded))
			setText(lastSuccess, formatTime(status.lastSuccess))
			setText(nextRun, formatNextRun(status.nextRun))
			showPaperless(status)
			showErrors(status, lastReport)
			if (Object.keys(lastReport).length > 0) {
				showReport(lastReport, false)
			}
			// The run that "Synchronize now" queued has ended: it failed, was interrupted or left the
			// report of a run, not of a dry-run that started while it waited.
			if (awaitedRun && !active(state) && (state === 'failed' || state === 'interrupted' || lastReport.dryRun === false)) {
				awaitedRun = false
				announce(status, lastReport)
			}
			watch(state)
		}

		// Asks for the status again while a run is queued or active, such as a scheduled one, until
		// it ends, and while the run that "Synchronize now" queued has not ended.
		function watch(state) {
			window.clearTimeout(pollTimer)
			pollTimer = 0
			if (state !== watchedState) {
				watchedState = state
				polls = 0
			}
			if (!active(state) && !awaitedRun) {
				return
			}
			pollTimer = window.setTimeout(async function() {
				polls++
				try {
					await refreshStatus()
				} catch (error) {
					watch(state)
				}
			}, pollDelay())
		}

		function pollDelay() {
			if (polls < quickPolls) {
				return quickPollInterval
			}
			return polls < pollLimit ? pollInterval : slowPollInterval
		}

		// How the run that "Synchronize now" queued ended, as its status shows it.
		function announce(status, lastReport) {
			if (status.state === 'interrupted') {
				showError(requestError(translate('The synchronization was interrupted before its end. The next run continues where it stopped.'), 0), '')
			} else if (status.state === 'failed') {
				showError(requestError(status.error || translate('Synchronization failed.'), 0), '')
			} else {
				showResult(lastReport, false)
				report.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
			}
		}

		// The kinds of changes of a report, by the type of their entry (SyncReport): the badge in
		// the language of the reader, its tone, and the filter that shows them, which the three
		// notices share. The errors come first in the list, then the notices, then the changes
		// in the order of the run.
		const entryKinds = {
			error: { label: translate('Error'), tone: 'error', rank: 0 },
			'import-error': { label: translate('Import failed'), tone: 'error', rank: 0 },
			'import-rejected': { label: translate('Refused'), tone: 'error', rank: 0 },
			'scan-incomplete': { label: translate('Notice'), tone: 'warning', rank: 1, filter: 'notice' },
			'export-stopped': { label: translate('Notice'), tone: 'warning', rank: 1, filter: 'notice' },
			'trash-empty': { label: translate('Notice'), tone: 'warning', rank: 1, filter: 'notice' },
			'tags-unknown': { label: translate('Notice'), tone: 'warning', rank: 1, filter: 'notice' },
			delete: { label: translate('Deletion'), tone: 'warning', rank: 2 },
			'remove-excluded': { label: translate('Excluded'), tone: 'warning', rank: 2 },
			trash: { label: translate('Trash'), tone: 'warning', rank: 2 },
			missing: { label: translate('Missing'), tone: 'warning', rank: 2 },
			wait: { label: translate('Waiting'), tone: 'neutral', rank: 2 },
			export: { label: translate('Export'), tone: 'info', rank: 2 },
			move: { label: translate('Move'), tone: 'info', rank: 2 },
			import: { label: translate('Upload'), tone: 'info', rank: 2 },
			'import-success': { label: translate('Imported'), tone: 'success', rank: 2 },
			'new-tag': { label: translate('New tag'), tone: 'info', rank: 2 },
		}

		// The counts of a report that name a change, with their tone; those that are 0 stay out.
		const reportCounts = [
			['errors', translate('Errors'), 'error'],
			['exported', translate('Exported'), 'info'],
			['moved', translate('Moved'), 'info'],
			['movedToTrash', translate('Moved to trash'), 'warning'],
			['permanentlyDeleted', translate('Deleted'), 'warning'],
			['removedExcluded', translate('Excluded copies removed'), 'warning'],
			['importsSubmitted', translate('Imports submitted'), 'info'],
			['importsSucceeded', translate('Imports succeeded'), 'success'],
			['importsFailed', translate('Imports failed'), 'error'],
			['foldersPruned', translate('Folders removed'), 'neutral'],
		]

		// What an entry says besides its badge, its document and its file, in the language of the
		// reader. The reasons of Paperless and the errors of a run stay as the run wrote them.
		function entryText(entry) {
			const values = entry.values && typeof entry.values === 'object' ? entry.values : {}
			switch (entry.type) {
			case 'trash':
				return translate('In the trash of Paperless: the copy moves to the deleted folder.')
			case 'missing':
				return translate('Gone from Paperless: the copy moves to the deleted folder.')
			case 'delete':
				return translate('The copy is deleted for good.')
			case 'remove-excluded':
				return translate('The document has an excluded tag: its copy is removed.')
			case 'wait':
				return translate('Gone from Paperless in {scans} of {limit} scans in a row: the copy stays until then.', { scans: values.scans, limit: values.limit })
			case 'import':
				return values.tags ? translate('Tags: {tags}', { tags: values.tags }) : ''
			case 'new-tag':
				return translate('The tag {name} is created in Paperless.', { name: entry.detail || '' })
			case 'import-success':
				return entry.document
					? translate('Paperless imported it as document {document}.', { document: 'P' + entry.document })
					: translate('Paperless imported it.')
			case 'import-error':
				return translate('Paperless could not import it, so it moves to the error folder: {reason}', { reason: entry.detail || '' })
			case 'import-rejected':
				return translate('Paperless refused it, so it stays in the inbox until it changes: {reason}', { reason: entry.detail || '' })
			case 'scan-incomplete':
				return values.list === 'trashed documents'
					? translate('The trash of Paperless came back incomplete or changed while the run read it ({received} of {listed} received), so no document counts as missing in this run.', values)
					: translate('The list of documents of Paperless came back incomplete or changed while the run read it ({received} of {listed} received), so no document counts as missing in this run.', values)
			case 'export-stopped':
				return translate('Nextcloud cannot store files in the archive, so the run stopped its exports and moves; the other documents wait for the next run.')
			case 'tags-unknown':
				return translatePlural(
					'Paperless shows the account no tag named {names}, so the files of the inbox go to Paperless without it. Create the tag in Paperless or share it with the account, or turn on "Create missing tags".',
					'Paperless shows the account no tags named {names}, so the files of the inbox go to Paperless without them. Create the tags in Paperless or share them with the account, or turn on "Create missing tags".',
					Number(values.count) || 0,
					{ names: values.names },
				)
			case 'trash-empty':
				return Number(values.deletes) === 1
					? translatePlural(
						'%n document of the archive is gone from Paperless, and its trash lists none. Paperless shows in its trash only the documents that the account of the app owns and those without an owner, so documents in the trash of other owners count as missing: their copies are deleted after {scans} missing scans in a row, although the documents can still be restored in Paperless. Make the account of the app their owner.',
						'%n documents of the archive are gone from Paperless, and its trash lists none. Paperless shows in its trash only the documents that the account of the app owns and those without an owner, so documents in the trash of other owners count as missing: their copies are deleted after {scans} missing scans in a row, although the documents can still be restored in Paperless. Make the account of the app their owner.',
						Number(values.missing) || 0,
						{ scans: values.scans },
					)
					: translatePlural(
						'%n document of the archive is gone from Paperless, and its trash lists none. Paperless shows in its trash only the documents that the account of the app owns and those without an owner, so documents in the trash of other owners count as missing: their copies move to the deleted folder only after {scans} missing scans in a row. Make the account of the app their owner.',
						'%n documents of the archive are gone from Paperless, and its trash lists none. Paperless shows in its trash only the documents that the account of the app owns and those without an owner, so documents in the trash of other owners count as missing: their copies move to the deleted folder only after {scans} missing scans in a row. Make the account of the app their owner.',
						Number(values.missing) || 0,
						{ scans: values.scans },
					)
			default:
				return entry.detail || ''
			}
		}

		// The file that an entry shows: where an import that failed went, and otherwise the file
		// that the change concerns. A dry-run moves nothing, so its failed import is still in the
		// inbox.
		function entryFile(entry, dryRun) {
			return String((entry.type === 'import-error' && !dryRun ? entry.target : entry.path) || '')
		}

		// Whether the file of an entry is there after the report: what a run wrote, moved or left
		// in place, and what a dry-run leaves where it is. A dry-run writes and moves nothing, and
		// a deletion leaves nothing to open.
		function fileIsThere(entry, dryRun) {
			if (['import', 'import-rejected', 'import-error', 'wait'].includes(entry.type)) {
				return true
			}
			return !dryRun && ['export', 'move', 'trash', 'missing'].includes(entry.type)
		}

		// The administrator who reads the page, as Nextcloud names the user.
		function viewer() {
			const user = window.OC && typeof window.OC.getCurrentUser === 'function' ? window.OC.getCurrentUser() : null
			return (user && user.uid) || document.head.dataset.user || ''
		}

		// The path of a file, relative to the home of the target user, and a link that opens its
		// folder in the Files app, in a tab of its own.
		function fileElement(path, open) {
			const file = document.createElement(open ? 'a' : 'span')
			file.className = 'paperless-sync-entry-file'
			file.textContent = path
			if (open) {
				const slash = path.lastIndexOf('/')
				file.href = root.dataset.filesUrl + '?' + new URLSearchParams({ dir: '/' + path.slice(0, Math.max(slash, 0)) })
				file.target = '_blank'
				file.rel = 'noreferrer noopener'
				file.title = translate('Open the folder in Files')
			}
			return file
		}

		function entryItem(entry, dryRun, owner) {
			const kind = entryKinds[entry.type]
			const item = document.createElement('li')
			item.className = 'paperless-sync-entry'
			item.dataset.filter = kind.filter || entry.type
			const head = document.createElement('div')
			head.className = 'paperless-sync-entry-head'
			const badge = document.createElement('span')
			badge.className = 'paperless-sync-badge paperless-sync-badge--' + kind.tone
			badge.textContent = kind.label
			head.append(badge)
			// An error names its document and its file in its message, a notice concerns the run.
			if (kind.rank === 2 && entry.document && entry.type !== 'import-success') {
				const id = document.createElement('span')
				id.className = 'paperless-sync-entry-document'
				id.textContent = 'P' + entry.document
				head.append(id)
			}
			const path = entryFile(entry, dryRun)
			if (path !== '' && entry.type !== 'error') {
				head.append(fileElement(path, owner && fileIsThere(entry, dryRun)))
			}
			item.append(head)
			const text = entryText(entry)
			if (text !== '') {
				// A div, not a paragraph, which the CSS of Nextcloud gives a margin.
				const detail = document.createElement('div')
				detail.className = 'paperless-sync-entry-text'
				detail.textContent = text
				item.append(detail)
			}
			return item
		}

		// A line of a report that has no entries, as one of an older version of the app keeps.
		function lineItem(line) {
			const item = document.createElement('li')
			item.className = 'paperless-sync-entry paperless-sync-entry--line'
			item.textContent = line
			return item
		}

		// The entries of a report, the errors first, then the notices, then the changes in the
		// order of the run; null when the report has none for its lines, such as one of an older
		// version of the app, or one of a type that this page doesn't know.
		function sortedEntries(result) {
			const lines = Array.isArray(result.actions) ? result.actions : []
			const entries = Array.isArray(result.entries) ? result.entries : null
			if (entries === null || entries.length !== lines.length || !entries.every(function(entry) {
				return entry && typeof entry === 'object' && Object.prototype.hasOwnProperty.call(entryKinds, entry.type)
			})) {
				return null
			}
			return entries.map(function(entry, index) {
				return { entry, index }
			}).sort(function(left, right) {
				return entryKinds[left.entry.type].rank - entryKinds[right.entry.type].rank || left.index - right.index
			}).map(function(sorted) {
				return sorted.entry
			})
		}

		// The buttons that show the changes of one kind, with their counts, when the list has
		// more than one kind.
		function showFilters(items) {
			const counts = new Map()
			items.forEach(function(item) {
				counts.set(item.dataset.filter, (counts.get(item.dataset.filter) || 0) + 1)
			})
			reportFilters.hidden = counts.size < 2
			if (!counts.has(reportFilter)) {
				reportFilter = ''
			}
			if (reportFilters.hidden) {
				reportFilters.replaceChildren()
				return
			}
			const labels = new Map(items.map(function(item) {
				return [item.dataset.filter, item.querySelector('.paperless-sync-badge').textContent]
			}))
			reportFilters.replaceChildren(filterButton('', translate('All'), items.length), ...Array.from(counts, function([filter, count]) {
				return filterButton(filter, labels.get(filter), count)
			}))
		}

		function filterButton(filter, label, count) {
			const button = document.createElement('button')
			button.type = 'button'
			button.className = 'paperless-sync-filter'
			button.dataset.filter = filter
			button.setAttribute('aria-pressed', String(filter === reportFilter))
			const name = document.createElement('span')
			name.textContent = label
			const number = document.createElement('span')
			number.className = 'paperless-sync-filter-count'
			number.textContent = String(count)
			button.append(name, number)
			button.addEventListener('click', function() {
				reportFilter = filter
				applyFilter()
			})
			return button
		}

		function applyFilter() {
			reportFilters.querySelectorAll('button').forEach(function(button) {
				button.setAttribute('aria-pressed', String(button.dataset.filter === reportFilter))
			})
			reportEntries.querySelectorAll('li').forEach(function(item) {
				item.hidden = reportFilter !== '' && item.dataset.filter !== reportFilter
			})
		}

		function showReport(result, reveal) {
			shownReport = result
			const drawn = JSON.stringify([savedTargetUser, result])
			if (drawn === drawnReport && !report.hidden) {
				if (reveal) {
					report.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
				}
				return
			}
			drawnReport = drawn
			setText(reportTitle, result.dryRun ? translate('Dry-run — nothing was changed') : translate('Run report'))
			reportSummary.replaceChildren(...reportCounts.filter(function([key]) {
				return Number(result[key]) > 0
			}).map(function([key, label, tone]) {
				const item = document.createElement('div')
				item.className = 'paperless-sync-count paperless-sync-count--' + tone
				const value = document.createElement('strong')
				const name = document.createElement('span')
				value.textContent = String(Number(result[key]))
				name.textContent = label
				item.append(value, name)
				return item
			}))
			reportSummary.hidden = reportSummary.children.length === 0
			setText(reportRest, restOf(result))
			reportRest.hidden = reportRest.textContent === ''
			const ended = result.completedAt ? formatTime(result.completedAt) : ''
			if (result.dryRun && result.unsavedSettings === true) {
				setText(reportMeta, ended ? translate('Dry-run of unsaved settings, finished {time}', { time: ended }) : translate('Dry-run of unsaved settings, stopped before its end'))
			} else if (result.dryRun) {
				setText(reportMeta, ended ? translate('Dry-run, finished {time}', { time: ended }) : translate('Dry-run, stopped before its end'))
			} else {
				setText(reportMeta, ended ? translate('Run, finished {time}', { time: ended }) : translate('Run, stopped before its end'))
			}
			const lines = Array.isArray(result.actions) ? result.actions : []
			const entries = sortedEntries(result)
			const owner = savedTargetUser !== '' && viewer() === savedTargetUser
			const items = entries === null
				? lines.map(lineItem)
				: entries.map(function(entry) {
					return entryItem(entry, result.dryRun === true, owner)
				})
			if (items.length === 0) {
				const empty = document.createElement('li')
				empty.className = 'paperless-sync-entry paperless-sync-entry--line'
				empty.textContent = translate('No file changes were required.')
				items.push(empty)
			}
			showFilters(entries === null || lines.length === 0 ? [] : items)
			reportEntries.replaceChildren(...items)
			applyFilter()
			const omitted = result.omittedActions || 0
			reportOmitted.hidden = omitted === 0
			setText(reportOmitted, omitted > 0 ? translatePlural('… and %n more change, which the list leaves out', '… and %n more changes, which the list leaves out', omitted) : '')
			report.hidden = false
			if (reveal) {
				report.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
			}
		}

		// The documents that a run left as they were, and those that it skipped, in a sentence below
		// the counts of the changes. A skipped one may wait for a later run, as when the batch is
		// full, or for good, as a document with an excluded tag does.
		function restOf(result) {
			const parts = []
			if (Number(result.unchanged) > 0) {
				parts.push(translatePlural('%n unchanged', '%n unchanged', Number(result.unchanged)))
			}
			if (Number(result.skipped) > 0) {
				parts.push(translatePlural('%n skipped', '%n skipped', Number(result.skipped)))
			}
			return parts.join(', ')
		}

		// The report as text for a bug report: its heading, its counts in the language of the
		// reader and its lines in English, as the log and the command line show them.
		function reportText(result) {
			const lines = [reportTitle.textContent, reportMeta.textContent]
			const counts = Array.from(reportSummary.children, function(item) {
				return item.lastChild.textContent + ': ' + item.firstChild.textContent
			})
			if (counts.length > 0) {
				lines.push(counts.join(', '))
			}
			if (!reportRest.hidden) {
				lines.push(reportRest.textContent)
			}
			lines.push('', ...(Array.isArray(result.actions) ? result.actions : []))
			if (!reportOmitted.hidden) {
				lines.push(reportOmitted.textContent)
			}
			return lines.join('\n') + '\n'
		}

		// Copies a text to the clipboard: through the Clipboard API on HTTPS, and through a
		// selected text area where the browser has no Clipboard API, such as on plain HTTP.
		async function copyText(text) {
			if (navigator.clipboard && window.isSecureContext) {
				await navigator.clipboard.writeText(text)
				return
			}
			const area = document.createElement('textarea')
			area.value = text
			area.setAttribute('readonly', '')
			area.className = 'paperless-sync-copy-area'
			document.body.append(area)
			area.select()
			const copied = document.execCommand('copy')
			area.remove()
			if (!copied) {
				throw new Error('The browser refused to copy.')
			}
		}

		reportCopy.addEventListener('click', async function() {
			if (shownReport === null) {
				return
			}
			try {
				await copyText(reportText(shownReport))
				showMessage(translate('The report is copied to the clipboard.'), 'success')
			} catch (error) {
				showMessage(translate('The browser did not allow copying the report. Select its lines and copy them yourself.'), 'error')
			}
			reportCopy.focus()
		})

		// How a run by hand ended, with the count of the errors that its report lists.
		function showResult(result, isDryRun) {
			const failures = Number(result.errors) || 0
			if (failures === 0 && result.unsavedSettings === true) {
				showMessage(translate('Dry-run of the unsaved settings completed. They are not saved yet, and no files were changed.'), 'success')
			} else if (failures === 0) {
				showMessage(isDryRun ? translate('Dry-run completed. No files were changed.') : translate('Synchronization completed.'), 'success')
			} else if (isDryRun) {
				showMessage(translatePlural('Dry-run completed with %n error. No files were changed; see the report.', 'Dry-run completed with %n errors. No files were changed; see the report.', failures), 'error')
			} else {
				showMessage(translatePlural('Synchronization completed with %n error. See the report.', 'Synchronization completed with %n errors. See the report.', failures), 'error')
			}
		}

		// A dry-run answers with its report. A run waits in a background job of Nextcloud, because it
		// may take longer than the web server waits for an answer; the status follows it.
		async function runSync(isDryRun) {
			setBusy(true)
			showState(isDryRun ? 'dry-run-running' : 'queued')
			showMessage(isDryRun ? translate('Calculating synchronization changes…') : translate('Queuing the synchronization…'), 'progress')
			let gateway = 0
			// A dry-run tries the changes of the form without saving them (SyncController::run()).
			const body = { dryRun: isDryRun }
			if (isDryRun && JSON.stringify(settings()) !== savedSettings) {
				body.settings = settings()
			}
			// The status that the answer of a queued run brings along.
			let status = null
			try {
				const result = await request(root.dataset.runUrl, 'POST', body)
				if (result.queued === true) {
					awaitedRun = true
					showMessage(translate('The synchronization starts with the next background jobs of Nextcloud, within a few minutes. The status above follows it, and this message says how it ends.'), 'progress')
					status = result.status && typeof result.status === 'object' ? result.status : null
				} else {
					showReport(result, true)
					showResult(result, isDryRun)
				}
			} catch (error) {
				if (error.status === 502 || error.status === 504) {
					gateway = error.status
				} else {
					showError(error, translate('Synchronization failed.'))
				}
			} finally {
				setBusy(false)
			}
			if (gateway !== 0) {
				await explainGateway(gateway)
				return
			}
			// The status shows how the run ended, also when it failed, or follows it while it waits
			// and continues. Without an answer, it shows the last state that the server sent.
			if (status !== null) {
				updateStatus(status)
			} else if (!(await refreshQuietly())) {
				showState(serverState)
			}
		}

		// A proxy or the web server answered for the app: it stopped waiting for a long run, while
		// PHP carries on with it, or PHP ended without an answer, such as when the web server
		// stopped it. The status tells which, and follows a run that continues.
		async function explainGateway(status) {
			let state = ''
			try {
				state = await refreshStatus()
			} catch (error) {
				// Neither the run nor the status answered, so how the run ended is unknown: the
				// status shows the last state that the server sent, and Refresh asks again.
				showState(serverState)
				showError(requestError(translate('The web server answered with status {status}, and the state of the run could not be read. Choose "Refresh" to see how the run ended.', { status }), status), '')
				return
			}
			if (state.endsWith('running')) {
				showMessage(translate('The run takes longer than the web server waits for an answer. It continues in the background, and the status above shows when it ends.'), 'progress')
			} else {
				showError(requestError(translate('The web server answered with status {status}. The status above shows how the run ended.', { status }), status), '')
			}
		}

		// Shows the status of the last run, and answers its state.
		async function refreshStatus() {
			const status = await request(root.dataset.statusUrl, 'GET')
			updateStatus(status)
			return status.state || 'never-run'
		}

		// After an action, the status follows it; when that fails, the message of the action stays,
		// and Refresh asks again. Answers whether the status was refreshed.
		async function refreshQuietly() {
			try {
				await refreshStatus()
				return true
			} catch (error) {
				return false
			}
		}

		// Shows the paths of the newest documents of Paperless as the server rendered them with the
		// template of the form, or why there are none.
		function showPreview(answer) {
			pathTemplate.removeAttribute('aria-invalid')
			previewStatus.classList.remove('paperless-sync-path-preview-status--error')
			const paths = Array.isArray(answer.paths) ? answer.paths : []
			if (answer.connected !== true) {
				setText(previewStatus, translate('Save the connection to Paperless, and the paths of its newest documents appear here.'))
			} else if (paths.length === 0) {
				setText(previewStatus, translate('Paperless has no documents yet, so there is no path to show.'))
			} else {
				setText(previewStatus, translatePlural('Where the newest document goes, in the archive folder:', 'Where the %n newest documents go, in the archive folder:', paths.length))
			}
			previewList.replaceChildren(...paths.map(function(entry) {
				const item = document.createElement('li')
				item.textContent = String(entry.path || '')
				if (entry.skipped === true) {
					const note = document.createElement('span')
					note.className = 'paperless-sync-path-preview-skipped'
					note.textContent = ' — ' + translate('a run skips it: in the inbox or with an excluded tag')
					item.append(note)
				}
				return item
			}))
			previewList.hidden = paths.length === 0
		}

		// Says why there is no preview: an invalid template, which a save would refuse too, or a
		// Paperless that can't be read.
		function showPreviewError(error) {
			if (error.status === 400) {
				pathTemplate.setAttribute('aria-invalid', 'true')
			} else {
				pathTemplate.removeAttribute('aria-invalid')
			}
			previewStatus.classList.add('paperless-sync-path-preview-status--error')
			setText(previewStatus, error.message || translate('The preview could not be loaded.'))
			previewList.hidden = true
		}

		// Asks the server for the paths with the settings of the form, without saving them; only
		// the answer to the last request is shown.
		async function updatePreview() {
			window.clearTimeout(previewTimer)
			const current = ++previewRequest
			preview.setAttribute('aria-busy', 'true')
			try {
				const answer = await request(root.dataset.previewUrl, 'POST', { settings: settings() })
				if (current === previewRequest) {
					showPreview(answer)
				}
			} catch (error) {
				if (current === previewRequest) {
					showPreviewError(error)
				}
			} finally {
				if (current === previewRequest) {
					preview.removeAttribute('aria-busy')
				}
			}
		}

		function schedulePreview() {
			window.clearTimeout(previewTimer)
			previewTimer = window.setTimeout(updatePreview, previewDelay)
		}

		// Puts {{ variable }} into the path template where its cursor was, or in place of the text
		// that was selected, and selects the part to type over, such as the name of a custom field.
		function insertVariable(variable, placeholder) {
			const text = '{{ ' + variable + ' }}'
			const focused = document.activeElement === pathTemplate
			const end = pathTemplate.value.length
			const [start, stop] = focused ? [pathTemplate.selectionStart ?? end, pathTemplate.selectionEnd ?? end] : templateSelection || [end, end]
			pathTemplate.focus()
			pathTemplate.setRangeText(text, Math.min(start, end), Math.min(stop, end), 'end')
			if (placeholder) {
				const from = Math.min(start, end) + text.length - ' }}'.length - placeholder.length
				pathTemplate.setSelectionRange(from, from + placeholder.length)
			}
			pathTemplate.dispatchEvent(new Event('input', { bubbles: true }))
		}

		pathTemplate.addEventListener('blur', function() {
			templateSelection = [pathTemplate.selectionStart, pathTemplate.selectionEnd]
		})
		root.querySelectorAll('[data-variable]').forEach(function(button) {
			button.addEventListener('click', function() {
				insertVariable(button.dataset.variable, button.dataset.select)
			})
		})
		form.addEventListener('input', function(event) {
			if (previewSettings.includes(event.target.dataset.setting)) {
				schedulePreview()
			}
		})

		// What came of the last call of the webhook, by the result that WebhookService::result()
		// names; translate() takes each text literally, so that scripts/check-l10n.mjs finds it.
		function lastCallText(call) {
			const parameters = { time: formatTime(call.at), document: call.document, error: call.message }
			switch (call.result) {
			case 'exported':
				return translate('Last call, {time}: P{document} exported.', parameters)
			case 'moved':
				return translate('Last call, {time}: P{document} moved.', parameters)
			case 'removed':
				return translate('Last call, {time}: the copy of P{document} removed, as the settings exclude the document.', parameters)
			case 'unchanged':
				return translate('Last call, {time}: the copy of P{document} was current already.', parameters)
			case 'excluded':
				return translate('Last call, {time}: the settings exclude P{document}, such as a document in the inbox of Paperless.', parameters)
			case 'missing':
				return translate('Last call, {time}: Paperless shows no active document P{document}. A scheduled run handles a document in the trash or deleted.', parameters)
			case 'queued':
				return translate('Last call, {time}: P{document} waits for the next background jobs.', parameters)
			case 'off':
				return translate('Last call, {time}: nothing changed for P{document}, as scheduled synchronization or the export is off.', parameters)
			case 'failed':
				return translate('Last call, {time}: P{document} failed: {error}', parameters)
			default:
				return ''
			}
		}

		// The webhook as the server sent it, without its secret: whether it has one, and its last call.
		function showWebhook(webhook) {
			setText(webhookCreate, webhook.configured ? translate('Create a new secret') : translate('Create secret'))
			webhookRemove.hidden = !webhook.configured
			setText(webhookState, webhook.configured
				? translate('The webhook accepts calls with its secret.')
				: translate('The webhook is off: it refuses every call until you create its secret.'))
			const text = webhook.lastCall ? lastCallText(webhook.lastCall) : ''
			webhookLastCall.hidden = text === ''
			setText(webhookLastCall, text)
		}

		async function refreshWebhook() {
			showWebhook(await request(root.dataset.webhookUrl, 'GET'))
		}

		// A new secret replaces the one before at once; the page shows it this once, selected for copying.
		webhookCreate.addEventListener('click', async function() {
			if (!webhookRemove.hidden && !window.confirm(translate('Create a new secret? The secret before stops working at once, so the workflow of Paperless needs the new one.'))) {
				return
			}
			setBusy(true)
			try {
				const answer = await request(root.dataset.webhookUrl, 'POST')
				showWebhook(answer)
				webhookSecret.value = answer.secret
				webhookSecretField.hidden = false
				webhookSecret.focus()
				webhookSecret.select()
				showMessage(translate('Secret created. Copy it now: it is shown only this once.'), 'success')
			} catch (error) {
				showError(error, translate('Could not create the secret.'))
			} finally {
				setBusy(false)
			}
		})

		webhookRemove.addEventListener('click', async function() {
			if (!window.confirm(translate('Turn the webhook off? Its secret stops working, and the calls of Paperless are refused until you create a new one. The scheduled runs go on.'))) {
				return
			}
			setBusy(true)
			try {
				showWebhook(await request(root.dataset.webhookUrl, 'DELETE'))
				webhookSecret.value = ''
				webhookSecretField.hidden = true
				showMessage(translate('The webhook is off.'), 'success')
			} catch (error) {
				showError(error, translate('Could not turn the webhook off.'))
			} finally {
				setBusy(false)
			}
		})

		// Copies the webhook URL or the secret; without the clipboard of the browser, which needs
		// HTTPS, the text stays selected for copying by hand.
		root.querySelectorAll('[data-copy]').forEach(function(button) {
			button.addEventListener('click', async function() {
				const input = document.getElementById(button.dataset.copy)
				input.focus()
				input.select()
				try {
					await window.navigator.clipboard.writeText(input.value)
					showMessage(translate('Copied.'), 'success')
				} catch (error) {
					showMessage(translate('Selected: copy it with Ctrl+C, or ⌘+C on a Mac.'), 'progress')
				}
			})
		})

		// The target user: the users of Nextcloud whose ID, name or e-mail address holds what is typed,
		// from its provisioning API, which answers administrators. A user ID that Nextcloud doesn't
		// know is marked at once, so that a typo doesn't wait for the save or the next run.
		const ocs = { headers: { 'OCS-APIRequest': 'true' } }
		// The user ID that the hint of the field speaks of, null before the first check.
		let checkedUser = null
		// Whether the hint of the field says that the last search failed, which says nothing about
		// the user of the field and so doesn't mark it as invalid.
		let searchFailed = false

		async function searchUsers(text) {
			try {
				const query = new URLSearchParams({ search: text, limit: '10' })
				const answer = await request(root.dataset.usersUrl + '/details?' + query, 'GET', undefined, ocs)
				if (searchFailed) {
					searchFailed = false
					setText(targetUserHint, '')
				}
				const users = answer.ocs && answer.ocs.data && answer.ocs.data.users ? Object.values(answer.ocs.data.users) : []
				return users.filter(function(user) {
					return user !== null && typeof user === 'object' && typeof user.id === 'string'
				}).map(function(user) {
					const name = typeof user.displayname === 'string' && user.displayname.trim() !== '' ? user.displayname : user.id
					const notes = name === user.id ? [] : [user.id]
					// A disabled user can't own the archive: a run fails until the account is enabled.
					if (user.enabled === false) {
						notes.push(translate('disabled'))
					}
					return { value: user.id, label: name, note: notes.join(' · ') }
				})
			} catch (error) {
				if (targetUser.getAttribute('aria-invalid') !== 'true') {
					searchFailed = true
					setText(targetUserHint, translate('Could not search the users of Nextcloud: {error}', { error: error.message }))
				}
				return []
			}
		}

		function showUserProblem(text) {
			searchFailed = false
			setText(targetUserHint, text)
			if (text === '') {
				targetUser.removeAttribute('aria-invalid')
			} else {
				targetUser.setAttribute('aria-invalid', 'true')
			}
		}

		// Asks Nextcloud for the user of the field, unless that was the last one it asked for. Only
		// an answer that the user doesn't exist marks the field; when Nextcloud can't answer, the save
		// still tests the user.
		async function checkTargetUser() {
			const id = targetUser.value.trim()
			if (id === checkedUser) {
				return
			}
			checkedUser = id
			showUserProblem('')
			if (id === '') {
				return
			}
			let known = true
			try {
				await request(root.dataset.usersUrl + '/' + encodeURIComponent(id), 'GET', undefined, ocs)
			} catch (error) {
				known = error.status !== 404
			}
			if (checkedUser === id && !known) {
				showUserProblem(translate('Nextcloud has no user with the ID {user}. Pick one from the list.', { user: id }))
			}
		}

		combobox(targetUser, element('target-user-options'), searchUsers, function(option) {
			targetUser.value = option.value
			checkedUser = option.value
			showUserProblem('')
			// A change, as when the field loses the focus: an input event would open the list again.
			targetUser.dispatchEvent(new Event('change', { bubbles: true }))
		})
		targetUser.addEventListener('change', checkTargetUser)

		// The group that may send files from Files: the groups of Nextcloud whose ID or name holds
		// what is typed, from the same provisioning API. The save checks that the group exists.
		const sendGroup = element('send-group')
		const sendGroupProblem = element('send-group-problem')

		async function searchGroups(text) {
			try {
				const query = new URLSearchParams({ search: text, limit: '10' })
				const answer = await request(root.dataset.groupsUrl + '/details?' + query, 'GET', undefined, ocs)
				setText(sendGroupProblem, '')
				const groups = answer.ocs && answer.ocs.data && Array.isArray(answer.ocs.data.groups) ? answer.ocs.data.groups : []
				return groups.filter(function(group) {
					return group !== null && typeof group === 'object' && typeof group.id === 'string'
				}).map(function(group) {
					const name = typeof group.displayname === 'string' && group.displayname.trim() !== '' ? group.displayname : group.id
					return { value: group.id, label: name, note: name === group.id ? '' : group.id }
				})
			} catch (error) {
				setText(sendGroupProblem, translate('Could not search the groups of Nextcloud: {error}', { error: error.message }))
				return []
			}
		}

		combobox(sendGroup, element('send-group-options'), searchGroups, function(option) {
			sendGroup.value = option.value
			setText(sendGroupProblem, '')
			sendGroup.dispatchEvent(new Event('change', { bubbles: true }))
		})

		// The tags of Paperless, which every tag picker offers: their names, or null while the page
		// doesn't know them. The page reads them with the saved connection, as a run does, and anew
		// after a save or a disconnect, which may change it, and after a failure, such as while
		// Paperless was down. A search waits for the request; each request counts up, so that the
		// answer for a connection that changed since then changes nothing.
		let paperlessTags = null
		let tagsLoading = null
		let tagsRequest = 0
		let tagsFailed = false
		const tagPickers = []

		// A run compares the names of tags in small letters, and so does the page.
		function tagKey(name) {
			return name.toLowerCase()
		}

		function splitTags(text) {
			return text.split(/[,\r\n]+/).map(function(name) {
				return name.trim()
			}).filter(function(name) {
				return name !== ''
			})
		}

		// The name of Paperless for a name, which may differ in its case, or undefined when Paperless
		// has no such tag; the name itself while the page doesn't know the tags.
		function paperlessName(name) {
			if (paperlessTags === null) {
				return name
			}
			return paperlessTags.find(function(tag) {
				return tagKey(tag) === tagKey(name)
			})
		}

		function loadTags() {
			if (tagsLoading === null) {
				reloadTags()
			}
			return tagsLoading
		}

		// Asks for the tags anew; the names known so far stay until the answer arrives.
		function reloadTags() {
			tagsLoading = fetchTags()
			return tagsLoading
		}

		async function fetchTags() {
			const current = ++tagsRequest
			let tags = null
			let status = ''
			try {
				const answer = await request(root.dataset.tagsUrl, 'GET')
				tags = Array.isArray(answer.tags) ? answer.tags.filter(function(tag) {
					return typeof tag === 'string'
				}) : []
				status = tags.length === 0 ? translate('Paperless has no tags yet.') : ''
			} catch (error) {
				status = error.message
			}
			if (current !== tagsRequest) {
				return
			}
			paperlessTags = tags
			tagsFailed = tags === null
			tagPickers.forEach(function(picker) {
				picker.showStatus(status)
				picker.show()
			})
		}

		function forgetTags() {
			tagsRequest++
			tagsLoading = null
			tagsFailed = false
			paperlessTags = null
			tagPickers.forEach(function(picker) {
				picker.showStatus('')
			})
		}

		// A list of tags of a setting, as templates/settings.php draws it: a chip for each name, with
		// a button that removes it, and a field that adds the tags of Paperless or any name that is
		// typed, also of a tag that Paperless doesn't have yet, which is marked, so that a typo shows.
		// The setting itself is the hidden input, with the names separated by commas, as the server
		// stores them; a change of it is an input event of the form, as a change of any other setting.
		function tagPicker(container) {
			const setting = container.querySelector('[data-setting]')
			const input = container.querySelector('[role="combobox"]')
			const prefix = input.id.replace(/-input$/, '')
			const part = function(name) {
				return container.querySelector('#' + prefix + '-' + name)
			}
			const list = part('list')
			const status = part('status')
			const announcement = part('announcement')

			function chosen() {
				return splitTags(setting.value)
			}

			function show() {
				const names = chosen()
				list.hidden = names.length === 0
				list.replaceChildren(...names.map(function(name) {
					const item = document.createElement('li')
					item.className = 'paperless-sync-chip'
					const label = document.createElement('span')
					label.textContent = name
					item.append(label)
					if (paperlessName(name) === undefined) {
						item.classList.add('paperless-sync-chip--new')
						const note = document.createElement('span')
						note.className = 'paperless-sync-chip-note'
						note.textContent = translate('not in Paperless')
						item.append(' ', note)
					}
					const remove = document.createElement('button')
					remove.type = 'button'
					remove.className = 'paperless-sync-chip-remove'
					remove.title = translate('Remove {tag}', { tag: name })
					remove.setAttribute('aria-label', remove.title)
					remove.textContent = '×'
					remove.addEventListener('click', function() {
						removeTag(name)
						input.focus()
					})
					item.append(remove)
					return item
				}))
			}

			function set(names) {
				setting.value = names.join(', ')
				show()
				setting.dispatchEvent(new Event('input', { bubbles: true }))
			}

			// Adds names, as Paperless writes them when it has them, each once; a screen reader hears
			// what changed.
			function add(names) {
				const before = chosen()
				const added = []
				let repeated = ''
				names.forEach(function(typed) {
					const name = paperlessName(typed) || typed
					if (before.concat(added).some(function(tag) { return tagKey(tag) === tagKey(name) })) {
						repeated = name
					} else {
						added.push(name)
					}
				})
				if (added.length > 0) {
					set(before.concat(added))
					setText(announcement, translate('Added: {tags}', { tags: added.join(', ') }))
				} else if (repeated !== '') {
					setText(announcement, translate('{tag} is already in the list.', { tag: repeated }))
				}
			}

			function removeTag(name) {
				set(chosen().filter(function(tag) {
					return tag !== name
				}))
				setText(announcement, translate('Removed: {tag}', { tag: name }))
			}

			// Adds what is typed in the field, which a save or a dry-run sends too, and closes the
			// list, which would otherwise open again for the empty field.
			function addTyped() {
				if (input.value.trim() === '') {
					return
				}
				const names = splitTags(input.value)
				input.value = ''
				picker.close()
				add(names)
				// The field is empty again: without a change of the setting, which updates the form
				// by its input event, the note of unsaved changes would stay for what was typed.
				updateForm()
			}

			async function search(text) {
				await loadTags()
				const taken = chosen().map(tagKey)
				const wanted = tagKey(text)
				const names = (paperlessTags || []).filter(function(tag) {
					return !taken.includes(tagKey(tag)) && tagKey(tag).includes(wanted)
				})
				// The tags whose names start with the text first, each group in the order of the server.
				names.sort(function(first, second) {
					return Number(!tagKey(first).startsWith(wanted)) - Number(!tagKey(second).startsWith(wanted))
				})
				const found = names.map(function(tag) {
					return { value: tag, label: tag, note: '' }
				})
				if (text !== '' && !taken.includes(wanted) && paperlessTags !== null && paperlessName(text) === undefined) {
					found.push({ value: text, label: translate('Add "{tag}"', { tag: text }), note: translate('not in Paperless') })
				}
				return found
			}

			const picker = combobox(input, part('options'), search, function(option) {
				input.value = ''
				add([option.value])
			})
			input.addEventListener('keydown', function(event) {
				if (event.defaultPrevented) {
					// The list took the key, to pick an option.
					return
				}
				if (event.key === 'Enter' && input.value.trim() !== '') {
					// Adds the name instead of submitting the form.
					event.preventDefault()
					addTyped()
				} else if (event.key === 'Backspace' && input.value === '') {
					const names = chosen()
					if (names.length > 0) {
						event.preventDefault()
						removeTag(names[names.length - 1])
					}
				}
			})
			// A comma or a line break, also in a pasted list, ends a name.
			input.addEventListener('input', function() {
				const parts = input.value.split(/[,\r\n]/)
				if (parts.length > 1) {
					const rest = parts.pop()
					add(splitTags(parts.join(',')))
					input.value = rest.trimStart()
				}
			})
			// Leaving the field adds what is typed, unless the focus goes to a button that removes a tag.
			input.addEventListener('blur', function(event) {
				if (!list.contains(event.relatedTarget)) {
					addTyped()
				}
			})
			// After a failure, such as while Paperless was down, the tags are asked for again.
			input.addEventListener('focus', function() {
				if (tagsFailed) {
					reloadTags()
				}
			})

			return {
				input,
				show,
				addTyped,
				typed: function() {
					return input.value.trim() !== ''
				},
				showStatus: function(text) {
					setText(status, text)
				},
			}
		}

		root.querySelectorAll('[data-tag-picker]').forEach(function(container) {
			tagPickers.push(tagPicker(container))
		})

		// Adds the names that are typed in a tag picker but not yet added, before the settings of the
		// form are sent.
		function addTypedTags() {
			tagPickers.forEach(function(picker) {
				picker.addTyped()
			})
		}

		form.addEventListener('submit', async function(event) {
			event.preventDefault()
			// A name that is typed but not yet added is saved as well.
			addTypedTags()
			setBusy(true)
			const testing = willTest()
			showMessage(testing ? translate('Testing Paperless and Nextcloud access…') : translate('Saving the settings…'), 'progress')
			let saved = false
			const sent = snapshot()
			try {
				const config = await request(root.dataset.saveUrl, 'POST', sent)
				applyConfig(config, sent)
				const counts = revealChecks(config.checks)
				if (!config.connectionTested) {
					showMessage(translate('Saved. Scheduled synchronization is off; the connection was not tested.'), 'success')
				} else if (counts.failed > 0) {
					// A check of one module failed, such as a missing permission for the tags: the
					// settings are saved, and the runs fail until it passes.
					showMessage(translatePlural('Configuration saved, but %n check failed, and runs fail until it passes. The connection checklist says what to do.', 'Configuration saved, but %n checks failed, and runs fail until they pass. The connection checklist says what to do.', counts.failed), 'error')
				} else if (counts.warning > 0) {
					showMessage(translate('Connection successful. Configuration saved. Read the notes of the connection checklist.'), 'success')
				} else {
					showMessage(translate('Connection successful. Configuration saved.'), 'success')
				}
				saved = true
			} catch (error) {
				revealChecks(error.checks)
				showError(error, translate('Could not save the configuration.'))
			} finally {
				setBusy(false)
			}
			if (saved) {
				// The schedule may have changed, and with it the next scheduled run, and the
				// preview may now reach Paperless.
				await refreshQuietly()
				updatePreview()
			}
		})

		// The checklist of the settings in the form, without saving them.
		test.addEventListener('click', async function() {
			addTypedTags()
			setBusy(true)
			showMessage(translate('Testing Paperless and Nextcloud access…'), 'progress')
			try {
				const result = await request(root.dataset.testUrl, 'POST', snapshot())
				const counts = revealChecks(result.checks)
				if (counts.failed > 0) {
					showMessage(translatePlural('%n check failed. The connection checklist says what to do.', '%n checks failed. The connection checklist says what to do.', counts.failed), 'error')
					// A failure that a setting of the form fixes, such as "Paperless runs in my local
					// network", moves the focus to that setting, as a refused save does.
					const fixable = result.checks.find(function(line) {
						return line.status === 'failed' && line.setting
					})
					const setting = fixable ? root.querySelector('[data-setting="' + window.CSS.escape(fixable.setting) + '"]') : null
					if (setting) {
						focusSetting(setting)
					}
				} else if (counts.warning > 0) {
					showMessage(translate('Every check passed. Read the notes of the connection checklist.'), 'success')
				} else {
					showMessage(translate('Every check passed.'), 'success')
				}
			} catch (error) {
				showError(error, translate('Could not test the connection.'))
			} finally {
				setBusy(false)
			}
		})

		dryRun.addEventListener('click', function() {
			addTypedTags()
			runSync(true)
		})
		run.addEventListener('click', function() {
			if (window.confirm(translate('Run synchronization now and apply the configured file moves and deletion policy?'))) {
				runSync(false)
			}
		})
		refresh.addEventListener('click', async function() {
			setBusy(true)
			try {
				await Promise.all([refreshStatus(), refreshWebhook()])
				showMessage(translate('Status refreshed.'), 'success')
			} catch (error) {
				showError(error, translate('Could not refresh status.'))
			} finally {
				setBusy(false)
			}
		})
		reset.addEventListener('click', async function() {
			let question = translate('Disconnect Paperless? This deletes the stored API token and turns off scheduled synchronization and the access to the local network. The other settings, the synchronized files and their state stay as they are.')
			if (isDirty()) {
				question += ' ' + translate('Your unsaved changes will be discarded.')
			}
			if (!window.confirm(question)) {
				return
			}
			setBusy(true)
			let disconnected = false
			const before = snapshot()
			try {
				applyConfig(await request(root.dataset.resetUrl, 'DELETE'), before)
				showMessage(translate('Paperless disconnected: the API token is deleted and scheduled synchronization is off. The other settings and the files are unchanged.'), 'success')
				disconnected = true
			} catch (error) {
				showError(error, translate('Could not disconnect Paperless.'))
			} finally {
				setBusy(false)
			}
			if (disconnected) {
				// Without the schedule, there is no next scheduled run, and without the token no preview.
				await refreshQuietly()
				updatePreview()
			}
		})

		form.addEventListener('input', updateForm)
		form.addEventListener('change', updateForm)
		form.addEventListener('input', outdateChecks)
		form.addEventListener('change', outdateChecks)
		// Leaving the page with unsaved changes asks first, in a dialog of the browser.
		window.addEventListener('beforeunload', function(event) {
			if (isDirty()) {
				event.preventDefault()
				event.returnValue = true
			}
		})

		const config = initialState('config')
		if (config !== null) {
			applyConfig(config)
		} else {
			// Without the initial state, the form as the server rendered it is the saved state.
			tokenConfigured = !token.required
			savedUrl = normalizeUrl(paperlessUrl.defaultValue)
			savedTargetUser = root.querySelector('[data-setting="target_user"]').defaultValue
			savedSettings = JSON.stringify(settings())
			if (tokenConfigured) {
				loadTags()
			}
			tagPickers.forEach(function(picker) {
				picker.show()
			})
			checkTargetUser()
			updateForm()
		}
		const status = initialState('status')
		if (status !== null) {
			updateStatus(status)
		}
		const webhook = initialState('webhook')
		if (webhook !== null) {
			showWebhook(webhook)
		}
		updatePreview()
	})
})()

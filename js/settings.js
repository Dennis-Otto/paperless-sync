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

	function translatePlural(singular, plural, count) {
		if (window.n) {
			return window.n('paperless_sync', singular, plural, count, undefined, { escape: false })
		}
		return (count === 1 ? singular : plural).replace('%n', String(count))
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

	async function request(url, method, body, confirmed = false) {
		let response
		try {
			response = await window.fetch(url, {
				method,
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: window.OC.requestToken,
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
		} catch (error) {
			// fetch() fails only when no answer arrives: the network or Nextcloud is down.
			throw requestError(translate('Nextcloud could not be reached. Check the connection and try again.'), 0)
		}
		const data = await answerData(response)
		if (response.status === 403 && data !== null && data.message === PASSWORD_CONFIRMATION_REQUIRED && !confirmed) {
			await confirmPassword()
			return request(url, method, body, true)
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
		const reportSummary = element('report-summary')
		const reportActions = element('report-actions')
		const reportMeta = element('report-meta')
		const reportOmitted = element('report-omitted')
		const lastState = element('last-state')
		const running = element('running')
		const lastEnded = element('last-ended')
		const lastSuccess = element('last-success')
		const nextRun = element('next-run')
		const errors = element('errors')
		const errorsTitle = element('errors-title')
		const errorsList = element('errors-list')
		const buttons = [save, test, dryRun, run, reset, refresh]
		// The settings that the connection checklist depends on: a change of one outdates its lines.
		const checkedSettings = ['paperless_url', 'paperless_local_network', 'target_user', 'base_path', 'export_enabled', 'inbox_enabled']
		// While a run is active, the page asks for its status every five seconds, at most five minutes long.
		const pollInterval = 5000
		const pollLimit = 60
		let pollTimer = 0
		let polls = 0
		let busy = false
		// The settings that the server stored, as settings() reads them from the form, to tell
		// unsaved changes.
		let savedSettings = ''
		// Whether the server holds a token, and the URL that it belongs to. The server sends the
		// stored token only there, so another URL needs the token again.
		let tokenConfigured = false
		let savedUrl = ''

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
			updateForm()
		}

		function isDirty() {
			return token.value !== '' || JSON.stringify(settings()) !== savedSettings
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

		// A dry-run and a run use the saved settings. A dry-run may show what they do before the
		// changes are saved; "Synchronize now" waits for the save, so that it never applies
		// settings that the page doesn't show.
		function updateButtons() {
			const dirty = isDirty()
			unsaved.hidden = !dirty
			buttons.forEach(function(button) {
				button.disabled = busy || (button === run && dirty)
			})
		}

		function updateForm() {
			updateToken()
			updateButtons()
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
			const target = setting || message
			target.focus()
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
			return new Date(timestamp * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
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

		// The state of the last run as StatusService stores it, and its label; templates/settings.php has the same.
		const stateLabels = {
			'never-run': translate('Never run'),
			running: translate('Running'),
			'dry-run-running': translate('Dry-run running'),
			completed: translate('Completed'),
			'dry-run-completed': translate('Dry-run completed'),
			'completed-with-errors': translate('Completed with errors'),
			failed: translate('Failed'),
		}

		function showState(state) {
			setText(lastState, stateLabels[state] || state)
			lastState.dataset.state = state
			running.hidden = !state.endsWith('running')
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
			showState(state)
			setText(lastEnded, formatTime(status.lastEnded))
			setText(lastSuccess, formatTime(status.lastSuccess))
			setText(nextRun, formatNextRun(status.nextRun))
			showErrors(status, lastReport)
			if (Object.keys(lastReport).length > 0) {
				showReport(lastReport, false)
			}
			watch(state)
		}

		// Asks for the status again while a run is active, such as a scheduled one, until it ends.
		function watch(state) {
			window.clearTimeout(pollTimer)
			pollTimer = 0
			if (!state.endsWith('running')) {
				polls = 0
				return
			}
			if (polls >= pollLimit) {
				return
			}
			pollTimer = window.setTimeout(async function() {
				polls++
				try {
					await refreshStatus()
				} catch (error) {
					watch(state)
				}
			}, pollInterval)
		}

		function showReport(result, reveal) {
			const labels = {
				exported: translate('Exported'), moved: translate('Moved'), movedToTrash: translate('Moved to trash'),
				permanentlyDeleted: translate('Deleted'), removedExcluded: translate('Excluded copies removed'), importsSubmitted: translate('Imports submitted'),
				importsSucceeded: translate('Imports succeeded'), importsFailed: translate('Imports failed'),
				foldersPruned: translate('Folders removed'), unchanged: translate('Unchanged'), skipped: translate('Skipped'), errors: translate('Errors'),
			}
			reportSummary.replaceChildren()
			Object.keys(labels).forEach(function(key) {
				const item = document.createElement('div')
				const value = document.createElement('strong')
				const label = document.createElement('span')
				value.textContent = result[key] || 0
				label.textContent = labels[key]
				item.append(value, label)
				reportSummary.append(item)
			})
			const ended = result.completedAt ? formatTime(result.completedAt) : ''
			if (result.dryRun) {
				setText(reportMeta, ended ? translate('Dry-run, finished {time}', { time: ended }) : translate('Dry-run, stopped before its end'))
			} else {
				setText(reportMeta, ended ? translate('Run, finished {time}', { time: ended }) : translate('Run, stopped before its end'))
			}
			setText(reportActions, Array.isArray(result.actions) && result.actions.length > 0 ? result.actions.join('\n') : translate('No file changes were required.'))
			const omitted = result.omittedActions || 0
			reportOmitted.hidden = omitted === 0
			setText(reportOmitted, omitted > 0 ? translatePlural('… and %n more change, which the list leaves out', '… and %n more changes, which the list leaves out', omitted) : '')
			report.hidden = false
			if (reveal) {
				report.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
			}
		}

		// How a run by hand ended, with the count of the errors that its report lists.
		function showResult(result, isDryRun) {
			const failures = Number(result.errors) || 0
			if (failures === 0) {
				showMessage(isDryRun ? translate('Dry-run completed. No files were changed.') : translate('Synchronization completed.'), 'success')
			} else if (isDryRun) {
				showMessage(translatePlural('Dry-run completed with %n error. No files were changed; see the report.', 'Dry-run completed with %n errors. No files were changed; see the report.', failures), 'error')
			} else {
				showMessage(translatePlural('Synchronization completed with %n error. See the report.', 'Synchronization completed with %n errors. See the report.', failures), 'error')
			}
		}

		async function runSync(isDryRun) {
			setBusy(true)
			showState(isDryRun ? 'dry-run-running' : 'running')
			showMessage(isDryRun ? translate('Calculating synchronization changes…') : translate('Synchronizing Paperless and Nextcloud…'), 'progress')
			let gateway = 0
			try {
				const result = await request(root.dataset.runUrl, 'POST', { dryRun: isDryRun })
				showReport(result, true)
				showResult(result, isDryRun)
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
			// The status shows how the run ended, also when it failed, or follows it while it continues.
			await refreshQuietly()
		}

		// A proxy or the web server answered for the app: it stopped waiting for a long run, while
		// PHP carries on with it, or PHP ended without an answer, such as when the web server
		// stopped it. The status tells which, and follows a run that continues.
		async function explainGateway(status) {
			let state = ''
			try {
				state = await refreshStatus()
			} catch (error) {
				// The status stays as it was, and the message says what is known.
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
		// and Refresh asks again.
		async function refreshQuietly() {
			try {
				await refreshStatus()
			} catch (error) {
				// The status stays as it was.
			}
		}

		form.addEventListener('submit', async function(event) {
			event.preventDefault()
			setBusy(true)
			// The server tests the connection when the schedule will run or a token was entered,
			// see SettingsController::save().
			const testing = schedule.checked || token.value.trim() !== ''
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
				// The schedule may have changed, and with it the next scheduled run.
				await refreshQuietly()
			}
		})

		// The checklist of the settings in the form, without saving them.
		test.addEventListener('click', async function() {
			setBusy(true)
			showMessage(translate('Testing Paperless and Nextcloud access…'), 'progress')
			try {
				const result = await request(root.dataset.testUrl, 'POST', snapshot())
				const counts = revealChecks(result.checks)
				if (counts.failed > 0) {
					showMessage(translatePlural('%n check failed. The connection checklist says what to do.', '%n checks failed. The connection checklist says what to do.', counts.failed), 'error')
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
				await refreshStatus()
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
				// Without the schedule, there is no next scheduled run.
				await refreshQuietly()
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
			savedSettings = JSON.stringify(settings())
			updateForm()
		}
		const status = initialState('status')
		if (status !== null) {
			updateStatus(status)
		}
	})
})()

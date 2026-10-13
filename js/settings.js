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
			return window.n('paperless_sync', singular, plural, count)
		}
		return (count === 1 ? singular : plural).replace('%n', String(count))
	}

	// The status that the server put into the page, as @nextcloud/initial-state reads it: JSON
	// in Base64, in a hidden input.
	function initialStatus() {
		const input = document.getElementById('initial-state-paperless_sync-status')
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

	async function request(url, method, body, confirmed = false) {
		const response = await window.fetch(url, {
			method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: window.OC.requestToken,
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const text = await response.text()
		let payload = {}
		if (text !== '') {
			try {
				payload = JSON.parse(text)
			} catch (error) {
				payload = { message: text }
			}
		}
		if (response.status === 403 && payload.message === PASSWORD_CONFIRMATION_REQUIRED && !confirmed) {
			await confirmPassword()
			return request(url, method, body, true)
		}
		if (!response.ok) {
			const error = new Error(payload.message || translate('The request failed.'))
			// The setting that the message of the server names as the way out, if any.
			error.setting = payload.setting
			throw error
		}
		return payload
	}

	document.addEventListener('DOMContentLoaded', function() {
		const root = document.getElementById('paperless-sync-settings')
		if (!root) {
			return
		}
		const form = document.getElementById('paperless-sync-form')
		const paperlessUrl = document.getElementById('paperless-sync-paperless-url')
		const token = document.getElementById('paperless-sync-token')
		const save = document.getElementById('paperless-sync-save')
		const dryRun = document.getElementById('paperless-sync-dry-run')
		const run = document.getElementById('paperless-sync-run')
		const reset = document.getElementById('paperless-sync-reset')
		const refresh = document.getElementById('paperless-sync-refresh-status')
		const message = document.getElementById('paperless-sync-message')
		const report = document.getElementById('paperless-sync-report')
		const reportSummary = document.getElementById('paperless-sync-report-summary')
		const reportActions = document.getElementById('paperless-sync-report-actions')
		const reportMeta = document.getElementById('paperless-sync-report-meta')
		const reportOmitted = document.getElementById('paperless-sync-report-omitted')
		const lastState = document.getElementById('paperless-sync-last-state')
		const running = document.getElementById('paperless-sync-running')
		const lastEnded = document.getElementById('paperless-sync-last-ended')
		const lastSuccess = document.getElementById('paperless-sync-last-success')
		const nextRun = document.getElementById('paperless-sync-next-run')
		const errors = document.getElementById('paperless-sync-errors')
		const errorsTitle = document.getElementById('paperless-sync-errors-title')
		const errorsList = document.getElementById('paperless-sync-errors-list')
		const buttons = [save, dryRun, run, reset, refresh]
		// While a run is active, the page asks for its status every five seconds, at most five minutes long.
		const pollInterval = 5000
		const pollLimit = 60
		let pollTimer = 0
		let polls = 0

		function settings() {
			const values = {}
			root.querySelectorAll('[data-setting]').forEach(function(input) {
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

		// The URL as the server compares it with the stored one.
		function normalizeUrl(url) {
			return url.trim().replace(/\/+$/, '')
		}

		// The URL that the stored token belongs to. The server sends the stored token only there, so
		// another URL needs the token again.
		let savedUrl = normalizeUrl(paperlessUrl.defaultValue)

		function updateToken() {
			const configured = root.dataset.tokenConfigured === 'true'
			const newUrl = configured && normalizeUrl(paperlessUrl.value) !== savedUrl
			token.required = !configured || newUrl
			if (!configured) {
				token.placeholder = translate('Required')
			} else if (newUrl) {
				token.placeholder = translate('Required again for a new URL')
			} else {
				token.placeholder = translate('Configured — leave blank to keep it')
			}
		}

		function setBusy(busy) {
			buttons.forEach(function(button) {
				button.disabled = busy
			})
		}

		function showMessage(text, isError) {
			message.textContent = text
			message.classList.toggle('paperless-sync-message--error', isError)
			message.classList.toggle('paperless-sync-message--success', !isError && text !== '')
		}

		// Moves the focus to the setting that the message names, such as "Paperless runs in my local
		// network", so that it is one key press away; a screen reader reads its hint with it.
		function focusSetting(error) {
			const input = error.setting ? root.querySelector('[data-setting="' + window.CSS.escape(error.setting) + '"]') : null
			if (input) {
				input.focus()
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
		function setText(element, text) {
			if (element.textContent !== text) {
				element.textContent = text
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

		async function runSync(isDryRun) {
			setBusy(true)
			showState(isDryRun ? 'dry-run-running' : 'running')
			showMessage(isDryRun ? translate('Calculating synchronization changes…') : translate('Synchronizing Paperless and Nextcloud…'), false)
			try {
				const result = await request(root.dataset.runUrl, 'POST', { dryRun: isDryRun })
				showReport(result, true)
				showMessage(isDryRun ? translate('Dry-run completed. No files were changed.') : translate('Synchronization completed.'), result.errors > 0)
			} catch (error) {
				showMessage(error.message || translate('Synchronization failed.'), true)
				focusSetting(error)
			} finally {
				setBusy(false)
			}
			try {
				await refreshStatus()
			} catch (error) {
				// The status stays as it was; Refresh asks again.
			}
		}

		async function refreshStatus() {
			const status = await request(root.dataset.statusUrl, 'GET')
			updateStatus(status)
		}

		form.addEventListener('submit', async function(event) {
			event.preventDefault()
			setBusy(true)
			showMessage(translate('Testing Paperless and Nextcloud access…'), false)
			try {
				const config = await request(root.dataset.saveUrl, 'POST', { settings: settings(), token: token.value })
				token.value = ''
				root.dataset.tokenConfigured = config.tokenConfigured ? 'true' : 'false'
				savedUrl = normalizeUrl(config.paperlessUrl)
				updateToken()
				showMessage(translate('Connection successful. Configuration saved.'), false)
				// The schedule may have changed, and with it the next scheduled run.
				await refreshStatus()
			} catch (error) {
				showMessage(error.message || translate('Could not save the configuration.'), true)
				focusSetting(error)
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
				showMessage(translate('Status refreshed.'), false)
			} catch (error) {
				showMessage(error.message || translate('Could not refresh status.'), true)
			} finally {
				setBusy(false)
			}
		})
		reset.addEventListener('click', async function() {
			if (!window.confirm(translate('Disconnect Paperless and delete the stored API token? Existing synchronized files and state remain untouched.'))) {
				return
			}
			setBusy(true)
			try {
				await request(root.dataset.resetUrl, 'DELETE')
				token.value = ''
				root.dataset.tokenConfigured = 'false'
				savedUrl = ''
				updateToken()
				root.querySelector('[data-setting="enabled"]').checked = false
				showMessage(translate('Paperless disconnected. Existing files were not changed.'), false)
			} catch (error) {
				showMessage(error.message || translate('Could not disconnect Paperless.'), true)
			} finally {
				setBusy(false)
			}
		})

		paperlessUrl.addEventListener('input', updateToken)
		updateToken()
		const status = initialStatus()
		if (status !== null) {
			updateStatus(status)
		}
	})
})()

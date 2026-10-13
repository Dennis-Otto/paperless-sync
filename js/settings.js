/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

(function() {
	'use strict'

	function translate(text) {
		return window.t ? window.t('paperless_sync', text) : text
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
			throw new Error(payload.message || translate('The request failed.'))
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
		const lastState = document.getElementById('paperless-sync-last-state')
		const lastCompleted = document.getElementById('paperless-sync-last-completed')
		const buttons = [save, dryRun, run, reset, refresh]

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

		function formatTime(timestamp) {
			if (!timestamp) {
				return '—'
			}
			return new Date(timestamp * 1000).toLocaleString()
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

		function updateStatus(status) {
			const state = status.state || 'never-run'
			lastState.textContent = stateLabels[state] || state
			lastState.dataset.state = state
			lastCompleted.textContent = formatTime(status.lastCompleted)
			lastCompleted.dataset.timestamp = status.lastCompleted || 0
			if (status.error) {
				showMessage(status.error, true)
			}
		}

		function showReport(result) {
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
			reportActions.textContent = Array.isArray(result.actions) && result.actions.length > 0 ? result.actions.join('\n') : translate('No file changes were required.')
			report.hidden = false
			report.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
		}

		async function runSync(isDryRun) {
			setBusy(true)
			showMessage(isDryRun ? translate('Calculating synchronization changes…') : translate('Synchronizing Paperless and Nextcloud…'), false)
			try {
				const result = await request(root.dataset.runUrl, 'POST', { dryRun: isDryRun })
				showReport(result)
				showMessage(isDryRun ? translate('Dry-run completed. No files were changed.') : translate('Synchronization completed.'), result.errors > 0)
				await refreshStatus()
			} catch (error) {
				showMessage(error.message || translate('Synchronization failed.'), true)
			} finally {
				setBusy(false)
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
			} catch (error) {
				showMessage(error.message || translate('Could not save the configuration.'), true)
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
		lastCompleted.textContent = formatTime(Number.parseInt(lastCompleted.dataset.timestamp, 10))
	})
})()

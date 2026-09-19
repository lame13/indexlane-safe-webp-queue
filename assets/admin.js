(function ($) {
	'use strict';

	var rows = [];
	var rowMap = {};
	var isBusy = false;
	var activeFilter = 'all';
	var searchQuery = '';
	var pauseRequested = false;
	var stopRequested = false;
	var resumeAction = null;
	var foregroundCanPause = false;
	var queueStatus = ILSWQ_Admin.queue || null;
	var queueRequestRunning = false;
	var queueTimer = null;
	var browserConfig = ILSWQ_Admin.browser || null;
	var browserEnabled = !!(browserConfig && browserConfig.enabled);
	// wp_localize_script casts top-level scalars to strings, so compare explicitly.
	var serverWriter = ILSWQ_Admin.serverWriter === true || ILSWQ_Admin.serverWriter === '1';
	var browserSupport = null;
	var browserRunning = false;
	var browserFinished = false;
	var browserStopped = false;
	var browserStopRequested = false;
	var browserController = null;
	var browserTotals = null;
	var browserRowIds = [];
	var BROWSER_BATCH_LIMIT = 500;
	var BROWSER_LOG_LIMIT = 200;

	function getSettings() {
		var $form = $('#ilswq-settings-form');

		return {
			batch_size: parseInt($form.find('[name="batch_size"]').val(), 10) || 3,
			max_pixels: parseInt($form.find('[name="max_pixels"]').val(), 10) || 16000000,
			jpeg_quality: parseInt($form.find('[name="jpeg_quality"]').val(), 10) || 82,
			png_quality: parseInt($form.find('[name="png_quality"]').val(), 10) || 90,
			skip_larger: $form.find('[name="skip_larger"]').is(':checked') ? 1 : 0,
			serve_webp: $form.find('[name="serve_webp"]').is(':checked') ? 1 : 0,
			auto_uploads: $form.find('[name="auto_uploads"]').is(':checked') ? 1 : 0,
			browser_conversion: $form.find('[name="browser_conversion"]').is(':checked') ? 1 : 0
		};
	}

	function setBusy(nextBusy, canPause) {
		isBusy = nextBusy;
		foregroundCanPause = nextBusy && !!canPause;
		$('#ilswq-scan, #ilswq-convert, #ilswq-library, #ilswq-validate-webp, #ilswq-export, #ilswq-cleanup, #ilswq-totals-rebuild, #ilswq-settings-form button').prop('disabled', nextBusy);
		$('#ilswq-pause, #ilswq-stop').prop('disabled', !foregroundCanPause);
		updateButtons();
	}

	function updateButtons() {
		var hasRows = rows.some(matchesReportRow);
		var $eligible = visibleEligibleCheckboxes();
		var selectedCount = $eligible.filter(':checked').length;
		var hasEligible = selectedCount > 0;
		var hasConverted = rows.some(function (row) {
			return (row.generated_source_count || 0) > 0;
		});
		var hasActiveJob = queueStatus && queueStatus.can_cancel;
		var hasQueuedFileWork = hasActiveJob || (queueStatus && queueStatus.automatic_pending > 0);

		$('#ilswq-export').prop('disabled', isBusy || !hasRows);
		$('#ilswq-convert').prop('disabled', isBusy || hasActiveJob || !hasEligible || !serverWriter);
		$('#ilswq-validate-webp').prop('disabled', isBusy || !hasConverted);
		$('#ilswq-browser-start').prop('disabled', browserStartDisabled());
		$('#ilswq-browser-stop').prop('disabled', !browserRunning);
		$('#ilswq-check-all')
			.prop('disabled', isBusy || !$eligible.length)
			.prop('checked', $eligible.length > 0 && selectedCount === $eligible.length)
			.prop('indeterminate', selectedCount > 0 && selectedCount < $eligible.length);
		$('#ilswq-resume').prop('disabled', isBusy || !resumeAction);
		$('#ilswq-scan').prop('disabled', isBusy || hasActiveJob);
		$('#ilswq-library').prop('disabled', isBusy || hasActiveJob || !serverWriter);
		$('#ilswq-cleanup').prop('disabled', isBusy || hasQueuedFileWork);
		$('#ilswq-totals-rebuild').prop('disabled', isBusy || hasQueuedFileWork);
		$('#ilswq-queue-pause').prop('disabled', isBusy || !queueStatus || !queueStatus.can_pause);
		$('#ilswq-queue-resume').prop('disabled', isBusy || !queueStatus || !queueStatus.can_resume);
		$('#ilswq-queue-cancel').prop('disabled', isBusy || !queueStatus || !queueStatus.can_cancel);
		$('#ilswq-queue-retry').prop('disabled', isBusy || !queueStatus || !queueStatus.can_retry);
		renderBrowserState();
	}

	function showNotice(message, type) {
		var $notice = $('#ilswq-notice');
		$notice.removeClass('is-error is-success').addClass(type ? 'is-' + type : '');
		$notice.text(message).prop('hidden', false);
	}

	function clearNotice() {
		$('#ilswq-notice').prop('hidden', true).text('');
	}

	function setProgress(message, current, total) {
		var percent = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;
		$('#ilswq-progress').prop('hidden', false);
		$('#ilswq-progress .ilswq-progress-bar span').css('width', percent + '%');
		$('#ilswq-progress p').text(message);
	}

	function hideProgress() {
		$('#ilswq-progress').prop('hidden', true);
		$('#ilswq-progress .ilswq-progress-bar span').css('width', '0');
		$('#ilswq-progress p').text('');
	}

	function escapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function csvEscape(value) {
		var stringValue = String(value === null || value === undefined ? '' : value);
		if (/^(?:[=+\-@\t\r]|\s+[=+\-@])/.test(stringValue)) {
			stringValue = "'" + stringValue;
		}

		if (/[",\r\n]/.test(stringValue)) {
			return '"' + stringValue.replace(/"/g, '""') + '"';
		}

		return stringValue;
	}

	function formatString(template, values) {
		var nextIndex = 0;
		return String(template).replace(/%(?:(\d+)\$)?[sd]/g, function (_match, position) {
			var index = position ? parseInt(position, 10) - 1 : nextIndex++;
			return values[index] === undefined || values[index] === null ? '' : String(values[index]);
		});
	}

	function setOptionalText(selector, value) {
		$(selector).text(value || '').prop('hidden', !value);
	}

	function renderTotals(totals) {
		if (!totals || !totals.labels) {
			return;
		}

		$('#ilswq-total-files').text(totals.labels.files || '0');
		$('#ilswq-total-source').text(totals.labels.source || '');
		$('#ilswq-total-webp').text(totals.labels.webp || '');
		$('#ilswq-total-saved').text(totals.labels.saved || '');
		$('#ilswq-total-percent').text(totals.is_empty ? '' : (totals.labels.percent || ''));
		$('#ilswq-totals-updated').prop('hidden', !totals.labels.updated);
		$('#ilswq-totals-updated-value').text(totals.labels.updated || '');
	}

	function renderQueueStatus(status, announceCompletion) {
		var previousState = queueStatus && queueStatus.state;
		queueStatus = status || {
			exists: false,
			state: 'none',
			state_label: '',
			progress: 0,
			automatic_pending: 0,
			automatic_failed: 0
		};

		var state = /^[a-z-]+$/.test(queueStatus.state || '') ? queueStatus.state : 'none';
		var progress = Math.max(0, Math.min(100, parseInt(queueStatus.progress, 10) || 0));
		var automaticPending = parseInt(queueStatus.automatic_pending, 10) || 0;
		var automaticFailed = parseInt(queueStatus.automatic_failed, 10) || 0;
		var automaticMessage = '';
		var automaticFailureMessage = '';

		if (automaticPending === 1) {
			automaticMessage = formatString(ILSWQ_Admin.strings.automaticPendingOne, [automaticPending]);
		} else if (automaticPending > 1) {
			automaticMessage = formatString(ILSWQ_Admin.strings.automaticPendingMany, [automaticPending]);
		}

		if (automaticFailed === 1) {
			automaticFailureMessage = formatString(ILSWQ_Admin.strings.automaticFailedOne, [automaticFailed]);
		} else if (automaticFailed > 1) {
			automaticFailureMessage = formatString(ILSWQ_Admin.strings.automaticFailedMany, [automaticFailed]);
		}

		$('#ilswq-queue-state')
			.removeClass()
			.addClass('ilswq-status is-' + state)
			.text(queueStatus.state_label || '');
		$('#ilswq-queue-progress')
			.attr('aria-valuenow', progress)
			.find('span')
			.css('width', progress + '%');
		$('#ilswq-queue-summary').text(queueStatus.summary || '');
		setOptionalText('#ilswq-queue-settings', queueStatus.settings_summary || '');
		setOptionalText(
			'#ilswq-queue-activity',
			queueStatus.last_activity_label
				? formatString(ILSWQ_Admin.strings.queueLastActivity, [queueStatus.last_activity_label])
				: ''
		);
		setOptionalText('#ilswq-queue-error', queueStatus.last_error || '');
		setOptionalText('#ilswq-auto-pending', automaticMessage);
		setOptionalText('#ilswq-auto-failed', automaticFailureMessage);
		$('#ilswq-queue-scope').prop('hidden', !queueStatus.scope_label);
		$('#ilswq-queue-scope-value').text(queueStatus.scope_label || '');
		renderTotals(queueStatus.totals);

		if (announceCompletion && ['queued', 'running'].indexOf(previousState) !== -1 && queueStatus.state === 'completed') {
			if ((parseInt(queueStatus.failed, 10) || 0) > 0) {
				showNotice(
					formatString(ILSWQ_Admin.strings.queueCompleteWithFailures, [queueStatus.failed]),
					'error'
				);
			} else if ((parseInt(queueStatus.conflicts, 10) || 0) > 0) {
				showNotice(ILSWQ_Admin.strings.queueConflictComplete, 'error');
			} else {
				showNotice(ILSWQ_Admin.strings.queueComplete, 'success');
			}
		}
		updateButtons();
	}

	function clearQueueTimer() {
		if (queueTimer) {
			window.clearTimeout(queueTimer);
			queueTimer = null;
		}
	}

	function scheduleQueueTick(delay) {
		clearQueueTimer();
		if (browserRunning) {
			return;
		}
		if (!queueStatus || (!queueStatus.has_runnable_work && !queueStatus.automatic_pending)) {
			return;
		}

		queueTimer = window.setTimeout(processQueueTick, delay);
	}

	function processQueueTick() {
		if (queueRequestRunning || !queueStatus || browserRunning) {
			return;
		}

		if (!queueStatus.has_runnable_work) {
			refreshQueueStatus(5000);
			return;
		}

		queueRequestRunning = true;
		var nextDelay = 5000;
		ajax('ilswq_queue_process', {}).then(function (data) {
			renderQueueStatus(data.queue || null, true);
			if (data.rows && data.rows.length) {
				upsertRows(data.rows);
			}
			if (data.error) {
				showNotice(data.error, 'error');
			}
			nextDelay = data.busy ? 5000 : (queueStatus && queueStatus.has_runnable_work ? 150 : 5000);
		}).fail(function (message) {
			showAjaxError(message);
		}).always(function () {
			queueRequestRunning = false;
			scheduleQueueTick(nextDelay);
		});
	}

	function refreshQueueStatus(nextDelay) {
		ajax('ilswq_queue_status', {}).then(function (data) {
			renderQueueStatus(data.queue || null, true);
			scheduleQueueTick(queueStatus && queueStatus.has_runnable_work ? 150 : (nextDelay || 5000));
		}).fail(function (message) {
			showAjaxError(message);
			scheduleQueueTick(nextDelay || 5000);
		});
	}

	function sendQueueCommand(command) {
		clearNotice();
		clearQueueTimer();
		setBusy(true);
		ajax('ilswq_queue_command', { command: command }).then(function (data) {
			renderQueueStatus(data.queue || null);
			scheduleQueueTick(100);
		}).fail(showAjaxError).always(function () {
			setBusy(false);
		});
	}

	function resetRows() {
		rows = [];
		rowMap = {};
		$('#ilswq-results-body').empty();
		updateCounts();
		applyFilter();
	}

	function renderEmptyRow(message) {
		return '<tr class="ilswq-empty-row"><td colspan="12">' + escapeHtml(message || ILSWQ_Admin.strings.noRows) + '</td></tr>';
	}

	function upsertRows(newRows) {
		$.each(newRows, function (_, row) {
			upsertRow(row);
		});
		updateCounts();
		applyFilter();
	}

	function upsertRow(row) {
		var existingIndex = rowMap[row.id];
		var $existing = $('#ilswq-row-' + row.id);

		if (existingIndex === undefined) {
			rowMap[row.id] = rows.length;
			rows.push(row);
			$('#ilswq-results-body').append(renderRow(row));
			return;
		}

		rows[existingIndex] = row;
		if ($existing.length) {
			$existing.replaceWith(renderRow(row));
		}
	}

	function renderRow(row) {
		var selectionLabel = formatString(
			ILSWQ_Admin.strings.selectAttachment,
			[row.title || row.file || ('#' + row.id)]
		);
		var checkbox = row.eligible
			? '<input type="checkbox" class="ilswq-row-check" value="' + escapeHtml(row.id) + '" checked aria-label="' + escapeHtml(selectionLabel) + '">'
			: '';
		var attachment = '<strong>#' + escapeHtml(row.id) + '</strong>';
		var file = escapeHtml(row.file);

		if (row.edit_url) {
			attachment += ' <a href="' + escapeHtml(row.edit_url) + '">' + escapeHtml(row.title) + '</a>';
		} else {
			attachment += ' ' + escapeHtml(row.title);
		}

		if (row.source_count_label) {
			file += '<br><span class="ilswq-muted">' + escapeHtml(row.source_count_label) + '</span>';
		}

		return [
			'<tr id="ilswq-row-' + escapeHtml(row.id) + '" data-ilswq-status="' + escapeHtml(row.status_key) + '">',
			'<th scope="row" class="check-column">' + checkbox + '</th>',
			'<td>' + attachment + '</td>',
			'<td>' + file + '</td>',
			'<td>' + escapeHtml(row.type) + '</td>',
			'<td>' + escapeHtml(row.dimensions) + '</td>',
			'<td>' + escapeHtml(row.original_size_label) + '</td>',
			'<td>' + escapeHtml(row.estimated_memory_label) + '</td>',
			'<td>' + escapeHtml(row.webp_size_label) + '</td>',
			'<td>' + escapeHtml(row.savings) + '</td>',
			'<td>' + escapeHtml(row.editor) + '</td>',
			'<td><span class="ilswq-status is-' + escapeHtml(row.status_key) + '">' + escapeHtml(row.status) + '</span></td>',
			'<td>' + escapeHtml(row.reason) + '</td>',
			'</tr>'
		].join('');
	}

	function updateCounts() {
		var counts = {
			total: rows.length,
			eligible: 0,
			converted: 0,
			skipped: 0,
			failed: 0,
			needsReview: 0,
			conflict: 0,
			excluded: 0
		};

		$.each(rows, function (_, row) {
			if (row.eligible) {
				counts.eligible++;
			}
			if (row.status_key === 'converted') {
				counts.converted++;
			}
			if (row.status_key === 'skipped' || row.status_key === 'already-exists') {
				counts.skipped++;
			}
			if (row.status_key === 'failed') {
				counts.failed++;
			}
			if (row.status_key === 'needs-review') {
				counts.needsReview++;
			}
			if (row.status_key === 'conflict') {
				counts.conflict++;
			}
			if (row.status_key === 'excluded') {
				counts.excluded++;
			}
		});

		$('#ilswq-count-total').text(counts.total);
		$('#ilswq-count-eligible').text(counts.eligible);
		$('#ilswq-count-converted').text(counts.converted);
		$('#ilswq-count-skipped').text(counts.skipped);
		$('#ilswq-count-failed').text(counts.failed);
		$('#ilswq-count-needs-review').text(counts.needsReview);
		$('#ilswq-count-conflict').text(counts.conflict);
		$('#ilswq-count-excluded').text(counts.excluded);
	}

	function visibleEligibleCheckboxes() {
		return $('.ilswq-row-check').filter(function () {
			var row = rows[rowMap[this.value]];
			return row && row.eligible && matchesReportRow(row);
		});
	}

	function getSelectedEligibleIds() {
		return visibleEligibleCheckboxes().filter(':checked').map(function () {
			return parseInt(this.value, 10);
		}).get();
	}

	function generatedAttachmentIds() {
		return rows.filter(function (row) {
			return (row.generated_source_count || 0) > 0;
		}).map(function (row) {
			return parseInt(row.id, 10);
		}).filter(function (id) {
			return id > 0;
		});
	}

	function matchesReportRow(row) {
		var matchesStatus = activeFilter === 'all' || row.status_key === activeFilter;
		if (activeFilter === 'eligible') {
			matchesStatus = !!row.eligible;
		} else if (activeFilter === 'skipped') {
			matchesStatus = row.status_key === 'skipped' || row.status_key === 'already-exists';
		}

		return matchesStatus && (!searchQuery || [row.title, row.file, row.id].some(function (value) {
			return String(value === null || value === undefined ? '' : value).toLowerCase().indexOf(searchQuery) !== -1;
		}));
	}

	function applyFilter() {
		var visibleCount = 0;
		$('.ilswq-empty-row').remove();
		$.each(rows, function (_, row) {
			var visible = matchesReportRow(row);
			$('#ilswq-row-' + row.id).toggle(visible);
			if (visible) {
				visibleCount++;
			}
		});
		if (!visibleCount) {
			$('#ilswq-results-body').append(renderEmptyRow(rows.length ? ILSWQ_Admin.strings.noMatches : ILSWQ_Admin.strings.noRows));
		}
		$('#ilswq-report-count')
			.text(formatString(ILSWQ_Admin.strings.reportCount, [visibleCount, rows.length]))
			.prop('hidden', !rows.length);
		$('#ilswq-search-clear').prop('disabled', !$('#ilswq-search').val());
		updateButtons();
	}

	function prepareQueueRun() {
		pauseRequested = false;
		stopRequested = false;
		resumeAction = null;
		updateButtons();
	}

	function ajax(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = ILSWQ_Admin.nonce;

		return $.post(ILSWQ_Admin.ajaxUrl, data).then(function (response) {
			if (!response || !response.success) {
				var message = response && response.data && response.data.message ? response.data.message : ILSWQ_Admin.strings.requestFailed;
				return $.Deferred().reject(message).promise();
			}

			return response.data;
		});
	}

	function scanPage(page, scanned, knownTotal) {
		if (stopRequested) {
			return $.Deferred().resolve({ stopped: true }).promise();
		}

		if (pauseRequested) {
			resumeAction = function () {
				clearNotice();
				pauseRequested = false;
				setBusy(true, true);
				scanPage(page, scanned, knownTotal).then(finishScan).fail(showAjaxError).always(finishBusy);
			};
			return $.Deferred().resolve({ paused: true }).promise();
		}

		setProgress(ILSWQ_Admin.strings.scanning, scanned, knownTotal || 0);

		return ajax('ilswq_scan', {
			page: page,
			settings: getSettings()
		}).then(function (data) {
			var newScanned = scanned + data.rows.length;
			var total = data.total || newScanned;

			upsertRows(data.rows);
			setProgress(ILSWQ_Admin.strings.scanning, newScanned, total);

			if (data.hasMore) {
				return scanPage(data.nextPage, newScanned, total);
			}

			return data;
		});
	}

	function cleanupQueue(totalDeleted, totalFailed, reset) {
		setProgress(ILSWQ_Admin.strings.cleanupRunning, totalDeleted + totalFailed, 0);

		return ajax('ilswq_cleanup', {
			reset: reset ? 1 : 0
		}).then(function (data) {
			totalDeleted += data.deleted || 0;
			totalFailed += data.failed || 0;
			setProgress(ILSWQ_Admin.strings.cleanupRunning, totalDeleted + totalFailed, 0);

			if (data.hasMore) {
				return cleanupQueue(totalDeleted, totalFailed, false);
			}

			return {
				deleted: totalDeleted,
				failed: totalFailed
			};
		});
	}

	function validateQueue(ids, processed, total, result) {
		var batch = ids.splice(0, 10);

		if (!batch.length) {
			return $.Deferred().resolve(result).promise();
		}

		setProgress(ILSWQ_Admin.strings.validationRunning, processed, total);

		return ajax('ilswq_validate_webp', {
			ids: batch
		}).then(function (data) {
			result.validated += data.validated || 0;
			result.invalid += data.invalid || 0;
			result.missing += data.missing || 0;
			processed += batch.length;
			setProgress(ILSWQ_Admin.strings.validationRunning, processed, total);

			return validateQueue(ids, processed, total, result);
		});
	}

	function rebuildTotals(restart, processed) {
		setProgress(ILSWQ_Admin.strings.totalsRebuilding, processed, 0);

		return ajax('ilswq_totals_rebuild', {
			restart: restart ? 1 : 0
		}).then(function (data) {
			if (data.totals) {
				renderTotals(data.totals);
			}

			if (!data.done) {
				return rebuildTotals(false, data.processed || 0);
			}

			return data;
		});
	}

	function finishBusy() {
		hideProgress();
		setBusy(false);
	}

	function showAjaxError(message) {
		showNotice(message, 'error');
	}

	function finishScan(result) {
		if (result && result.paused) {
			showNotice(ILSWQ_Admin.strings.paused, 'success');
			return;
		}

		if (result && result.stopped) {
			showNotice(ILSWQ_Admin.strings.stopped, 'success');
			return;
		}

		if (!rows.length) {
			$('#ilswq-results-body').html(renderEmptyRow());
		}
		showNotice(ILSWQ_Admin.strings.scanComplete, 'success');
	}

	function exportCsv() {
		var visibleRows = rows.filter(matchesReportRow);
		if (!visibleRows.length) {
			showNotice(rows.length ? ILSWQ_Admin.strings.noMatches : ILSWQ_Admin.strings.noRows, 'error');
			return;
		}

		var headers = ILSWQ_Admin.csvHeaders;
		var lines = [headers.map(csvEscape).join(',')];

		$.each(visibleRows, function (_, row) {
			lines.push([
				row.id,
				row.title,
				row.file,
				row.source_count || '',
				row.mime_type,
				row.dimensions,
				row.original_size_label,
				row.estimated_memory_label,
				row.webp_size_label,
				row.savings,
				row.editor,
				row.status,
				row.reason
			].map(csvEscape).join(','));
		});

		var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
		var url = window.URL.createObjectURL(blob);
		var link = document.createElement('a');
		var date = new Date().toISOString().slice(0, 10);

		link.href = url;
		link.download = 'safe-webp-queue-report-' + date + '.csv';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.URL.revokeObjectURL(url);
	}

	/**
	 * Report which browser features the WebAssembly encoder needs.
	 */
	function detectBrowserSupport() {
		var missing = [];

		if (!window.isSecureContext) {
			return { ok: false, insecure: true, missing: [] };
		}

		if (typeof window.Worker !== 'function') {
			missing.push('Web Workers');
		}

		if (typeof window.WebAssembly === 'undefined') {
			missing.push('WebAssembly');
		} else {
			try {
				// Compiling an empty module proves the policy actually allows WASM.
				new window.WebAssembly.Module(new Uint8Array([0, 97, 115, 109, 1, 0, 0, 0]));
			} catch (error) {
				missing.push('WebAssembly (blocked by this site\'s Content Security Policy)');
			}
		}

		if (typeof window.createImageBitmap !== 'function') {
			missing.push('createImageBitmap');
		}

		if (typeof window.OffscreenCanvas !== 'function') {
			missing.push('OffscreenCanvas');
		}

		if (!window.crypto || !window.crypto.subtle) {
			missing.push('Web Crypto');
		}
		if (!window.crypto || typeof window.crypto.randomUUID !== 'function') {
			missing.push('crypto.randomUUID');
		}

		if (typeof window.fetch !== 'function') {
			missing.push('fetch');
		}

		if (typeof window.AbortController !== 'function') {
			missing.push('AbortController');
		}
		if (!window.AbortSignal || typeof window.AbortSignal.prototype.throwIfAborted !== 'function') {
			missing.push('AbortSignal.throwIfAborted');
		}

		return { ok: missing.length === 0, insecure: false, missing: missing };
	}

	function initializeBrowserSupport() {
		browserSupport = browserEnabled ? detectBrowserSupport() : { ok: false, insecure: false, missing: [] };
		if (!browserSupport.ok) {
			return;
		}
		browserSupport.ok = false;
		browserSupport.pending = true;
		import(browserConfig.bundleUrl).then(function (module) {
			return module.probeBrowserEncoder();
		}).then(function () {
			browserSupport = { ok: true, insecure: false, missing: [] };
			updateButtons();
		}).catch(function (error) {
			browserSupport = { ok: false, insecure: false, missing: [error.message || 'Module worker / WebAssembly encoder'] };
			updateButtons();
		});
	}

	/**
	 * Format a byte count for the browser conversion log.
	 */
	function formatBytes(bytes) {
		var units = ['B', 'KB', 'MB', 'GB'];
		var value = Math.max(0, parseInt(bytes, 10) || 0);
		var index = 0;

		while (value >= 1024 && index < units.length - 1) {
			value = value / 1024;
			index++;
		}

		return (value >= 10 || index === 0 ? Math.round(value) : Math.round(value * 10) / 10) + ' ' + units[index];
	}

	/**
	 * Build the browser conversion list from the checked report rows.
	 */
	function browserSelection() {
		var variants = [];
		var seen = {};

		$.each(getSelectedEligibleIds(), function (_, attachmentId) {
			var row = rows[rowMap[attachmentId]];
			var sources = row && row.browser_sources ? row.browser_sources : [];

			$.each(sources, function (__, source) {
				var name = source && source.name ? String(source.name) : '';
				var key = attachmentId + ':' + name;

				if (!name || seen[key]) {
					return;
				}

				seen[key] = true;
				variants.push({
					attachmentId: parseInt(attachmentId, 10),
					sizeKey: name,
					file: row.file || '',
					label: source.label || name,
					bytes: parseInt(source.bytes, 10) || 0
				});
			});
		});

		return variants;
	}

	function describeVariant(variant) {
		var file = variant && variant.file ? String(variant.file) : '';
		var label = variant && variant.label ? String(variant.label) : '';
		var name = variant && variant.sizeKey ? String(variant.sizeKey) : '';
		var suffix = label && label !== 'full' ? label : name;

		if (file && suffix) {
			return file + ' (' + suffix + ')';
		}

		return file || suffix || '';
	}

	function browserStartDisabled() {
		return isBusy ||
			browserRunning ||
			!browserEnabled ||
			!browserSupport ||
			!browserSupport.ok ||
			!!(queueStatus && queueStatus.can_pause) ||
			!browserSelection().length;
	}

	function setBrowserProgress(done, total, message) {
		var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;

		$('#ilswq-browser-progress').prop('hidden', false);
		$('#ilswq-browser-progress .ilswq-browser-progress-bar span').css('width', percent + '%');
		$('#ilswq-browser-progress .ilswq-browser-progress-bar').attr('aria-valuenow', percent);
		$('#ilswq-browser-progress-text').text(message || '');
	}

	function appendBrowserLog(message, statusKey) {
		var $log = $('#ilswq-browser-log');

		$log.prop('hidden', false);
		$log.append($('<li class="ilswq-browser-log-item is-' + statusKey + '"></li>').text(message));

		while ($log.children().length > BROWSER_LOG_LIMIT) {
			$log.children().first().remove();
		}

		if ($log.length && $log[0]) {
			$log[0].scrollTop = $log[0].scrollHeight;
		}
	}

	function renderBrowserState() {
		var strings = ILSWQ_Admin.strings;
		var $state = $('#ilswq-browser-state');
		var selectionCount = browserEnabled ? browserSelection().length : 0;
		var state = 'is-skipped';
		var label = strings.browserStateOff;
		var note = '';

		if (browserEnabled) {
			state = 'is-eligible';
			label = strings.browserStateReady;
			note = selectionCount
				? formatString(strings.browserSelectedCount, [selectionCount])
				: strings.browserSelectedNone;
		}

		if (browserEnabled && browserSupport && browserSupport.pending) {
			label = strings.browserStateChecking;
			note = strings.browserChecking;
		} else if (browserEnabled && browserSupport && !browserSupport.ok) {
			state = 'is-failed';
			label = strings.browserStateUnavailable;
			note = browserSupport.insecure
				? strings.browserInsecureNote
				: formatString(strings.browserUnsupportedNote, [browserSupport.missing.join(', ')]);
		} else if (browserRunning) {
			state = 'is-running';
			label = strings.browserStateRunning;
		} else if (browserFinished) {
			if (browserStopped) {
				state = 'is-paused';
				label = strings.browserStateStopped;
			} else {
				state = 'is-completed';
				label = strings.browserStateFinished;
			}
		}
		if (browserEnabled && browserSupport && browserSupport.ok && queueStatus && queueStatus.can_pause) {
			note = strings.browserQueueBusy;
		}

		if (!browserEnabled) {
			note = strings.browserOffNote;
		}

		$state.removeClass().addClass('ilswq-status ' + state).text(label);
		setOptionalText('#ilswq-browser-support', note);
		$('#ilswq-browser-start')
			.text(selectionCount
				? formatString(strings.browserStartCount, [selectionCount])
				: strings.browserStart)
			.prop('disabled', browserStartDisabled());
		$('#ilswq-browser-stop')
			.text(strings.browserStop)
			.prop('disabled', !browserRunning);
	}

	function recordBrowserOutcome(outcome, fallbackVariant) {
		var strings = ILSWQ_Admin.strings;
		var variant = fallbackVariant || (outcome && outcome.variant) || {};
		var entry = describeVariant(variant);
		var result = (outcome && outcome.result) || {};
		var inputBytes = parseInt(result.inputBytes, 10) || 0;
		var outputBytes = parseInt(result.outputBytes, 10) || 0;

		if (!outcome || outcome.status !== 'completed') {
			browserTotals.failed++;
			appendBrowserLog(entry + ': ' + formatString(strings.browserOutcomeFailed, [outcome && outcome.message ? outcome.message : '']), 'failed');
			return;
		}

		if (result.status === 'skipped_not_smaller') {
			browserTotals.skipped++;
			appendBrowserLog(entry + ': ' + formatString(strings.browserOutcomeSkipped, [result.reason || '']), 'skipped');
			return;
		}

		if (result.editor) {
			browserTotals.skipped++;
			appendBrowserLog(entry + ': ' + strings.browserOutcomeAlready, 'converted');
			return;
		}

		if (inputBytes > 0 && outputBytes > 0) {
			browserTotals.savedBytes += Math.max(0, inputBytes - outputBytes);
		}

		browserTotals.converted++;
		appendBrowserLog(
			entry + ': ' + formatString(strings.browserOutcomeSaved, [
				(inputBytes > 0 && outputBytes > 0 ? Math.round(((inputBytes - outputBytes) / inputBytes) * 100) : 0) + '%',
				formatString(strings.browserSizeFrom, [formatBytes(inputBytes)]),
				formatString(strings.browserSizeTo, [formatBytes(outputBytes)])
			]),
			'converted'
		);
	}

	function browserPhaseLabel(phase) {
		var strings = ILSWQ_Admin.strings;

		if (phase === 'preparing') {
			return strings.browserStepPreparing;
		}

		if (phase === 'downloading') {
			return strings.browserStepDownloading;
		}

		if (phase === 'encoding') {
			return strings.browserStepEncoding;
		}

		if (phase === 'uploading') {
			return strings.browserStepUploading;
		}

		return '';
	}

	function handleBrowserProgress(event, chunk, offset, total) {
		var variant = chunk[event.index] || (event && event.variant) || {};
		var position = offset + event.index;

		if (event.phase) {
			setBrowserProgress(position, total, formatString(ILSWQ_Admin.strings.browserProgress, [
				position + 1,
				total,
				browserPhaseLabel(event.phase) + ' — ' + describeVariant(variant)
			]));
			return;
		}

		if (event.outcome) {
			recordBrowserOutcome(event.outcome, variant);
			browserTotals.done = position + 1;
			setBrowserProgress(position + 1, total, formatString(ILSWQ_Admin.strings.browserProgressDone, [
				Math.min(position + 1, total),
				total,
				browserTotals.converted,
				browserTotals.skipped,
				browserTotals.failed
			]));
		}
	}

	function runBrowserChunks(module, variants, offset) {
		if (offset >= variants.length) {
			return Promise.resolve();
		}

		var chunk = variants.slice(offset, offset + BROWSER_BATCH_LIMIT);
		var total = variants.length;

		return module.runBatch(
			{
				prepareUrl: browserConfig.prepareUrl,
				finishUrl: browserConfig.finishUrl,
				nonce: browserConfig.nonce
			},
			chunk.map(function (variant) {
				return { attachmentId: variant.attachmentId, sizeKey: variant.sizeKey };
			}),
			function (event) {
				handleBrowserProgress(event, chunk, offset, total);
			},
			browserController ? browserController.signal : undefined
		).then(function () {
			return runBrowserChunks(module, variants, offset + BROWSER_BATCH_LIMIT);
		});
	}

	function refreshBrowserRows(ids, done) {
		if (!ids.length) {
			done();
			return;
		}

		ajax('ilswq_refresh_rows', { ids: ids.slice(0, 10) }).then(function (data) {
			if (data.rows && data.rows.length) {
				upsertRows(data.rows);
			}
			refreshBrowserRows(ids.slice(10), done);
		}).fail(function () {
			showNotice(ILSWQ_Admin.strings.browserRefreshFailed, 'error');
			done();
		});
	}

	function finishBrowserRun() {
		var finalText = formatString(ILSWQ_Admin.strings.browserProgressDone, [
			browserTotals.done,
			browserTotals.total,
			browserTotals.converted,
			browserTotals.skipped,
			browserTotals.failed
		]);

		browserRunning = false;
		browserFinished = true;
		browserController = null;
		renderBrowserState();
		updateButtons();

		setBrowserProgress(browserTotals.done, browserTotals.total, finalText);

		if (!browserRowIds.length) {
			setBusy(false);
			scheduleQueueTick(200);
			return;
		}

		$('#ilswq-browser-progress-text').text(ILSWQ_Admin.strings.browserRefreshing);

		var ids = browserRowIds.slice();
		browserRowIds = [];
		refreshBrowserRows(ids, function () {
			$('#ilswq-browser-progress-text').text(finalText);
			setBrowserProgress(browserTotals.done, browserTotals.total, finalText);
			setBusy(false);
			// Browser conversion records savings server side, so the totals
			// panel needs the same refresh the queue would have triggered.
			refreshQueueStatus(5000);
		});
	}

	function failBrowserRun(message) {
		showNotice(message, 'error');
		finishBrowserRun();
	}

	function startBrowserConversion() {
		var strings = ILSWQ_Admin.strings;

		if (isBusy || browserRunning) {
			return;
		}

		if (!browserEnabled || !browserSupport || !browserSupport.ok) {
			showNotice(strings.browserSelectedNone, 'error');
			return;
		}

		if (queueStatus && queueStatus.can_pause) {
			showNotice(strings.browserQueueBusy, 'error');
			return;
		}

		var variants = browserSelection();
		if (!variants.length) {
			showNotice(strings.browserSelectedNone, 'error');
			return;
		}

		if (window.confirm && !window.confirm(formatString(strings.browserConfirm, [variants.length]))) {
			return;
		}

		clearNotice();
		clearQueueTimer();
		browserRunning = true;
		setBusy(true);
		browserFinished = false;
		browserStopped = false;
		browserStopRequested = false;
		browserController = new window.AbortController();
		browserTotals = {
			converted: 0,
			skipped: 0,
			failed: 0,
			savedBytes: 0,
			done: 0,
			total: variants.length
		};
		browserRowIds = [];

		var seen = {};
		$.each(variants, function (_, variant) {
			if (!seen[variant.attachmentId]) {
				seen[variant.attachmentId] = true;
				browserRowIds.push(variant.attachmentId);
			}
		});

		$('#ilswq-browser-log').empty();
		setBrowserProgress(0, variants.length, formatString(strings.browserProgress, [1, variants.length, describeVariant(variants[0])]));
		renderBrowserState();
		updateButtons();
		showNotice(strings.browserStarted, 'success');

		var imported = false;
		var settingsSaved = false;

		// Apply the visible form values, as the server conversion controls do.
		Promise.resolve(ajax('ilswq_save_settings', { settings: getSettings() })).then(function () {
			settingsSaved = true;
			return import(browserConfig.bundleUrl);
		}).then(function (module) {
			imported = true;

			return runBrowserChunks(module, variants, 0);
		}).then(function () {
			var totals = browserTotals;
			var message = formatString(strings.browserComplete, [totals.converted, totals.skipped, totals.failed]);

			if (totals.savedBytes > 0) {
				message += ' ' + formatString(strings.browserSavedTotal, [formatBytes(totals.savedBytes)]);
			}

			browserStopped = false;
			showNotice(message, totals.failed ? 'error' : 'success');
			finishBrowserRun();
		}).catch(function (error) {
			var message = error && error.message ? String(error.message) : String(error || '');

			if (browserStopRequested || (error && error.name === 'AbortError')) {
				browserStopped = true;
				showNotice(formatString(strings.browserStopped, [browserTotals.done, variants.length]), 'success');
				finishBrowserRun();
				return;
			}

			if (!settingsSaved) {
				browserStopped = true;
				failBrowserRun(message);
				return;
			}

			if (!imported) {
				browserStopped = true;
				failBrowserRun(formatString(strings.browserImportFailed, [message]));
				return;
			}

			if (/nonce|session|401|403/i.test(message)) {
				browserStopped = true;
				failBrowserRun(strings.browserSessionExpired);
				return;
			}

			browserStopped = true;
			failBrowserRun(formatString(strings.browserRunFailed, [message]));
		});
	}

	function stopBrowserConversion() {
		if (!browserRunning || !browserController) {
			return;
		}

		browserStopRequested = true;
		browserController.abort();
	}

	$('#ilswq-settings-form').on('submit', function (event) {
		event.preventDefault();
		clearNotice();
		setBusy(true);

		var nextBrowserEnabled = getSettings().browser_conversion === 1;

		ajax('ilswq_save_settings', {
			settings: getSettings()
		}).then(function () {
			if (nextBrowserEnabled !== browserEnabled && window.location && typeof window.location.reload === 'function') {
				window.location.reload();
				return;
			}

			showNotice(ILSWQ_Admin.strings.settingsSaved, 'success');
		}).fail(function (message) {
			showNotice(message, 'error');
		}).always(function () {
			setBusy(false);
		});
	});

	$('#ilswq-scan').on('click', function () {
		clearNotice();
		resetRows();
		$('#ilswq-check-all').prop('checked', false);
		prepareQueueRun();
		setBusy(true, true);

		scanPage(1, 0, 0).then(finishScan).fail(showAjaxError).always(finishBusy);
	});

	$('#ilswq-convert').on('click', function () {
		var ids = getSelectedEligibleIds();

		if (!ids.length) {
			showNotice(ILSWQ_Admin.strings.noEligible, 'error');
			return;
		}

		clearNotice();
		setBusy(true);

		ajax('ilswq_queue_start', {
			ids: JSON.stringify(ids),
			settings: getSettings()
		}).then(function (data) {
			renderQueueStatus(data.queue || null);
			showNotice(ILSWQ_Admin.strings.convertStarted, 'success');
			scheduleQueueTick(100);
		}).fail(showAjaxError).always(function () {
			setBusy(false);
		});
	});

	$('#ilswq-resume').on('click', function () {
		if (resumeAction) {
			var action = resumeAction;
			resumeAction = null;
			action();
		}
	});

	$('#ilswq-library').on('click', function () {
		if (!window.confirm(ILSWQ_Admin.strings.libraryConfirm)) {
			return;
		}

		clearNotice();
		setBusy(true);

		ajax('ilswq_library_start', {
			settings: getSettings()
		}).then(function (data) {
			renderQueueStatus(data.queue || null);
			showNotice(ILSWQ_Admin.strings.libraryStarted, 'success');
			scheduleQueueTick(100);
		}).fail(showAjaxError).always(function () {
			setBusy(false);
		});
	});

	$('#ilswq-totals-rebuild').on('click', function () {
		clearNotice();
		setBusy(true);

		rebuildTotals(true, 0).then(function () {
			showNotice(ILSWQ_Admin.strings.totalsRebuilt, 'success');
		}).fail(showAjaxError).always(function () {
			hideProgress();
			setBusy(false);
		});
	});

	$('#ilswq-pause').on('click', function () {
		pauseRequested = true;
	});

	$('#ilswq-stop').on('click', function () {
		stopRequested = true;
		resumeAction = null;
	});

	$('#ilswq-queue-pause').on('click', function () {
		sendQueueCommand('pause');
	});

	$('#ilswq-queue-resume').on('click', function () {
		sendQueueCommand('resume');
	});

	$('#ilswq-queue-cancel').on('click', function () {
		if (window.confirm(ILSWQ_Admin.strings.queueCancelConfirm)) {
			sendQueueCommand('cancel');
		}
	});

	$('#ilswq-queue-retry').on('click', function () {
		sendQueueCommand('retry');
	});

	$('#ilswq-export').on('click', function () {
		exportCsv();
	});

	$('#ilswq-browser-start').on('click', function () {
		startBrowserConversion();
	});

	$('#ilswq-browser-stop').on('click', function () {
		stopBrowserConversion();
	});

	$('#ilswq-validate-webp').on('click', function () {
		var ids = generatedAttachmentIds();
		if (!ids.length) {
			showNotice(ILSWQ_Admin.strings.noEligible, 'error');
			return;
		}

		clearNotice();
		setBusy(true);

		validateQueue(ids, 0, ids.length, {
			validated: 0,
			invalid: 0,
			missing: 0
		}).then(function (result) {
			if (result.invalid > 0 || result.missing > 0) {
				showNotice(
					formatString(ILSWQ_Admin.strings.validationFailed, [result.validated, result.invalid, result.missing]),
					'error'
				);
				return;
			}

			showNotice(
				formatString(ILSWQ_Admin.strings.validationPassed, [result.validated]),
				'success'
			);
		}).fail(showAjaxError).always(function () {
			setBusy(false);
		});
	});

	$('#ilswq-cleanup').on('click', function () {
		if (!window.confirm(ILSWQ_Admin.strings.cleanupConfirm)) {
			return;
		}

		clearNotice();
		setBusy(true);

		cleanupQueue(0, 0, true).then(function (result) {
			resetRows();
			$('#ilswq-results-body').html(renderEmptyRow());
			showNotice(
				formatString(ILSWQ_Admin.strings.cleanupSummary, [result.deleted, result.failed]),
				'success'
			);
		}).fail(function (message) {
			showNotice(message, 'error');
		}).always(function () {
			hideProgress();
			setBusy(false);
		});
	});

	// Keep WordPress's bulk checkbox handler from clearing hidden selections.
	$('#ilswq-check-all').on('click', function (event) {
		event.stopPropagation();
	}).on('change', function () {
		visibleEligibleCheckboxes().prop('checked', $(this).is(':checked'));
		updateButtons();
	});

	$(document).on('change', '.ilswq-row-check', function () {
		updateButtons();
	});

	$('.ilswq-filters').on('click', '[data-ilswq-filter]', function () {
		activeFilter = $(this).data('ilswq-filter');
		$('.ilswq-filters [data-ilswq-filter]').removeClass('is-active').attr('aria-pressed', 'false');
		$(this).addClass('is-active').attr('aria-pressed', 'true');
		applyFilter();
	});

	$('#ilswq-search').on('input search', function () {
		searchQuery = String($(this).val() || '').trim().toLowerCase();
		applyFilter();
	});

	$('#ilswq-search-clear').on('click', function () {
		$('#ilswq-search').val('').trigger('input').trigger('focus');
	});

	initializeBrowserSupport();
	renderQueueStatus(queueStatus);
	scheduleQueueTick(queueStatus && queueStatus.has_runnable_work ? 150 : 5000);
})(jQuery);

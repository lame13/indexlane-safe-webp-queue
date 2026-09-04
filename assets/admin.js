(function ($) {
	'use strict';

	var rows = [];
	var rowMap = {};
	var isBusy = false;
	var activeFilter = 'all';
	var pauseRequested = false;
	var stopRequested = false;
	var resumeAction = null;
	var foregroundCanPause = false;
	var queueStatus = ILSWQ_Admin.queue || null;
	var queueRequestRunning = false;
	var queueTimer = null;

	function getSettings() {
		var $form = $('#ilswq-settings-form');

		return {
			batch_size: parseInt($form.find('[name="batch_size"]').val(), 10) || 3,
			max_pixels: parseInt($form.find('[name="max_pixels"]').val(), 10) || 16000000,
			jpeg_quality: parseInt($form.find('[name="jpeg_quality"]').val(), 10) || 82,
			png_quality: parseInt($form.find('[name="png_quality"]').val(), 10) || 90,
			skip_larger: $form.find('[name="skip_larger"]').is(':checked') ? 1 : 0,
			serve_webp: $form.find('[name="serve_webp"]').is(':checked') ? 1 : 0,
			auto_uploads: $form.find('[name="auto_uploads"]').is(':checked') ? 1 : 0
		};
	}

	function setBusy(nextBusy, canPause) {
		isBusy = nextBusy;
		foregroundCanPause = nextBusy && !!canPause;
		$('#ilswq-scan, #ilswq-convert, #ilswq-validate-webp, #ilswq-export, #ilswq-cleanup, #ilswq-settings-form button').prop('disabled', nextBusy);
		$('#ilswq-pause, #ilswq-stop').prop('disabled', !foregroundCanPause);
		updateButtons();
	}

	function updateButtons() {
		var hasRows = rows.length > 0;
		var hasEligible = getSelectedEligibleIds().length > 0;
		var hasConverted = rows.some(function (row) {
			return (row.generated_source_count || 0) > 0;
		});
		var hasActiveJob = queueStatus && queueStatus.can_cancel;
		var hasQueuedFileWork = hasActiveJob || (queueStatus && queueStatus.automatic_pending > 0);

		$('#ilswq-export').prop('disabled', isBusy || !hasRows);
		$('#ilswq-convert').prop('disabled', isBusy || hasActiveJob || !hasEligible);
		$('#ilswq-validate-webp').prop('disabled', isBusy || !hasConverted);
		$('#ilswq-check-all').prop('disabled', isBusy || !hasRows);
		$('#ilswq-resume').prop('disabled', isBusy || !resumeAction);
		$('#ilswq-scan').prop('disabled', isBusy || hasActiveJob);
		$('#ilswq-cleanup').prop('disabled', isBusy || hasQueuedFileWork);
		$('#ilswq-queue-pause').prop('disabled', isBusy || !queueStatus || !queueStatus.can_pause);
		$('#ilswq-queue-resume').prop('disabled', isBusy || !queueStatus || !queueStatus.can_resume);
		$('#ilswq-queue-cancel').prop('disabled', isBusy || !queueStatus || !queueStatus.can_cancel);
		$('#ilswq-queue-retry').prop('disabled', isBusy || !queueStatus || !queueStatus.can_retry);
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
		$('.ilswq-queue-progress')
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
		if (!queueStatus || (!queueStatus.has_runnable_work && !queueStatus.automatic_pending)) {
			return;
		}

		queueTimer = window.setTimeout(processQueueTick, delay);
	}

	function processQueueTick() {
		if (queueRequestRunning || !queueStatus) {
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
		updateButtons();
	}

	function renderEmptyRow() {
		return '<tr class="ilswq-empty-row"><td colspan="12">' + escapeHtml(ILSWQ_Admin.strings.noRows) + '</td></tr>';
	}

	function upsertRows(newRows) {
		$.each(newRows, function (_, row) {
			upsertRow(row);
		});
		updateCounts();
		applyFilter();
		updateButtons();
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
			conflict: 0
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
		});

		$('#ilswq-count-total').text(counts.total);
		$('#ilswq-count-eligible').text(counts.eligible);
		$('#ilswq-count-converted').text(counts.converted);
		$('#ilswq-count-skipped').text(counts.skipped);
		$('#ilswq-count-failed').text(counts.failed);
		$('#ilswq-count-needs-review').text(counts.needsReview);
		$('#ilswq-count-conflict').text(counts.conflict);
	}

	function getSelectedEligibleIds() {
		return $('.ilswq-row-check:checked').map(function () {
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

	function applyFilter() {
		$('.ilswq-results tbody tr').each(function () {
			var status = $(this).data('ilswq-status');
			if (!status || activeFilter === 'all' || status === activeFilter) {
				$(this).show();
			} else {
				$(this).hide();
			}
		});
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
		if (!rows.length) {
			showNotice(ILSWQ_Admin.strings.noRows, 'error');
			return;
		}

		var headers = ILSWQ_Admin.csvHeaders;
		var lines = [headers.map(csvEscape).join(',')];

		$.each(rows, function (_, row) {
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

	$('#ilswq-settings-form').on('submit', function (event) {
		event.preventDefault();
		clearNotice();
		setBusy(true);

		ajax('ilswq_save_settings', {
			settings: getSettings()
		}).then(function () {
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

	$('#ilswq-check-all').on('change', function () {
		$('.ilswq-row-check').prop('checked', $(this).is(':checked'));
		updateButtons();
	});

	$(document).on('change', '.ilswq-row-check', function () {
		updateButtons();
	});

	$('.ilswq-filters').on('click', '[data-ilswq-filter]', function () {
		activeFilter = $(this).data('ilswq-filter');
		$('.ilswq-filters [data-ilswq-filter]').removeClass('is-active');
		$(this).addClass('is-active');
		applyFilter();
	});

	renderQueueStatus(queueStatus);
	scheduleQueueTick(queueStatus && queueStatus.has_runnable_work ? 150 : 5000);
})(jQuery);

(function ($) {
	'use strict';

	var rows = [];
	var rowMap = {};
	var isBusy = false;
	var activeFilter = 'all';
	var pauseRequested = false;
	var stopRequested = false;
	var resumeAction = null;

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

	function setBusy(nextBusy) {
		isBusy = nextBusy;
		$('#ilswq-scan, #ilswq-convert, #ilswq-validate-webp, #ilswq-export, #ilswq-cleanup, #ilswq-settings-form button').prop('disabled', nextBusy);
		$('#ilswq-pause, #ilswq-stop').prop('disabled', !nextBusy);
		updateButtons();
	}

	function updateButtons() {
		var hasRows = rows.length > 0;
		var hasEligible = getSelectedEligibleIds().length > 0;
		var hasConverted = rows.some(function (row) {
			return row.status === 'Converted' || row.status === 'Needs review';
		});

		$('#ilswq-export').prop('disabled', isBusy || !hasRows);
		$('#ilswq-convert').prop('disabled', isBusy || !hasEligible);
		$('#ilswq-validate-webp').prop('disabled', isBusy || !hasConverted);
		$('#ilswq-check-all').prop('disabled', isBusy || !hasRows);
		$('#ilswq-resume').prop('disabled', isBusy || !resumeAction);
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
		if (/[",\r\n]/.test(stringValue)) {
			return '"' + stringValue.replace(/"/g, '""') + '"';
		}

		return stringValue;
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
		var checkbox = row.eligible
			? '<input type="checkbox" class="ilswq-row-check" value="' + escapeHtml(row.id) + '" checked aria-label="Select ' + escapeHtml(row.title || row.file || ('#' + row.id)) + '">'
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
			needsReview: 0
		};

		$.each(rows, function (_, row) {
			if (row.eligible) {
				counts.eligible++;
			}
			if (row.status === 'Converted') {
				counts.converted++;
			}
			if (row.status === 'Skipped' || row.status === 'Already exists') {
				counts.skipped++;
			}
			if (row.status === 'Failed') {
				counts.failed++;
			}
			if (row.status === 'Needs review') {
				counts.needsReview++;
			}
		});

		$('#ilswq-count-total').text(counts.total);
		$('#ilswq-count-eligible').text(counts.eligible);
		$('#ilswq-count-converted').text(counts.converted);
		$('#ilswq-count-skipped').text(counts.skipped);
		$('#ilswq-count-failed').text(counts.failed);
		$('#ilswq-count-needs-review').text(counts.needsReview);
	}

	function getSelectedEligibleIds() {
		return $('.ilswq-row-check:checked').map(function () {
			return parseInt(this.value, 10);
		}).get();
	}

	function firstConvertedId() {
		var found = null;
		$.each(rows, function (_, row) {
			if (found === null && (row.status === 'Converted' || row.status === 'Needs review')) {
				found = parseInt(row.id, 10);
			}
		});

		return found;
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
				var message = response && response.data && response.data.message ? response.data.message : 'Request failed.';
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
				setBusy(true);
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

	function convertQueue(ids, processed, total) {
		if (stopRequested) {
			return $.Deferred().resolve({ stopped: true }).promise();
		}

		if (pauseRequested) {
			var remaining = ids.slice(0);
			resumeAction = function () {
				clearNotice();
				pauseRequested = false;
				setBusy(true);
				convertQueue(remaining, processed, total).then(finishConvert).fail(showAjaxError).always(finishBusy);
			};
			return $.Deferred().resolve({ paused: true }).promise();
		}

		var settings = getSettings();
		var batchSize = Math.max(1, Math.min(10, settings.batch_size || 3));
		var batch = ids.splice(0, batchSize);

		if (!batch.length) {
			return $.Deferred().resolve().promise();
		}

		setProgress(ILSWQ_Admin.strings.converting, processed, total);

		return ajax('ilswq_convert', {
			ids: batch,
			settings: settings
		}).then(function (data) {
			upsertRows(data.rows || []);
			processed += batch.length;
			setProgress(ILSWQ_Admin.strings.converting, processed, total);

			return convertQueue(ids, processed, total);
		});
	}

	function cleanupQueue(totalDeleted, totalFailed) {
		setProgress(ILSWQ_Admin.strings.cleanupRunning, totalDeleted + totalFailed, 0);

		return ajax('ilswq_cleanup', {
			reset: totalDeleted === 0 && totalFailed === 0 ? 1 : 0
		}).then(function (data) {
			totalDeleted += data.deleted || 0;
			totalFailed += data.failed || 0;
			setProgress(ILSWQ_Admin.strings.cleanupRunning, totalDeleted + totalFailed, 0);

			if (data.hasMore) {
				return cleanupQueue(totalDeleted, totalFailed);
			}

			return {
				deleted: totalDeleted,
				failed: totalFailed
			};
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

	function finishConvert(result) {
		if (result && result.paused) {
			showNotice(ILSWQ_Admin.strings.paused, 'success');
			return;
		}

		if (result && result.stopped) {
			showNotice(ILSWQ_Admin.strings.stopped, 'success');
			return;
		}

		showNotice(ILSWQ_Admin.strings.convertComplete, 'success');
	}

	function exportCsv() {
		if (!rows.length) {
			showNotice(ILSWQ_Admin.strings.noRows, 'error');
			return;
		}

		var headers = [
			'Attachment ID',
			'Title',
			'File',
			'Source Files',
			'MIME Type',
			'Dimensions',
			'Original Size',
			'Estimated Memory',
			'WebP Size',
			'Savings',
			'Editor',
			'Status',
			'Reason'
		];
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
		setBusy(true);

		scanPage(1, 0, 0).then(finishScan).fail(showAjaxError).always(finishBusy);
	});

	$('#ilswq-convert').on('click', function () {
		var ids = getSelectedEligibleIds();

		if (!ids.length) {
			showNotice(ILSWQ_Admin.strings.noEligible, 'error');
			return;
		}

		clearNotice();
		prepareQueueRun();
		setBusy(true);

		convertQueue(ids, 0, ids.length).then(finishConvert).fail(showAjaxError).always(finishBusy);
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

	$('#ilswq-export').on('click', function () {
		exportCsv();
	});

	$('#ilswq-validate-webp').on('click', function () {
		var id = firstConvertedId();
		if (!id) {
			showNotice(ILSWQ_Admin.strings.noEligible, 'error');
			return;
		}

		clearNotice();
		setBusy(true);

		ajax('ilswq_validate_webp', {
			id: id
		}).then(function (data) {
			showNotice(data.message || ILSWQ_Admin.strings.validationPassed, 'success');
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

		cleanupQueue(0, 0).then(function (result) {
			resetRows();
			$('#ilswq-results-body').html(renderEmptyRow());
			showNotice(
				ILSWQ_Admin.strings.cleanupDone + ' ' +
				ILSWQ_Admin.strings.deleted + ': ' + result.deleted + '. ' +
				ILSWQ_Admin.strings.failed + ': ' + result.failed + '. ' +
				ILSWQ_Admin.strings.cleanupRefresh,
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

	updateButtons();
})(jQuery);

'use strict';

const fs = require('fs');
const vm = require('vm');

const sourcePath = require.resolve('../assets/admin.js');
let source = fs.readFileSync(sourcePath, 'utf8');

source = source.replace(
	'\n\trenderQueueStatus(queueStatus);\n\tscheduleQueueTick(queueStatus && queueStatus.has_runnable_work ? 150 : 5000);\n})(jQuery);',
	'\n\tglobalThis.ILSWQ_TestHooks = { csvEscape: csvEscape, formatString: formatString, cleanupQueue: cleanupQueue, renderQueueStatus: renderQueueStatus, renderTotals: renderTotals, matchesReportRow: matchesReportRow, exportCsv: exportCsv, getSelectedEligibleIds: getSelectedEligibleIds, setReport: function (nextRows, filter, query) { rows = nextRows; rowMap = {}; rows.forEach(function (row, index) { rowMap[row.id] = index; }); activeFilter = filter; searchQuery = query; } };\n\trenderQueueStatus(queueStatus);\n\tscheduleQueueTick(queueStatus && queueStatus.has_runnable_work ? 150 : 5000);\n})(jQuery);'
);

if (!source.includes('ILSWQ_TestHooks')) {
	throw new Error('Could not expose admin helpers for testing.');
}

const requests = [];
const downloads = [];
const textValues = {};
const hiddenValues = {};
let csvBlob;
let checkboxes = [];
const cleanupResponses = [
	{ success: true, data: { deleted: 0, failed: 0, hasMore: true } },
	{ success: true, data: { deleted: 0, failed: 0, hasMore: false } }
];

function collection(items = [], selector = '') {
	return {
		length: items.length,
		addClass: function () { return this; },
		attr: function () { return this; },
		css: function () { return this; },
		find: function () { return this; },
		filter: function (predicate) {
			return collection(items.filter(function (item) {
				return predicate === ':checked' ? item.checked : predicate.call(item);
			}));
		},
		get: function () { return items; },
		map: function (callback) { return collection(items.map(function (item) { return callback.call(item); })); },
		on: function () { return this; },
		prop: function (name, value) {
			if (selector && name === 'hidden') {
				hiddenValues[selector] = value;
			}
			return this;
		},
		removeClass: function () { return this; },
		text: function (value) {
			if (selector) {
				if (value === undefined) {
					return textValues[selector] || '';
				}
				textValues[selector] = value;
			}
			return this;
		}
	};
}

function jQuery(selector) {
	return collection(selector === '.ilswq-row-check' ? checkboxes : [], selector);
}

jQuery.each = function (items, callback) {
	items.forEach(function (item, index) { callback(index, item); });
};

jQuery.post = function (_url, data) {
	requests.push(Object.assign({}, data));
	return Promise.resolve(cleanupResponses.shift());
};

const context = {
	Blob: Blob,
	console: console,
	document: {
		createElement: function () { return { click: function () { downloads.push(this.download); } }; },
		body: { appendChild: function () {}, removeChild: function () {} }
	},
	globalThis: null,
	ILSWQ_Admin: {
		ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		queue: {
			exists: false,
			state: 'none',
			state_label: 'Not started',
			progress: 0,
			automatic_pending: 0,
			automatic_failed: 0,
			has_runnable_work: false
		},
		strings: {
			automaticFailedMany: '%d automatic conversions failed',
			automaticFailedOne: '%d automatic conversion failed',
			automaticPendingMany: '%d new uploads waiting',
			automaticPendingOne: '%d new upload waiting',
			cleanupRunning: 'Cleaning',
			libraryConfirm: 'Convert every convertible image in the Media Library?',
			libraryStarted: 'Whole-library conversion job started.',
			noRows: 'Run a scan to build a report.',
			noMatches: 'No images match this search and filter.',
			queueComplete: 'Conversion job complete.',
			queueCompleteWithFailures: '%d conversions failed',
			queueConflictComplete: 'Conversion job completed with conflicts',
			queueLastActivity: 'Last activity: %s',
			totalsRebuilding: 'Recalculating stored savings...',
			totalsRebuilt: 'Stored savings were recalculated from the generated WebP files.'
		},
		csvHeaders: []
	},
	jQuery: jQuery,
	window: {
		URL: {
			createObjectURL: function (blob) { csvBlob = blob; return 'blob:test-report'; },
			revokeObjectURL: function () {}
		},
		clearTimeout: clearTimeout,
		setTimeout: setTimeout
	}
};
context.globalThis = context;

vm.runInNewContext(source, context, { filename: sourcePath });

function assertEqual(actual, expected, message) {
	if (actual !== expected) {
		throw new Error(message + ': expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
	}
}

assertEqual(context.ILSWQ_TestHooks.csvEscape('=2+2'), "'=2+2", 'Formula prefix was not neutralized');
assertEqual(context.ILSWQ_TestHooks.csvEscape('  @SUM(A1:A2)'), "'  @SUM(A1:A2)", 'Whitespace-prefixed formula was not neutralized');
assertEqual(context.ILSWQ_TestHooks.csvEscape('+1,000'), '"\'+1,000"', 'Quoted formula cell was not neutralized');
assertEqual(context.ILSWQ_TestHooks.csvEscape('photo.jpg'), 'photo.jpg', 'Safe CSV value changed');
assertEqual(context.ILSWQ_TestHooks.formatString('Select %s', ['Photo']), 'Select Photo', 'Sequential string placeholder was not replaced');
assertEqual(context.ILSWQ_TestHooks.formatString('%2$d failed; %1$d passed', [4, 2]), '2 failed; 4 passed', 'Positional string placeholders were not replaced');
context.ILSWQ_TestHooks.renderQueueStatus(context.ILSWQ_Admin.queue);

const reportRows = [
	{ id: 12, title: '=Summer, Sale', file: 'Summer-Hero.JPG', status_key: 'eligible', eligible: true },
	{ id: 25, title: 'Summer Logo', file: 'logo.png', status_key: 'conflict', eligible: false },
	{ id: 37, title: 'Already optimized', file: 'photo.jpg', status_key: 'already-exists', eligible: false },
	{ id: 48, title: 'Summer Detail', file: 'detail.png', status_key: 'needs-review', eligible: true },
	{ id: 59, title: null, file: null, status_key: 'skipped', eligible: false }
];
const hooks = context.ILSWQ_TestHooks;
function matchingIds(filter, query) {
	hooks.setReport(reportRows, filter, query);
	return reportRows.filter(hooks.matchesReportRow).map(function (row) { return row.id; }).join(',');
}

function matchingIdsFor(rowsToMatch, filter, query) {
	hooks.setReport(rowsToMatch, filter, query);
	return rowsToMatch.filter(hooks.matchesReportRow).map(function (row) { return row.id; }).join(',');
}

assertEqual(matchingIds('all', 'summer'), '12,25,48', 'Title and filename search did not ignore case');
assertEqual(matchingIds('all', 'hero.jpg'), '12', 'Filename substring did not match');
assertEqual(matchingIds('all', '25'), '25', 'Attachment ID search did not match');
assertEqual(matchingIds('eligible', 'summer'), '12,48', 'Eligible filter excluded a convertible review item');
assertEqual(matchingIds('conflict', 'summer'), '25', 'Search and status were not combined');
assertEqual(matchingIds('skipped', ''), '37,59', 'Skipped filter omitted existing WebP results');
assertEqual(matchingIds('all', '[.*'), '', 'Search interpreted literal text as a pattern');
assertEqual(matchingIds('all', 'undefined'), '', 'Missing text fields created a false match');
assertEqual(matchingIds('all', ''), '12,25,37,48,59', 'Clearing filters did not restore the report');

checkboxes = reportRows.map(function (row) { return { value: String(row.id), checked: true }; });
hooks.setReport(reportRows, 'all', 'hero');
assertEqual(hooks.getSelectedEligibleIds().join(','), '12', 'Conversion included hidden checked images');
hooks.setReport(reportRows, 'conflict', '');
assertEqual(hooks.getSelectedEligibleIds().join(','), '', 'Conversion included ineligible images');
hooks.setReport(reportRows, 'eligible', 'summer');
checkboxes[3].checked = false;
assertEqual(hooks.getSelectedEligibleIds().join(','), '12', 'Conversion included an unchecked image');

hooks.setReport(reportRows, 'eligible', 'hero');
hooks.exportCsv();
assertEqual(downloads.length, 1, 'Matching report was not exported');
hooks.setReport(reportRows, 'failed', 'hero');
hooks.exportCsv();
assertEqual(downloads.length, 1, 'An empty filtered report was exported');

csvBlob.text().then(function (csv) {
	assertEqual(csv.split('\r\n').length, 2, 'CSV included hidden rows');
	assertEqual(csv.includes('Summer-Hero.JPG'), true, 'CSV omitted the matching filename');
	assertEqual(csv.includes('"\'=Summer, Sale"'), true, 'Filtered CSV lost formula protection or quoting');
	assertEqual(csv.includes('Summer Logo'), false, 'CSV ignored the status filter');
}).catch(function (error) {
	console.error(error);
	process.exitCode = 1;
});

const excludedRows = [
	{ id: 91, title: 'Excluded hero', file: 'excluded-hero.jpg', status_key: 'excluded', eligible: false },
	{ id: 92, title: 'Hero', file: 'hero.jpg', status_key: 'eligible', eligible: true },
	{ id: 93, title: 'Hero copy', file: 'hero-copy.jpg', status_key: 'converted', eligible: false }
];

assertEqual(matchingIdsFor(excludedRows, 'excluded', ''), '91', 'Excluded filter did not isolate excluded images');
assertEqual(matchingIdsFor(excludedRows, 'excluded', 'hero'), '91', 'Search and the excluded filter were not combined');
assertEqual(matchingIdsFor(excludedRows, 'eligible', 'hero'), '92', 'Excluded images leaked into the eligible filter');
assertEqual(matchingIdsFor(excludedRows, 'all', 'hero'), '91,92,93', 'Excluded images disappeared from the full report');

hooks.renderTotals({
	files: 4,
	source_bytes: 2400000,
	webp_bytes: 620000,
	saved_bytes: 1780000,
	saved_percent: 74.2,
	updated_at: 1789000000,
	is_empty: false,
	labels: {
		files: '4',
		source: '2.3 MB',
		webp: '605 KB',
		saved: '1.7 MB',
		percent: '74.2%',
		updated: 'September 15, 2026 10:00'
	}
});

assertEqual(textValues['#ilswq-total-files'], '4', 'Savings panel did not render the generated file count');
assertEqual(textValues['#ilswq-total-saved'], '1.7 MB', 'Savings panel did not render the saved size');
assertEqual(textValues['#ilswq-total-percent'], '74.2%', 'Savings panel did not render the saved percentage');
assertEqual(hiddenValues['#ilswq-totals-updated'], false, 'Savings panel hid the last updated time');

hooks.renderTotals({
	files: 0,
	source_bytes: 0,
	webp_bytes: 0,
	saved_bytes: 0,
	saved_percent: 0,
	updated_at: 0,
	is_empty: true,
	labels: { files: '0', source: '0 B', webp: '0 B', saved: '0 B', percent: '0%', updated: '' }
});

assertEqual(textValues['#ilswq-total-percent'], '', 'Savings panel showed a percentage without stored totals');
assertEqual(hiddenValues['#ilswq-totals-updated'], true, 'Savings panel showed an update time without stored totals');
hooks.renderTotals(undefined);

hooks.renderQueueStatus(Object.assign({}, context.ILSWQ_Admin.queue, {
	state: 'running',
	state_label: 'Running',
	summary: '3 of 400 attachments processed.',
	is_library: true,
	scope_label: 'Whole Media Library',
	totals: {
		files: 2,
		is_empty: false,
		labels: {
			files: '2',
			source: '900 KB',
			webp: '240 KB',
			saved: '660 KB',
			percent: '73.3%',
			updated: 'Yesterday'
		}
	}
}));

assertEqual(hiddenValues['#ilswq-queue-scope'], false, 'Queue scope stayed hidden for a whole-library job');
assertEqual(textValues['#ilswq-queue-scope-value'], 'Whole Media Library', 'Queue scope was not rendered');
assertEqual(textValues['#ilswq-total-files'], '2', 'Queue status did not refresh the savings panel');

context.ILSWQ_TestHooks.cleanupQueue(0, 0, true).then(function () {
	assertEqual(requests.length, 2, 'Cleanup did not request both pages');
	assertEqual(requests[0].reset, 1, 'First cleanup request did not reset the cursor');
	assertEqual(requests[1].reset, 0, 'Later cleanup request reset the cursor again');
	console.log('Admin JavaScript tests passed.');
}).catch(function (error) {
	console.error(error);
	process.exitCode = 1;
});

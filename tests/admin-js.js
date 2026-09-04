'use strict';

const fs = require('fs');
const vm = require('vm');

const sourcePath = require.resolve('../assets/admin.js');
let source = fs.readFileSync(sourcePath, 'utf8');

source = source.replace(
	'\n\trenderQueueStatus(queueStatus);\n\tscheduleQueueTick(queueStatus && queueStatus.has_runnable_work ? 150 : 5000);\n})(jQuery);',
	'\n\tglobalThis.ILSWQ_TestHooks = { csvEscape: csvEscape, formatString: formatString, cleanupQueue: cleanupQueue, renderQueueStatus: renderQueueStatus };\n\trenderQueueStatus(queueStatus);\n\tscheduleQueueTick(queueStatus && queueStatus.has_runnable_work ? 150 : 5000);\n})(jQuery);'
);

if (!source.includes('ILSWQ_TestHooks')) {
	throw new Error('Could not expose admin helpers for testing.');
}

const requests = [];
const cleanupResponses = [
	{ success: true, data: { deleted: 0, failed: 0, hasMore: true } },
	{ success: true, data: { deleted: 0, failed: 0, hasMore: false } }
];

function collection() {
	return {
		addClass: function () { return this; },
		attr: function () { return this; },
		css: function () { return this; },
		find: function () { return this; },
		get: function () { return []; },
		map: function () { return this; },
		on: function () { return this; },
		prop: function () { return this; },
		removeClass: function () { return this; },
		text: function () { return this; }
	};
}

function jQuery() {
	return collection();
}

jQuery.post = function (_url, data) {
	requests.push(Object.assign({}, data));
	return Promise.resolve(cleanupResponses.shift());
};

const context = {
	Blob: Blob,
	console: console,
	document: {},
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
			queueComplete: 'Conversion job complete.',
			queueCompleteWithFailures: '%d conversions failed',
			queueConflictComplete: 'Conversion job completed with conflicts',
			queueLastActivity: 'Last activity: %s'
		},
		csvHeaders: []
	},
	jQuery: jQuery,
	window: {
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

context.ILSWQ_TestHooks.cleanupQueue(0, 0, true).then(function () {
	assertEqual(requests.length, 2, 'Cleanup did not request both pages');
	assertEqual(requests[0].reset, 1, 'First cleanup request did not reset the cursor');
	assertEqual(requests[1].reset, 0, 'Later cleanup request reset the cursor again');
	console.log('Admin JavaScript tests passed.');
}).catch(function (error) {
	console.error(error);
	process.exitCode = 1;
});

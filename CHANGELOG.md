# Changelog

## 1.0.0 - 2026-09-19

- Added browser (WebAssembly) conversion. A WebP encoder compiled to WebAssembly ships with the plugin and runs in the administrator's browser, so images on hosts whose image editor cannot write WebP are still convertible. Nothing is installed on the machine and no service is contacted; only the finished WebP file returns to the site.
- Added a browser conversion panel to the plugin page: capability detection that names the missing browser feature, HTTPS and Content Security Policy checks, a per-file progress bar, a running result log, a stop control, and a report refresh when the run ends.
- Added the "Convert images in the browser" setting, off by default, plus a browser conversion row in the server checks and a report reason and editor label for files that only the browser can convert.
- Routed browser output through the same staging, ownership, conflict, totals and generated-map code as the local image editor backends, so a browser conversion is indistinguishable from a server conversion afterwards, including frontend serving.
- Kept the persistent queue honest on hosts without a WebP writer: conversion jobs, whole-library jobs and automatic new-upload conversion are refused with a clear message instead of failing attachment by attachment, and browser-only files are reported as skipped rather than failed.
- Blocked a browser run while a server conversion job holds the queue, and suspended the page's queue worker while a browser run is in progress, so the two backends never write the same attachment at the same time.
- Added `tests/browser-wordpress.php`: integration coverage for capability routing, the REST prepare/finish handshake, container validation, replay, changed sources, foreign sibling files, cross-user tokens, WebP validation, stored metadata, totals and cleanup.
- Added a CI job that type checks the WebAssembly module, rebuilds the committed bundle, compares it with the shipped files and confirms the bundled codec licences.

- Fixed server conversion ignoring the selected quality when WordPress changes the output format to WebP.
- Fixed stale browser results after quality changes, damaged outputs, or a replaced attachment source; browser saves recheck the current source and settings.
- Serialized cleanup, totals recalculation, and browser saves with the server queue, kept conflicting controls disabled during a browser run, and allowed conversion after a server job is paused.
- Added a real worker/codec capability probe, useful server error messages, bounded uploads, and EXIF rotation/mirror rejection before decoding.
- Corrected encoder labels and savings for partially converted attachments, and applied memory limits to regeneration too.
- Stopped automatic uploads from retrying browser-only images.
- Verified WordPress 7.1.1 with GD, Imagick, WP-CLI, and the browser backend regressions; updated the CI matrix.
- Added measured size and speed comparisons for large/small JPEG and PNG files using Chrome, GD, and Imagick at quality 80.
- Rewrote the description for shared-hosting users and refreshed six screenshots, including a live WebAssembly conversion.
- Tested browser WebAssembly conversion in Google Chrome 153.0.8010.52 on WordPress 7.1.1 and real Safari 26.6.2 on WordPress 7.1 (macOS), covering JPEGs, transparent PNGs, generated sizes, saved results, and larger-output skips.

## 0.3.0 - 2026-09-15

- Added whole-library background conversion with bounded batches, attachment ID cursors, pause/resume/cancel, and retries that remain available after large failure counts.
- Added Media Library WebP status and savings, row and bulk conversion actions, and per-image exclusions from conversion and frontend serving.
- Added incremental savings totals and bounded recalculation for earlier conversions, partial cleanup, and attachment-deletion retries.
- Added WP-CLI status, scan, convert, totals, queue, and cleanup commands with dry runs and whole-library conversion.
- Fixed exclusions changed after queuing, CLI output formatting and command registration, automatic queue draining, and cleanup/recalculation conflicts with queued conversion.
- Expanded WordPress and CLI regression coverage for library changes between batches and consistent savings after cleanup retries.

## 0.2.1 - 2026-09-08

- Added report search by filename, attachment title, and ID with matching result counts.
- Made CSV exports and conversion selection respect the active search and status filter, including selecting only visible eligible images.
- Added a clear-search control, a no-results message, and accessible status-filter states.
- Included existing WebP results in the Skipped filter and convertible review items in the Eligible filter to match their counters.

## 0.2.0 - 2026-09-04

- Added a persistent, resumable conversion queue with bounded batches, duplicate-worker locking, progress reporting, pause, resume, cancel, and failed-item retry controls.
- Moved automatic new-upload conversion out of the upload request and into retryable WP-Cron work.
- Added generation fingerprints so source-file or quality-setting changes are detected before plugin-owned WebP files are safely regenerated.
- Reconciled plugin-owned WebP files when attachment sizes change and cleaned them up when attachments are deleted.
- Retained failed attachment cleanup as background orphan work so temporary deletion failures can be retried safely.
- Expanded browser and WordPress smoke coverage for persistent jobs, retries, asynchronous uploads, fingerprints, and attachment lifecycle cleanup.

## 0.1.5 - 2026-08-28

- Renamed the displayed plugin to IndexLane Safe WebP Queue and removed the third-party update URI.
- Changed the WordPress.org contributor to `wpfixpath` and declared testing through WordPress 7.1.
- Treated unrelated sibling WebP files as conflicts and prevented them from being overwritten or deleted.
- Staged and validated generated WebP files before replacing plugin-owned output.
- Made PHP statuses, JavaScript request errors, CSV headers, selection labels, and result summaries translation-ready.
- Added the root GPL license and repeatable WordPress.org release packaging with top-level SVN assets.
- Changed CI to test the unmodified release package with WordPress 7.1 using GD and Imagick and to run Plugin Check against that same package.

## 0.1.4 - 2026-07-19

- Fixed cleanup pagination restarting when a page contains no generated files.
- Preserved generated-file ownership metadata when physical deletion fails so cleanup can be retried safely.
- Neutralized formula-like values in CSV exports to prevent spreadsheet formula injection.
- Validated every stored WebP file across the current report instead of stopping after the first valid file.
- Cached frontend generated-file inspection once per attachment and request.
- Added regression coverage for admin cleanup, CSV export, complete validation, and failed cleanup retries.

## 0.1.3 - 2026-05-25

- Renamed the generated-file check from Delivery Check to Validate WebP.
- Added Mac and archive ZIP ignores to the repository.
- Refreshed screenshots with safety defaults visible and the Reason column readable.
- Widened the screenshot capture viewport for public plugin assets.

## 0.1.2 - 2026-05-25

- Added generated attachment size conversion and a stored WebP file map per attachment.
- Added optional frontend serving for normal WordPress image output and optional conversion for new uploads.
- Added pause, stop, resume, result filters, and complete WebP validation controls.
- Added stale and invalid generated WebP detection and stored new maps with uploads-relative paths.
- Hardened uploads-directory validation, cleanup paths, AJAX settings, attachment IDs, and server-path handling.
- Added Plugin Check CI packaging and lowered the maximum pixel setting ceiling.

## 0.1.0 - 2026-05-25

- Added server capability checks and a Media Library dry-run scan.
- Added selected, small-batch sibling WebP conversion while preserving original images.
- Added skip reasons, CSV export, and explicit cleanup for plugin-generated WebP files.

# Changelog

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

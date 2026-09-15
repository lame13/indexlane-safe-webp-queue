=== IndexLane Safe WebP Queue ===
Contributors: wpfixpath
Tags: webp, image optimization, images, media library, performance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create smaller WebP images, keep your originals, and choose when to use them on your site. Local conversion with no cloud account.

== Description ==

**Lighter images. Your originals kept. You're in control.**

Give your WordPress images a smaller WebP version without giving up the JPEGs and PNGs you already have. IndexLane Safe WebP Queue helps you convert your Media Library, see the file-size savings, and put those WebP copies to work when you're ready.

Everything runs on your own server, right inside WordPress. No cloud account to connect, no API key to manage, and no images sent to an optimization service.

= Make images lighter, at your own pace =

Start with a scan to see which images your server can convert. Choose a few to try or work through your library in small batches. The plugin covers both your main attachment images and the thumbnail and other sizes WordPress creates.

Your conversion job keeps its progress if you close the page. Pause when you need to, resume later, and retry failed conversions from the same screen. Background processing uses WP-Cron, so progress while you're away depends on site traffic or a configured cron runner.

= Convert the whole library, in the background =

You do not have to pick images one at a time. Choose **Convert Entire Library** and the plugin queues every convertible image in your Media Library, then works through it in small batches. The job can be paused, resumed, or cancelled, and it keeps its progress if you close the browser. Images you excluded are skipped, so a full-library run never overrides your decisions.

= Manage conversion from the Media Library =

The Media Library list has a WebP column that shows the status and savings of each image. Use the row actions to convert a single image or to exclude it from conversion, and use the bulk actions to convert or exclude several images at once. Excluded images keep their originals, are skipped by conversion jobs, and are not served as WebP even if copies already exist.

= See how much you saved =

The plugin page keeps a running total of the WebP files it generated, the size of the originals they cover, the size of the WebP copies, and the resulting savings. Totals update as conversions finish and drop again when you delete generated files. If you upgraded from an earlier version, choose **Recalculate** once to rebuild the totals from the stored plugin metadata.

= Keep the images you trust =

Your original JPEGs and PNGs stay where they are. WebP copies are saved beside them, and you can remove the copies created by this plugin whenever you choose.

Before converting, the plugin checks your server's WebP support and estimates memory needs. It skips files that exceed your settings or look too demanding for your server, and leaves WebP files created by other tools untouched. By default, it also skips a new WebP if it would be larger than its source.

= See what changed and what needs attention =

Compare original and WebP file sizes, check savings for each attachment, and see a reason when something is skipped or fails.

Search the report by filename, title, or attachment ID, then narrow it by status. Convert the selected images in that view or export the matching results as CSV. It's a quick way to find one image, review a problem, or share the results of your work.

= Put WebP to work when you're ready =

Turn on optional frontend serving to use matching WebP copies in normal WordPress image output. Smaller files can reduce the image data your visitors download; actual savings depend on your images and how your site displays them.

You can also enable automatic conversion for future uploads. New images join the queue after WordPress creates their sizes, keeping conversion out of the upload request.

Both options start **off**, so you can convert and review your images before changing what visitors receive.

= Script it with WP-CLI =

Sites managed from the command line can use `wp ilswq status`, `wp ilswq scan`, `wp ilswq convert`, `wp ilswq totals`, `wp ilswq queue`, and `wp ilswq cleanup`. `wp ilswq convert --all` runs the whole-library job to completion, and `--dry-run` reports what would change first.

= Start with a few images =

Open **Tools -> IndexLane Safe WebP Queue**, scan your library, and convert a small selection. Review the results, then enable frontend serving if it fits your site.

This plugin works with JPEG and PNG Media Library attachments. It keeps saved content and attachment URLs unchanged. Frontend serving applies where WordPress uses its standard image-output filters; it does not cover saved image HTML, CSS backgrounds, theme files, page builder fields, or hardcoded URLs. GIF, SVG, AVIF, and existing WebP files are outside its conversion scope.

[Learn more about IndexLane Safe WebP Queue](https://indexlane.dev/plugins/safe-webp-queue).

== Installation ==

1. Install and activate IndexLane Safe WebP Queue from Plugins in WordPress, or upload the plugin folder to /wp-content/plugins/.
2. Open **Tools -> IndexLane Safe WebP Queue** and check that your server supports WebP conversion.
3. Select **Scan Media Library**, then choose a few eligible images and click **Convert Selected**.
4. Review the file sizes, savings, and any skip reasons. Enable frontend serving in Settings when you're ready to use matching WebP copies on your site.

== Frequently Asked Questions ==

= Can I try WebP without losing my original images? =

Yes. The plugin saves WebP copies beside your JPEGs and PNGs, including generated attachment sizes. Your originals remain available. You can use **Delete Generated WebPs** to remove copies owned by this plugin; files created by other tools are left alone.

= Will my site use WebP as soon as I convert an image? =

You choose when. Frontend serving starts off. Enable it in Settings to use matching WebP copies where your theme or plugin requests images through normal WordPress image output. Images with no matching copy continue to use their existing format. Saved post content, attachment URLs, CSS backgrounds, and hardcoded image markup are not rewritten.

= Will every image be smaller? =

Results vary by image and quality setting. The report shows actual file sizes and savings so you can judge the result. By default, the plugin skips newly converted files that are larger than their source. Keeping both formats uses additional disk space; this plugin does not free space by deleting originals.

= Do I need an account, API key, or cloud service? =

No. Your server does the conversion using a compatible WordPress image editor with GD or Imagick WebP support. Your images are not uploaded to an external optimization service.

= Can I close the page while a conversion runs? =

Yes. Conversion progress is saved, and WP-Cron can continue the queue when your site receives traffic. If traffic is low or WP-Cron is disabled, work may wait until you reopen the plugin page or your configured cron runner runs. You can pause, resume, cancel pending work, or retry failures from the queue controls. If a whole-library job has more than 10,000 failures, retry rescans the library and reuses valid existing WebP files.

= How do I convert my whole Media Library? =

Choose **Convert Entire Library** on the plugin page. The plugin counts the convertible images, queues them all, and works through them in the same small batches used for selected images. The queue panel shows the scope, progress, and any failures, and the job continues with WP-Cron between visits. Because images are converted one batch at a time, a large library is safe to leave running.

= Can I keep an image out of WebP conversion? =

Yes. Use **Exclude from WebP** in the Media Library row actions, or the exclude bulk action for several images at once. Excluded images are skipped by every conversion job, are not regenerated when you change quality settings, and are not served as WebP. Existing copies generated earlier stay on disk until you delete them, and you can include an image again at any time.

= Where do the savings numbers come from? =

The plugin records the original and WebP byte size of every file it generates, so the totals on the plugin page reflect real files rather than estimates. Totals are adjusted when files are regenerated or deleted. If you converted images with an earlier version, press **Recalculate** once to rebuild the totals from the metadata stored on your attachments.

= Can I use WP-CLI? =

Yes. `wp ilswq status` reports server support, settings, queue state, and stored savings; `wp ilswq scan` lists images with their status; `wp ilswq convert` converts specific IDs, a dry run, or the whole library with `--all`; `wp ilswq totals` shows or rebuilds the savings; `wp ilswq queue` inspects or controls the queue; and `wp ilswq cleanup` removes generated WebP files.

= Can new uploads be converted automatically? =

Yes, if you enable automatic new-upload conversion in Settings. It starts off. Once enabled, new uploads are queued after WordPress finishes creating their image sizes. Conversion runs in the background and follows the same server checks and conversion settings.

= How do I find or export a particular image? =

Run a scan, then search the report by filename, attachment title, or ID. Search works together with the status filters. **Export CSV** downloads only matching rows, and **Convert Selected** queues only selected eligible images in that view. Clear the search and choose **All** to see the full report again.

= Why was an image skipped or marked as a conflict? =

Check the Reason column for that attachment. A file may be missing, unsupported, above your pixel limit, or likely to need more memory than your server can spare. A small file on disk can still require substantial memory when decoded. A conflict means a WebP already exists that this plugin does not own, so it is left unchanged.

= What happens if I edit an image or regenerate thumbnails? =

The plugin detects source-file and quality-setting changes before regenerating its WebP output. It also reconciles its copies when attachment sizes change and cleans them up when an attachment is deleted. Run a fresh scan to review which images need conversion after making changes.

== Screenshots ==

1. Check that your server is ready and choose the conversion settings that suit your images.
2. Review your Media Library before converting, with eligible images and existing WebP conflicts clearly identified.
3. Compare image sizes and savings, and see the reason for each conversion result.
4. Choose when to serve WebP to visitors and whether to convert future uploads automatically.

== Changelog ==

= 0.3.0 =

* Added whole-library background conversion with bounded batches, stable attachment cursors, and existing queue controls.
* Added Media Library WebP status, savings, conversion actions, and per-image exclusions for conversion and serving.
* Added stored savings totals and a bounded rebuild for images converted by earlier versions.
* Added WP-CLI commands for status, scanning, conversion, queue controls, savings, and cleanup.
* Kept exclusions effective after queuing and corrected savings accounting for partial cleanup and orphan retries.
* Validated real WP-CLI formatting, automatic queue draining, and cleanup conflict checks.

= 0.2.1 =

* Added report search by filename, attachment title, and ID with matching result counts.
* Made CSV exports and conversion selection respect the active search and status filter.
* Added a clear-search control, a no-results message, and accessible status-filter states.


= 0.2.0 =

* Added persistent, resumable conversion jobs with progress, pause, resume, cancel, and failed-item retry controls.
* Added bounded queue workers with duplicate-worker locking and WP-Cron continuation.
* Moved automatic new-upload conversion out of the upload request and into retryable background work.
* Added generation fingerprints to detect source-file and quality-setting changes before safe regeneration.
* Reconciled plugin-owned WebP files when attachment sizes change or attachments are deleted.
* Retained failed attachment cleanup for safe background retries.

= 0.1.5 =

* Renamed the plugin to IndexLane Safe WebP Queue and aligned WordPress.org release metadata.
* Protected unrelated sibling WebP files as conflicts instead of replacing or deleting them.
* Generated and validated WebP output in a temporary file before installing it at the final path.
* Made statuses, browser request errors, CSV headers, selection labels, and result summaries translation-ready.
* Added a root GPL license, dated project changelog, repeatable release packaging, and exact-package checks.
* Declared testing through WordPress 7.1 with GD and Imagick coverage.

= 0.1.4 =

* Fixed cleanup pagination restarting when a page contains no generated files.
* Preserved generated-file ownership metadata when physical deletion fails so cleanup can be retried safely.
* Neutralized formula-like values in CSV exports to prevent spreadsheet formula injection.
* Validated every stored WebP file across the current report instead of stopping after the first valid file.
* Cached frontend generated-file inspection once per attachment and request.
* Added regression coverage for admin cleanup, CSV export, complete validation, and failed cleanup retries.

= 0.1.3 =

* Renamed the generated-file check from Delivery Check to Validate WebP.
* Added Mac/archive ZIP ignores to the repository.
* Refreshed screenshots with safety defaults visible and the Reason column readable.
* Widened the screenshot capture viewport for public plugin assets.

= 0.1.2 =

* Added generated attachment size conversion.
* Added stored WebP file map per attachment.
* Added optional frontend serving for normal WordPress image output.
* Added optional WebP generation for new uploads.
* Added pause, stop, resume, result filters, and WebP validation controls.
* Added generated WebP output validation.
* Added stale and invalid existing WebP detection.
* Stored new WebP maps with uploads-relative paths.
* Hardened uploads-directory validation before scan and conversion.
* Hardened cleanup path validation.
* Hardened AJAX settings and ID handling.
* Added clean Plugin Check CI build handling and ignored IDE/build artifacts.
* Removed full server paths from admin AJAX rows.
* Lowered the max pixel setting ceiling.

= 0.1.0 =

* Initial release.
* Added server capability checks.
* Added Media Library dry-run scan.
* Added small-batch selected conversion.
* Added sibling WebP output with originals preserved.
* Added skip reasons and CSV export.
* Added explicit cleanup for generated WebP files.

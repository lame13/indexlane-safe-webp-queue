=== IndexLane Safe WebP Queue ===
Contributors: wpfixpath
Tags: webp, image optimization, images, media library, performance
Requires at least: 6.0
Tested up to: 7.1.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert images to WebP on your hosting or in your browser. Keep your originals. No cloud service, API key, or conversion credits.

== Description ==

**WebP conversion, even when your hosting can't do it.**

Some WordPress hosts can serve WebP images but don't have the tools to create them. Safe WebP Queue lets your own computer do the conversion, inside WordPress admin.

The plugin includes a WebP encoder that runs in your browser. It downloads an image from your site, converts it, and uploads the finished copy back to WordPress. Your original JPEGs and PNGs stay where they are.

No subscription, conversion credits, API key, or external conversion service. If your hosting already supports WebP, you can let it do the work in background batches instead.

= Convert in your browser =

Enable **Convert images in the browser**, scan your Media Library, select a few images, and click **Convert Selected in Browser**.

The bundled encoder uses WebAssembly to run on your computer. There's no desktop app or browser extension to install. Keep the plugin page open while it processes each file, including existing thumbnails and other WordPress sizes. You'll see progress and a result for each one.

You can stop a run and return later. Completed copies stay saved; a new scan shows what remains.

The panel checks browser support before enabling conversion. You need HTTPS and enough memory on your computer. Browser conversion works with images served from the same origin as your admin; the FAQ explains file limits and restrictions for separately hosted media.

= Or let your hosting work in the background =

When WordPress has GD or Imagick with WebP support, use **Convert Selected** or **Convert Entire Library**. Jobs run in small batches, save progress, and let you pause, resume, cancel pending work, or retry failures.

You can close the browser during a server job. Background progress uses WP-Cron, so it depends on site visits or a configured cron runner. Automatic conversion for future uploads is also available on supported hosts.

Browser conversion remains available if you prefer it. Whole-library background jobs and automatic uploads need server conversion.

= Review the results =

See original and WebP sizes, savings, the conversion method, and reasons for skipped or failed files. Search and filter the report, or export matching rows as CSV. JPEG and PNG quality can be adjusted separately.

By default, a new WebP is skipped if it's the same size or larger than its source. Files created by other tools are left alone: an existing WebP the plugin doesn't own is reported as a conflict.

The Media Library shows status and savings too. You can exclude images from conversion and serving, or use **Delete Generated WebPs** to remove this plugin's copies while keeping your originals.

= Choose when visitors receive WebP =

**Serve WebP on the front end** starts off. Review your conversions, then enable it to use matching copies in normal WordPress image output. Images without a matching copy keep their existing format.

The plugin doesn't rewrite saved posts, attachment URLs, CSS backgrounds, page builder fields, theme files, or hardcoded image markup. It converts JPEG and PNG attachments and their generated sizes.

Smaller copies reduce image downloads when your site serves them. Keeping both formats takes more disk space.

= Four files, measured on my Mac =

I tested version 1.0.0 with JPEG and PNG quality both set to 80. Browser conversion, GD, and Imagick all ran locally on the same Apple M1 Pro Mac with 32 GB RAM.

The large JPEG and PNG contain the same 3000 x 1348 photo. The small JPEG is a 600 x 270 version of it; the small PNG is a 600 x 400 transparent graphic.

**File sizes in KB, with the percentage saved in brackets.** The server column covers GD and Imagick, which produced the same sizes in this test.

<pre>
Image       Original  Browser WebP   Server WebP
----------  --------  -------------  -------------
Large JPEG    1077.5  566.4 (47.4%)  566.4 (47.4%)
Small JPEG      51.4  28.0 (45.5%)   28.0 (45.5%)
Large PNG     6105.9  544.7 (91.1%)  544.7 (91.1%)
Small PNG        2.6  2.3 (11.6%)    2.3 (11.8%)
</pre>

**Time to convert and save one file:**

<pre>
Image       Browser   GD        Imagick
----------  --------  --------  --------
Large JPEG  0.63 s    0.36 s    0.37 s
Small JPEG  0.10 s    0.04 s    0.04 s
Large PNG   0.74 s    0.41 s    0.41 s
Small PNG   0.09 s    0.04 s    0.04 s
</pre>

The JPEG examples shrank by about 45-47%; the large PNG by 91%. The already-small transparent graphic saved about 12%. Your images and quality settings will give different results.

GD and Imagick were faster in this local test. The useful part of browser conversion is being able to do the job when your host can't.

**Test details:** macOS 26.6.2, WordPress 7.1.1, PHP 8.4.23 and Chrome 153.0.8010.52. Each time is the median of five fresh conversions after one warm-up. Times include conversion, validation, saving and local requests; they exclude queue waiting and the first encoder download. Your hosting, computer and connection affect the time, including the browser's image download and WebP upload. KB means 1,000 bytes. Full-size files were measured individually; generated sizes add more work.

Photo: [Fronalpstock by Hannes Röst](https://commons.wikimedia.org/wiki/File:Fronalpstock_big.jpg), [CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/), resized for these tests.

= Start with a few images =

Open **Tools -> IndexLane Safe WebP Queue**, scan your library, and try a few images. Check the results before enabling frontend serving or converting more. Command-line users can also manage server jobs and reports through WP-CLI; see the FAQ.

[Plugin details and documentation](https://indexlane.dev/plugins/safe-webp-queue).

== Installation ==

1. Install and activate IndexLane Safe WebP Queue from Plugins in WordPress, or upload the plugin folder to /wp-content/plugins/.
2. Open **Tools -> IndexLane Safe WebP Queue** and check the server support panel. To use your computer for conversion, enable **Convert images in the browser** in Queue Settings.
3. Click **Scan Media Library** and select a few eligible images.
4. Choose **Convert Selected** for server conversion, or **Convert Selected in Browser** for browser conversion. Keep the page open during a browser run.
5. Review the file sizes, savings, and any skip reasons. Enable **Serve WebP on the front end** in Settings when you're ready to use matching copies on your site.

== Frequently Asked Questions ==

= Can I try WebP without losing my original images? =

Yes. The plugin saves WebP copies beside your JPEGs and PNGs, including generated attachment sizes. Your originals remain available. You can use **Delete Generated WebPs** to remove copies owned by this plugin; files created by other tools are left alone.

= Will my site use WebP as soon as I convert an image? =

You choose when. Frontend serving starts off. Enable it in Settings to use matching WebP copies where your theme or plugin requests images through normal WordPress image output. Images with no matching copy continue to use their existing format. Saved post content, attachment URLs, CSS backgrounds, and hardcoded image markup are not rewritten.

= Will every image be smaller? =

Results vary by image and quality setting. The report shows actual file sizes and savings so you can judge the result. By default, the plugin skips newly converted files that are larger than their source. Keeping both formats uses additional disk space; this plugin does not free space by deleting originals.

= Do I need an account, API key, or cloud service? =

No. Conversion runs on your hosting or on your computer, depending on the method you choose. Browser conversion exchanges images only with your own WordPress site. The plugin does not use an external optimization API, account, or credit system.

= What if my server cannot create WebP files? =

Turn on **Convert images in the browser** in Queue Settings. The WebP encoder that ships with the plugin then runs inside your own browser, so the image data never goes to another server and nothing has to be installed on your computer or your host. Your browser sends the finished WebP file back to your WordPress site, where it is stored beside the original exactly like a server conversion.

This mode is attended work: keep the plugin page open while it runs. Whole-library jobs and automatic new-upload conversion still need a server WebP writer, because those run in the background.

= Do I need anything installed for browser conversion? =

No desktop software or browser extension is needed. The WebAssembly encoder comes with the plugin and runs in a supported browser on macOS, Windows, or Linux. It doesn't use image tools installed on your computer.

Use a current Chrome, Edge, Firefox, or Safari browser. The admin page must use HTTPS (localhost also works for development), and the site must allow the bundled scripts, module workers, and WebAssembly encoder to load. The panel tests these capabilities on your site before enabling conversion. Older browsers can lack a required feature even if they can display WebP images.

= Does the plugin use cwebp on my hosting? =

Server conversion uses the WordPress image editor through GD or Imagick with WebP support. This version does not call the standalone `cwebp` command, so having that command installed by itself is not enough. If WordPress cannot write WebP, use browser conversion instead; its libwebp encoder is included in the plugin.

= Are there limits to browser conversion? =

Each source file must be at most 20 MiB, at most 8,192 pixels along either edge, and at most 16 million pixels in total. Your configured maximum pixel limit also applies. The browser processes one file at a time, and very large images may still exceed your device's memory or the conversion time limit.

Source images and the conversion endpoints must be available on the same origin as your WordPress admin (the same protocol, host, and port). Offloaded images, CDN-only uploads, or a separate media domain are not supported by browser conversion. Your hosting must also accept the finished file upload; its upload-size limits still apply. Animated PNGs are not supported by the browser encoder.

= Does browser conversion put less load on my server? =

It moves the image encoding to your computer. That can help when PHP cannot spare enough memory for an image, provided the file fits the browser's limits. Your server still serves the source file, receives and validates the WebP upload, saves it, and updates the conversion records. Browser conversion reduces the encoding work on your hosting; it doesn't remove all server work.

= Is browser conversion a replacement for the queue? =

Use the server queue for background work when your hosting has a WebP writer. Browser conversion gives you a way to convert selected images when it doesn't, and can also help with files that exceed the server's memory estimate. The browser run needs the plugin page to stay open.

= What about photos with rotation data? =

WordPress normally creates an upright version when it processes a photo with EXIF rotation data. Those images convert normally. Older uploads or images that WordPress could not rotate may still depend on a rotation or mirror tag. Browser conversion rejects those files before encoding, including rotations that leave the dimensions unchanged. Save an upright copy without that tag and upload it again.

= Can I close the page while a conversion runs? =

For **server conversion**, yes. Conversion progress is saved, and WP-Cron can continue the queue when your site receives traffic. If traffic is low or WP-Cron is disabled, work may wait until you reopen the plugin page or your configured cron runner runs. You can pause, resume, cancel pending work, or retry failures from the queue controls. If a whole-library job has more than 10,000 failures, retry rescans the library and reuses valid existing WebP files. For **browser conversion**, keep the tab open: closing it stops the remaining work, while files already saved stay in place.

= How do I convert my whole Media Library? =

On a host with a working WebP writer, choose **Convert Entire Library**. The plugin counts eligible images and processes them in small batches, saving progress as it goes. The queue panel shows progress and failures. WP-Cron can continue the job between visits, depending on site traffic or your cron runner. If your host cannot write WebP, use selected browser conversions and keep the page open instead.

= Can I keep an image out of WebP conversion? =

Yes. Use **Exclude from WebP** in the Media Library row actions, or the exclude bulk action for several images at once. Excluded images are skipped by every conversion job, are not regenerated when you change quality settings, and are not served as WebP. Existing copies generated earlier stay on disk until you delete them, and you can include an image again at any time.

= Where do the savings numbers come from? =

The plugin records the original and WebP byte size of every file it generates, so the totals on the plugin page reflect real files rather than estimates. Totals are adjusted when files are regenerated or deleted. If you converted images with an earlier version, press **Recalculate** once to rebuild the totals from the metadata stored on your attachments.

= Can I use WP-CLI? =

Yes. `wp ilswq status` reports server support, settings, queue state, and stored savings; `wp ilswq scan` lists images with their status; `wp ilswq convert` converts specific IDs, a dry run, or the whole library with `--all`; `wp ilswq totals` shows or rebuilds the savings; `wp ilswq queue` inspects or controls the queue; and `wp ilswq cleanup` removes generated WebP files.

= Can new uploads be converted automatically? =

Yes, if your server has a WebP writer and you enable automatic new-upload conversion in Settings. It starts off. Once enabled, new uploads are queued after WordPress finishes creating their image sizes. Conversion runs in the background and follows the same server checks and conversion settings.

= How do I find or export a particular image? =

Run a scan, then search the report by filename, attachment title, or ID. Search works together with the status filters. **Export CSV** downloads only matching rows, and **Convert Selected** queues only selected eligible images in that view. Clear the search and choose **All** to see the full report again.

= Why was an image skipped or marked as a conflict? =

Check the Reason column for that attachment. A file may be missing, unsupported, above your pixel limit, or likely to need more memory than your server can spare. A small file on disk can still require substantial memory when decoded. A conflict means a WebP already exists that this plugin does not own, so it is left unchanged.

= What happens if I edit an image or regenerate thumbnails? =

The plugin detects source-file and quality-setting changes before regenerating its WebP output. It also reconciles its copies when attachment sizes change and cleans them up when an attachment is deleted. Run a fresh scan to review which images need conversion after making changes.

== Screenshots ==

1. Check your hosting's WebP support and choose whether to convert on the server or in your browser.
2. Scan your Media Library, select images, and see which files need conversion or have a conflict.
3. Convert selected images and their generated sizes in the browser, with progress and a result for each file.
4. Review completed conversions, the method used, file sizes, and savings.
5. Manage WebP status and exclusions from the Media Library list.
6. See the completed browser run, including the files saved and any copies skipped because they were not smaller.

== Changelog ==

= 1.0.0 =

* Added browser (WebAssembly) conversion: a copy of the WebP encoder ships with the plugin and can create WebP files on your own computer, so images can still be converted on hosts whose image tools cannot write WebP.
* Added a browser conversion panel with a capability report, per-file progress, a running result log, a stop control, and the same report, savings, and serving updates as server conversion.
* Added a "Convert images in the browser" setting, off by default, and a report reason that names the files only the browser can convert.
* Wrote every browser conversion through the same install, ownership, conflict, totals, and generated-map path as the local image editor backends.
* Kept the server queue from accepting work it cannot perform on a host without a WebP writer, and skipped browser-only files instead of reporting them as failures.
* Added an integration test for the browser backend that covers routing, the REST handshake, container validation, trust boundaries, stored metadata, and frontend serving.

* Fixed server conversion ignoring the selected quality when WordPress changes the output format to WebP.
* Fixed stale browser results after quality changes, damaged outputs, or a replaced attachment source; browser saves recheck the current source and settings.
* Serialized cleanup, totals recalculation, and browser saves with the server queue, kept conflicting controls disabled during a browser run, and allowed conversion after a server job is paused.
* Added a real worker/codec capability probe, useful server error messages, bounded uploads, and EXIF rotation/mirror rejection before decoding.
* Corrected encoder labels and savings for partially converted attachments, and applied memory limits to regeneration too.
* Stopped automatic uploads from retrying browser-only images.
* Verified WordPress 7.1.1 with GD, Imagick, WP-CLI, and the browser backend regressions; updated the CI matrix.
* Added measured size and speed comparisons for large/small JPEG and PNG files using Chrome, GD, and Imagick at quality 80.
* Rewrote the description for shared-hosting users and refreshed six screenshots, including a live WebAssembly conversion.
* Tested browser WebAssembly conversion in Google Chrome 153.0.8010.52 on WordPress 7.1.1 and real Safari 26.6.2 on WordPress 7.1 (macOS), covering JPEGs, transparent PNGs, generated sizes, saved results, and larger-output skips.

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

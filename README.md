# IndexLane Safe WebP & AVIF Queue

**Smaller images. Smarter media reviews. You stay in control.**

Create WebP and AVIF copies of your WordPress images, and use optional AI to draft alternative text, flag possible page-context mismatches, and spot visible privacy concerns. Keep your originals, see the actual savings, and approve every text change yourself.

## Give your Media Library a second pair of eyes

Bring your own **OpenAI, Claude, or Grok API key** and choose a vision-capable model. **Media Review** helps with the work that compression alone cannot do:

- **Draft useful alt text.** Get a concise proposal for an image, with the existing text beside it. Focus on missing alt text or review images that already have it.
- **Check whether an image fits its page.** Optionally compare the image with its attachment parent's title and a short excerpt to flag possible mismatches.
- **Catch visible privacy concerns.** Review screenshots and other images for readable email addresses, phone numbers, names, account identifiers, credentials, and other sensitive content.
- **Review decorative-image suggestions.** Consider an empty alt value when an image adds no information.
- **Make the final call.** Apply or dismiss each proposal. Restore the previous alt text while the review remains current and nobody has edited the value afterward.

A product photo that may not match its page. A screenshot with an email address in view. A library full of blank alt fields. Media Review gives you specific findings to check and text to work from, right inside WordPress.

Review follows the current scan report's filters and search, with **up to 10 matching images per run**, processed one at a time. Context comes from the attachment's recorded parent, not every page where the image appears. AI findings can be wrong; the plugin does not redact images, identify people, or automatically publish text. Applying alt text changes attachment metadata, not image markup already saved in posts or page builders.

## Your provider, your choice

Media Review starts off. When enabled and run, it sends a temporary, metadata-stripped preview directly to the provider you select. Previews are capped at 1600 pixels on the longest edge and 4 MiB. Optional page context includes the parent title and up to 700 characters of plain text. WordPress needs a working image editor to prepare previews, and provider API charges may apply.

Stored API keys are encrypted with AES-256-GCM using WordPress salts. Define `ILSWQ_AI_API_KEY` in `wp-config.php` to keep the key out of the database. Saved keys stay out of browser configuration; changing providers requires that provider's key. Image conversion works independently without an AI account or API key.

## Lighter images without a conversion subscription

Convert JPEG and PNG attachments, including thumbnails and other generated sizes. **No conversion credits, external optimization API, or desktop software.** Everything needed for conversion ships with the plugin.

- **Browser WebP and AVIF:** use your computer's resources through the bundled WebAssembly encoder, even when the host cannot create those formats. Keep the plugin tab open during the run.
- **Background WebP:** use GD or Imagick for selected images, whole-library jobs, and automatic uploads. Jobs save progress and can continue through WP-Cron, depending on traffic or a configured cron runner.
- **Pure-PHP WebP fallback:** on PHP 8.1+ with zlib, the optional bundled encoder can run background conversion without a WebP-capable image editor. It is slower and limited to smaller images.

Search and filter the report, export matching rows as CSV, and manage exclusions from the Media Library. See real file sizes and savings. New copies that are not smaller are skipped by default. Originals and files owned by other tools stay protected.

Enable optional frontend serving when you are ready. WebP replaces matching URLs in normal WordPress image output. AVIF is offered through a `<picture>` element when all responsive candidates have matching copies, with WebP or the original as fallback. AVIF conversion runs in the browser; server jobs produce WebP.

## Try it on a few images

1. Open **Tools → IndexLane Safe WebP & AVIF Queue** and check server support. Enable browser conversion if you need it.
2. Scan the library and select a few images. Use **Convert Selected** for a server job or **Convert Selected in Browser** to use your computer.
3. Review savings and skip reasons, then enable optional frontend serving when you want to use the generated copies.
4. To try AI assistance, save your provider, vision model, and API key in **Media Review**. Filter the scan report, choose **Review Visible Images**, and review each proposal before applying text.

The plugin supports **PHP 7.4+**. Only the optional pure-PHP encoder requires PHP 8.1+. Keep the tab open for browser conversion and AI review runs.

[Plugin details](https://indexlane.dev/plugins/safe-webp-queue) · [Full description and FAQs](readme.txt)

## Measured conversion results

The [plugin description](readme.txt) includes compression and timing comparisons for a large and small JPEG and PNG, measured through the actual plugin in Chrome, GD, and Imagick at quality 80. See [the benchmark record](benchmarks/README.md) for the setup, input hashes, raw samples, and limitations.

## Browser conversion

Browser conversion is the second conversion backend. Instead of `wp_get_image_editor()`, the administrator's browser downloads the source file from the site, encodes it in a Web Worker with the bundled WebAssembly codec, and uploads the finished file to a REST endpoint. The server still authorises the work, validates the returned container and writes the sidecar, so a browser conversion produces exactly the same stored metadata, savings totals and frontend output as a server conversion; only the `editor` label differs (`WASM (browser)` or `WASM AVIF (browser)`).

The browser worker carries two codecs. WebP comes from libwebp through `@jsquash/webp`. AVIF comes from a single-threaded libavif/libaom build brought in with `@jsquash/avif`; the multithreaded variant is avoided so the bundle never needs `SharedArrayBuffer` or COOP/COEP headers. Only the codec for the configured format is loaded. The browser checks the AVIF brand and decodes the result to verify its dimensions; the server checks the still ISO-BMFF container and its `ispe` dimensions. Because ownership is tracked per format, a library can hold both a WebP and an AVIF copy of the same file.

It is off by default and attended: the tab has to stay open, and one image is processed at a time. It never replaces the queue for unattended work.

The panel checks for module Workers, WebAssembly (including a Content Security Policy probe), `createImageBitmap`, `OffscreenCanvas`, Web Crypto, `fetch`, `AbortController` and a secure context, and reports the specific missing capability rather than failing silently.

### Rebuilding the browser bundle

The bundle in `assets/wasm/` is build output. Rebuild it with the pinned toolchain and copy the result back:

```sh
cd wasm
npm ci
npm run build
cp dist/safewebp-browser.js ../assets/wasm/
rsync -a --delete dist/assets/ ../assets/wasm/assets/
```

`npm run build` type checks both the DOM entry and the worker, runs the module regressions, and bundles the result. The build needs Node `^20.19.0` or `>=22.12.0`. Replace the generated assets directory so old hashed chunks cannot linger. Keep the existing `THIRD-PARTY-NOTICES/` directory and omit the bundler's `.vite/` metadata. CI rebuilds the module and compares it with the committed files, so the sources and the shipped bundle cannot drift apart.

## Pure-PHP conversion

Pure-PHP conversion is the third backend. The plugin bundles [`nikocodes/picowebp`](https://packagist.org/packages/nikocodes/picowebp), a WebP encoder written in PHP, so a host with no WebP-writing image editor — and no browser in the loop — can still convert on the server. Because it runs in the queue rather than a tab, whole-library jobs, automatic uploads and WP-CLI all work with it.

The plugin still supports **PHP 7.4+**. picowebp is optional: PHP 8.1+ and `ext-zlib` are detected automatically, its library is never loaded on PHP 7.4 or 8.0, and its setting is disabled on incompatible hosts. GD/Imagick and browser conversion remain independent options. Composer lists picowebp as a suggestion, so installing the plugin does not require a newer PHP runtime.

On compatible hosts, PHP conversion stays off until enabled and only runs when GD/Imagick cannot write WebP. It is much slower than libwebp, so it is capped at two megapixels per source file and processes one attachment per queue request, including automatic uploads. The `ilswq_php_encoder_max_pixels` filter can adjust the cap; the site's own max-pixels setting can only lower it. Actual file dimensions are checked before decoding. Outputs carry the `PHP (picowebp)` editor label and use the same ownership, savings, cleanup and serving paths as other conversions.

Unsupported inputs, such as progressive JPEGs on a host without GD, and files above the cap can use browser conversion when it is enabled and its limits are met. JPEGs that still require EXIF rotation or mirroring must be uploaded as upright copies first.

### Refreshing the bundled library

The package is the source of truth. The plugin ships the copy under `includes/picowebp/`, so users do not need Composer. To refresh it, install the optional source package on a PHP 8.1+ development machine or use a local checkout, then sync:

```sh
bash scripts/sync-picowebp.sh
```

The script reads the installed Composer package, or the local `codec/picowebp` checkout while developing the package itself, and refuses to copy a tree that contains hidden files. Update the upstream version note in `includes/picowebp/README.md` when you bump it.

## Know what changes

The plugin converts JPEG and PNG Media Library attachments. It preserves saved post content and attachment URLs. Frontend serving works through normal WordPress image-output filters; it does not rewrite saved image HTML, CSS backgrounds, theme files, page builder fields, or hardcoded URLs. GIF, SVG, AVIF, and existing WebP files are outside its conversion scope.

Smaller WebP and AVIF copies can reduce the image data visitors download when those copies are served. Results depend on your images and site. Keeping the originals, a WebP copy and an AVIF copy uses additional disk space.

See [readme.txt](readme.txt) for installation steps, FAQs, and release history.

## Development checks

Run the JavaScript regression checks and translation audit:

```sh
node --check assets/admin.js
node tests/admin-js.js
php tests/php-encoder.php
php tests/avif-container.php
php tests/ai-wordpress.php /path/to/wordpress
bash scripts/check-i18n.sh
```

`tests/php-encoder.php` checks automatic disabling without loading picowebp on older PHP runtimes. On PHP 8.1+ with zlib it exercises the bundled PNG/JPEG readers without GD, the enable setting, actual pixel limits, WebP output, ICC preservation, EXIF orientation protection and unsupported-input routing.

`tests/avif-container.php` runs without WordPress. It builds still, animated, HEIF-branded, truncated and oversized AVIF containers and checks that the server-side box walk accepts only the still images, together with the format and generated-file helpers.

The translation check requires PHP and WP-CLI. Build the release ZIP and WordPress.org SVN bundle with:

```sh
bash scripts/build-wordpress-org-dist.sh
```

Install that ZIP in an isolated WordPress environment, then run:

```sh
php tests/smoke-wordpress.php /path/to/wordpress
php tests/cli-wordpress.php /path/to/wordpress
php tests/browser-wordpress.php /path/to/wordpress
```

The smoke test exercises JPEG and transparent PNG conversion, generated sizes, persistent queue controls and retries, whole-library jobs, exclusions, Media Library actions, stored savings totals and rebuilds, foreign WebP protection, optional frontend serving, automatic uploads, source and quality changes, and attachment cleanup. The WP-CLI test boots WordPress with a WP-CLI stand-in and runs every command. Set `ILSWQ_SMOKE_EDITOR=GD` or `ILSWQ_SMOKE_EDITOR=Imagick` to check a specific editor.

The browser test covers the server side of the WebAssembly backend: capability routing on a host without a WebP writer, the REST prepare/finish handshake, WebP container validation, prepared-job ownership, changed sources, foreign sibling files, replay, stored metadata, totals, frontend serving, and cleanup. When the optional PHP encoder is available, it also checks manual and automatic queue batching, PHP-generated maps and totals, and browser fallback. It needs GD with WebP writing to build its fixtures and exits with status 3 when that is unavailable.

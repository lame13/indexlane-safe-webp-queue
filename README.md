# IndexLane Safe WebP Queue

**Smaller images, without another subscription.**

Your hosting plan may be light on image tools. Your images don't have to stay heavy. Create WebP copies right from your WordPress admin, without paying for an image-conversion API or asking your host to install missing libraries. Keep your original JPEGs and PNGs, and choose when to serve the WebP copies.

Shared hosting can lack WebP support or enough PHP memory for image conversion. Enable **Convert images in the browser** and your computer does the encoding with the plugin's bundled WebAssembly encoder. Each source comes from your own site; each finished WebP goes back there. There is no desktop app or browser extension to install.

If WordPress has GD or Imagick with WebP support, server conversion can handle selected images, the whole library, and future uploads in background batches. The standalone `cwebp` command is not used by this version.

## Try it on a few images

1. Open **Tools → IndexLane Safe WebP Queue** and check server support. Enable browser conversion if you need it.
2. Scan the library and select a few images. Use **Convert Selected** for a server job or **Convert Selected in Browser** to use your computer.
3. Review file sizes, savings, and any skip reasons. Both methods include generated attachment sizes.
4. Enable optional frontend serving when you want matching WebP copies used in normal WordPress image output.

Keep the tab open for browser conversion. Server jobs save progress and can continue through WP-Cron, which depends on site traffic or a configured cron runner. Whole-library jobs and automatic uploads need server WebP support.

Search and filter the report, export matching rows as CSV, or manage status and exclusions from the Media Library list. Stored totals cover both conversion methods. Originals stay in place, foreign WebPs are protected, and new copies that are not smaller are skipped by default.

[Plugin details](https://indexlane.dev/plugins/safe-webp-queue) · [Full description and FAQs](readme.txt)

## Measured conversion results

The [plugin description](readme.txt) includes compression and timing comparisons for a large and small JPEG and PNG, measured through the actual plugin in Chrome, GD, and Imagick at quality 80. See [the benchmark record](benchmarks/README.md) for the setup, input hashes, raw samples, and limitations.

## Browser conversion

Browser conversion is the second conversion backend. Instead of `wp_get_image_editor()`, the administrator's browser downloads the source file from the site, encodes WebP in a Web Worker with the bundled WebAssembly codec, and uploads the finished file to a REST endpoint. The server still authorises the work, validates the returned container and writes the sidecar, so a browser conversion produces exactly the same stored metadata, savings totals and frontend output as a server conversion; only the `editor` label differs (`WASM (browser)`).

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

## Know what changes

The plugin converts JPEG and PNG Media Library attachments. It preserves saved post content and attachment URLs. Frontend serving works through normal WordPress image-output filters; it does not rewrite saved image HTML, CSS backgrounds, theme files, page builder fields, or hardcoded URLs. GIF, SVG, AVIF, and existing WebP files are outside its conversion scope.

Smaller WebP copies can reduce the image data visitors download when those copies are served. Results depend on your images and site. Keeping both formats uses additional disk space.

See [readme.txt](readme.txt) for installation steps, FAQs, and release history.

## Development checks

Run the JavaScript regression checks and translation audit:

```sh
node --check assets/admin.js
node tests/admin-js.js
bash scripts/check-i18n.sh
```

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

The browser test covers the server side of the WebAssembly backend: capability routing on a host without a WebP writer, the REST prepare/finish handshake, WebP container validation, prepared-job ownership, changed sources, foreign sibling files, replay, stored metadata, totals, frontend serving, and cleanup. It needs GD with WebP writing to build its fixtures and exits with status 3 when that is unavailable.

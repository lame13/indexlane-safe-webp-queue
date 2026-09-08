# IndexLane Safe WebP Queue

**Lighter images. Your originals kept. You're in control.**

Create WebP copies of your WordPress Media Library images, compare the file-size savings, and choose when to use them on your site. Your JPEGs and PNGs stay in place, and conversion runs on your own server with no cloud account or API key.

[View the plugin on IndexLane](https://indexlane.dev/plugins/safe-webp-queue)

## Try it on a few images

Open **Tools → IndexLane Safe WebP Queue**, check your server's WebP support, and select **Scan Media Library**. Choose a few eligible images, click **Convert Selected**, and review the results before working through more of your library.

- **Keep control of the work.** Convert attachments and their generated sizes in small batches. Progress is saved when you close the page, with pause, resume, cancel, and failed-item retry controls.
- **Find the images that matter.** Search the report by filename, title, or attachment ID and combine search with a status filter. Conversion selection and CSV exports use the matching results.
- **Judge the result yourself.** Compare original and WebP sizes and see why an image was skipped or failed. New WebPs that are larger than their source are skipped by default.
- **Keep your originals.** Remove this plugin's WebP copies when needed. WebP files created by other tools are reported as conflicts and left alone.
- **Choose when to go live.** Optional frontend serving uses matching copies in normal WordPress image output. Optional new-upload conversion queues future images after WordPress creates their sizes. Both settings start off.

Background work uses WP-Cron and depends on site traffic or a configured cron runner. Your server needs a compatible WordPress image editor with GD or Imagick WebP support; server checks and memory estimates help skip files that are too demanding.

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
```

The smoke test exercises JPEG and transparent PNG conversion, generated sizes, persistent queue controls and retries, foreign WebP protection, optional frontend serving, automatic uploads, source and quality changes, and attachment cleanup. Set `ILSWQ_SMOKE_EDITOR=GD` or `ILSWQ_SMOKE_EDITOR=Imagick` to check a specific editor.

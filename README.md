# IndexLane Safe WebP Queue

Convert WordPress Media Library images and generated sizes to WebP locally.

IndexLane Safe WebP Queue runs inside wp-admin, checks server support first, converts in small batches, keeps originals, and shows whether each image was converted, skipped, failed, conflicted, or needs review.

It is for cautious local WebP conversion: no cloud service, no database URL rewrites, no original-file replacement.

[Plugin page on IndexLane](https://indexlane.dev/plugins/safe-webp-queue)

## What it does

- Runs from Tools -> IndexLane Safe WebP Queue.
- Checks GD and Imagick WebP support before conversion.
- Converts in small browser-driven batches.
- Stores uploads-relative metadata for generated WebP files.
- Detects stale or invalid generated WebP files.
- Treats unrelated sibling WebP files as conflicts and leaves them unchanged.
- Validates every plugin-generated WebP represented in the current report.
- Exports the visible report as CSV.
- Deletes plugin-generated WebP files on request.
- Optionally serves generated WebP files in normal WordPress image output.
- Optionally generates WebP files for new uploads.

## What it does not do

- It does not delete original JPEG or PNG files.
- It does not rewrite post content or database image URLs.
- It does not call an external API or cloud optimization service.
- It does not convert GIF, SVG, AVIF, CSS images, theme files, or hardcoded URLs.

## Local Smoke Test

Copy the plugin into a WordPress install, activate it, then run:

```sh
php tests/smoke-wordpress.php /path/to/wordpress
```

The smoke test creates repeatable JPEG and transparent PNG fixtures, preserves an unrelated sibling WebP as a conflict, converts generated attachment sizes, validates complete WebP maps, checks optional frontend serving, verifies uploads-relative metadata storage, checks automatic new-upload conversion, and confirms failed cleanup keeps ownership metadata until a successful retry.

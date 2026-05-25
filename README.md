# Safe WebP Queue

Safe WebP Queue is a local WordPress WebP conversion plugin for cautious Media Library cleanup.

It scans JPEG and PNG attachments, estimates memory risk, converts selected originals and generated image sizes to sibling `.webp` files, keeps originals, and reports why each attachment converted, skipped, failed, or needs review.

## What it does

- Runs from Tools -> Safe WebP Queue.
- Checks GD and Imagick WebP support before conversion.
- Converts in small browser-driven batches.
- Stores uploads-relative metadata for generated WebP files.
- Detects stale or invalid generated WebP files.
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

The smoke test creates JPEG and transparent PNG fixtures, converts generated attachment sizes, validates WebP output, checks optional frontend serving, verifies uploads-relative metadata storage, checks automatic new-upload conversion, and confirms cleanup removes generated files.

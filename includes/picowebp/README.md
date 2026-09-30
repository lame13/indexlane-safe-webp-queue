# picowebp (bundled)

This directory is a bundled copy of the [`nikocodes/picowebp`](https://packagist.org/packages/nikocodes/picowebp)
package: a pure-PHP VP8 lossy WebP encoder with bundled PNG and JPEG readers.
The plugin uses it as its server-side conversion backend for hosts that have no
WebP-writing image editor and no browser in the loop.

The package is the source of truth. This copy is produced by
[`scripts/sync-picowebp.sh`](../../scripts/sync-picowebp.sh) and tested by CI,
so the bundled files must never be edited in place.

- Upstream version: 1.0.0
- License: MIT (see [LICENSE](LICENSE))
- Requires: PHP 8.1+ and `ext-zlib` at runtime; `ext-gd` is optional

This backend is optional. The plugin itself supports PHP 7.4+ and never loads
these sources on PHP 7.4 or 8.0. Its setting is disabled automatically when
the runtime requirements are not met. Composer installation of the plugin
does not require this source package.

Only the package's [`src/`](src) tree and its `LICENSE` are bundled. The
command-line binary, examples and tests are intentionally left out of the
release package.

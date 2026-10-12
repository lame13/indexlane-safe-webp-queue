# Browser WebP and AVIF module

This is the browser conversion module shipped with IndexLane Safe WebP & AVIF Queue. It runs from the authenticated plugin admin page and stops when the tab closes. The batch handles existing JPEG/PNG attachment variants, including existing thumbnail files. Generating missing thumbnails and processing an upload before WordPress creates its attachment metadata are outside this version.

The batch encodes either **WebP** or **AVIF**, chosen per run by the server's `format` field. WebP uses the saved JPEG or PNG quality and libwebp method **4**. AVIF uses the separate AVIF quality on libavif's scale. Both are lossy and `runBatch` never requests lossless. Each `encodeImage()` call carries the target format, and the worker loads only that format's codec.

## Build and copy

Use Node `^20.19.0` or `>=22.12.0` and npm:

```sh
npm ci
npm run build
```

The supplied lockfile pins the installed dependency graph. Direct dependencies are `@jsquash/webp` 1.5.0, `@jsquash/avif` 2.1.1, TypeScript 7.0.2, and Vite 8.3.0. Copy `dist/safewebp-browser.js` and the complete `dist/assets/` directory to `assets/wasm/` in the plugin, replacing the previous generated files. Do not ship the hidden `dist/.vite/` build metadata. Keep `THIRD-PARTY-NOTICES/`: Vite's generated licence listing does not capture these worker dependencies, and the AVIF codec needs its libavif and libaom notices. No runtime CDN is used. Do not deploy `node_modules/`.

Serve JavaScript with a JavaScript MIME type and `.wasm` as `application/wasm`. Use a secure context (HTTPS, or localhost during development). A site's CSP must allow its own module scripts, workers, and WASM compilation; test against that site's actual policy. Neither codec needs a SharedArrayBuffer/COOP/COEP deployment: the AVIF path deliberately imports the single-threaded libavif build rather than the `avif_enc_mt` variant, and that build is only fetched when AVIF is configured.

`dist/safewebp-browser.js` is an ES module with named exports, not a classic script that adds globals. Keep its neighboring `assets/` directory intact; `base: './'` allows placing this build beneath a plugin directory. Vite's manifest alone does not enumerate every dynamically imported worker asset, so copying only manifest-listed files is insufficient.

## Call it from the admin page

WordPress supplies this configuration using `wp_json_encode`, an admin-only script, and a fresh `wp_create_nonce('wp_rest')`. Generate both complete URLs with `rest_url` so sites using query-style REST URLs also work:

```js
// Values are injected by WordPress; do not hard-code an example nonce.
const config = window.ILSWQ_Admin.browser;
// {
//   prepareUrl: rest_url('ilswq-wasm/v1/prepare'),
//   finishUrl: rest_url('ilswq-wasm/v1/finish'),
//   nonce: wp_create_nonce('wp_rest')
// }

const { runBatch } = await import('./safewebp-browser.js');
const controller = new AbortController();
const selected = [
  { attachmentId: 42, sizeKey: 'full' },
  { attachmentId: 42, sizeKey: 'medium' },
];

try {
  const outcomes = await runBatch(config, selected, (event) => {
    // Update your admin UI. Use textContent for error messages; do not throw here.
    console.log(event);
  }, controller.signal);
  console.log(outcomes);
} catch (error) {
  // Cancellation or an expired session stops the remaining queue.
  console.error(error);
}
// Connect a separate Stop button to: controller.abort();
```

Each selection is one stored file, not an entire attachment. `full` is the attachment's current full-size file; it does not imply a historical pre-scaling original. The array is copied when the run starts. A module instance rejects overlapping batches. Separate tabs may encode simultaneously; the server serializes final saves with the same lock used by the conversion queue.

Progress events have either `{index,total,variant,phase}` with phase `preparing`, `downloading`, `encoding`, or `uploading`, or `{index,total,outcome}`. Indexes are zero-based. An outcome has `status:'completed'` and a confirmed server `result`, or `status:'failed'` and a message. Ordinary image failures are reported and the queue continues. HTTP 401/403 stops the batch so the page can refresh its session/nonce. The progress callback must not throw.

An upload that times out or is cancelled may already have committed on the server. Cancellation interrupts browser work; it does not roll back a finished request. Prepare the same selection again to reconcile its server state before retrying. The server, rather than the browser, decides whether an output is smaller and should be kept.

The standalone entry transfers and detaches its input buffer:

```ts
import { encodeImage } from './src/index';

const result = await encodeImage(sourceArrayBuffer, {
  format: 'webp', // or 'avif'
  quality: 80, // libwebp's 0..100 scale, not canvas's 0..1 scale
  lossless: false,
}, { signal: controller.signal });
const output = new Blob([result.bytes], { type: result.mimeType });
```

## What the code actually does

1. `batch.ts` prepares one variant using the REST nonce. All endpoints and source URLs must be same-origin, and fetch redirects are rejected. The response body is streamed with a byte cap, including when Content-Length is missing or incorrect.
2. It checks downloaded length and SHA-256 against the server's prepared snapshot, catching changed or stale source bytes before encoding.
3. `encoder.ts` transfers the compressed source bytes into a fresh dedicated worker. Its main-thread timer terminates the worker on timeout or cancellation; a cancel message cannot interrupt synchronous WASM encoding.
4. `worker.ts` preflights JPEG/PNG headers and dimensions before decoding. It rejects APNG rather than discarding animation. `createImageBitmap` and `OffscreenCanvas` produce RGBA inside the worker, then jSquash/libwebp or the single-threaded libavif build performs the actual encode there.
5. The worker checks the output envelope for the requested format (RIFF/WebP or an ISO-BMFF `ftyp` with a still AVIF brand), decodes the encoded output with `createImageBitmap`, and checks its dimensions. It transfers the resulting bytes back. The controller terminates the worker after every result, releasing that instance's heap.
6. The main thread checks returned dimensions against the prepared server dimensions, then posts multipart `{token,file}`. The server independently validates, locks, saves a sidecar, and records its mapping. Browser checks are not an authorization or file-validation boundary.

The source and output message protocols use discriminated unions and runtime checks of `unknown` values. DOM and Worker TypeScript libraries are checked separately. Workers are fresh per file for predictable cleanup; repeated codec initialization costs some CPU, although browsers can cache the local WASM downloads.

## Bounds and tradeoffs

| Item | Limit |
|---|---:|
| Compressed source | 20 MiB |
| Either image dimension | 8,192 px |
| Decoded image area | 16,000,000 px |
| Encoded output | 32 MiB |
| Encoding and output verification | 60 seconds per image |
| Each HTTP request including body | 30 seconds |
| Selected variants | 500 |
| In-flight work in a batch | 1 image |

These are rejection limits, not a guaranteed browser memory budget. One 16-megapixel RGBA surface is approximately 64 MB (61 MiB), before compressed input, bitmap/canvas backing storage, WASM copies and scratch space, and output verification. Peak memory can be several times that amount. The canvas is shrunk after extracting pixels and the worker is terminated after each image. Lower the pixel cap for mobile or low-memory deployments; keep client/server caps aligned. Lossy encoding and quality 80 do not remove the full-resolution pixel buffers. Method 4 selects encoding effort; it is not a memory cap.

This module does not resize, copy EXIF/XMP/ICC metadata, preserve embedded color profiles, or promise archival pixel identity. The browser may perform color management, and canvas has alpha representation limitations. JPEG APP1 and PNG eXIf orientation tags are inspected before decoding; rotation or mirror tags are rejected even when they leave the dimensions unchanged. Decoded dimension mismatches are also rejected. Normalize orientation before this stage.

Supported browsers must provide module Workers, WebAssembly, `createImageBitmap` and `OffscreenCanvas` inside the worker, fetch streams, Web Crypto (including `randomUUID`), and AbortController with `AbortSignal.throwIfAborted`. The admin page calls `probeBrowserEncoder(format)` to load a real module worker, check its 2D canvas, and initialize the codec for the configured format before enabling conversion. There is no silently blocking main-thread fallback. A compatibility extension could decode with an HTML image and document canvas, then transfer RGBA to the same worker; that adds main-thread work and needs its own orientation, allocation, and cancellation tests.

## jSquash/Vite details that matter

The installed 1.5.0 source and declarations were inspected directly. `encode` accepts `ImageData` and partial WebP options and returns `Promise<ArrayBuffer>`. The typed `init` accepts a module-options object; this code uses `init({locateFile})`.

The package's initializer detects SIMD and dynamically selects `webp_enc_simd.js` or `webp_enc.js`. Therefore the build imports **both corresponding `.wasm?url` assets** and maps their filenames in `locateFile`. Supplying a scalar module blindly can mismatch the selected SIMD glue. Do not use `new URL('a-package-name/...', import.meta.url)` as if a package name were a browser URL, and do not assume an Emscripten-relative filename survives bundling.

For AVIF the package's own `init()` is not used, because it upgrades to the multithreaded `avif_enc_mt` build whenever `wasm-feature-detect` reports threads. The worker imports the single-threaded factory from `@jsquash/avif/codec/enc/avif_enc.js` and the shared `initEmscriptenModule` helper's behaviour through the factory's own `locateFile`, mapping `avif_enc.wasm` to the emitted asset. `@jsquash/avif`'s TypeScript declarations reuse the same global `EmscriptenWasm` namespace as `@jsquash/webp`, so referencing both sets of declarations collides; `src/wasm.d.ts` declares the two AVIF deep imports locally instead.

Vite recognizes the literal `new Worker(new URL('./worker.ts', import.meta.url), {type:'module'})` construction and builds a separate module worker. WASM is imported as a URL for Emscripten to instantiate; it is not treated as a self-contained `?init` module without its glue imports. Explicit URL mapping and the copied asset directory are essential.

References: [jSquash repository](https://github.com/jamsinclair/jSquash), [Vite worker and asset handling](https://vite.dev/guide/features.html#web-workers), [Emscripten locateFile](https://emscripten.org/docs/api_reference/module.html#Module.locateFile). Dependency inspection, rather than fetched GitHub pages, verified the exact jSquash API in this environment.

## AVIF

AVIF is a lazily loaded worker codec branch with its own quality setting, output decoder check, and memory/time limits, exactly as the 1.0.0 boundary described. The server side is format-aware rather than a filename switch: it has a separate AVIF validator and extension, per-format map keys, and `<picture>`-based serving with a fallback. Only the single-threaded libavif/libaom build is imported, so the bundle keeps working without cross-origin isolation.

AVIF encoding is slower than WebP, especially with the default `speed: 6`, so remain within the same 16-megapixel and 60-second per-image bounds. The measurement harness should still be run against the representative image set before changing batch or quality defaults; AVIF can also come out larger than WebP for tiny, already-optimized images, which is why the server keeps applying the skip-larger rule per format.

## Verification status

For 1.2.0, `npm run build` passed, including strict TypeScript checks for both the DOM entry and the worker and the module regression tests. The shipped output matches the build and includes the scalar and SIMD WebP WASM assets, the AVIF WASM asset, their local worker/glue chunks, and the libwebp, libavif, libaom, jSquash and wasm-feature-detect licence notices.

Actual WebP conversions through the WordPress admin passed in Chrome 153.0.8010.52 on WordPress 7.1.1 and Safari 26.6.2 on WordPress 7.1, both on macOS. These runs covered JPEGs, transparent PNGs, generated sizes, saved results, and larger-output skips. Chrome also passed stopping a run, report refresh, and responsive layout checks. Firefox and older browser versions have not been tested directly. The PHP backend regressions run separately in `tests/browser-wordpress.php`, and the AVIF container validators run in `tests/avif-container.php`. A live AVIF run through the admin UI still needs a manual pass in a real browser before release.

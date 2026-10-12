import encode, { init } from '@jsquash/webp/encode.js';
import wasmUrl from '@jsquash/webp/codec/enc/webp_enc.wasm?url';
import simdWasmUrl from '@jsquash/webp/codec/enc/webp_enc_simd.wasm?url';
import createAvifEncoder from '@jsquash/avif/codec/enc/avif_enc.js';
import { defaultOptions as avifDefaults } from '@jsquash/avif/meta.js';
import avifWasmUrl from '@jsquash/avif/codec/enc/avif_enc.wasm?url';
import { inspectImage } from './image-header';
import { assertOutput, isEncodeRequest, isOutputFormat, isRecord, messageOf, outputMime } from './protocol';
import type { EncodeFailure, EncodeRequest, EncodeResult, OutputFormat } from './protocol';

// This module is compiled with WebWorker types, separate from the DOM entry.
const scope: DedicatedWorkerGlobalScope = self;
let busy = false;

// Pass the emitted local WASM URL explicitly. Emscripten's default relative
// lookup cannot reliably find a file that Vite has moved/renamed during build.
async function initializeCodec(): Promise<void> {
  // v1.5 selects its SIMD glue automatically. Map BOTH matching WASM binaries;
  // supplying only the scalar binary can mismatch the selected SIMD glue.
  await init({
    locateFile: (path: string): string => {
      if (path.endsWith('webp_enc_simd.wasm')) return simdWasmUrl;
      if (path.endsWith('webp_enc.wasm')) return wasmUrl;
      throw new Error(`Unexpected encoder asset: ${path}`);
    },
  });
}

interface AvifModule {
  encode(
    data: BufferSource,
    width: number,
    height: number,
    options: Record<string, unknown>,
  ): Uint8Array | null;
}

let avifModule: Promise<AvifModule> | undefined;

// The single-threaded libavif build is loaded on demand, only when the site is
// configured for AVIF. `speed: 6` trades a little size for a much shorter
// encode; `subsample: 1` keeps 4:2:0 chroma like a normal photographic JPEG.
function initializeAvifCodec(): Promise<AvifModule> {
  avifModule ??= createAvifEncoder({
    locateFile: (path: string): string => {
      if (path.endsWith('avif_enc.wasm')) return avifWasmUrl;
      throw new Error(`Unexpected encoder asset: ${path}`);
    },
  });
  return avifModule;
}

async function encodeWebpOutput(
  pixels: ImageData,
  options: EncodeRequest['options'],
): Promise<ArrayBuffer> {
  await initializeCodec();
  const output = await encode(pixels, {
    quality: options.quality,
    lossless: options.lossless ? 1 : 0,
    method: 4,
  });
  return output;
}

async function encodeAvifOutput(
  pixels: ImageData,
  options: EncodeRequest['options'],
): Promise<ArrayBuffer> {
  const module = await initializeAvifCodec();
  const output = module.encode(new Uint8Array(pixels.data.buffer), pixels.width, pixels.height, {
    ...avifDefaults,
    quality: options.lossless ? 100 : options.quality,
    qualityAlpha: options.lossless ? -1 : 100,
    subsample: options.lossless ? 3 : avifDefaults.subsample,
    bitDepth: 8,
    lossless: options.lossless,
  });
  if (!output) throw new Error('Encoding error.');
  // Return a standalone ArrayBuffer: a Uint8Array view can share a larger or
  // SharedArrayBuffer, which is neither transferable nor a valid ArrayBuffer.
  return output.buffer.slice(output.byteOffset, output.byteOffset + output.byteLength) as ArrayBuffer;
}

async function convert(request: EncodeRequest): Promise<EncodeResult> {
  if (typeof createImageBitmap !== 'function' || typeof OffscreenCanvas !== 'function') {
    throw new Error('This browser does not support image conversion inside a worker.');
  }
  const header = inspectImage(request.bytes); // before a decoded bitmap can allocate
  const format: OutputFormat = request.options.format;
  const bitmap = await createImageBitmap(new Blob([request.bytes], { type: header.mimeType }), {
    imageOrientation: 'none',
    premultiplyAlpha: 'none',
    colorSpaceConversion: 'default',
  });
  const canvas = new OffscreenCanvas(header.width, header.height);
  try {
    if (bitmap.width !== header.width || bitmap.height !== header.height) {
      throw new Error('Decoded image dimensions differ; normalize image orientation before retrying.');
    }
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) throw new Error('A canvas context is unavailable.');
    context.drawImage(bitmap, 0, 0);
    bitmap.close();
    const pixels = context.getImageData(0, 0, header.width, header.height);
    canvas.width = 1;
    canvas.height = 1;
    const output = format === 'avif'
      ? await encodeAvifOutput(pixels, request.options)
      : await encodeWebpOutput(pixels, request.options);
    assertOutput(output, format);
    // Confirm browser-decodable output and dimensions before allowing upload.
    const verification = await createImageBitmap(new Blob([output], { type: outputMime(format) }));
    try {
      if (verification.width !== header.width || verification.height !== header.height) {
        throw new Error('Encoded image dimensions differ from the source.');
      }
    } finally { verification.close(); }
    return {
      type: 'result', id: request.id, bytes: output, width: header.width,
      height: header.height, sourceBytes: request.bytes.byteLength, mimeType: outputMime(format),
    };
  } finally {
    bitmap.close();
    canvas.width = 1;
    canvas.height = 1;
  }
}

scope.onmessage = (event: MessageEvent<unknown>): void => {
  if (isRecord(event.data) && event.data.type === 'probe') {
    const format: OutputFormat = isOutputFormat(event.data.format) ? event.data.format : 'webp';
    void (async () => {
      if (typeof createImageBitmap !== 'function') throw new Error('createImageBitmap in a worker');
      if (typeof OffscreenCanvas !== 'function' || !new OffscreenCanvas(1, 1).getContext('2d')) {
        throw new Error('OffscreenCanvas 2D in a worker');
      }
      if (format === 'avif') await initializeAvifCodec();
      else await initializeCodec();
      scope.postMessage({ type: 'probe', ok: true });
    })().catch((error: unknown) => scope.postMessage({ type: 'probe', ok: false, message: messageOf(error) }));
    return;
  }
  if (!isEncodeRequest(event.data)) {
    // The controller rejects the malformed response and terminates this worker.
    const response: EncodeFailure = { type: 'error', id: '', message: 'Invalid worker request.' };
    scope.postMessage(response);
    return;
  }
  const request = event.data;
  if (busy) {
    const response: EncodeFailure = { type: 'error', id: request.id, message: 'Worker is already busy.' };
    scope.postMessage(response);
    return;
  }
  busy = true;
  void convert(request).then(
    (response) => scope.postMessage(response, [response.bytes]),
    (error: unknown) => {
      const response: EncodeFailure = { type: 'error', id: request.id, message: messageOf(error) };
      scope.postMessage(response);
    },
  ).finally(() => { busy = false; });
};

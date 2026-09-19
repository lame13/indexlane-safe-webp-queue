import { LIMITS, assertWebP, isEncodeOptions, isEncodeResponse, positiveInteger } from './protocol';
import type { EncodeOptions, EncodeRequest, EncodeResult } from './protocol';

export interface EncodeControl { readonly signal?: AbortSignal; readonly timeoutMs?: number }

/** Prove module-worker support and load the actual codec under this site's CSP. */
export function probeBrowserEncoder(): Promise<void> {
  return new Promise((resolve, reject) => {
    const worker = new Worker(new URL('./worker.ts', import.meta.url), { type: 'module' });
    const finish = (error?: Error): void => {
      clearTimeout(timeout);
      worker.terminate();
      if (error) reject(error);
      else resolve();
    };
    const timeout = setTimeout(() => finish(new Error('Module worker or WebAssembly encoder did not load.')), 15_000);
    worker.onerror = () => finish(new Error('Module worker or WebAssembly encoder blocked or unavailable.'));
    worker.onmessageerror = () => finish(new Error('Module worker response unavailable.'));
    worker.onmessage = (event: MessageEvent<{ type?: string; ok?: boolean; message?: string }>) => {
      if (event.data?.type !== 'probe') return;
      finish(event.data.ok ? undefined : new Error(event.data.message || 'Browser encoder unavailable.'));
    };
    worker.postMessage({ type: 'probe' });
  });
}

/** Transfers ownership of `bytes`; its ArrayBuffer is detached after dispatch. */
export async function encodeWebP(
  bytes: ArrayBuffer,
  options: EncodeOptions = { quality: 80, lossless: false },
  control: EncodeControl = {},
): Promise<EncodeResult> {
  if (!positiveInteger(bytes.byteLength, LIMITS.sourceBytes)) throw new Error('Invalid source size.');
  if (!isEncodeOptions(options)) throw new Error('Invalid encoding options.');
  const timeoutMs = control.timeoutMs ?? LIMITS.encodeTimeoutMs;
  if (!positiveInteger(timeoutMs, 300_000)) throw new Error('Invalid encoding timeout.');
  control.signal?.throwIfAborted();
  const worker = new Worker(new URL('./worker.ts', import.meta.url), { type: 'module' });
  const id = crypto.randomUUID();
  const sourceBytes = bytes.byteLength;
  return new Promise<EncodeResult>((resolve, reject) => {
    let settled = false;
    const finish = (result: EncodeResult | Error): void => {
      if (settled) return;
      settled = true;
      clearTimeout(timeout);
      control.signal?.removeEventListener('abort', abort);
      // Termination interrupts synchronous WASM as well as async decoding and
      // frees the instance for every result. A postMessage("cancel") cannot do so.
      worker.terminate();
      if (result instanceof Error) reject(result);
      else resolve(result);
    };
    const abort = (): void => finish(new DOMException('Image conversion cancelled.', 'AbortError'));
    const timeout = setTimeout(() => finish(new Error('Image conversion timed out.')), timeoutMs);
    control.signal?.addEventListener('abort', abort, { once: true });
    worker.onerror = () => finish(new Error('Image worker failed to load or execute.'));
    worker.onmessageerror = () => finish(new Error('Unreadable image worker response.'));
    worker.onmessage = (event: MessageEvent<unknown>): void => {
      if (!isEncodeResponse(event.data) || event.data.id !== id) {
        finish(new Error('Invalid image worker response.'));
        return;
      }
      if (event.data.type === 'error') { finish(new Error(event.data.message)); return; }
      try {
        assertWebP(event.data.bytes);
        if (event.data.sourceBytes !== sourceBytes) throw new Error('Worker source size differs.');
        finish(event.data);
      } catch (error: unknown) {
        finish(error instanceof Error ? error : new Error('Invalid WebP output.'));
      }
    };
    if (control.signal?.aborted) { abort(); return; }
    const request: EncodeRequest = { type: 'encode', id, bytes, options };
    try { worker.postMessage(request, [bytes]); }
    catch (error: unknown) { finish(error instanceof Error ? error : new Error('Worker transfer failed.')); }
  });
}

import { encodeWebP } from './encoder';
import { LIMITS, isRecord, messageOf, positiveInteger, validDimensions } from './protocol';

export interface Variant { readonly attachmentId: number; readonly sizeKey: string }
export interface BatchConfig { readonly prepareUrl: string; readonly finishUrl: string; readonly nonce: string }
export type JobPhase = 'preparing' | 'downloading' | 'encoding' | 'uploading';
export interface StoredResult {
  readonly status: 'ready' | 'skipped_not_smaller';
  readonly inputBytes: number;
  readonly outputBytes?: number;
  readonly outputUrl?: string;
}
export type Outcome =
  | { readonly variant: Variant; readonly status: 'completed'; readonly result: StoredResult }
  | { readonly variant: Variant; readonly status: 'failed'; readonly message: string };
export type Progress =
  | { readonly index: number; readonly total: number; readonly variant: Variant; readonly phase: JobPhase }
  | { readonly index: number; readonly total: number; readonly outcome: Outcome };
interface Prepared {
  readonly status: 'prepared';
  readonly token: string;
  readonly sourceUrl: string;
  readonly sourceSha256: string;
  readonly width: number;
  readonly height: number;
  readonly inputBytes: number;
  readonly mimeType: 'image/jpeg' | 'image/png';
  readonly quality: number;
}

class SessionError extends Error {}
let batchRunning = false;
function sameOriginUrl(value: string): string {
  const url = new URL(value, location.href);
  if (url.origin !== location.origin || !['https:', 'http:'].includes(url.protocol) ||
      url.username || url.password) throw new Error('Only same-origin URLs are supported.');
  return url.href;
}
function isStored(value: unknown): value is StoredResult {
  if (!isRecord(value) || !positiveInteger(value.inputBytes, LIMITS.sourceBytes)) return false;
  if (value.status === 'skipped_not_smaller') return true;
  return value.status === 'ready' && typeof value.outputUrl === 'string' &&
    positiveInteger(value.outputBytes, LIMITS.outputBytes);
}
function isPrepared(value: unknown): value is Prepared {
  return isRecord(value) && value.status === 'prepared' && typeof value.token === 'string' &&
    value.token.length > 0 && value.token.length <= 256 && typeof value.sourceUrl === 'string' &&
    typeof value.sourceSha256 === 'string' && /^[a-f0-9]{64}$/i.test(value.sourceSha256) &&
    validDimensions(value.width, value.height) && positiveInteger(value.inputBytes, LIMITS.sourceBytes) &&
    (value.mimeType === 'image/jpeg' || value.mimeType === 'image/png') &&
    typeof value.quality === 'number' && Number.isFinite(value.quality) && value.quality >= 0 && value.quality <= 100;
}

// Applies the timeout to reading the body too; cancelling fetch after headers
// alone would still leave a stalled response body consuming a queue slot.
async function request<T>(
  url: string, init: RequestInit, signal: AbortSignal | undefined,
  read: (response: Response) => Promise<T>,
): Promise<T> {
  signal?.throwIfAborted();
  const controller = new AbortController();
  const abort = (): void => controller.abort(signal?.reason);
  signal?.addEventListener('abort', abort, { once: true });
  let timedOut = false;
  let drained = false;
  const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, LIMITS.requestTimeoutMs);
  try {
    const response = await fetch(sameOriginUrl(url), {
      ...init, credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal,
    });
    if (response.status === 401 || response.status === 403) {
      throw new SessionError('Your session or REST nonce expired. Reload the admin page and retry.');
    }
    if (!response.ok) {
      // WordPress returns the actionable reason (conflict, changed source,
      // queue busy, etc.) in JSON. Keep it in the per-file result log.
      const error = await jsonBody(response).catch(() => undefined);
      drained = true;
      throw new Error(isRecord(error) && typeof error.message === 'string'
        ? error.message.slice(0, 500) : `Request failed (${response.status}).`);
    }
    const body = await read(response);
    drained = true;
    return body;
  } catch (error: unknown) {
    if (signal?.aborted) throw new DOMException('Batch cancelled.', 'AbortError');
    if (timedOut) throw new Error('Request timed out. Check stored results before retrying an upload.');
    throw error;
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener('abort', abort);
    // Aborting a fully drained response only produces a confusing ERR_ABORTED
    // entry in the browser's network log.
    if (!drained) controller.abort();
  }
}

async function boundedBody(response: Response, maxBytes: number): Promise<ArrayBuffer> {
  const length = response.headers.get('Content-Length');
  if (length !== null && Number(length) > maxBytes) throw new Error('Response exceeds the byte limit.');
  if (!response.body) throw new Error('The browser cannot stream this response.');
  const reader = response.body.getReader();
  const chunks: Uint8Array[] = [];
  let size = 0;
  try {
    for (;;) {
      const part = await reader.read();
      if (part.done) break;
      size += part.value.byteLength;
      if (size > maxBytes) throw new Error('Response exceeds the byte limit.');
      chunks.push(part.value);
    }
  } catch (error: unknown) {
    await reader.cancel().catch(() => undefined);
    throw error;
  } finally { reader.releaseLock(); }
  const result = new ArrayBuffer(size);
  const target = new Uint8Array(result);
  let offset = 0;
  for (const chunk of chunks) { target.set(chunk, offset); offset += chunk.byteLength; }
  return result;
}
async function jsonBody(response: Response): Promise<unknown> {
  const text = new TextDecoder().decode(await boundedBody(response, 64 * 1024));
  return JSON.parse(text) as unknown;
}

/** Only one download/worker/upload runs at a time within this module instance. */
export async function runBatch(
  config: BatchConfig, variants: readonly Variant[], onProgress: (event: Progress) => void,
  signal?: AbortSignal,
): Promise<readonly Outcome[]> {
  if (batchRunning) throw new Error('An image batch is already running.');
  if (!crypto.subtle || typeof Worker !== 'function') throw new Error('A secure modern browser is required.');
  if (typeof config.nonce !== 'string' || !config.nonce) throw new Error('A REST nonce is required.');
  const prepareUrl = sameOriginUrl(config.prepareUrl);
  const finishUrl = sameOriginUrl(config.finishUrl);
  if (variants.length > 500 || variants.some((variant) =>
    !positiveInteger(variant.attachmentId, Number.MAX_SAFE_INTEGER) ||
    typeof variant.sizeKey !== 'string' || !/^[a-zA-Z0-9_-]{1,100}$/.test(variant.sizeKey))) {
    throw new Error('Invalid attachment selection (at most 500 variants).');
  }
  // Snapshot caller input; UI selection changes must not change an active run.
  const selections = variants.map((variant) => ({ ...variant }));
  const outcomes: Outcome[] = [];
  batchRunning = true;
  try {
  for (const [index, variant] of selections.entries()) {
    signal?.throwIfAborted();
    const phase = (value: JobPhase): void => onProgress({ index, total: selections.length, variant, phase: value });
    let outcome: Outcome;
    try {
      phase('preparing');
      const prepared: unknown = await request(prepareUrl, {
        method: 'POST', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify(variant),
      }, signal, jsonBody);
      if (isStored(prepared)) {
        if (prepared.outputUrl) sameOriginUrl(prepared.outputUrl);
        outcome = { variant, status: 'completed', result: prepared };
      } else {
        if (!isPrepared(prepared)) throw new Error('Invalid prepared-image response.');
        phase('downloading');
        const source = await request(prepared.sourceUrl, { method: 'GET' }, signal,
          (response) => boundedBody(response, LIMITS.sourceBytes));
        if (source.byteLength !== prepared.inputBytes) throw new Error('Source byte length changed.');
        const digest = await crypto.subtle.digest('SHA-256', source);
        const sha256 = [...new Uint8Array(digest)].map((value) => value.toString(16).padStart(2, '0')).join('');
        if (sha256 !== prepared.sourceSha256.toLowerCase()) throw new Error('Source content changed. Prepare it again.');
        phase('encoding');
        const output = await encodeWebP(source, { quality: prepared.quality, lossless: false },
          signal === undefined ? {} : { signal });
        if (output.width !== prepared.width || output.height !== prepared.height) {
          throw new Error('Source dimensions differ from server metadata.');
        }
        signal?.throwIfAborted();
        phase('uploading');
        const body = new FormData();
        body.set('token', prepared.token);
        body.set('file', new Blob([output.bytes], { type: 'image/webp' }), 'converted.webp');
        const stored: unknown = await request(finishUrl, {
          method: 'POST', headers: { 'X-WP-Nonce': config.nonce }, body,
        }, signal, jsonBody);
        if (!isStored(stored)) throw new Error('Invalid stored-image response.');
        if (stored.outputUrl) sameOriginUrl(stored.outputUrl);
        outcome = { variant, status: 'completed', result: stored };
      }
    } catch (error: unknown) {
      if (signal?.aborted || error instanceof SessionError) throw error;
      outcome = { variant, status: 'failed', message: messageOf(error) };
    }
    outcomes.push(outcome);
    onProgress({ index, total: selections.length, outcome });
  }
  return outcomes;
  } finally { batchRunning = false; }
}

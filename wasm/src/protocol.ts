export const LIMITS = Object.freeze({
  sourceBytes: 20 * 1024 * 1024,
  outputBytes: 32 * 1024 * 1024,
  dimension: 8192,
  pixels: 16_000_000,
  encodeTimeoutMs: 60_000,
  requestTimeoutMs: 30_000,
});

export type SourceMime = 'image/jpeg' | 'image/png';
export interface EncodeOptions {
  readonly quality: number;
  readonly lossless: boolean;
}
export interface EncodeRequest {
  readonly type: 'encode';
  readonly id: string;
  readonly bytes: ArrayBuffer;
  readonly options: EncodeOptions;
}
export interface EncodeResult {
  readonly type: 'result';
  readonly id: string;
  readonly bytes: ArrayBuffer;
  readonly width: number;
  readonly height: number;
  readonly sourceBytes: number;
  readonly mimeType: 'image/webp';
}
export interface EncodeFailure {
  readonly type: 'error';
  readonly id: string;
  readonly message: string;
}
export type EncodeResponse = EncodeResult | EncodeFailure;

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}
export function positiveInteger(value: unknown, max: number): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0 && value <= max;
}
export function validDimensions(width: unknown, height: unknown): width is number {
  return positiveInteger(width, LIMITS.dimension) &&
    positiveInteger(height, LIMITS.dimension) && width * height <= LIMITS.pixels;
}
export function isEncodeOptions(value: unknown): value is EncodeOptions {
  return isRecord(value) && typeof value.quality === 'number' &&
    Number.isFinite(value.quality) && value.quality >= 0 && value.quality <= 100 &&
    typeof value.lossless === 'boolean';
}
export function isEncodeRequest(value: unknown): value is EncodeRequest {
  return isRecord(value) && value.type === 'encode' && typeof value.id === 'string' &&
    value.id.length > 0 && value.id.length <= 100 && value.bytes instanceof ArrayBuffer &&
    positiveInteger(value.bytes.byteLength, LIMITS.sourceBytes) && isEncodeOptions(value.options);
}
export function isEncodeResponse(value: unknown): value is EncodeResponse {
  if (!isRecord(value) || typeof value.id !== 'string') return false;
  if (value.type === 'error') return typeof value.message === 'string' && value.message.length <= 500;
  return value.type === 'result' && value.bytes instanceof ArrayBuffer &&
    positiveInteger(value.bytes.byteLength, LIMITS.outputBytes) &&
    positiveInteger(value.sourceBytes, LIMITS.sourceBytes) &&
    validDimensions(value.width, value.height) && value.mimeType === 'image/webp';
}
export function messageOf(error: unknown): string {
  return error instanceof Error ? error.message.slice(0, 500) : 'Image conversion failed.';
}
export function assertWebP(bytes: ArrayBuffer): void {
  if (bytes.byteLength < 20 || bytes.byteLength > LIMITS.outputBytes) throw new Error('Invalid WebP length.');
  const view = new DataView(bytes);
  if (view.getUint32(0) !== 0x52494646 || view.getUint32(8) !== 0x57454250 ||
      view.getUint32(4, true) + 8 !== bytes.byteLength) throw new Error('Invalid WebP container.');
}

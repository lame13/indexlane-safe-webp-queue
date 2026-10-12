export const LIMITS = Object.freeze({
  sourceBytes: 20 * 1024 * 1024,
  outputBytes: 32 * 1024 * 1024,
  dimension: 8192,
  pixels: 16_000_000,
  encodeTimeoutMs: 60_000,
  requestTimeoutMs: 30_000,
});

export type SourceMime = 'image/jpeg' | 'image/png';
export type OutputFormat = 'webp' | 'avif';
export interface EncodeOptions {
  readonly format: OutputFormat;
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
  readonly mimeType: 'image/webp' | 'image/avif';
}
export interface EncodeFailure {
  readonly type: 'error';
  readonly id: string;
  readonly message: string;
}
export type EncodeResponse = EncodeResult | EncodeFailure;

export function isOutputFormat(value: unknown): value is OutputFormat {
  return value === 'webp' || value === 'avif';
}
export function outputMime(format: OutputFormat): 'image/webp' | 'image/avif' {
  return format === 'avif' ? 'image/avif' : 'image/webp';
}
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
  return isRecord(value) && isOutputFormat(value.format) && typeof value.quality === 'number' &&
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
    validDimensions(value.width, value.height) &&
    (value.mimeType === 'image/webp' || value.mimeType === 'image/avif');
}
export function messageOf(error: unknown): string {
  return error instanceof Error ? error.message.slice(0, 500) : 'Image conversion failed.';
}

// AVIF is an ISO-BMFF file. The server performs the strict box walk; here we
// only prove the envelope is a still AVIF image before handing it to the
// upload step. Animated (avis) and HEIF-branded files are refused.
const AVIF_BRANDS = ['avif', 'mif1', 'miaf', 'MA1B', 'MA1A'];
export function assertAvif(bytes: ArrayBuffer): void {
  if (bytes.byteLength < 32 || bytes.byteLength > LIMITS.outputBytes) throw new Error('Invalid AVIF length.');
  const view = new DataView(bytes);
  if (view.getUint32(4) !== 0x66747970) throw new Error('Invalid AVIF container.');
  const size = view.getUint32(0);
  if (size < 16 || size > bytes.byteLength || (size - 16) % 4 !== 0) {
    throw new Error('Invalid AVIF brand box.');
  }
  const brand = String.fromCharCode(
    view.getUint8(8), view.getUint8(9), view.getUint8(10), view.getUint8(11));
  if (!AVIF_BRANDS.includes(brand)) throw new Error('The encoded file is not a still AVIF image.');
  let hasAvif = brand === 'avif';
  for (let at = 16; at < size; at += 4) {
    const compatible = String.fromCharCode(...new Uint8Array(bytes, at, 4));
    if (['avis', 'msf1', 'heic', 'heix', 'hevc', 'hevx'].includes(compatible)) {
      throw new Error('The encoded file is not a still AVIF image.');
    }
    hasAvif ||= compatible === 'avif';
  }
  if (!hasAvif) throw new Error('The encoded file is not a still AVIF image.');
}
export function assertOutput(bytes: ArrayBuffer, format: OutputFormat): void {
  if (format === 'avif') { assertAvif(bytes); return; }
  assertWebP(bytes);
}
export function assertWebP(bytes: ArrayBuffer): void {
  if (bytes.byteLength < 20 || bytes.byteLength > LIMITS.outputBytes) throw new Error('Invalid WebP length.');
  const view = new DataView(bytes);
  if (view.getUint32(0) !== 0x52494646 || view.getUint32(8) !== 0x57454250 ||
      view.getUint32(4, true) + 8 !== bytes.byteLength) throw new Error('Invalid WebP container.');
}

import { LIMITS, validDimensions } from './protocol';
import type { SourceMime } from './protocol';

export interface ImageHeader { readonly mimeType: SourceMime; readonly width: number; readonly height: number }

function checked(mimeType: SourceMime, width: number, height: number): ImageHeader {
  if (!validDimensions(width, height)) throw new Error('Image exceeds the dimension or pixel limit.');
  return { mimeType, width, height };
}

// Refuse orientation-dependent sources before decoding. Dimensions alone do
// not catch a half-turn, a mirror, or a rotated square image.
function checkExif(data: DataView, start: number, length: number): void {
  const invalid = (): never => { throw new Error('Invalid EXIF orientation data. Save an upright copy and retry.'); };
  const end = start + length;
  if (length < 8) invalid();
  const order = data.getUint16(start);
  if (order !== 0x4949 && order !== 0x4d4d) invalid();
  const little = order === 0x4949;
  if (data.getUint16(start + 2, little) !== 42) invalid();
  const offset = data.getUint32(start + 4, little);
  if (offset < 8 || offset > length - 2) invalid();
  const directory = start + offset;
  const count = data.getUint16(directory, little);
  if (directory + 2 + count * 12 > end) invalid();
  for (let i = 0; i < count; i++) {
    const entry = directory + 2 + i * 12;
    if (data.getUint16(entry, little) !== 0x0112) continue;
    if (data.getUint16(entry + 2, little) !== 3 || data.getUint32(entry + 4, little) !== 1) invalid();
    if (data.getUint16(entry + 8, little) !== 1) {
      throw new Error('This image still has an EXIF rotation or mirror tag. Save an upright copy without the tag, then upload it again.');
    }
  }
}

// A bounded format/dimension preflight, not a replacement for browser decoding
// or the server's independent validation. Reject animation instead of dropping frames.
export function inspectImage(bytes: ArrayBuffer): ImageHeader {
  if (bytes.byteLength < 12 || bytes.byteLength > LIMITS.sourceBytes) throw new Error('Invalid source size.');
  const data = new DataView(bytes);
  if (data.getUint32(0) === 0x89504e47 && data.getUint32(4) === 0x0d0a1a0a) {
    if (bytes.byteLength < 33 || data.getUint32(8) !== 13 || data.getUint32(12) !== 0x49484452) {
      throw new Error('Invalid PNG header.');
    }
    const header = checked('image/png', data.getUint32(16), data.getUint32(20));
    let offset = 8;
    let hasImageData = false;
    while (offset + 12 <= bytes.byteLength) {
      const length = data.getUint32(offset);
      const kind = data.getUint32(offset + 4);
      const end = offset + 12 + length;
      if (end > bytes.byteLength) throw new Error('Truncated PNG chunk.');
      if (kind === 0x6163544c) throw new Error('Animated PNG is not supported.');
      if (kind === 0x65584966) checkExif(data, offset + 8, length);
      if (kind === 0x49444154) hasImageData = true;
      if (kind === 0x49454e44) {
        if (length !== 0 || !hasImageData || end !== bytes.byteLength) throw new Error('Invalid PNG end.');
        return header;
      }
      offset = end;
    }
    throw new Error('PNG has no complete end chunk.');
  }
  if (data.getUint16(0) === 0xffd8) {
    let offset = 2;
    let header: ImageHeader | undefined;
    while (offset + 4 <= bytes.byteLength) {
      if (data.getUint8(offset) !== 0xff) throw new Error('Invalid JPEG marker.');
      while (offset < bytes.byteLength && data.getUint8(offset) === 0xff) offset++;
      if (offset >= bytes.byteLength) break;
      const marker = data.getUint8(offset++);
      if (marker === 0xda || marker === 0xd9) {
        if (header) return header;
        break;
      }
      if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) continue;
      if (offset + 2 > bytes.byteLength) break;
      const length = data.getUint16(offset);
      if (length < 2 || offset + length > bytes.byteLength) throw new Error('Truncated JPEG segment.');
      if (marker === 0xe1 && length >= 8 && data.getUint32(offset + 2) === 0x45786966 &&
          data.getUint16(offset + 6) === 0) checkExif(data, offset + 8, length - 8);
      // JPEG SOF0/SOF1/SOF2: baseline, extended sequential, progressive.
      if (marker === 0xc0 || marker === 0xc1 || marker === 0xc2) {
        if (length < 8 || data.getUint8(offset + 2) !== 8) throw new Error('Unsupported JPEG precision.');
        header = checked('image/jpeg', data.getUint16(offset + 5), data.getUint16(offset + 3));
      }
      offset += length;
    }
    throw new Error('Supported JPEG dimensions were not found.');
  }
  throw new Error('Only static PNG and supported JPEG sources are accepted.');
}

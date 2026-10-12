import assert from 'node:assert/strict';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const compiled = await mkdtemp(join(tmpdir(), 'ilswq-module-test-'));
try {
execFileSync(process.execPath, [fileURLToPath(new URL('./node_modules/typescript/bin/tsc', import.meta.url)), '-p', fileURLToPath(new URL('./tsconfig.json', import.meta.url)), '--noEmit', 'false', '--rootDir', fileURLToPath(new URL('./src', import.meta.url)), '--outDir', compiled], { stdio: 'inherit' });

const asModule = (text) => 'data:text/javascript;base64,' + Buffer.from(text).toString('base64');
const protocol = asModule(await readFile(join(compiled, 'protocol.js'), 'utf8'));
const header = asModule((await readFile(join(compiled, 'image-header.js'), 'utf8')).replace("'./protocol'", JSON.stringify(protocol)));
const { inspectImage } = await import(header);
const { assertAvif, assertOutput, isEncodeOptions, isOutputFormat } = await import(protocol);
const arrayBuffer = (b) => b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength);

// The AVIF envelope check must accept a still AVIF/HEIF-branded ISO-BMFF file
// and reject animated, WebP, and truncated inputs before any upload happens.
function avifBytes(brand = 'avif', length = 40) {
  const bytes = Buffer.alloc(length);
  bytes.writeUInt32BE(length, 0);
  bytes.write('ftyp', 4);
  bytes.write(brand, 8);
  if (length >= 20) bytes.write('avif', 16);
  return arrayBuffer(bytes);
}
assertOutput(avifBytes('avif'), 'avif');
assertOutput(avifBytes('mif1'), 'avif');
assert.throws(() => assertAvif(avifBytes('avis')), /still AVIF/);
assert.throws(() => assertAvif(avifBytes('heic')), /still AVIF/);
assert.throws(() => assertAvif(avifBytes('avif', 16)), /Invalid AVIF length/);
const heifOnly = avifBytes('mif1');
new Uint8Array(heifOnly).fill(0, 16);
assert.throws(() => assertAvif(heifOnly), /still AVIF/);
const animatedCompatible = avifBytes();
new Uint8Array(animatedCompatible).set(Buffer.from('avis'), 20);
assert.throws(() => assertAvif(animatedCompatible), /still AVIF/);
const brokenBrand = avifBytes();
new DataView(brokenBrand).setUint32(0, 1000);
assert.throws(() => assertAvif(brokenBrand), /brand box/);
assert.throws(() => assertOutput(arrayBuffer(Buffer.alloc(40, 0x41)), 'avif'), /Invalid AVIF container/);
const riffSize = Buffer.alloc(4); riffSize.writeUInt32LE(12, 0);
assertOutput(arrayBuffer(Buffer.concat([Buffer.from('RIFF'), riffSize, Buffer.from('WEBP'), Buffer.from('VP8 '), Buffer.alloc(4)])), 'webp');
assert(isOutputFormat('avif') && isOutputFormat('webp') && !isOutputFormat('gif'));
assert(isEncodeOptions({ format: 'avif', quality: 60, lossless: false }));
assert(!isEncodeOptions({ quality: 60, lossless: false }));
function exif(orientation, little) {
  const data = Buffer.alloc(26);
  const u16 = (v, at) => little ? data.writeUInt16LE(v, at) : data.writeUInt16BE(v, at);
  const u32 = (v, at) => little ? data.writeUInt32LE(v, at) : data.writeUInt32BE(v, at);
  data.write(little ? 'II' : 'MM');
  u16(42, 2); u32(8, 4); u16(1, 8);
  u16(0x112, 10); u16(3, 12); u32(1, 14); u16(orientation, 18);
  return data;
}
function segment(marker, bytes) {
  const prefix = Buffer.alloc(4);
  prefix[0] = 0xff; prefix[1] = marker; prefix.writeUInt16BE(bytes.length + 2, 2);
  return Buffer.concat([prefix, bytes]);
}
const frame = segment(0xc0, Buffer.from([8, 0, 32, 0, 32, 1, 1, 0x11, 0]));
for (const little of [true, false]) {
  for (let orientation = 1; orientation <= 8; orientation++) {
    const tag = segment(0xe1, Buffer.concat([Buffer.from('Exif\0\0'), exif(orientation, little)]));
    for (const parts of [[tag, frame], [frame, tag]]) {
      const bytes = arrayBuffer(Buffer.concat([Buffer.from([255, 216]), ...parts, Buffer.from([255, 218, 0, 2])]));
      if (orientation === 1) assert.equal(inspectImage(bytes).width, 32);
      else assert.throws(() => inspectImage(bytes), /EXIF rotation or mirror/);
    }
  }
}
const malformed = exif(1, true); malformed.writeUInt32LE(0xffffffff, 4);
assert.throws(() => inspectImage(arrayBuffer(Buffer.concat([Buffer.from([255, 216]), segment(0xe1, Buffer.concat([Buffer.from('Exif\0\0'), malformed])), frame, Buffer.from([255, 218, 0, 2])]))), /Invalid EXIF/);

function chunk(kind, payload) {
  const prefix = Buffer.alloc(8); prefix.writeUInt32BE(payload.length); prefix.write(kind, 4);
  return Buffer.concat([prefix, payload, Buffer.alloc(4)]);
}
const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(32, 0); ihdr.writeUInt32BE(32, 4);
const png = (extra) => arrayBuffer(Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), chunk('IHDR', ihdr), ...extra, chunk('IDAT', Buffer.from([0])), chunk('IEND', Buffer.alloc(0))]));
assert.equal(inspectImage(png([])).mimeType, 'image/png');
assert.throws(() => inspectImage(png([chunk('eXIf', exif(3, true))])), /EXIF rotation/);
assert.throws(() => inspectImage(png([chunk('acTL', Buffer.alloc(8))])), /Animated PNG/);

// REST rejection text must reach the result log, rather than a bare HTTP code.
const encoderStub = asModule('export async function encodeImage() { throw new Error("Should not encode after prepare fails"); }');
const batch = asModule((await readFile(join(compiled, 'batch.js'), 'utf8')).replace("'./protocol'", JSON.stringify(protocol)).replace("'./encoder'", JSON.stringify(encoderStub)));
globalThis.location = new URL('https://example.test/wp-admin/');
globalThis.Worker = function () {};
globalThis.fetch = async () => new Response(JSON.stringify({ message: 'A sibling WebP file is in the way.' }), { status: 409 });
const { runBatch } = await import(batch);
const outcomes = await runBatch({ prepareUrl: '/prepare', finishUrl: '/finish', nonce: 'test' }, [{ attachmentId: 1, sizeKey: 'full' }], () => {});
assert.equal(outcomes[0].status, 'failed');
assert.equal(outcomes[0].message, 'A sibling WebP file is in the way.');
let requestedFormat;
globalThis.fetch = async (_url, init) => {
  requestedFormat = JSON.parse(init.body).format;
  return new Response(JSON.stringify({ status: 'ready', inputBytes: 100, outputBytes: 50 }));
};
await runBatch({ prepareUrl: '/prepare', finishUrl: '/finish', nonce: 'test', format: 'avif' }, [{ attachmentId: 1, sizeKey: 'full' }], () => {});
assert.equal(requestedFormat, 'avif', 'The run must retain its selected format when site settings change.');
console.log('Browser module regression tests passed.');

} finally { await rm(compiled, { recursive: true, force: true }); }

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
const arrayBuffer = (b) => b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength);
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
const encoderStub = asModule('export async function encodeWebP() { throw new Error("Should not encode after prepare fails"); }');
const batch = asModule((await readFile(join(compiled, 'batch.js'), 'utf8')).replace("'./protocol'", JSON.stringify(protocol)).replace("'./encoder'", JSON.stringify(encoderStub)));
globalThis.location = new URL('https://example.test/wp-admin/');
globalThis.Worker = function () {};
globalThis.fetch = async () => new Response(JSON.stringify({ message: 'A sibling WebP file is in the way.' }), { status: 409 });
const { runBatch } = await import(batch);
const outcomes = await runBatch({ prepareUrl: '/prepare', finishUrl: '/finish', nonce: 'test' }, [{ attachmentId: 1, sizeKey: 'full' }], () => {});
assert.equal(outcomes[0].status, 'failed');
assert.equal(outcomes[0].message, 'A sibling WebP file is in the way.');
console.log('Browser module regression tests passed.');

} finally { await rm(compiled, { recursive: true, force: true }); }

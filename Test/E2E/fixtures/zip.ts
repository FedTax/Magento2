import * as zlib from 'zlib';

/**
 * Minimal ZIP reader for asserting on downloaded diagnostics bundles.
 *
 * Reads the central directory and inflates stored/deflated entries — all the
 * format PHP's ZipArchive writes. Kept dependency-free so the E2E package
 * needs nothing beyond Playwright.
 */
export function readZip(buffer: Buffer): Map<string, string> {
  const EOCD = 0x06054b50;
  const CENTRAL = 0x02014b50;
  const LOCAL = 0x04034b50;

  let eocd = -1;
  for (let i = buffer.length - 22; i >= Math.max(0, buffer.length - 65557); i--) {
    if (buffer.readUInt32LE(i) === EOCD) {
      eocd = i;
      break;
    }
  }
  if (eocd < 0) {
    throw new Error('Not a ZIP file: end of central directory not found');
  }

  const count = buffer.readUInt16LE(eocd + 10);
  let offset = buffer.readUInt32LE(eocd + 16);
  const entries = new Map<string, string>();

  for (let n = 0; n < count; n++) {
    if (buffer.readUInt32LE(offset) !== CENTRAL) {
      throw new Error(`Corrupt ZIP: bad central directory entry ${n}`);
    }
    const method = buffer.readUInt16LE(offset + 10);
    const compressedSize = buffer.readUInt32LE(offset + 20);
    const nameLength = buffer.readUInt16LE(offset + 28);
    const extraLength = buffer.readUInt16LE(offset + 30);
    const commentLength = buffer.readUInt16LE(offset + 32);
    const localOffset = buffer.readUInt32LE(offset + 42);
    const name = buffer.toString('utf8', offset + 46, offset + 46 + nameLength);

    if (buffer.readUInt32LE(localOffset) !== LOCAL) {
      throw new Error(`Corrupt ZIP: bad local header for ${name}`);
    }
    const dataStart =
      localOffset + 30 + buffer.readUInt16LE(localOffset + 26) + buffer.readUInt16LE(localOffset + 28);
    const data = buffer.subarray(dataStart, dataStart + compressedSize);
    const content = method === 0 ? data : zlib.inflateRawSync(data);
    entries.set(name, content.toString('utf8'));

    offset += 46 + nameLength + extraLength + commentLength;
  }

  return entries;
}

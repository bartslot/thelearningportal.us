#!/usr/bin/env node
/**
 * check-asset-budget.mjs — fail the build when a surface's textures outgrow their budget.
 *
 * A budget nobody measures is a wish. These numbers drift the first time somebody is in a hurry,
 * and the drift is invisible until a school reports a black screen.
 *
 * TWO CEILINGS, because they fail differently and only one of them is visible in a file listing:
 *
 *  - TRANSFER is what a file size tells you. It binds on school wifi.
 *  - VRAM is what the texture costs once decoded, and it is the one that actually breaks things.
 *    A texture uploaded from an <img> is RGBA8 whatever the file said, and mipmaps add a third
 *    again — so a 475 KB WebP is 10.7 MiB resident, and a 4 MB compressed asset can sit
 *    comfortably inside a transfer budget while blowing a VRAM ceiling by an order of magnitude.
 *    On the low-end machines this protects, VRAM is the binding limit, not bandwidth.
 *
 * And a hard dimension ceiling, which is the rule worth defending hardest: above a device's
 * MAX_TEXTURE_SIZE a texture does not degrade, it fails to bind. Black sky, no error, and
 * invisible in review because the reviewer does not own the hardware it breaks on.
 *
 * Deliberately not clever: a JSON of limits and a header parser. Run it where the build runs.
 */

import { readFileSync, statSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const budget = JSON.parse(readFileSync(resolve(root, 'asset-budget.json'), 'utf8'))

/** Pixel dimensions from the file header. PNG, JPEG and WebP cover everything we ship. */
const dimensionsOf = (buf, label) => {
  // PNG: IHDR width/height are the first two big-endian u32 after the 8-byte signature + 8.
  if (buf.readUInt32BE(0) === 0x89504e47) {
    return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) }
  }
  // WebP: RIFF....WEBP, then VP8 / VP8L / VP8X, each storing size differently.
  if (buf.toString('ascii', 0, 4) === 'RIFF' && buf.toString('ascii', 8, 12) === 'WEBP') {
    const fourcc = buf.toString('ascii', 12, 16)
    if (fourcc === 'VP8X') {
      return { width: 1 + buf.readUIntLE(24, 3), height: 1 + buf.readUIntLE(27, 3) }
    }
    if (fourcc === 'VP8 ') {
      return { width: buf.readUInt16LE(26) & 0x3fff, height: buf.readUInt16LE(28) & 0x3fff }
    }
    if (fourcc === 'VP8L') {
      const bits = buf.readUInt32LE(21)
      return { width: (bits & 0x3fff) + 1, height: ((bits >> 14) & 0x3fff) + 1 }
    }
  }
  // JPEG: walk the segments to a start-of-frame marker.
  if (buf.readUInt16BE(0) === 0xffd8) {
    let i = 2
    while (i < buf.length - 9) {
      if (buf[i] !== 0xff) { i++; continue }
      const marker = buf[i + 1]
      if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
        return { height: buf.readUInt16BE(i + 5), width: buf.readUInt16BE(i + 7) }
      }
      if (marker === 0xd8 || marker === 0xd9 || (marker >= 0xd0 && marker <= 0xd7)) { i += 2; continue }
      i += 2 + buf.readUInt16BE(i + 2)
    }
  }
  throw new Error(`${label}: could not read image dimensions — unsupported format?`)
}

const mib = (bytes) => `${(bytes / 1048576).toFixed(1)} MiB`

/** Resident cost of an RGBA8 upload, mipmaps included. Independent of how small the file was. */
const vramOf = ({ width, height }) => Math.round(width * height * 4 * budget.mipmapOverhead)

const inspect = (path) => {
  const abs = resolve(root, path)
  const buf = readFileSync(abs)
  const dims = dimensionsOf(buf, path)
  return { path, transfer: statSync(abs).size, vram: vramOf(dims), ...dims }
}

const failures = []
const report = []

for (const [name, surface] of Object.entries(budget.surfaces)) {
  const assets = surface.assets.map(inspect)
  const transfer = assets.reduce((n, a) => n + a.transfer, 0)
  const vram = assets.reduce((n, a) => n + a.vram, 0)

  report.push(`\n${name} — ${assets.length} texture${assets.length === 1 ? '' : 's'}`)
  for (const a of assets) {
    report.push(`   ${a.width}x${a.height}  ${mib(a.transfer).padStart(9)} transfer  ${mib(a.vram).padStart(9)} resident   ${a.path}`)
    if (a.transfer > surface.maxSingleAssetTransferBytes) {
      failures.push(`${name}: ${a.path} is ${mib(a.transfer)}, over the ${mib(surface.maxSingleAssetTransferBytes)} single-asset limit`)
    }
  }
  report.push(`   TOTAL  ${mib(transfer)} / ${mib(surface.transferBytes)} transfer   ${mib(vram)} / ${mib(surface.vramBytes)} resident`)

  if (transfer > surface.transferBytes) failures.push(`${name}: transfer ${mib(transfer)} exceeds ${mib(surface.transferBytes)}`)
  if (vram > surface.vramBytes) failures.push(`${name}: RESIDENT ${mib(vram)} exceeds ${mib(surface.vramBytes)} — this is the one that shows as a black screen, not a slow one`)
}

// The dimension rule applies to everything we ship, loaded by default or not.
const everything = [...Object.values(budget.surfaces).flatMap((s) => s.assets), ...budget.optional.assets]
for (const path of everything) {
  const { width, height } = inspect(path)
  if (Math.max(width, height) > budget.maxDimension) {
    failures.push(`${path} is ${width}x${height}, over the ${budget.maxDimension} dimension ceiling — above a device's MAX_TEXTURE_SIZE this does not look worse, it fails to bind`)
  }
}

console.log(report.join('\n'))

if (failures.length) {
  console.error(`\nAsset budget exceeded:\n${failures.map((f) => `  - ${f}`).join('\n')}\n`)
  process.exit(1)
}
console.log('\nAsset budget OK.\n')

/**
 * build-terrain-normals.mjs — bake the global relief normal map the globe shades itself with.
 *
 * A custom MapLibre layer cannot sample the map's own raster tiles, so the terrain has to arrive as
 * one texture. This turns AWS's terrarium DEM tiles into an equirectangular normal map in the
 * convention resources/js/timemap/terrain-normals.js defines and daylight.js reads back.
 *
 *   node scripts/build-terrain-normals.mjs --width 8192 --zoom 5
 *   node scripts/build-terrain-normals.mjs --width 4096 --out /tmp/candidates
 *
 * SOURCE ZOOM AND OUTPUT WIDTH ARE NOT INDEPENDENT. A terrarium grid at zoom z is 256·2^z pixels
 * around the equator, and so is an equirectangular map of that width — so z4 feeds 4096 exactly,
 * z5 feeds 8192, and asking for more output than the source has is inventing detail. The script
 * says so rather than quietly producing a blurry map that looks like a resolution problem in the
 * shader.
 *
 * WebP is written when cwebp is on the path, LOSSLESS. Lossy compression on a normal map does not
 * degrade gracefully: its block artefacts are slope errors, and a slope error is a lighting error,
 * so an 8x8 quilt appears across the terminator exactly where the effect is meant to be at its
 * best. The PNG is always written as the reference.
 */

import { writeFileSync, mkdirSync, existsSync, statSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { PNG } from 'pngjs'
import { loadHeightGrid, gridSizeForZoom } from './lib/elevation.mjs'
import {
  equirectHeightGrid, normalsFromHeights, encodeNormals, equirectMetresPerPixel,
  heightsAboveSeaLevel,
} from '../resources/js/timemap/terrain-normals.js'

const __dirname = dirname(fileURLToPath(import.meta.url))

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`)
  return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback
}

const width = parseInt(arg('width', '4096'), 10)
const height = width / 2
const zoom = parseInt(arg('zoom', String(Math.max(0, Math.round(Math.log2(width / 256))))), 10)
const outDir = resolve(__dirname, '..', arg('out', 'public/timemap'))

const sourceSize = gridSizeForZoom(zoom)
if (sourceSize < width) {
  console.error(
    `refusing: z${zoom} gives ${sourceSize}px around the equator, which cannot fill a ${width}px map.\n` +
    `use --zoom ${Math.round(Math.log2(width / 256))} (${(4 ** Math.round(Math.log2(width / 256)))} tiles).`,
  )
  process.exit(1)
}

console.error(`relief normal map: ${width}x${height} equirectangular, from terrarium z${zoom} (${sourceSize}px mercator)`)
console.error(`  ${Math.round(equirectMetresPerPixel(width, 0))} m per texel at the equator`)

const raw = await loadHeightGrid(zoom)
// Sea level FIRST, then resample. The shared grid keeps true ocean depth because the ocean layer
// is built on it; averaging those depths in with the land would drag every coastal texel below
// zero and flatten the ranges that run to the sea. See heightsAboveSeaLevel.
const source = heightsAboveSeaLevel(raw)

console.error('  resampling mercator -> equirectangular…')
const heights = equirectHeightGrid(source, sourceSize, width, height)

console.error('  measuring slopes…')
const normals = normalsFromHeights(heights, width, height)
const bytes = encodeNormals(normals)

// What the map actually contains, so a flat or inverted bake is caught here rather than on screen.
let steepest = 0
let land = 0
for (let k = 0; k < width * height; k++) {
  const tilt = Math.hypot(normals[k * 3], normals[k * 3 + 1])
  if (tilt > steepest) steepest = tilt
  if (heights[k] > 0) land++
}
console.error(`  steepest slope ${(Math.asin(Math.min(1, steepest)) * 180 / Math.PI).toFixed(1)}°`)
console.error(`  land ${(100 * land / (width * height)).toFixed(1)}% of texels (earth is about 29%)`)

mkdirSync(outDir, { recursive: true })
const png = new PNG({ width, height, colorType: 2 })   // RGB, no alpha
for (let k = 0; k < width * height; k++) {
  png.data[k * 4] = bytes[k * 3]
  png.data[k * 4 + 1] = bytes[k * 3 + 1]
  png.data[k * 4 + 2] = bytes[k * 3 + 2]
  png.data[k * 4 + 3] = 255
}
const pngPath = resolve(outDir, `earth-normal-${width}.png`)
writeFileSync(pngPath, PNG.sync.write(png, { colorType: 2, deflateLevel: 9 }))
console.error(`  wrote ${pngPath} (${(statSync(pngPath).size / 1e6).toFixed(2)} MB)`)

const webpPath = resolve(outDir, `earth-normal-${width}.webp`)
try {
  execFileSync('cwebp', ['-lossless', '-z', '9', '-quiet', pngPath, '-o', webpPath])
  console.error(`  wrote ${webpPath} (${(statSync(webpPath).size / 1e6).toFixed(2)} MB)`)
} catch (e) {
  console.error('  cwebp not available — PNG only')
}

if (!existsSync(webpPath)) process.exitCode = 0

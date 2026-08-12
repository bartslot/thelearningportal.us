#!/usr/bin/env node
/**
 * build-cloud-atlas.mjs — turn the harvested NASA patches into one cloud-cover atlas.
 *
 * Second half of the source replacement. build-cloud-patches.mjs fetches true-colour tiles of open
 * ocean; this reduces them to what the deck actually needs, which is COVERAGE — a single channel
 * saying how much cloud is at each texel — and packs the six regions into one texture.
 *
 * WHY COVERAGE AND NOT THE PICTURE. The deck lights cloud itself: sun angle, terminator, the
 * shadow it casts on the ground. Sampling the photograph's colour would paste MODIS's lighting on
 * top of ours, so a cloud photographed at local noon would stay noon-lit while sitting on our night
 * side. Take the shape, light it here.
 *
 * WHY BRIGHTNESS IS ENOUGH. Only because the patches are open ocean. Water is near-uniform dark, so
 * anything bright is cloud and the separation is one threshold. That is exactly the constraint the
 * harvester imposed, and it is why this file is short: over land the same rule would call every
 * desert, ice sheet and salt flat a cumulus.
 *
 * PER-PATCH NORMALISATION, not global. The six were shot at different sun angles and hazes, so a
 * single global range would leave one patch permanently overcast and another permanently clear. The
 * percentiles are taken within each patch, which makes them read as the same sky.
 *
 *   node scripts/build-cloud-atlas.mjs
 */
import { readdirSync, readFileSync, writeFileSync, mkdirSync, existsSync, rmSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import jpeg from 'jpeg-js'
import { PNG } from 'pngjs'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const PATCHES = join(ROOT, 'storage/app/cloud-patches')
const OUT_WEBP = join(ROOT, 'public/img/map/cloud-patches.webp')

/** 3 x 2 grid of 256px tiles. Matches the six regions the harvester takes. */
const TILE = 256
const COLS = 3
const ROWS = 2

/**
 * Ocean floor and cloud ceiling, as percentiles of each patch's own brightness.
 *
 * 5th rather than the minimum: a single dark pixel would drag the floor down and leave the whole
 * patch reading as slightly cloudy. 98th rather than the maximum for the same reason at the top —
 * sun glint off the water is brighter than any cloud in the frame and would otherwise define
 * "fully overcast", washing every real cloud out to half strength.
 */
const OCEAN_PCTL = 0.05
const CLOUD_PCTL = 0.98

const luma = (r, g, b) => 0.2126 * r + 0.7152 * g + 0.0722 * b

const percentile = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.max(0, Math.round(p * (sorted.length - 1))))]

/** Nearest-neighbour resample to TILE x TILE. The patches arrive at 256 already; this is a guard. */
const resample = (src, w, h) => {
  const out = new Float32Array(TILE * TILE)
  for (let y = 0; y < TILE; y++) {
    const sy = Math.min(h - 1, Math.floor((y * h) / TILE))
    for (let x = 0; x < TILE; x++) {
      const sx = Math.min(w - 1, Math.floor((x * w) / TILE))
      out[y * TILE + x] = src[sy * w + sx]
    }
  }
  return out
}

/**
 * MODIS flies a swath, and between passes it leaves wedges of the globe un-imaged on any given day.
 * Those arrive as pure black, and black is the one value this file cannot tell apart from "no cloud
 * at all" — so a gap bakes into the deck as a hard-edged clear wedge that then tiles across the
 * planet, which is a worse artefact than the blur it replaces.
 *
 * Nothing upstream checks for it: two of the first six patches had one.
 */
const NO_DATA_LEVEL = 6
const NO_DATA_LIMIT = 0.01

/** One patch → coverage in 0..1, normalised against its own ocean and its own brightest cloud. */
const coverageOf = (file) => {
  const { data, width, height } = jpeg.decode(readFileSync(file), { useTArray: true })
  const bright = new Float32Array(width * height)
  for (let i = 0; i < width * height; i++) {
    bright[i] = luma(data[i * 4], data[i * 4 + 1], data[i * 4 + 2])
  }

  let dark = 0
  for (const v of bright) if (v <= NO_DATA_LEVEL) dark++
  const noData = dark / bright.length

  const sorted = Float32Array.from(bright).sort()
  const floor = percentile(sorted, OCEAN_PCTL)
  const ceil = percentile(sorted, CLOUD_PCTL)
  const span = Math.max(1, ceil - floor)

  const cover = new Float32Array(width * height)
  for (let i = 0; i < bright.length; i++) {
    cover[i] = Math.max(0, Math.min(1, (bright[i] - floor) / span))
  }

  return { cover: resample(cover, width, height), floor, ceil, noData }
}

const files = existsSync(PATCHES)
  ? readdirSync(PATCHES).filter((f) => f.endsWith('.jpg')).sort()
  : []

if (files.length === 0) {
  console.error('No patches in storage/app/cloud-patches — run build-cloud-patches.mjs first.')
  process.exit(1)
}

const wanted = COLS * ROWS
if (files.length !== wanted) {
  console.warn(`${files.length} patches for a ${COLS}x${ROWS} atlas — the grid expects ${wanted}.`)
}

const atlas = new PNG({ width: COLS * TILE, height: ROWS * TILE })

console.log(`Cloud atlas — ${COLS}x${ROWS} of ${TILE}px\n`)

const rejected = []

files.slice(0, wanted).forEach((file, index) => {
  const { cover, floor, ceil, noData } = coverageOf(join(PATCHES, file))
  if (noData > NO_DATA_LIMIT) rejected.push({ file, noData })
  const col = index % COLS
  const row = Math.floor(index / COLS)

  let sum = 0
  for (let y = 0; y < TILE; y++) {
    for (let x = 0; x < TILE; x++) {
      const v = cover[y * TILE + x]
      sum += v
      const px = ((row * TILE + y) * COLS * TILE + (col * TILE + x)) * 4
      const byte = Math.round(v * 255)
      // Grey in RGB, OPAQUE alpha. Coverage went in the alpha channel first, which made WebP encode
      // a second full-detail plane and cost more bytes than the 2048x1024 field this replaces. The
      // shader reads .r; alpha carries nothing and compresses to nothing.
      atlas.data[px] = byte
      atlas.data[px + 1] = byte
      atlas.data[px + 2] = byte
      atlas.data[px + 3] = 255
    }
  }

  const mean = sum / (TILE * TILE)
  console.log(
    `  ${file.replace(/-z\d+.*$/, '').padEnd(16)} cover ${(mean * 100).toFixed(1).padStart(5)}%` +
    `   ocean ${floor.toFixed(0).padStart(3)}  cloud ${ceil.toFixed(0).padStart(3)}` +
    (noData > NO_DATA_LIMIT ? `   SWATH GAP ${(noData * 100).toFixed(1)}%` : ''),
  )
})

if (rejected.length) {
  console.error(`\n  ${rejected.length} patch(es) contain a MODIS swath gap and would tile a black wedge`)
  for (const r of rejected) console.error(`    ${r.file} — ${(r.noData * 100).toFixed(1)}% no-data`)
  console.error('\n  Re-harvest those regions on another date; the gaps move daily:')
  console.error('    node scripts/build-cloud-patches.mjs --date=2026-07-18\n')
  process.exit(1)
}

mkdirSync(dirname(OUT_WEBP), { recursive: true })
const tmpPng = OUT_WEBP.replace(/\.webp$/, '.png')
writeFileSync(tmpPng, PNG.sync.write(atlas))

// Lossy at 88 is invisible on a coverage field and about a fifth of the lossless size. The field is
// smooth by nature, which is exactly what WebP's spatial prediction is good at.
execFileSync('cwebp', ['-q', '88', '-quiet', tmpPng, '-o', OUT_WEBP])

const kb = (p) => (readFileSync(p).length / 1024).toFixed(0)
const webpKb = kb(OUT_WEBP)
const pngKb = kb(tmpPng)

// The intermediate PNG is cwebp's input, not an asset. Left behind it sits in public/img/map as a
// second megabyte-sized copy of the atlas that nothing loads — and the build's own budget check
// fails on it, correctly, as an undeclared texture. Which is how this was found.
rmSync(tmpPng, { force: true })

console.log(`\n  ${OUT_WEBP.replace(ROOT + '/', '')} — ${webpKb} KB (png was ${pngKb} KB)`)
console.log(`  ${COLS * TILE}x${ROWS * TILE}, ${wanted} regions, 2446 m per texel`)

/**
 * The atlas's own mean coverage — a number the SHADER needs, not a statistic for the log.
 *
 * The stochastic tiling blends three taps in a variance-preserving way, and it centres that blend
 * on this figure. Get it wrong and the lattice reappears as a faint brightness pattern, which is
 * the one artefact the tiling exists to prevent. So it is printed here, next to the asset it
 * describes, because the two have to be changed together: CLOUD_PATCH_MEAN in cloud-field.js.
 */
let total = 0
for (let i = 0; i < atlas.data.length; i += 4) total += atlas.data[i]
const mean = total / (atlas.data.length / 4) / 255
console.log(`\n  mean coverage ${mean.toFixed(4)}  ->  CLOUD_PATCH_MEAN in resources/js/timemap/cloud-field.js\n`)

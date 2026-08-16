#!/usr/bin/env node
/**
 * build-cloud-patches.mjs — harvest real cloud patterns from NASA imagery.
 *
 * The deck has been one 2048x1024 picture of the whole planet: 19.6 km per texel, against ground
 * that runs 0.6 km/px beneath it. Roughly 32x coarser than what it sits on, which is why it goes to
 * blurred blobs the moment you descend. No shader setting fixes a source that has run out of pixels
 * — that was tried at both ends and reported as "trippy and fake" one way and "blurred" the other.
 *
 * WHY PATCHES AND NOT A GLOBAL BAKE. Cloud is the one layer on this globe with no correct answer.
 * A coastline is either Ireland or it is wrong; a mountain is either 4,000 m or it is wrong. Cloud
 * over the Atlantic on an unspecified day is a plausible field and nothing more. Bart's call:
 * "clouds are so random it's fine to use the same cloud pattern". So a handful of real patches
 * tiled across the sphere beats a global bake, at a fraction of the bytes.
 *
 * WHY OVER OPEN OCEAN. These are true-colour tiles, so they carry ground AND cloud. Over open water
 * the background is near-uniform dark blue, so cloud separates on brightness alone — no mask, no
 * classifier, no cloud-fraction product to co-register. Over land the same extraction would pull up
 * every desert, ice sheet and salt flat. The ocean is what makes this a five-line separation
 * instead of a research problem.
 *
 * AND WHY THIS IS NOT AN ANACHRONISM. Bart, settling it: "even in 10000 before christ. clouds have
 * always been like this." An asset dates a picture if it could only exist in one period — electric
 * light, drawn cartography, a named storm. Cloud does not. The date below is an arbitrary clear-ish
 * pass used to FETCH pixels; nothing downstream knows it, and nothing may key on it.
 *
 * Usage: node scripts/build-cloud-patches.mjs [--date=YYYY-MM-DD] [--zoom=6]
 */

import { mkdirSync, writeFileSync } from 'node:fs'
import jpeg from 'jpeg-js'
import { PNG } from 'pngjs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const arg = (name, fallback) => {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`))
  return hit ? hit.split('=')[1] : fallback
}

const DATE = arg('date', '2026-08-01')

/**
 * MODIS flies a swath and leaves wedges of the globe un-imaged on any given day. They arrive as pure
 * black, and downstream nothing can tell black apart from "no cloud" — so a gap bakes into the deck
 * as a hard clear wedge that then tiles across the planet, which is worse than the blur being
 * replaced. Two of the first six patches had one and nothing noticed.
 *
 * The gaps MOVE daily but never all clear at once: every date tried had gaps somewhere. So the
 * search is per REGION, not per run — each region walks back a day at a time until its own tile is
 * clean, which is why these are separate from DATE rather than a global retry.
 */
const NO_DATA_LEVEL = 6
const NO_DATA_LIMIT = 0.01
const MAX_DAYS_BACK = Number(arg('search', 14))

/**
 * A patch needs CONTRAST, and a gap-free tile can still be useless.
 *
 * The swath guard above catches missing data. It says nothing about the weather, and most open-ocean
 * tiles turn out to be unusable for the opposite reason: they are simply too cloudy. A 90% overcast
 * patch has no cloud EDGES, and edges are the entire point — it tiles into exactly the featureless
 * wash the whole source replacement exists to end. The opposite failure is just as real: a patch of
 * almost-empty water contributes nothing but sea.
 *
 * Found by Map works probing hand-picked regions, where five of six first choices were unusable —
 * north Pacific 4.1% clear against 58.5% cloud, south Pacific 5.6% against 85.9%, and an Indian
 * Ocean pick at 13.7% cloud that was mostly bare water. Measured on the six that shipped before this
 * was added, three were weak: one at 10.2% clear / 8.3% cloud was mid-grey mush, and one was 64%
 * solid overcast.
 *
 * So both fractions are scored and the PRODUCT is kept. That matters: a threshold on either number
 * alone lets one of the two failure modes straight through, and it is the product that only peaks
 * when a patch has real sky AND real cloud in it.
 *
 * CHANNEL MINIMUM, not luminance. Water is dark in red and green while still fairly bright in blue,
 * so its luminance overlaps thin cloud; its channel minimum does not. Cloud is bright in all three.
 * One number separates them where luminance needs a threshold per patch.
 */
const CLEAR_LEVEL = 45
const CLOUD_LEVEL = 140

/** No-data, clear water and real cloud, in one decode. */
const scoreOf = (bytes) => {
  const { data, width, height } = jpeg.decode(bytes, { useTArray: true })
  const n = width * height
  let dark = 0
  let clear = 0
  let cloud = 0
  for (let i = 0; i < n; i++) {
    const r = data[i * 4]
    const g = data[i * 4 + 1]
    const b = data[i * 4 + 2]
    if (0.2126 * r + 0.7152 * g + 0.0722 * b <= NO_DATA_LEVEL) dark++
    const lowest = Math.min(r, g, b)
    if (lowest < CLEAR_LEVEL) clear++
    if (lowest > CLOUD_LEVEL) cloud++
  }
  const contrast = { noData: dark / n, clear: clear / n, cloud: cloud / n }
  return { ...contrast, score: contrast.clear * contrast.cloud }
}

const dayBefore = (iso, days) => {
  const [y, m, d] = iso.split('-').map(Number)
  const t = new Date(Date.UTC(y, m - 1, d - days))
  return t.toISOString().slice(0, 10)
}
/**
 * TWO ZOOMS, and the split is what makes sixteen times the detail almost free.
 *
 * SCORING happens at `zoom`, one tile per candidate. Cloud fraction is a property of the sky, not of
 * the resolution it is photographed at, so a z6 tile answers "is this patch worth having" exactly as
 * well as a z8 one and costs a sixteenth as much. Eighteen candidates, eighteen fetches.
 *
 * FETCHING happens at `detail-zoom`, and only for the six that won. A z9 tile is 306 m per texel and
 * 78.3 km across, so an 8x8 grid of them spans 626 km — the SAME footprint as one z6 tile, to the
 * metre. Sixty-four times the texels over identical ground.
 *
 * Because the footprint does not move, nothing downstream that is expressed as a RATIO moves either:
 * CLOUD_PATCH_TILES stays at 144 and a lattice cell stays 0.44 of a patch. Only the sampling gets
 * finer.
 */
const ZOOM = Number(arg('zoom', 6))
const DETAIL_ZOOM = Number(arg('detail-zoom', 9))
const GRID = 2 ** (DETAIL_ZOOM - ZOOM)
const LAYER = 'MODIS_Terra_CorrectedReflectance_TrueColor'
const TILE = (z, y, x, date) =>
  `https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/${LAYER}/default/${date}/GoogleMapsCompatible_Level9/${z}/${y}/${x}.jpg`

/**
 * Where to look. Open water only, spread across both hemispheres and several latitudes so the set
 * carries more than one weather regime — trade cumulus, mid-latitude frontal cloud, and the
 * stratocumulus sheets off the west coasts all look different, and a deck built from one of them
 * repeats visibly however it is tiled.
 *
 * CANDIDATES, NOT THE ANSWER. Which six ship is decided by the contrast score above, not by this
 * list, because whether a spot is any good on a given pass is weather and cannot be known in
 * advance. There are three times as many here as are wanted for exactly that reason. Every one is
 * open ocean with no land in the tile, which is the constraint the whole brightness separation
 * rests on — adding a candidate near a coast breaks the extraction rather than the selection.
 */
const KEEP = Number(arg('keep', 6))
const CANDIDATES = [
  { name: 'north-atlantic-a', lng: -30, lat: 45 },
  { name: 'north-atlantic-b', lng: -40, lat: 35 },
  { name: 'south-atlantic-a', lng: -25, lat: -35 },
  { name: 'south-atlantic-b', lng: -10, lat: -25 },
  { name: 'south-atlantic-c', lng: -45, lat: -50 },
  { name: 'south-pacific-a', lng: -120, lat: -30 },
  { name: 'south-pacific-b', lng: -140, lat: -40 },
  { name: 'south-pacific-c', lng: -100, lat: -45 },
  { name: 'north-pacific-a', lng: -160, lat: 35 },
  { name: 'north-pacific-b', lng: -175, lat: 25 },
  { name: 'central-pacific', lng: -150, lat: 10 },
  { name: 'west-pacific', lng: 170, lat: 15 },
  { name: 'indian-a', lng: 75, lat: -20 },
  { name: 'indian-b', lng: 85, lat: -35 },
  { name: 'indian-c', lng: 65, lat: -10 },
  { name: 'south-indian', lng: 95, lat: -45 },
  { name: 'southern-ocean', lng: 30, lat: -45 },
  { name: 'tasman', lng: 160, lat: -40 },
]

/**
 * The slippy tile a lng/lat falls in, and whether that tile exists.
 *
 * The bounds check is not ceremony. Map works hit a candidate that scored best and then 404'd on
 * the fetch, because its tile ran off the grid — and a winner that cannot be downloaded is the
 * worst kind, since it displaces a usable patch and only fails at the last step. Their case was a
 * multi-tile block whose edge ran off; a single tile at the same latitude is in range, so this
 * would not have caught theirs. It is here because the general fault is real: mercator stops at
 * ±85.0511°, and a candidate past it produces a y outside the grid with no error until the fetch.
 */
const tileFor = (lng, lat, z) => {
  const n = 2 ** z
  const x = Math.floor(((lng + 180) / 360) * n)
  const latRad = (lat * Math.PI) / 180
  const y = Math.floor(((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n)
  return { x, y, inGrid: x >= 0 && y >= 0 && x < n && y < n }
}

/**
 * One winner, re-fetched as a GRID x GRID block of finer tiles and stitched into a single image.
 *
 * ON THE WINNER'S OWN DATE, not the run's. Each region walked back independently until its own tile
 * was gap-free, so a block fetched on a different day would be different weather from the one that
 * was scored — and could carry the very swath gap the walk was avoiding.
 *
 * Every sub-tile is checked for no-data in its own right. The z6 tile being clean does not promise
 * sixteen z8 tiles are: a gap edge that covered under 1% of the coarse tile can still fill one
 * sixteenth of the area completely.
 */
const fetchDetail = async (x6, y6, date) => {
  const size = 256
  const out = new PNG({ width: GRID * size, height: GRID * size })
  let fetched = 0
  let clear = 0
  let cloud = 0

  for (let dy = 0; dy < GRID; dy++) {
    for (let dx = 0; dx < GRID; dx++) {
      const x = x6 * GRID + dx
      const y = y6 * GRID + dy
      const response = await fetch(TILE(DETAIL_ZOOM, y, x, date))
      if (!response.ok) return { error: `z${DETAIL_ZOOM}/${x}/${y} returned ${response.status}` }

      const bytes = Buffer.from(await response.arrayBuffer())
      const marks = scoreOf(bytes)
      if (marks.noData > NO_DATA_LIMIT) {
        return { error: `z${DETAIL_ZOOM}/${x}/${y} is ${(marks.noData * 100).toFixed(1)}% no-data` }
      }
      // The block's own contrast, accumulated as it arrives. The score that chose this region was
      // measured on a COARSE tile and possibly on a different date, so it does not describe what is
      // actually being written — and a date walked back to dodge a gap can land on quite different
      // weather.
      clear += marks.clear
      cloud += marks.cloud

      const { data, width, height } = jpeg.decode(bytes, { useTArray: true })
      if (width !== size || height !== size) {
        return { error: `z${DETAIL_ZOOM}/${x}/${y} is ${width}x${height}, expected ${size}` }
      }
      for (let row = 0; row < size; row++) {
        for (let col = 0; col < size; col++) {
          const from = (row * size + col) * 4
          const to = ((dy * size + row) * out.width + (dx * size + col)) * 4
          out.data[to] = data[from]
          out.data[to + 1] = data[from + 1]
          out.data[to + 2] = data[from + 2]
          out.data[to + 3] = 255
        }
      }
      fetched++
      process.stdout.write(`\r    fetching ${fetched}/${GRID * GRID} tiles`)
    }
  }
  process.stdout.write('\r'.padEnd(40) + '\r')
  const n = GRID * GRID
  return { png: out, tiles: fetched, clear: clear / n, cloud: cloud / n, score: (clear / n) * (cloud / n) }
}

/**
 * A winner, fetched at detail — walking its own date back until the whole block is gap-free.
 *
 * THE COARSE TILE PASSING DOES NOT PROMISE THE FINE ONES DO, and this is not a corner case: on the
 * first z8 run, three of six winners had a sub-tile over the no-data limit while their z6 tile was
 * under it. A gap edge covering 0.9% of a coarse tile can fill a sixteenth of that ground
 * completely, and averaging is exactly what hid it.
 *
 * So the same per-region date walk the harvester already does at the coarse zoom happens again here.
 * It is cheap because it abandons a date on the FIRST bad tile rather than fetching all sixteen, and
 * because only the six winners ever reach it.
 *
 * The block is re-scored on arrival. The score that chose the region was measured on a coarse tile
 * and possibly on another date, so it describes a different picture from the one being written.
 */
const DETAIL_DAYS_BACK = Number(arg('detail-search', 8))
const DETAIL_SCORE_FLOOR = 0.02

const harvestDetail = async (hit) => {
  const tried = []
  for (let back = 0; back <= DETAIL_DAYS_BACK; back++) {
    const date = dayBefore(hit.date, back)
    const block = await fetchDetail(hit.x, hit.y, date)
    if (block.error) { tried.push(`${date} ${block.error}`); continue }
    if (block.score < DETAIL_SCORE_FLOOR) {
      tried.push(`${date} contrast collapsed to ${(block.score * 1000).toFixed(1)}`)
      continue
    }
    return { ...block, date, tries: back + 1 }
  }
  return { error: `no clean ${GRID}x${GRID} block in ${DETAIL_DAYS_BACK + 1} days`, tried }
}

const main = async () => {
  const outDir = resolve(root, 'storage/app/cloud-patches')
  mkdirSync(outDir, { recursive: true })

  const metresPerTexel = (156543.03392 / 2 ** ZOOM).toFixed(0)
  console.log(`\nGIBS ${LAYER} @ ${DATE}, z${ZOOM} — ${metresPerTexel} m per texel`)
  console.log('(the field being replaced is 19543 m per texel)\n')
  console.log(`Probing ${CANDIDATES.length} candidates for ${KEEP} places:\n`)

  const scored = []
  for (const region of CANDIDATES) {
    const { x, y, inGrid } = tileFor(region.lng, region.lat, ZOOM)
    if (!inGrid) {
      console.log(`  skip  ${region.name.padEnd(17)} z${ZOOM}/${x}/${y} is off the tile grid`)
      continue
    }

    // The date walk stays PER REGION: swath gaps move daily but never all clear at once, so a
    // global retry cannot work. What is new is that a gap-free tile is now the start of the
    // question rather than the end of it.
    let landed = null
    for (let back = 0; back <= MAX_DAYS_BACK && !landed; back++) {
      const date = dayBefore(DATE, back)
      const response = await fetch(TILE(ZOOM, y, x, date))
      if (!response.ok) continue
      const bytes = Buffer.from(await response.arrayBuffer())
      const marks = scoreOf(bytes)
      if (marks.noData > NO_DATA_LIMIT) continue
      landed = { bytes, date, tries: back + 1, ...marks }
    }

    if (!landed) {
      console.log(`  gap   ${region.name.padEnd(17)} no gap-free tile in ${MAX_DAYS_BACK + 1} days`)
      continue
    }

    console.log(
      `  ${(landed.score * 1000).toFixed(1).padStart(5)} ${region.name.padEnd(17)}`
      + ` clear ${(landed.clear * 100).toFixed(1).padStart(5)}%  cloud ${(landed.cloud * 100).toFixed(1).padStart(5)}%`
      + `  ${landed.date}${landed.tries > 1 ? `  (${landed.tries} dates)` : ''}`,
    )
    scored.push({ region, x, y, ...landed })
  }

  // Highest product first. Ties are not worth breaking carefully — anything this close is a
  // judgement about weather nobody can make from a number.
  scored.sort((a, b) => b.score - a.score)
  const kept = scored.slice(0, KEEP)

  if (kept.length < KEEP) {
    console.error(`\n  only ${kept.length} usable candidates for ${KEEP} places.`)
    console.error('  Add candidates over open water, or try another date:')
    console.error('    node scripts/build-cloud-patches.mjs --date=2026-07-18\n')
    process.exit(1)
  }

  const detailMetres = (156543.03392 / 2 ** DETAIL_ZOOM).toFixed(0)
  console.log(
    `\nKeeping ${kept.length}, dropping ${scored.length - kept.length}.`
    + ` Re-fetching each at z${DETAIL_ZOOM} as ${GRID}x${GRID} tiles — ${detailMetres} m per texel:\n`,
  )

  const failures = []
  for (const [index, hit] of kept.entries()) {
    const detail = await harvestDetail(hit)
    if (detail.error) {
      console.log(`  FAIL  ${hit.region.name.padEnd(17)} ${detail.error}`)
      for (const line of detail.tried) console.log(`          ${line}`)
      failures.push(hit.region.name)
      continue
    }

    // PNG, not JPEG. The block is stitched from sixteen already-compressed tiles and the atlas
    // builder decodes it again — re-encoding to JPEG in between would put a second generation of
    // ringing on exactly the cloud edges this whole change exists to sharpen. Nothing ships from
    // storage/, so lossless costs only disk.
    //
    // Numbered, so the atlas builder's directory sort lands them in score order rather than in
    // alphabetical order — which would otherwise reshuffle the atlas every time a candidate is
    // renamed, and silently move which patch a given lattice cell draws.
    const file = resolve(outDir, `${index}-${hit.region.name}-z${DETAIL_ZOOM}-${hit.x}-${hit.y}.png`)
    const bytes = PNG.sync.write(detail.png)
    writeFileSync(file, bytes)
    console.log(
      `  ok    ${hit.region.name.padEnd(17)} z${DETAIL_ZOOM} ${detail.png.width}x${detail.png.height}`
      + `  ${(bytes.length / 1024 / 1024).toFixed(1)} MB`
      + `  clear ${(detail.clear * 100).toFixed(1).padStart(5)}%  cloud ${(detail.cloud * 100).toFixed(1).padStart(5)}%`
      + `  ${detail.date}${detail.tries > 1 ? `  (${detail.tries} dates)` : ''}`,
    )
  }

  if (failures.length) {
    console.error(`\n  ${failures.length} region(s) failed at z${DETAIL_ZOOM}: ${failures.join(', ')}`)
    console.error('  Try another date; the gaps move daily:')
    console.error('    node scripts/build-cloud-patches.mjs --date=2026-07-18\n')
    process.exit(1)
  }

  console.log(`\n${kept.length}/${KEEP} into storage/app/cloud-patches\n`)
}

main()

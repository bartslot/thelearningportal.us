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
const ZOOM = Number(arg('zoom', 6))
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

  console.log(`\nKeeping ${kept.length}, dropping ${scored.length - kept.length}:\n`)
  for (const [index, hit] of kept.entries()) {
    // Numbered, so the atlas builder's directory sort lands them in score order rather than in
    // alphabetical order — which would otherwise reshuffle the atlas every time a candidate is
    // renamed, and silently move which patch a given lattice cell draws.
    const file = resolve(outDir, `${index}-${hit.region.name}-z${ZOOM}-${hit.x}-${hit.y}.jpg`)
    writeFileSync(file, hit.bytes)
    console.log(
      `  ok    ${hit.region.name.padEnd(17)} z${ZOOM}/${hit.x}/${hit.y}`
      + `  ${(hit.bytes.length / 1024).toFixed(0)} KB  score ${(hit.score * 1000).toFixed(1)}`,
    )
  }
  console.log(`\n${kept.length}/${KEEP} into storage/app/cloud-patches\n`)
}

main()

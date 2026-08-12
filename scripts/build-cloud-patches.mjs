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

/** Fraction of near-black pixels — a swath gap, not weather. */
const noDataFraction = (bytes) => {
  const { data, width, height } = jpeg.decode(bytes, { useTArray: true })
  let dark = 0
  for (let i = 0; i < width * height; i++) {
    const l = 0.2126 * data[i * 4] + 0.7152 * data[i * 4 + 1] + 0.0722 * data[i * 4 + 2]
    if (l <= NO_DATA_LEVEL) dark++
  }
  return dark / (width * height)
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
 */
const REGIONS = [
  { name: 'north-atlantic', lng: -30, lat: 45 },
  { name: 'south-pacific', lng: -120, lat: -30 },
  { name: 'indian', lng: 75, lat: -20 },
  { name: 'north-pacific', lng: -160, lat: 35 },
  { name: 'south-atlantic', lng: -20, lat: -35 },
  { name: 'tasman', lng: 160, lat: -40 },
]

const tileFor = (lng, lat, z) => {
  const n = 2 ** z
  const x = Math.floor(((lng + 180) / 360) * n)
  const latRad = (lat * Math.PI) / 180
  const y = Math.floor(((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n)
  return { x, y }
}

const main = async () => {
  const outDir = resolve(root, 'storage/app/cloud-patches')
  mkdirSync(outDir, { recursive: true })

  const report = []
  for (const region of REGIONS) {
    const { x, y } = tileFor(region.lng, region.lat, ZOOM)
    let landed = null

    for (let back = 0; back <= MAX_DAYS_BACK && !landed; back++) {
      const date = dayBefore(DATE, back)
      const response = await fetch(TILE(ZOOM, y, x, date))
      if (!response.ok) continue
      const bytes = Buffer.from(await response.arrayBuffer())
      const gap = noDataFraction(bytes)
      if (gap > NO_DATA_LIMIT) continue
      landed = { bytes, date, gap, tries: back + 1 }
    }

    if (!landed) {
      report.push({ region: region.name, ok: false, status: `no gap-free tile in ${MAX_DAYS_BACK + 1} days` })
      continue
    }

    const file = resolve(outDir, `${region.name}-z${ZOOM}-${x}-${y}.jpg`)
    writeFileSync(file, landed.bytes)
    report.push({
      region: region.name, ok: true, z: ZOOM, x, y,
      bytes: landed.bytes.length, file, date: landed.date, tries: landed.tries,
    })
  }

  const metresPerTexel = (156543.03392 / 2 ** ZOOM).toFixed(0)
  console.log(`\nGIBS ${LAYER} @ ${DATE}, z${ZOOM} — ${metresPerTexel} m per texel`)
  console.log(`(the field being replaced is 19543 m per texel)\n`)
  for (const r of report) {
    console.log(r.ok
      ? `  ok    ${r.region.padEnd(16)} z${r.z}/${r.x}/${r.y}  ${(r.bytes / 1024).toFixed(0)} KB  ${r.date}${r.tries > 1 ? `  (${r.tries} dates tried)` : ''}`
      : `  FAIL  ${r.region.padEnd(16)} ${r.status}`)
  }
  const failed = report.filter((r) => !r.ok).length
  console.log(`\n${report.length - failed}/${report.length} fetched into storage/app/cloud-patches\n`)
  if (failed) process.exit(1)
}

main()

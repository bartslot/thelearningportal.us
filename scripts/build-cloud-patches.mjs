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
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const arg = (name, fallback) => {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`))
  return hit ? hit.split('=')[1] : fallback
}

const DATE = arg('date', '2026-08-01')
const ZOOM = Number(arg('zoom', 6))
const LAYER = 'MODIS_Terra_CorrectedReflectance_TrueColor'
const TILE = (z, y, x) =>
  `https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/${LAYER}/default/${DATE}/GoogleMapsCompatible_Level9/${z}/${y}/${x}.jpg`

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
    const url = TILE(ZOOM, y, x)
    const response = await fetch(url)
    if (!response.ok) {
      report.push({ region: region.name, ok: false, status: response.status })
      continue
    }
    const bytes = Buffer.from(await response.arrayBuffer())
    const file = resolve(outDir, `${region.name}-z${ZOOM}-${x}-${y}.jpg`)
    writeFileSync(file, bytes)
    report.push({ region: region.name, ok: true, z: ZOOM, x, y, bytes: bytes.length, file })
  }

  const metresPerTexel = (156543.03392 / 2 ** ZOOM).toFixed(0)
  console.log(`\nGIBS ${LAYER} @ ${DATE}, z${ZOOM} — ${metresPerTexel} m per texel`)
  console.log(`(the field being replaced is 19543 m per texel)\n`)
  for (const r of report) {
    console.log(r.ok
      ? `  ok    ${r.region.padEnd(16)} z${r.z}/${r.x}/${r.y}  ${(r.bytes / 1024).toFixed(0)} KB`
      : `  FAIL  ${r.region.padEnd(16)} HTTP ${r.status}`)
  }
  const failed = report.filter((r) => !r.ok).length
  console.log(`\n${report.length - failed}/${report.length} fetched into storage/app/cloud-patches\n`)
  if (failed) process.exit(1)
}

main()

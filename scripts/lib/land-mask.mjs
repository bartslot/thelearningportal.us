/**
 * land-mask.mjs — shared land test for the terrain builders. Backed by Natural Earth 50m land.
 *
 * `onLand` is a plain point-in-polygon test. `wellInland` also requires the point to sit a margin
 * INSIDE the coast (it samples 8 compass points at marginKm and needs them all on land) — this keeps
 * decorative artwork off the shoreline, where the map's land FILL (OHM tiles) and this NE mask don't
 * perfectly agree and near-coast marks would otherwise float over water.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import * as turf from '@turf/turf'

const __dirname = dirname(fileURLToPath(import.meta.url))
const landFC = JSON.parse(readFileSync(resolve(__dirname, '../../storage/app/naturalearth/ne_50m_land.geojson'), 'utf8'))
const landParts = landFC.features.map((f) => ({ f, bbox: turf.bbox(f) }))

export function onLand (lng, lat) {
  const pt = turf.point([lng, lat])
  for (const { f, bbox } of landParts) {
    if (lng < bbox[0] || lng > bbox[2] || lat < bbox[1] || lat > bbox[3]) continue
    if (turf.booleanPointInPolygon(pt, f)) return true
  }
  return false
}

const KM_DEG = 1 / 111
// 8 compass directions (unit offsets); lng is scaled by 1/cos(lat) so the margin is true km.
const DIRS = [[1, 0], [-1, 0], [0, 1], [0, -1], [0.7, 0.7], [-0.7, 0.7], [0.7, -0.7], [-0.7, -0.7]]

export function wellInland (lng, lat, marginKm = 20) {
  if (!onLand(lng, lat)) return false
  if (marginKm <= 0) return true
  const dLat = marginKm * KM_DEG
  const dLng = dLat / Math.max(0.2, Math.cos((lat * Math.PI) / 180))
  for (const [ox, oy] of DIRS) {
    if (!onLand(lng + ox * dLng, lat + oy * dLat)) return false
  }
  return true
}

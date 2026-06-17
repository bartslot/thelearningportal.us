/**
 * map-mountains.js — shared mountain layer for the Time-Map and lesson map.
 *
 * Ranges are rendered as a dense FIELD of peaks (public/timemap/mountains-points.geojson, built
 * from the curated ridge lines by scripts/build-mountain-points.mjs). Each peak carries a `size`
 * (small | medium | big) — big in a massif's core, small at its edges — so a range like the Alps
 * reads as many peaks tapering outward, not a single line.
 *
 * Icons are hand-painted SVGs mapped per size in public/timemap/assets/mountains/manifest.json:
 *   { "small": "small.svg", "medium": "medium.svg", "big": "big.svg" }
 * Replace those files (or the manifest) and the map picks them up — no code change.
 *
 *   await addMountainLayer(map, { beforeId: 'city-dots' })
 */

const ASSET_BASE = '/timemap/assets/mountains/'
const POINTS_URL = '/timemap/mountains-points.geojson'
const SIZES = ['small', 'medium', 'big']
const FALLBACK = { small: 'pen-ink-mountain.svg', medium: 'pen-ink-mountain.svg', big: 'pen-ink-mountain.svg' }

function loadImage (map, id, url) {
  return new Promise((resolve) => {
    if (map.hasImage(id)) return resolve(true)
    const img = new Image()
    img.onload = () => { if (!map.hasImage(id)) map.addImage(id, img); resolve(true) }
    img.onerror = () => resolve(false)
    img.src = url
  })
}

/**
 * Add (once) the `mountains` symbol layer: a sized peak per point.
 *
 * @param {maplibregl.Map} map
 * @param {{ beforeId?: string, opacity?: number, visibility?: 'visible'|'none' }} opts
 */
export async function addMountainLayer (map, opts = {}) {
  const { beforeId, opacity = 0.85, visibility = 'visible' } = opts
  if (map.getLayer('mountains')) return

  // 1. Resolve the size→file map (manifest, else fallback).
  let map3 = FALLBACK
  try {
    const m = await fetch(ASSET_BASE + 'manifest.json').then((r) => (r.ok ? r.json() : null))
    if (m && typeof m === 'object' && !Array.isArray(m)) map3 = { ...FALLBACK, ...m }
    else if (Array.isArray(m) && m.length) map3 = { small: m[0], medium: m[0], big: m[0] }
  } catch (_) { /* keep fallback */ }

  // 2. Register one icon per size (skip any that fail).
  await Promise.all(SIZES.map((s) => loadImage(map, `mtn-${s}`, ASSET_BASE + map3[s])))

  // 3. Load the peak field.
  let data
  try {
    data = await fetch(POINTS_URL).then((r) => r.json())
  } catch (_) { return }

  if (map.getSource('mountains')) map.getSource('mountains').setData(data)
  else map.addSource('mountains', { type: 'geojson', data })

  if (map.getLayer('mountains')) return
  map.addLayer({
    id: 'mountains',
    type: 'symbol',
    source: 'mountains',
    layout: {
      visibility,
      'icon-image': ['concat', 'mtn-', ['coalesce', ['get', 'size'], 'medium']],
      'icon-anchor': 'bottom',
      'icon-allow-overlap': true,
      'icon-ignore-placement': true,
      // Bigger peaks in the core, and everything grows with zoom.
      'icon-size': [
        'interpolate', ['linear'], ['zoom'],
        2, ['match', ['get', 'size'], 'big', 0.34, 'medium', 0.24, 'small', 0.17, 0.24],
        6, ['match', ['get', 'size'], 'big', 0.80, 'medium', 0.55, 'small', 0.38, 0.55],
      ],
    },
    paint: { 'icon-opacity': opacity },
  }, map.getLayer(beforeId) ? beforeId : undefined)
}

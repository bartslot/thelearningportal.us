/**
 * map-mountains.js — shared mountain layer for the Time-Map and lesson map.
 *
 * Ranges render as a dense FIELD of peaks (public/timemap/mountains-points.geojson, built from the
 * curated ridge lines by scripts/build-mountain-points.mjs). Each peak carries a `size`
 * (smaller | small | medium | large) — large in a massif's core, smaller at its edges — so a range
 * like the Alps reads as many peaks tapering outward.
 *
 * Icons are hand-painted SVGs mapped per size in public/timemap/assets/mountains/manifest.json.
 * Each SVG has an `id="bg"` silhouette which we recolor to the map's live land colour at load,
 * so the painted base always melts into the terrain (and tracks each Time-Map style).
 *
 *   await addMountainLayer(map, { beforeId: 'city-dots', landColor: '#f3ead6' })
 */

const ASSET_BASE = '/timemap/assets/mountains/'
const POINTS_URL = '/timemap/mountains-points.geojson'
const SIZES = ['smaller', 'small', 'medium', 'large']
const FALLBACK = {
  smaller: 'mountains_mountain-smaller.svg',
  small: 'mountains_mountain-small.svg',
  medium: 'mountains_mountain-medium.svg',
  large: 'mountains_mountain-large.svg',
}
const RASTER_SCALE = 4 // render the SVG at 4× for crisp icons

// Load one size's SVG, recolor its #bg silhouette to the land colour, rasterize, register.
async function loadSizedIcon (map, size, file, landColor) {
  const id = `mtn-${size}`
  let txt
  try {
    const res = await fetch(ASSET_BASE + file)
    if (!res.ok) return
    txt = await res.text()
  } catch (_) { return }

  const doc = new DOMParser().parseFromString(txt, 'image/svg+xml')
  const svg = doc.documentElement
  const bg = doc.getElementById('bg')
  if (bg) bg.setAttribute('fill', landColor)

  const vb = (svg.getAttribute('viewBox') || '0 0 24 16').split(/\s+/).map(Number)
  const w = Math.max(1, Math.round((vb[2] || 24) * RASTER_SCALE))
  const h = Math.max(1, Math.round((vb[3] || 16) * RASTER_SCALE))
  const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg))

  await new Promise((resolve) => {
    const img = new Image(w, h)
    img.onload = () => {
      try {
        if (map.hasImage(id)) map.updateImage(id, img)
        else map.addImage(id, img, { pixelRatio: RASTER_SCALE })
      } catch (_) {}
      resolve()
    }
    img.onerror = () => resolve()
    img.src = url
  })
}

/**
 * Add (once) the `mountains` symbol layer: a sized, terrain-blended peak per point.
 *
 * @param {maplibregl.Map} map
 * @param {{ beforeId?: string, opacity?: number, visibility?: 'visible'|'none', landColor?: string }} opts
 */
export async function addMountainLayer (map, opts = {}) {
  const { beforeId, opacity = 1, visibility = 'visible', landColor = '#f3ead6' } = opts
  if (map.getLayer('mountains')) return

  // 1. size → file map (manifest, else fallback).
  let map4 = FALLBACK
  try {
    const m = await fetch(ASSET_BASE + 'manifest.json').then((r) => (r.ok ? r.json() : null))
    if (m && typeof m === 'object' && !Array.isArray(m)) map4 = { ...FALLBACK, ...m }
  } catch (_) { /* keep fallback */ }

  // 2. register one (recolored) icon per size.
  await Promise.all(SIZES.map((s) => loadSizedIcon(map, s, map4[s], landColor)))

  // 3. load the peak field.
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
      // The SVGs already encode their relative sizes; this just scales the whole field with zoom.
      'icon-size': ['interpolate', ['linear'], ['zoom'], 2, 0.26, 5, 0.52, 7, 0.74],
    },
    paint: { 'icon-opacity': opacity },
  }, map.getLayer(beforeId) ? beforeId : undefined)
}

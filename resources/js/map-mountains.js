/**
 * map-mountains.js — shared mountain layer for the Time-Map and lesson map.
 *
 * Ranges render as a sparse row of peaks following each ridge (public/timemap/mountains-points.geojson,
 * built by scripts/build-mountain-points.mjs). Each peak carries:
 *   • size  (smaller | small | medium | large) — driven by the range's real peak elevation
 *   • icon  — one of several VARIANT glyphs for that size (so the field isn't one shape repeated)
 *   • sz    — a per-peak size multiplier (random ~0.9–1.12) for organic scale variety
 *   • rot   — a small per-peak rotation (deg) so peaks don't line up mechanically
 *
 * Glyphs are detailed pen-ink drawings (ink on transparent) listed per size in
 * public/timemap/assets/mountains/manifest.json: { smaller:[ids], small:[…], medium:[…], large:[…] }.
 * Built (with mirrored variants) by scripts/build-terrain-icons.mjs.
 *
 *   await addMountainLayer(map, { beforeId: 'city-dots' })
 */

const ASSET_BASE = '/timemap/assets/mountains/'
const POINTS_URL = '/timemap/mountains-points.geojson'
const RASTER_SCALE = 4 // render the SVG at 4× for crisp icons

// Load one icon SVG, rasterize, register under its id. Mountains are ink-on-transparent — no recolor.
async function loadIcon (map, id) {
  let txt
  try {
    const res = await fetch(`${ASSET_BASE}${id}.svg`)
    if (!res.ok) return
    txt = await res.text()
  } catch (_) { return }

  const doc = new DOMParser().parseFromString(txt, 'image/svg+xml')
  const svg = doc.documentElement
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
 * Add (once) the `mountains` symbol layer: a varied, randomized peak per point.
 *
 * @param {maplibregl.Map} map
 * @param {{ beforeId?: string, opacity?: number, visibility?: 'visible'|'none' }} opts
 */
export async function addMountainLayer (map, opts = {}) {
  const { beforeId, opacity = 1, visibility = 'visible' } = opts
  if (map.getLayer('mountains')) return

  // 1. variant ids per size (manifest).
  let manifest
  try {
    manifest = await fetch(ASSET_BASE + 'manifest.json').then((r) => (r.ok ? r.json() : null))
  } catch (_) { /* ignore */ }
  if (!manifest || typeof manifest !== 'object') return
  const ids = [...new Set(Object.values(manifest).flat())]

  // 2. register every variant icon.
  await Promise.all(ids.map((id) => loadIcon(map, id)))

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
      // each peak names its own variant glyph; fall back to a medium variant if missing.
      'icon-image': ['coalesce', ['get', 'icon'], 'm-medium-0'],
      'icon-anchor': 'bottom',
      'icon-allow-overlap': true,
      'icon-ignore-placement': true,
      'icon-rotation-alignment': 'viewport',
      'icon-rotate': ['coalesce', ['get', 'rot'], 0],
      // zoom scale with the peak's own size jitter (sz) folded into each stop, so heights vary
      // organically. (maplibre forbids nesting `zoom` inside `*`, so multiply per-stop instead.)
      'icon-size': ['interpolate', ['linear'], ['zoom'],
        2, ['*', 0.2, ['coalesce', ['get', 'sz'], 1]],
        5, ['*', 0.46, ['coalesce', ['get', 'sz'], 1]],
        7, ['*', 0.66, ['coalesce', ['get', 'sz'], 1]]],
    },
    paint: { 'icon-opacity': opacity },
  }, map.getLayer(beforeId) ? beforeId : undefined)
}

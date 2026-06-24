/**
 * map-forests.js — shared forest layer for the Time-Map and lesson map. Sibling of map-mountains.js.
 *
 * Forest regions render as a field of little GROVE glyphs (public/timemap/forest-points.geojson,
 * built by scripts/build-forest-points.mjs). Each point carries:
 *   • icon — one of several grove VARIANTS (so the canopy isn't one shape repeated)
 *   • sz   — a per-grove size multiplier for organic variety
 *
 * Glyphs are parchment-canopy + ink-outline tree clusters listed per size in
 * public/timemap/assets/forests/manifest.json: { small:[ids], medium:[ids] } (with mirrored variants),
 * built by scripts/build-terrain-icons.mjs.
 *
 *   await addForestLayer(map, { beforeId: 'mountains' })
 */

const ASSET_BASE = '/timemap/assets/forests/'
const POINTS_URL = '/timemap/forest-points.geojson'
const RASTER_SCALE = 4 // render the SVG at 4× for crisp icons

// Load one grove SVG, rasterize, register under its id. Groves are baked (canopy + ink) — no recolor.
async function loadIcon (map, id) {
  let txt
  try {
    const res = await fetch(`${ASSET_BASE}${id}.svg`)
    if (!res.ok) return
    txt = await res.text()
  } catch (_) { return }

  const doc = new DOMParser().parseFromString(txt, 'image/svg+xml')
  const svg = doc.documentElement
  const vb = (svg.getAttribute('viewBox') || '0 0 16 16').split(/\s+/).map(Number)
  const w = Math.max(1, Math.round((vb[2] || 16) * RASTER_SCALE))
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
 * Add (once) the `forests` symbol layer: a varied grove per point.
 *
 * @param {maplibregl.Map} map
 * @param {{ beforeId?: string, opacity?: number, visibility?: 'visible'|'none' }} opts
 */
export async function addForestLayer (map, opts = {}) {
  const { beforeId, opacity = 1, visibility = 'visible' } = opts
  if (map.getLayer('forests')) return

  // 1. variant ids per size (manifest).
  let manifest
  try {
    manifest = await fetch(ASSET_BASE + 'manifest.json').then((r) => (r.ok ? r.json() : null))
  } catch (_) { /* ignore */ }
  if (!manifest || typeof manifest !== 'object') return
  const ids = [...new Set(Object.values(manifest).flat())]

  // 2. register every grove variant.
  await Promise.all(ids.map((id) => loadIcon(map, id)))

  // 3. load the grove field.
  let data
  try {
    data = await fetch(POINTS_URL).then((r) => r.json())
  } catch (_) { return }

  if (map.getSource('forests')) map.getSource('forests').setData(data)
  else map.addSource('forests', { type: 'geojson', data })

  if (map.getLayer('forests')) return
  map.addLayer({
    id: 'forests',
    type: 'symbol',
    source: 'forests',
    layout: {
      visibility,
      'icon-image': ['coalesce', ['get', 'icon'], 'g-small-0'],
      'icon-anchor': 'bottom',
      'icon-allow-overlap': true,
      'icon-ignore-placement': true,
      // per-grove size jitter (sz) folded into each zoom stop (zoom can't be nested inside `*`).
      'icon-size': ['interpolate', ['linear'], ['zoom'],
        2, ['*', 0.45, ['coalesce', ['get', 'sz'], 1]],
        4, ['*', 0.78, ['coalesce', ['get', 'sz'], 1]],
        6, ['*', 1.05, ['coalesce', ['get', 'sz'], 1]],
        7, ['*', 1.25, ['coalesce', ['get', 'sz'], 1]]],
    },
    paint: { 'icon-opacity': opacity },
  }, map.getLayer(beforeId) ? beforeId : undefined)
}

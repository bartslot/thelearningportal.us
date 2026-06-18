/**
 * map-forests.js — shared forest layer for the Time-Map and lesson map. Sibling of map-mountains.js.
 *
 * Forest regions render as a dense FIELD of little tree glyphs (public/timemap/forest-points.geojson,
 * built from the curated regions by scripts/build-forest-points.mjs). Each tree carries a `size`
 * (small | medium) for a natural, non-uniform canopy.
 *
 * Icons are ink-line fir SVGs in public/timemap/assets/forests/manifest.json. Each SVG has an
 * `id="bg"` silhouette recolored to the map's live land colour at load, so overlapping canopies
 * melt into one forest mass (and track each Time-Map style).
 *
 *   await addForestLayer(map, { beforeId: 'mountains', landColor: '#e6d6ad' })
 */

const ASSET_BASE = '/timemap/assets/forests/'
const POINTS_URL = '/timemap/forest-points.geojson'
// Each tree carries a `kind` (conifer | deciduous, assigned by latitude) and a `size` (small |
// medium). The glyph id is `tree-${kind}-${size}`.
const COMBOS = ['conifer-small', 'conifer-medium', 'deciduous-small', 'deciduous-medium']
const FALLBACK = {
  'conifer-small': 'tree-small.svg',
  'conifer-medium': 'tree-medium.svg',
  'deciduous-small': 'tree-deciduous-small.svg',
  'deciduous-medium': 'tree-deciduous-medium.svg',
}
const RASTER_SCALE = 4 // render the SVG at 4× for crisp icons

// Load one glyph's SVG, recolor its #bg silhouette to the land colour, rasterize, register.
async function loadSizedIcon (map, combo, file, landColor) {
  const id = `tree-${combo}`
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

  const vb = (svg.getAttribute('viewBox') || '0 0 16 26').split(/\s+/).map(Number)
  const w = Math.max(1, Math.round((vb[2] || 16) * RASTER_SCALE))
  const h = Math.max(1, Math.round((vb[3] || 26) * RASTER_SCALE))
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
 * Add (once) the `forests` symbol layer: a sized, terrain-blended tree per point.
 *
 * @param {maplibregl.Map} map
 * @param {{ beforeId?: string, opacity?: number, visibility?: 'visible'|'none', landColor?: string }} opts
 */
export async function addForestLayer (map, opts = {}) {
  const { beforeId, opacity = 1, visibility = 'visible', landColor = '#e6d6ad' } = opts
  if (map.getLayer('forests')) return

  // 1. size → file map (manifest, else fallback).
  let map2 = FALLBACK
  try {
    const m = await fetch(ASSET_BASE + 'manifest.json').then((r) => (r.ok ? r.json() : null))
    if (m && typeof m === 'object' && !Array.isArray(m)) map2 = { ...FALLBACK, ...m }
  } catch (_) { /* keep fallback */ }

  // 2. register one (recolored) icon per kind×size combo.
  await Promise.all(COMBOS.map((c) => loadSizedIcon(map, c, map2[c], landColor)))

  // 3. load the tree field.
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
      'icon-image': ['concat', 'tree-', ['coalesce', ['get', 'kind'], 'conifer'], '-', ['coalesce', ['get', 'size'], 'small']],
      'icon-anchor': 'bottom',
      'icon-allow-overlap': true,
      'icon-ignore-placement': true,
      // Bolder trees, spaced out so each reads; scales the whole field with zoom.
      'icon-size': ['interpolate', ['linear'], ['zoom'], 2, 0.3, 4, 0.52, 6, 0.78, 7, 0.98],
    },
    paint: { 'icon-opacity': opacity },
  }, map.getLayer(beforeId) ? beforeId : undefined)
}

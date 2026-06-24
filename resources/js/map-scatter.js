/**
 * map-scatter.js — sparse single-tree groves sprinkled over empty land for texture. Sibling of
 * map-forests.js, but a lighter/smaller field from public/timemap/land-scatter.geojson
 * (scripts/build-land-scatter.mjs). Each point carries { icon, sz }; glyphs live in
 * public/timemap/assets/scatter/manifest.json (built by scripts/build-terrain-icons.mjs).
 *
 *   await addScatterLayer(map, { beforeId: 'forests' })
 */
const ASSET_BASE = '/timemap/assets/scatter/'
const POINTS_URL = '/timemap/land-scatter.geojson'
const RASTER_SCALE = 4

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
    img.onload = () => { try { if (map.hasImage(id)) map.updateImage(id, img); else map.addImage(id, img, { pixelRatio: RASTER_SCALE }) } catch (_) {} resolve() }
    img.onerror = () => resolve()
    img.src = url
  })
}

/**
 * Add (once) the `land-scatter` symbol layer.
 * @param {maplibregl.Map} map
 * @param {{ beforeId?: string, opacity?: number, visibility?: 'visible'|'none' }} opts
 */
export async function addScatterLayer (map, opts = {}) {
  const { beforeId, opacity = 0.9, visibility = 'visible' } = opts
  if (map.getLayer('land-scatter')) return

  let manifest
  try { manifest = await fetch(ASSET_BASE + 'manifest.json').then((r) => (r.ok ? r.json() : null)) } catch (_) {}
  if (!manifest || typeof manifest !== 'object') return
  const ids = [...new Set(Object.values(manifest).flat())]
  await Promise.all(ids.map((id) => loadIcon(map, id)))

  let data
  try { data = await fetch(POINTS_URL).then((r) => r.json()) } catch (_) { return }
  if (map.getSource('land-scatter')) map.getSource('land-scatter').setData(data)
  else map.addSource('land-scatter', { type: 'geojson', data })

  if (map.getLayer('land-scatter')) return
  map.addLayer({
    id: 'land-scatter',
    type: 'symbol',
    source: 'land-scatter',
    layout: {
      visibility,
      'icon-image': ['coalesce', ['get', 'icon'], 's-tree-0'],
      'icon-anchor': 'bottom',
      'icon-allow-overlap': true,
      'icon-ignore-placement': true,
      'icon-rotation-alignment': 'viewport',
      'icon-rotate': ['coalesce', ['get', 'rot'], 0],
      // smaller than the named forests — this is background texture.
      'icon-size': ['interpolate', ['linear'], ['zoom'],
        2, ['*', 0.34, ['coalesce', ['get', 'sz'], 1]],
        5, ['*', 0.8, ['coalesce', ['get', 'sz'], 1]],
        7, ['*', 1.1, ['coalesce', ['get', 'sz'], 1]]],
    },
    paint: { 'icon-opacity': opacity },
  }, map.getLayer(beforeId) ? beforeId : undefined)
}

/**
 * map-volcanoes.js — a handful of famous volcanoes drawn with the cone glyphs extracted from
 * drawing.svg. Points from public/timemap/volcanoes.geojson (scripts/build-volcanoes.mjs); glyphs
 * from public/timemap/assets/volcanoes/manifest.json (scripts/build-drawing-icons.mjs).
 *
 *   await addVolcanoLayer(map, { beforeId: 'boundaries-label' })
 */
const ASSET_BASE = '/timemap/assets/volcanoes/'
const POINTS_URL = '/timemap/volcanoes.geojson'
const RASTER_SCALE = 4

async function loadIcon (map, id) {
  let txt
  try { const res = await fetch(`${ASSET_BASE}${id}.svg`); if (!res.ok) return; txt = await res.text() } catch (_) { return }
  const doc = new DOMParser().parseFromString(txt, 'image/svg+xml')
  const svg = doc.documentElement
  const vb = (svg.getAttribute('viewBox') || '0 0 24 18').split(/\s+/).map(Number)
  const w = Math.max(1, Math.round((vb[2] || 24) * RASTER_SCALE)), h = Math.max(1, Math.round((vb[3] || 18) * RASTER_SCALE))
  const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg))
  await new Promise((resolve) => {
    const img = new Image(w, h)
    img.onload = () => { try { if (map.hasImage(id)) map.updateImage(id, img); else map.addImage(id, img, { pixelRatio: RASTER_SCALE }) } catch (_) {} resolve() }
    img.onerror = () => resolve()
    img.src = url
  })
}

export async function addVolcanoLayer (map, opts = {}) {
  const { beforeId, opacity = 1, visibility = 'visible' } = opts
  if (map.getLayer('volcanoes')) return
  let manifest
  try { manifest = await fetch(ASSET_BASE + 'manifest.json').then((r) => (r.ok ? r.json() : null)) } catch (_) {}
  if (!manifest?.ids) return
  await Promise.all(manifest.ids.map((id) => loadIcon(map, id)))

  let data
  try { data = await fetch(POINTS_URL).then((r) => r.json()) } catch (_) { return }
  if (map.getSource('volcanoes')) map.getSource('volcanoes').setData(data)
  else map.addSource('volcanoes', { type: 'geojson', data })

  if (map.getLayer('volcanoes')) return
  map.addLayer({
    id: 'volcanoes',
    type: 'symbol',
    source: 'volcanoes',
    layout: {
      visibility,
      'icon-image': ['coalesce', ['get', 'icon'], 'v-0'],
      'icon-anchor': 'bottom',
      'icon-allow-overlap': true,
      'icon-ignore-placement': true,
      'icon-size': ['interpolate', ['linear'], ['zoom'], 2, 0.5, 5, 1.2, 7, 1.7],
    },
    paint: { 'icon-opacity': opacity },
  }, map.getLayer(beforeId) ? beforeId : undefined)
}

export function setVolcanoVisibility (map, visible) {
  if (map.getLayer('volcanoes')) map.setLayoutProperty('volcanoes', 'visibility', visible ? 'visible' : 'none')
}

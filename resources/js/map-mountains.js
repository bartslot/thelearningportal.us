/**
 * map-mountains.js — shared mountain-range layer for the Time-Map and lesson map.
 *
 * Mountain ranges are curated polylines (public/timemap/mountains.geojson). Each range is drawn
 * as repeated icons along the ridge. Icons are hand-painted SVGs listed in
 * public/timemap/assets/mountains/manifest.json — add a file there and it's used automatically;
 * each range is assigned a stable variant by hashing its name, so the set looks varied without
 * any per-range config.
 *
 *   await addMountainLayer(map, { beforeId: 'city-dots', iconSize: 0.6 })
 */

const ASSET_BASE = '/timemap/assets/mountains/'
const GEOJSON_URL = '/timemap/mountains.geojson'
const FALLBACK_ICONS = ['pen-ink-mountain.svg']

// Stable string hash → non-negative int (deterministic variant assignment).
function hashStr (s) {
  let h = 0
  for (let i = 0; i < (s || '').length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0
  return h
}

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
 * Add (once) the `mountains` symbol layer, loading every icon in the manifest and distributing
 * the variants across ranges. Safe to call after `map` is loaded.
 *
 * @param {maplibregl.Map} map
 * @param {{ beforeId?: string, iconSize?: number, opacity?: number, visibility?: 'visible'|'none' }} opts
 */
export async function addMountainLayer (map, opts = {}) {
  const { beforeId, iconSize = 0.6, opacity = 0.85, visibility = 'visible' } = opts
  if (map.getLayer('mountains')) return

  // 1. Resolve the icon list (manifest, else the bundled fallback).
  let icons = FALLBACK_ICONS
  try {
    const m = await fetch(ASSET_BASE + 'manifest.json').then((r) => (r.ok ? r.json() : null))
    if (Array.isArray(m) && m.length) icons = m
  } catch (_) { /* keep fallback */ }

  // 2. Register each icon as mtn-0..N (skip any that fail to load).
  const loaded = []
  await Promise.all(icons.map(async (file, i) => {
    const ok = await loadImage(map, `mtn-${i}`, ASSET_BASE + file)
    if (ok) loaded.push(i)
  }))
  if (!loaded.length) return
  const n = loaded.length

  // 3. Fetch ranges + assign a stable variant per range (by hashed name).
  let data
  try {
    data = await fetch(GEOJSON_URL).then((r) => r.json())
  } catch (_) { return }
  for (const f of data.features || []) {
    const variant = loaded[hashStr(f.properties && f.properties.name) % n]
    f.properties = { ...(f.properties || {}), icon: `mtn-${variant}` }
  }

  if (map.getSource('mountains')) map.getSource('mountains').setData(data)
  else map.addSource('mountains', { type: 'geojson', data })

  if (map.getLayer('mountains')) return
  map.addLayer({
    id: 'mountains',
    type: 'symbol',
    source: 'mountains',
    layout: {
      visibility,
      'symbol-placement': 'line',
      'symbol-spacing': 18,
      'icon-image': ['coalesce', ['get', 'icon'], 'mtn-0'],
      'icon-size': iconSize,
      'icon-anchor': 'bottom',
      'icon-rotation-alignment': 'viewport',
      'icon-allow-overlap': true,
      'icon-ignore-placement': true,
    },
    paint: { 'icon-opacity': opacity },
  }, map.getLayer(beforeId) ? beforeId : undefined)
}

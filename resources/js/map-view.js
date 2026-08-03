/**
 * map-view.js — where a lesson map should point, as plain maths.
 *
 * No MapLibre in here on purpose: the camera decisions are the part worth testing, and they are
 * pure functions of a few coordinates.
 *
 *   openingView({ annotations, labels })   // → { center, zoom } for a fresh map
 *   boxView(box)                           // → { center, zoom } framing a lng/lat box
 */

// Where a map opens when the block itself says nothing about where it is (Switzerland — the
// default that used to be hardcoded, kept as the fallback for a blank new map block).
export const FALLBACK_VIEW = { center: [8.23, 46.8], zoom: 3 }

/**
 * Centre + zoom for a lng/lat box. Shared by the opening camera and the polity fit so both frame
 * their subject the same way.
 *
 * @param {{minX:number, minY:number, maxX:number, maxY:number}} box
 * @param {{ pad?: number, maxZoom?: number }} [opts] pad widens the box (1 = tight); maxZoom caps
 *   how close we go, which matters for a single point, where the box has no span at all.
 * @returns {{ center: [number, number], zoom: number }}
 */
export function boxView (box, { pad = 1, maxZoom = 6 } = {}) {
  const spanX = Math.max(0.4, (box.maxX - box.minX) * pad)
  const spanY = Math.max(0.4, (box.maxY - box.minY) * pad)
  return {
    center: [(box.minX + box.maxX) / 2, (box.minY + box.maxY) / 2],
    zoom: Math.min(maxZoom, Math.max(1.6, Math.log2(300 / Math.max(spanX, spanY * 1.7)))),
  }
}

/**
 * Bounding box of every point a map block names, or null when it names none.
 *
 * @param {{ annotations?: Array<object>, labels?: Array<object> }} content
 * @returns {{minX:number, minY:number, maxX:number, maxY:number}|null}
 */
export function contentBox ({ annotations = [], labels = [] } = {}) {
  let minX = 180, minY = 90, maxX = -180, maxY = -90
  let found = false
  const add = (lng, lat) => {
    const x = Number(lng); const y = Number(lat)
    if (!Number.isFinite(x) || !Number.isFinite(y)) return
    found = true
    if (x < minX) minX = x
    if (x > maxX) maxX = x
    if (y < minY) minY = y
    if (y > maxY) maxY = y
  }
  ;(Array.isArray(annotations) ? annotations : []).forEach((a) => { if (a && a.type === 'focus') add(a.lng, a.lat) })
  ;(Array.isArray(labels) ? labels : []).forEach((l) => { if (l && l.text) add(l.lng, l.lat) })
  return found ? { minX, minY, maxX, maxY } : null
}

/**
 * The camera a map block opens on.
 *
 * The map used to always start over Europe and only leave it if the linked territory resolved — so
 * a block whose focus cities and territory are in the USA opened on the wrong continent, and
 * STAYED there whenever the polity fit couldn't run (no territory linked, or Cliopatria has no
 * polygon for it at that year). Frame the block's own content instead: the teacher's focus cities
 * and pinned place labels. Padded, and capped short of the polity fit's zoom 6, so a block with a
 * single focus city opens on its region rather than pressed against the city's own dot.
 *
 * @param {{ annotations?: Array<object>, labels?: Array<object> }} content
 * @returns {{ center: [number, number], zoom: number }}
 */
export function openingView (content) {
  const box = contentBox(content)
  return box ? boxView(box, { pad: 1.6, maxZoom: 5 }) : FALLBACK_VIEW
}

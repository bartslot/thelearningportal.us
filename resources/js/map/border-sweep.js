/**
 * border-sweep.js — an amber light that runs around an outline.
 *
 * Built for the voyage's landfall: as a ship makes land, a front sweeps out from the landing point
 * along the coast, leaving the drawn coastline behind it. It reads as the coast being CHARTED rather
 * than switched on, which is the difference between a map that is discovered and a map that pops.
 *
 * It is here, shared, because the Time-Map wants the same gesture for a different reason — clicking
 * a territory runs the light around its border and lets it go. Same idea, one implementation: the
 * two callers differ only in what is left behind afterwards.
 *
 * HOW. A MapLibre line source with `lineMetrics: true` exposes `line-progress` (0..1 along each
 * feature), and a `line-gradient` can then be rebuilt per frame as three stops: drawn behind the
 * front, amber AT it, nothing ahead. No geometry changes and no second canvas — it composites with
 * the map exactly like any other line, so it can never sit over labels or pins that belong on top.
 */
import * as EASING from '../easing.js'

const AMBER = '#f59e0b'
const CLEAR = 'rgba(0,0,0,0)'
/** How far the glow trails behind the front, as a fraction of the line. */
const COMET_TAIL = 0.14

/** Rough length of a ring in degrees — only used to scale duration, so planar is fine. */
const ringLength = (points) => {
  let total = 0
  for (let i = 1; i < points.length; i++) {
    total += Math.hypot(points[i][0] - points[i - 1][0], points[i][1] - points[i - 1][1])
  }
  return total
}

/** Every ring of a Polygon/MultiPolygon as its own LineString — the outline, without the inside. */
export const outlineOf = (geometry) => {
  if (!geometry) return []
  const polys = geometry.type === 'MultiPolygon' ? geometry.coordinates
    : geometry.type === 'Polygon' ? [geometry.coordinates]
      : []
  return polys.flatMap((poly) => poly).filter((ring) => ring.length > 3)
}

/**
 * Run the light around `lines`.
 *
 * @param {object} map                 MapLibre map
 * @param {object} opts
 * @param {string} opts.id             source/layer id — reused, so a second call replaces the first
 * @param {number[][][]} opts.lines    rings/paths as arrays of [lng, lat]
 * @param {string} [opts.trail]        colour left behind; omit to leave nothing (a transient glow)
 * @param {string} [opts.colour]       the front itself
 * @param {number} [opts.width]
 * @param {number} [opts.durationMs]   omitted → scaled to the longest path
 * @param {number} [opts.maxMs]        ceiling for that scaling
 * @param {string} [opts.beforeId]     insert beneath this layer
 * @param {Function} [opts.easing]     defaults to easeInCubic: slow build, then away
 * @returns {{cancel: Function}}
 */
export const sweepBorder = (map, {
  id, lines, trail = null, colour = AMBER, width = 2.6,
  durationMs, maxMs = 4200, beforeId, easing = EASING.easeInCubic,
} = {}) => {
  const noop = { cancel: () => {} }
  if (!map || !id || !Array.isArray(lines) || !lines.length) return noop

  const remove = () => {
    try {
      if (map.getLayer(id)) map.removeLayer(id)
      if (map.getSource(id)) map.removeSource(id)
    } catch { /* the map went away mid-sweep */ }
  }
  remove()

  const features = lines.map((points) => ({
    type: 'Feature', properties: {},
    geometry: { type: 'LineString', coordinates: points.map((c) => [c[0], c[1]]) },
  }))

  try {
    map.addSource(id, { type: 'geojson', lineMetrics: true, data: { type: 'FeatureCollection', features } })
    map.addLayer({
      id, type: 'line', source: id,
      layout: { 'line-cap': 'round', 'line-join': 'round' },
      paint: {
        'line-width': width,
        'line-gradient': ['interpolate', ['linear'], ['line-progress'], 0, CLEAR, 1, CLEAR],
      },
    }, beforeId && map.getLayer(beforeId) ? beforeId : undefined)
  } catch {
    return noop
  }

  const behind = trail || CLEAR
  /** Comet at the front `p`: drawn behind, the colour AT it, nothing ahead. */
  const gradient = (p) => {
    const front = Math.max(0.002, Math.min(0.998, p))
    const back = Math.max(0.001, front - COMET_TAIL)
    return ['interpolate', ['linear'], ['line-progress'],
      0, behind, back, behind, front, colour, Math.min(0.999, front + 0.003), CLEAR, 1, CLEAR]
  }

  // Duration inverse to size, so a tiny island's glow lingers and a long coast draws briskly.
  const longest = lines.reduce((m, pts) => Math.max(m, ringLength(pts)), 0)
  const dur = durationMs ?? Math.round(Math.max(1400, Math.min(maxMs, maxMs - Math.min(15, longest) * 190)))

  let raf = null
  const started = performance.now()
  const tick = (now) => {
    const t = Math.min(1, (now - started) / dur)
    try {
      map.setPaintProperty(id, 'line-gradient', t < 1
        ? gradient(easing(t))
        // Settle to the trail colour, or take the layer away entirely when there is none: a
        // transient highlight that stayed would quietly become a second border layer.
        : ['interpolate', ['linear'], ['line-progress'], 0, behind, 1, behind])
    } catch { /* layer removed under us */ }
    if (t < 1) raf = requestAnimationFrame(tick)
    else if (!trail) remove()
  }
  raf = requestAnimationFrame(tick)

  return {
    cancel: () => {
      if (raf) cancelAnimationFrame(raf)
      if (!trail) remove()
    },
  }
}

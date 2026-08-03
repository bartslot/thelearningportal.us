/**
 * map-ocean.js — the living sea under the Satellite style.
 *
 * Blue Marble ships its ocean as BATHYMETRY: a painted depth map of trenches, ridges and pale
 * shelves. On a history map that reads as murk, and it never moves. This paints over it with one
 * flat sea and drifts two wave sheets across it — the small, constant motion an RTS ocean has:
 * enough that the water reads as water, never enough to pull the eye off the lesson.
 *
 * How it works, and why this way:
 *
 *  - The mask. A raster layer cannot be clipped, so the sea is a polygon: the whole world with
 *    every landmass punched out as a hole (see oceanMaskFC). It is assembled in the browser from
 *    the coastline GeoJSON the map already loads — no new asset, no build step — so the sea edge
 *    is the same line as the ink shore drawn on top of it, to the vertex.
 *
 *  - The motion. Two `fill-pattern` layers: a broad slow swell and a finer, faster ripple. Each
 *    pattern is a short loop of frames generated once (sums of sine waves with whole-number wave
 *    counts and whole-number cycles per loop, so the texture tiles without a seam AND the loop
 *    closes without a jump), and the animation only ever swaps the frame under the pattern via
 *    map.updateImage. The geometry never moves, so the coastline never shivers.
 *
 *  - The cost. Frames are computed once (~3 MB, both loops), the ripple ticks at 15fps and the
 *    swell at 8fps, nothing runs until the Satellite style is actually chosen, and
 *    prefers-reduced-motion holds the sea on a single still frame.
 */
import { mapGone } from './map-alive.js'

/**
 * The sea, one colour. Deliberately NOT a depth ramp — the point of this layer is to replace the
 * imagery's depth map, not to redraw it. `shelf` is the one concession: a soft band hugging the
 * coast, the way a shoreline reads from above, which also hides the hairline where our mask and
 * NASA's coastline disagree.
 */
export const OCEAN = {
  deep: '#0d2b45',
  shelf: '#2d7ea0',
  crest: '#cfeaf7',
}

const TAU = Math.PI * 2

// The two wave sheets. `kx`/`ky` are how many wave periods fit across the tile (whole numbers, so
// the texture repeats seamlessly); `cycles` is how many periods the sheet travels over one loop
// (whole numbers, so the loop closes). Wavelength/frames is how far the sheet jumps per frame —
// keep it small on anything with visible contrast or the drift stutters.
//
// Every component in a sheet runs down roughly the SAME axis. Crossed directions interfere into a
// field of dots — dust, not water; near-parallel ones sum into long crest lines travelling one way,
// which is what a sea looks like from above. The odd low cross-wave stops those lines from reading
// as corduroy.
const SWELL = {
  id: 'lp-ocean-swell',
  size: 144,
  frames: 20,
  fps: 7,
  alpha: 0.1,
  sharpness: 0.4,
  warp: 14,
  waves: [
    { kx: 1, ky: 2, amp: 1, cycles: 1, phase: 0 },
    { kx: 2, ky: 3, amp: 0.5, cycles: 1, phase: 1.7 },
    { kx: 1, ky: 1, amp: 0.35, cycles: 2, phase: 3.1 },
  ],
  chop: [
    { kx: 2, ky: -1, amp: 1, cycles: 1, phase: 0.9 },
    { kx: -1, ky: 1, amp: 0.6, cycles: 1, phase: 2.6 },
  ],
}
const RIPPLE = {
  id: 'lp-ocean-ripple',
  size: 96,
  frames: 30,
  fps: 15,
  alpha: 0.13,
  sharpness: 0.52,
  warp: 9,
  waves: [
    { kx: 2, ky: 1, amp: 1, cycles: 1, phase: 0.4 },
    { kx: 3, ky: 1, amp: 0.5, cycles: 2, phase: 2.2 },
    { kx: 4, ky: 2, amp: 0.3, cycles: 2, phase: 4.0 },
  ],
  chop: [
    { kx: -1, ky: 2, amp: 1, cycles: 1, phase: 1.1 },
    { kx: 1, ky: -3, amp: 0.55, cycles: 1, phase: 3.7 },
  ],
}

/** The empty sea, declared style-time; oceanMaskFC fills it once the Satellite style is picked. */
export const OCEAN_SOURCE = {
  type: 'geojson',
  data: { type: 'FeatureCollection', features: [] },
}

/**
 * The four sea layers, in draw order, all hidden until the Satellite style asks for them. Splice
 * them into the style directly above the satellite raster: they cover the photographed ocean and
 * nothing else, and every drawn-atlas layer still goes over the top.
 *
 * They must be declared at style time (runtime-added layers don't render reliably in this
 * pipeline) — the patterns and the mask arrive later, through createOcean().
 */
export function oceanLayers ({ source = 'ocean', coastline = 'coastline' } = {}) {
  const hidden = { visibility: 'none' }
  return [
    // The flat sea itself, opaque — this is what buries the bathymetry.
    // No antialias: the fill is a single polygon and the AA outline only draws seams on tile edges.
    { id: 'ocean', type: 'fill', source, layout: hidden, paint: { 'fill-color': OCEAN.deep, 'fill-antialias': false } },
    // Shore band: a wide blurred line ON the coastline, so it feathers a few pixels each way —
    // shallows on the water side, a hint of surf on the land side.
    {
      id: 'ocean-shelf',
      type: 'line',
      source: coastline,
      layout: { ...hidden, 'line-cap': 'round', 'line-join': 'round' },
      paint: {
        'line-color': OCEAN.shelf,
        'line-width': ['interpolate', ['linear'], ['zoom'], 1, 3, 4, 7, 7, 16],
        'line-blur': ['interpolate', ['linear'], ['zoom'], 1, 3, 4, 6, 7, 14],
        'line-opacity': 0.5,
      },
    },
    // The two moving sheets. fill-pattern is set by createOcean() once the frames exist; until
    // then these draw nothing.
    { id: 'ocean-swell', type: 'fill', source, layout: hidden, paint: { 'fill-opacity': 0, 'fill-antialias': false } },
    { id: 'ocean-ripple', type: 'fill', source, layout: hidden, paint: { 'fill-opacity': 0, 'fill-antialias': false } },
  ]
}

export const OCEAN_LAYER_IDS = ['ocean', 'ocean-shelf', 'ocean-swell', 'ocean-ripple']

// ── the mask ────────────────────────────────────────────────────────────────────────────────────

// Holes are pulled a hair inside the world rectangle: a hole edge lying exactly on the outer ring
// is a degenerate case for the tessellator. 1e-4° is about 10 m — far under a pixel at any zoom.
const EDGE_LNG = 180 - 1e-4
const EDGE_LAT = 90 - 1e-4
// A ring end this close to ±180 came out of an antimeridian cut, not out of the sea.
const ANTIMERIDIAN = 179.9

const key = (p) => `${p[0]},${p[1]}`
const isClosed = (r) => r.length > 3 && r[0][0] === r[r.length - 1][0] && r[0][1] === r[r.length - 1][1]

/** Shoelace area — positive is counter-clockwise, which is GeoJSON's exterior winding. */
export function signedArea (ring) {
  let sum = 0
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    sum += ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1]
  }
  return sum / 2
}

/**
 * Coastline arcs come split — at the antimeridian, and wherever the source cut a long ring in two.
 * Join every pair that shares an endpoint back into one ring before anything else looks at them.
 */
function stitch (arcs) {
  const byStart = new Map()
  const byEnd = new Map()
  const index = (m, k, i) => { const a = m.get(k); a ? a.push(i) : m.set(k, [i]) }
  arcs.forEach((r, i) => { index(byStart, key(r[0]), i); index(byEnd, key(r[r.length - 1]), i) })

  const used = new Array(arcs.length).fill(false)
  const free = (m, k) => (m.get(k) || []).find((i) => !used[i])
  const out = []
  for (let i = 0; i < arcs.length; i++) {
    if (used[i]) continue
    used[i] = true
    let ring = arcs[i]
    // Follow the chain off this arc's tail, then off its head — an arc picked up mid-chain has
    // neighbours on both sides. The guard is the arc count: every step consumes one arc, so it
    // cannot loop forever on odd data.
    for (let step = 0; step < arcs.length && !isClosed(ring); step++) {
      const tail = key(ring[ring.length - 1])
      const next = free(byStart, tail)
      if (next != null) {
        used[next] = true
        ring = ring.concat(arcs[next].slice(1))
        continue
      }
      const back = free(byEnd, tail) // a neighbour that runs the other way — walk it backwards
      if (back == null) break
      used[back] = true
      ring = ring.concat(arcs[back].slice(0, -1).reverse())
    }
    for (let step = 0; step < arcs.length && !isClosed(ring); step++) {
      const head = key(ring[0])
      const prev = free(byEnd, head)
      if (prev != null) {
        used[prev] = true
        ring = arcs[prev].slice(0, -1).concat(ring)
        continue
      }
      const flip = free(byStart, head)
      if (flip == null) break
      used[flip] = true
      ring = arcs[flip].slice(1).reverse().concat(ring)
    }
    out.push(ring)
  }
  return out
}

/** Keep a hole clear of the world rectangle it is punched into. */
const inset = (p) => [
  Math.max(-EDGE_LNG, Math.min(EDGE_LNG, p[0])),
  Math.max(-EDGE_LAT, Math.min(EDGE_LAT, p[1])),
]

/**
 * Close a ring the stitcher could not finish.
 *
 * A landmass cut at the antimeridian comes back with both ends on ±180: joining them straight is
 * the seam itself, which is correct. Antarctica is the exception — it enters at one antimeridian
 * and leaves at the other, and a straight join would chord across the continent and flood
 * everything south of the chord, so it is closed around the pole instead.
 */
function closeRing (ring) {
  const a = ring[0]
  const b = ring[ring.length - 1]
  if (a[0] === b[0] && a[1] === b[1]) return ring
  const bothEdges = Math.abs(a[0]) >= ANTIMERIDIAN && Math.abs(b[0]) >= ANTIMERIDIAN
  if (bothEdges && Math.sign(a[0]) !== Math.sign(b[0])) {
    const pole = b[1] < 0 ? -EDGE_LAT : EDGE_LAT
    return [...ring, [b[0], pole], [a[0], pole], a]
  }
  return [...ring, a]
}

/**
 * Every landmass in `coastline` as a hole in one world-covering polygon — the shape of the sea.
 *
 * Accepts the coastline as lines (what public/timemap/coastline.geojson is) or as polygons, so it
 * does not care which of the two the source ever becomes.
 */
export function oceanMaskFC (coastline) {
  const arcs = []
  for (const f of (coastline && coastline.features) || []) {
    const g = f && f.geometry
    if (!g) continue
    if (g.type === 'LineString') arcs.push(g.coordinates)
    else if (g.type === 'MultiLineString' || g.type === 'Polygon') for (const part of g.coordinates) arcs.push(part)
    else if (g.type === 'MultiPolygon') for (const poly of g.coordinates) for (const part of poly) arcs.push(part)
  }

  const closed = []
  const open = []
  for (const r of arcs) {
    if (!r || r.length < 2) continue
    // Anything that isn't already a ring is an arc to be stitched and closed; whatever comes out of
    // that with no area at all is dropped below.
    ;(isClosed(r) ? closed : open).push(r)
  }

  const holes = []
  for (const ring of [...closed, ...stitch(open).map(closeRing)]) {
    if (ring.length < 4) continue
    const area = signedArea(ring)
    if (area === 0) continue
    // Holes wind the opposite way from the exterior — that is how the tessellator tells them apart.
    holes.push({ area: Math.abs(area), ring: (area > 0 ? ring.slice().reverse() : ring).map(inset) })
  }

  // Biggest landmass first. This ordering is load-bearing, not tidiness.
  //
  // MapLibre decides what is a hole purely by winding, taking the first ring as the exterior — and
  // an island simplified down to a 3-point sliver at low zoom can come out wound the wrong way.
  // Such a ring is then read as a new exterior and every ring AFTER it is attached to it instead of
  // to the sea, so those landmasses flood. Sorted big-to-small the damage is bounded: a ring only
  // degenerates once it is around a pixel across, and all it can take with it are rings smaller
  // still. Unsorted, one collapsed island in the Moluccas sank Africa.
  holes.sort((a, b) => b.area - a.area)

  // Counter-clockwise, and the first ring, so it reads as the exterior.
  const world = [[-180, -90], [180, -90], [180, 90], [-180, 90], [-180, -90]]
  return {
    type: 'FeatureCollection',
    features: [{ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [world, ...holes.map((h) => h.ring)] } }],
  }
}

// ── the waves ───────────────────────────────────────────────────────────────────────────────────

const smoothstep = (edge0, edge1, x) => {
  const t = Math.max(0, Math.min(1, (x - edge0) / (edge1 - edge0)))
  return t * t * (3 - 2 * t)
}

/**
 * One loop of a water texture, as RGBA frames ready for map.addImage/map.updateImage.
 *
 * Every component is periodic in x, in y and in time, so the tile repeats without a seam and the
 * last frame runs into the first without a jump. The colour is constant; all the shape lives in
 * the alpha, so the sea colour underneath shows through and only the crests catch the light.
 */
export function waveFrames ({ size, frames, waves, chop = [], warp = 0, alpha, sharpness, color = OCEAN.crest }) {
  const [r, g, b] = hexToRgb(color)
  const sum = (list) => list.reduce((n, w) => n + w.amp, 0) || 1
  const peak = sum(waves)
  const peakChop = sum(chop)
  // One wave sampled at (x, y) at loop position t. Whole-number kx/ky/cycles are what make the
  // tile and the loop seamless; nothing here may break that, warping included.
  const at = (w, x, y, t) => w.amp * Math.sin(TAU * ((w.kx * x + w.ky * y) / size - w.cycles * t) + w.phase)

  const out = []
  for (let f = 0; f < frames; f++) {
    const t = f / frames
    const data = new Uint8ClampedArray(size * size * 4)
    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) {
        // Bend the sheet before sampling it, so crests curve and wander the way a swell does
        // instead of ruling perfect diagonal lines across the sea.
        const wx = x + warp * Math.sin(TAU * (y / size - t))
        const wy = y + warp * Math.sin(TAU * (x / size + t) + 1.3)
        let h = 0
        for (const w of waves) h += at(w, wx, wy, t)
        let c = 0
        for (const w of chop) c += at(w, wx, wy, t)

        const lit = 0.5 + 0.5 * (h / peak)                     // 0..1, the face turned to the sun
        const crest = smoothstep(sharpness, 1, lit)            // the bright edge riding on top
        // A slow cross-swell that lets the crest through in patches — an unbroken crest line reads
        // as a scratch on the screen, a broken one reads as water.
        const patch = chop.length ? 0.3 + 0.7 * smoothstep(0.3, 0.95, 0.5 + 0.5 * (c / peakChop)) : 1

        const i = (y * size + x) * 4
        data[i] = r
        data[i + 1] = g
        data[i + 2] = b
        data[i + 3] = 255 * alpha * (0.25 * lit * lit + 0.75 * crest * patch)
      }
    }
    out.push({ width: size, height: size, data })
  }
  return out
}

function hexToRgb (hex) {
  const n = parseInt(hex.replace('#', ''), 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

// ── the layer ───────────────────────────────────────────────────────────────────────────────────

const reducedMotion = () => typeof window !== 'undefined'
  && typeof window.matchMedia === 'function'
  && window.matchMedia('(prefers-reduced-motion: reduce)').matches

/**
 * Wire the sea to a map. Nothing is fetched, generated or animated until show(true) — the atlas
 * styles paint their own water and never pay for any of this.
 *
 * @param map          a MapLibre map carrying the layers from oceanLayers()
 * @param coastlineUrl the same coastline GeoJSON the map's shore line uses
 */
export function createOcean (map, { coastlineUrl, source = 'ocean' } = {}) {
  let building = null
  let raf = null
  let shown = false
  const sheets = []   // { cfg, frames, at, last }

  const layout = (id, on) => {
    if (!mapGone(map) && map.getLayer(id)) {
      try { map.setLayoutProperty(id, 'visibility', on ? 'visible' : 'none') } catch (_) { /* style reloading */ }
    }
  }

  // The mask is the same coastline the shore line already drew, so this is a cache hit in practice.
  async function build () {
    const res = await fetch(coastlineUrl)
    const coastline = await res.json()
    if (mapGone(map)) return
    const src = map.getSource(source)
    if (src) src.setData(oceanMaskFC(coastline))

    for (const [cfg, layer] of [[SWELL, 'ocean-swell'], [RIPPLE, 'ocean-ripple']]) {
      // A sheet's frames cost ~80ms to compute. Yield between them so the flat sea (already
      // painted, already an improvement on the depth map) is on screen while the rest arrives,
      // instead of the style switch stalling on one long task.
      await new Promise((resolve) => setTimeout(resolve, 0))
      if (mapGone(map)) return
      const frames = waveFrames(cfg)
      if (!map.hasImage(cfg.id)) map.addImage(cfg.id, frames[0])
      if (map.getLayer(layer)) {
        map.setPaintProperty(layer, 'fill-pattern', cfg.id)
        map.setPaintProperty(layer, 'fill-opacity', 1)
      }
      sheets.push({ cfg, frames, at: 0, last: 0 })
    }
  }

  // Swapping the frame under a pattern repaints the pattern in place — the fill itself never moves,
  // and MapLibre does not re-tile anything. Each sheet runs at its own rate; the swell is slow
  // enough that its steps would judder if it carried any contrast, which is why it barely does.
  const tick = () => {
    raf = requestAnimationFrame(tick)
    if (mapGone(map)) return stop()
    const now = performance.now()
    let drew = false
    for (const sheet of sheets) {
      if (now - sheet.last < 1000 / sheet.cfg.fps) continue
      sheet.last = now
      sheet.at = (sheet.at + 1) % sheet.frames.length
      try { map.updateImage(sheet.cfg.id, sheet.frames[sheet.at]) } catch (_) { return stop() }
      drew = true
    }
    if (drew) map.triggerRepaint()
  }

  const start = () => { if (!raf && !reducedMotion() && sheets.length) raf = requestAnimationFrame(tick) }
  const stop = () => { if (raf) { cancelAnimationFrame(raf); raf = null } }

  return {
    /** Show or hide the whole sea. The first show builds it; later ones are just a visibility flip. */
    show (on) {
      shown = !!on
      for (const id of OCEAN_LAYER_IDS) layout(id, shown)
      if (!shown) return stop()
      if (!building) building = build().catch(() => { building = null })
      building.then(() => { if (shown) start() })
    },
    destroy () {
      stop()
      sheets.length = 0
    },
  }
}

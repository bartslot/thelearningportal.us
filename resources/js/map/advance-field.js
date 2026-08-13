/**
 * The spreading-territory effect from war documentaries: an organic front creeping across a country
 * in rounded lobes, like ink in water.
 *
 * The whole thing is one idea — an ARRIVAL FIELD. For every pixel, compute once the distance the
 * front has to travel to reach it, warped by noise. Animating is then just a moving threshold:
 * "everything the front has already reached". Nothing is recomputed per frame, so the cost of a
 * frame is one compare per pixel, and the shape is identical every replay.
 *
 * Two things make it read as liquid rather than as a growing circle:
 *
 *   - The noise warp. Without it the front is a circle, and a circle reads as a radar sweep.
 *   - smin, not min, where two fronts meet. A plain min gives two circles touching at a crease;
 *     smin fuses them with a neck, which is the metaball behaviour the eye reads as one substance.
 *
 * And the front advances on √t, not linearly. Liquid creeping across a surface decelerates as it
 * thins (Washburn); a linear front looks mechanical, which is the giveaway on hand-animated ones.
 *
 * This module is deliberately free of canvas and MapLibre so the maths can be tested on its own.
 */

/** Kilometres per degree of latitude. Longitude shrinks by cos(lat), applied at the sample. */
const KM_PER_DEG = 111.32

const clamp01 = (v) => (v < 0 ? 0 : v > 1 ? 1 : v)

/**
 * Polynomial smooth minimum (Inigo Quilez). Blends the two distances near where they cross, so two
 * advancing fronts join with a rounded neck instead of a crease.
 *
 * @param {number} a first distance
 * @param {number} b second distance
 * @param {number} k blend width in the same units; 0 degrades to a plain Math.min
 */
export const smin = (a, b, k) => {
  if (!(k > 0)) return Math.min(a, b)
  const h = clamp01(0.5 + (0.5 * (b - a)) / k)
  return b * (1 - h) + a * h - k * h * (1 - h)
}

/** Deterministic hash → [0,1). Same field every replay, which also makes it testable. */
const hash2 = (x, y) => {
  const n = Math.sin(x * 127.1 + y * 311.7) * 43758.5453
  return n - Math.floor(n)
}

/** Value noise in [0,1] with smoothstep interpolation — cheap, and smooth enough for lobes. */
export const valueNoise = (x, y) => {
  const xi = Math.floor(x)
  const yi = Math.floor(y)
  const xf = x - xi
  const yf = y - yi
  const u = xf * xf * (3 - 2 * xf)
  const v = yf * yf * (3 - 2 * yf)
  const a = hash2(xi, yi)
  const b = hash2(xi + 1, yi)
  const c = hash2(xi, yi + 1)
  const d = hash2(xi + 1, yi + 1)
  return (a * (1 - u) + b * u) * (1 - v) + (c * (1 - u) + d * u) * v
}

/** Two octaves is enough for lobes: one for the big bulges, one to keep the edge from looking drawn. */
const lobeNoise = (x, y) => valueNoise(x, y) * 0.7 + valueNoise(x * 2.3, y * 2.3) * 0.3

/**
 * How far the front has advanced after `elapsed`.
 *
 * @param {number} elapsed   seconds since the advance started
 * @param {number} duration  seconds for the front to cover maxKm
 * @param {number} maxKm     distance covered by the end
 * @param {number} curve     0.5 = √t, the physical one. 1 = linear, for comparison.
 */
export const frontRadius = (elapsed, duration, maxKm, curve = 0.5) =>
  maxKm * Math.pow(clamp01(duration > 0 ? elapsed / duration : 1), curve)

/**
 * Build the arrival field: for each pixel, the warped distance from the nearest seed, or Infinity
 * where the pixel is outside the target country (so the advance can never spill into the sea or a
 * neighbour — the mask is a hard boundary, not a fade).
 *
 * @param {object}   o
 * @param {number}   o.width       field width in pixels
 * @param {number}   o.height      field height in pixels
 * @param {number[]} o.bbox        [west, south, east, north] the field covers
 * @param {function} o.inside      (px, py) => boolean — is this pixel in the target country
 * @param {Array}    o.seeds       [[lng, lat], …] where the advance enters from
 * @param {number}   [o.lobeScale] lobes per field width; higher = smaller, busier lobes
 * @param {number}   [o.lobeAmount] how far the noise pushes the front, in km
 * @param {number}   [o.mergeKm]   smin blend width between seeds, in km
 * @returns {{field: Float32Array, maxKm: number, spanKm: number}}
 *   maxKm is the farthest reachable pixel. spanKm is the 99.5th percentile, and it is the one to
 *   animate against: a country almost always has a few outlying pixels — an enclave, a peninsula,
 *   a sliver of a coastal tile — sitting far beyond everything else. Pacing the advance to the
 *   absolute farthest pixel spends most of the duration already finished, waiting on those few.
 *   Measured on Poland 1939 the visible advance completed at about a quarter of the run.
 */
export const buildArrivalField = ({
  width, height, bbox, inside, seeds,
  lobeScale = 6, lobeAmount = 45, mergeKm = 120,
}) => {
  const [west, south, east, north] = bbox
  const field = new Float32Array(width * height)
  const lngSpan = east - west
  const latSpan = north - south
  let maxKm = 0

  // Seeds in km-space, measured from the field's own origin, so distances are metric rather than
  // degrees — otherwise the blob stretches horizontally as you move away from the equator.
  const midLat = (north + south) / 2
  const kmPerLng = KM_PER_DEG * Math.cos((midLat * Math.PI) / 180)
  const toKm = ([lng, lat]) => [(lng - west) * kmPerLng, (north - lat) * KM_PER_DEG]
  const seedsKm = seeds.map(toKm)

  for (let py = 0; py < height; py++) {
    const lat = north - (latSpan * (py + 0.5)) / height
    const y = (north - lat) * KM_PER_DEG
    for (let px = 0; px < width; px++) {
      const i = py * width + px
      if (!inside(px, py)) { field[i] = Infinity; continue }

      const lng = west + (lngSpan * (px + 0.5)) / width
      const x = (lng - west) * kmPerLng

      let d = Infinity
      for (const [sx, sy] of seedsKm) {
        const dist = Math.hypot(x - sx, y - sy)
        d = d === Infinity ? dist : smin(d, dist, mergeKm)
      }

      // The warp. Noise is sampled in field space so lobeScale means "lobes across the country"
      // regardless of how big the country is, and it is centred on 0 so it pushes both ways —
      // a one-sided warp only ever delays the front and reads as erosion, not as bulging.
      const n = lobeNoise((px / width) * lobeScale, (py / height) * lobeScale) - 0.5
      const warped = Math.max(0, d + n * 2 * lobeAmount)

      field[i] = warped
      if (warped > maxKm) maxKm = warped
    }
  }

  return { field, maxKm, spanKm: percentile(field, maxKm, 0.995) }
}

/**
 * The distance below which `fraction` of the reachable pixels lie, via a histogram — cheap, and
 * exact enough at this bucket count for a value that only ever sets an animation's pace.
 */
export const percentile = (field, maxKm, fraction) => {
  if (!(maxKm > 0)) return 0
  const BUCKETS = 512
  const counts = new Int32Array(BUCKETS)
  let total = 0
  for (let i = 0; i < field.length; i++) {
    const d = field[i]
    if (!Number.isFinite(d)) continue
    counts[Math.min(BUCKETS - 1, Math.floor((d / maxKm) * BUCKETS))]++
    total++
  }
  if (!total) return 0
  const target = total * fraction
  let seen = 0
  for (let b = 0; b < BUCKETS; b++) {
    seen += counts[b]
    if (seen >= target) return ((b + 1) / BUCKETS) * maxKm
  }
  return maxKm
}

/**
 * Paint one frame into an RGBA buffer.
 *
 * The rim is the detail that sells it. Real spreading liquid piles up denser at its leading edge
 * from surface tension, so the front is darker than what it has already covered. Without it the
 * front reads as a fade-in and the whole thing looks like an opacity ramp.
 *
 * @param {Uint8ClampedArray} rgba   destination, width*height*4
 * @param {Float32Array} field       from buildArrivalField
 * @param {object} o
 * @param {number} o.radiusKm        how far the front has come
 * @param {number[]} o.body          [r,g,b] of covered ground
 * @param {number[]} o.rim           [r,g,b] of the leading edge
 * @param {number} o.rimKm           how deep the rim band is
 * @param {number} o.opacity         0..1 for the covered area
 * @param {number} [o.featherKm]     softness of the outer edge
 */
export const paintFrame = (rgba, field, { radiusKm, body, rim, rimKm, opacity, featherKm = 8 }) => {
  for (let i = 0; i < field.length; i++) {
    const d = field[i]
    const o = i * 4
    if (!(d <= radiusKm + featherKm)) { rgba[o + 3] = 0; continue }

    // 1 well inside the front, easing to 0 just past it.
    const reach = featherKm > 0 ? clamp01((radiusKm + featherKm - d) / featherKm) : 1
    // 1 at the very edge, 0 once the front has moved rimKm beyond this pixel.
    const atEdge = rimKm > 0 ? clamp01(1 - (radiusKm - d) / rimKm) : 0

    rgba[o] = body[0] + (rim[0] - body[0]) * atEdge
    rgba[o + 1] = body[1] + (rim[1] - body[1]) * atEdge
    rgba[o + 2] = body[2] + (rim[2] - body[2]) * atEdge
    // The rim is denser as well as darker, or it reads as a colour change rather than a build-up.
    rgba[o + 3] = 255 * opacity * reach * (1 + 0.35 * atEdge)
  }
}

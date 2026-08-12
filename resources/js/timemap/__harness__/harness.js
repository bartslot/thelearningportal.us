/**
 * harness.js — a measuring rig for the globe's lighting layers.
 *
 * WHY THIS EXISTS RATHER THAN A SCREENSHOT. Every failure these layers have had was invisible: a
 * mirrored sky, a terminator half a continent out, a relief map leaning the wrong way. All of them
 * render perfectly. Looking at them is not evidence, so this reads actual pixels and reports
 * numbers.
 *
 * TWO TRAPS, BOTH OF WHICH RETURN A CONVINCING BLACK IMAGE:
 *
 *  - MapLibre creates its context without `preserveDrawingBuffer`, so anything that reads the
 *    canvas after the frame — drawImage, toDataURL, a screenshot API — gets a cleared buffer. The
 *    only place the pixels exist is INSIDE a render pass, so the probe below is itself a custom
 *    layer, added last, doing gl.readPixels in its own `render`.
 *  - readPixels is in device pixels from the BOTTOM left; map.project is in CSS pixels from the
 *    top left. Mixing them puts every sample in the wrong hemisphere, which on a globe still looks
 *    like plausible terrain.
 *
 * The basemap is a flat grey rather than satellite imagery, deliberately: measuring a translucent
 * shell against a KNOWN constant isolates what the shell did. Real imagery is available for
 * screenshots (`?imagery=1`) but it is scenery, not evidence.
 */

import maplibregl from 'maplibre-gl'
import { createDaylightLayer } from '../daylight.js'
import { createCloudLayer } from '../clouds.js'
import { createAtmosphereLayer } from '../atmosphere.js'
import { sunDirection } from '../sun.js'
import { deepestEclipse } from '../eclipse.js'
import { equirectMetresPerPixel, encodeNormals } from '../terrain-normals.js'
import { planetSpacePosition } from '../planet-mesh.js'

const RELIEF_URL = '/img/map/earth-normal-8192.webp'
const RELIEF_WIDTH = 8192
const FIELD_URL = '/img/map/clouds-field.webp'
const WIND_URL = '/img/map/wind-field.png'
// The tiled source. `?patches=0` drops back to the whole-planet field, which is how the two are
// compared: the same measurements, the same camera, one asset different.
const PATCH_URL = '/img/map/cloud-patches.webp'
const BACKGROUND = '#8a8a8a'

const params = new URLSearchParams(location.search)
const readout = document.getElementById('readout')
const say = (text) => { readout.textContent = text }

/** Direction TO the sun for a subsolar point, in planet space. Matches sun.js's convention. */
const sunFor = (lng, lat) => {
  const a = lat * Math.PI / 180
  const b = lng * Math.PI / 180
  return [Math.cos(a) * Math.cos(b), Math.sin(a), Math.cos(a) * Math.sin(b)]
}

// The projection belongs in the style, not in a setProjection call — that one throws if the style
// has not finished loading, which on a cold page it never has.
const GLOBE = { type: 'globe' }

const style = params.get('imagery')
  ? {
    version: 8,
    projection: GLOBE,
    sources: {
      satellite: {
        type: 'raster',
        tiles: ['https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/BlueMarble_ShadedRelief/default/GoogleMapsCompatible_Level8/{z}/{y}/{x}.jpeg'],
        tileSize: 256,
        maxzoom: 8,
      },
    },
    layers: [{ id: 'satellite', type: 'raster', source: 'satellite' }],
  }
  : {
    version: 8,
    projection: GLOBE,
    sources: {},
    layers: [{ id: 'bg', type: 'background', paint: { 'background-color': BACKGROUND } }],
  }

const map = new maplibregl.Map({
  container: 'map',
  style,
  center: [86.9, 28],
  zoom: 4,
  bearing: 0,
  pitch: 0,
  attributionControl: false,
})

const patchUrl = params.get('patches') === '0' ? null : PATCH_URL

const daylight = createDaylightLayer({
  sun: sunFor(-3.1, 0),
  reliefUrl: RELIEF_URL,
  reliefWidth: RELIEF_WIDTH,
  fieldUrl: FIELD_URL,
  patchUrl,
  windUrl: WIND_URL,
  animate: false,          // a frozen clock, so two captures are comparable
})
const clouds = createCloudLayer({
  sun: sunFor(-3.1, 0),
  fieldUrl: FIELD_URL,
  patchUrl,
  windUrl: WIND_URL,
  animate: false,
})

/**
 * The haze band, for the night-limb measurement. Its own sun, set per capture, so the limb under
 * test can be put where the reading is taken rather than the camera flown to it.
 */
const atmosphere = createAtmosphereLayer({ sun: sunFor(0, 0), strength: 1 })

// ── The probe ────────────────────────────────────────────────────────────────────────────────
// Added last so it runs after everything it is measuring. It reads the whole canvas once per
// requested capture and hands the bytes back.
let pending = null
const probe = {
  id: 'tm-probe',
  type: 'custom',
  renderingMode: '3d',
  onAdd() {},
  render(gl) {
    if (!pending) return
    const { width, height } = gl.canvas
    const pixels = new Uint8Array(width * height * 4)
    gl.readPixels(0, 0, width, height, gl.RGBA, gl.UNSIGNED_BYTE, pixels)
    const resolve = pending
    pending = null
    resolve({ pixels, width, height })
  },
}

const capture = () => new Promise((resolve) => {
  pending = resolve
  map.triggerRepaint()
})

/**
 * A frame with the layers set the way `options` says.
 *
 * Two repaints, not one: `setOptions` only marks the map dirty, and the frame already in flight
 * would be captured with the old uniforms. The first capture flushes it.
 */
const frameWith = async (options) => {
  daylight.setOptions(options.daylight || {})
  clouds.setOptions(options.clouds || {})
  await capture()
  return capture()
}

// ── Reading the frames ───────────────────────────────────────────────────────────────────────

/** Where a lng/lat lands in the readPixels grid: device pixels, origin bottom left. */
const pixelAt = (lng, lat) => {
  const point = map.project([lng, lat])
  const canvas = map.getCanvas()
  const scaleX = canvas.width / canvas.clientWidth
  const scaleY = canvas.height / canvas.clientHeight
  return {
    x: Math.round(point.x * scaleX),
    y: Math.round((canvas.clientHeight - point.y) * scaleY),
  }
}

const luma = (pixels, index) =>
  0.2126 * pixels[index] + 0.7152 * pixels[index + 1] + 0.0722 * pixels[index + 2]

/** Every pixel in a box around a lng/lat, as an index list into the frame. */
const boxAround = (frame, lng, lat, halfWidth) => {
  const { x, y } = pixelAt(lng, lat)
  const indices = []
  for (let dy = -halfWidth; dy <= halfWidth; dy++) {
    for (let dx = -halfWidth; dx <= halfWidth; dx++) {
      const px = x + dx
      const py = y + dy
      if (px < 0 || py < 0 || px >= frame.width || py >= frame.height) continue
      indices.push((py * frame.width + px) * 4)
    }
  }
  return indices
}

/**
 * A measurement over zero samples is not zero, it is a broken instrument — so it throws.
 *
 * This rig once reported a full set of results with every figure at 0.000 and every sample count
 * at 0, and looked entirely healthy doing it: the canvas had silently come up at a fallback size,
 * so every point projected outside the frame and `boxAround` returned nothing. Dividing by
 * `max(1, length)` turned "I measured nothing" into "I measured no effect", which is the most
 * dangerous number this file could produce — it is indistinguishable from a working shader that
 * does nothing, and every assertion downstream would have been comparing zeros.
 */
const differenceOver = (a, b, indices) => {
  if (!indices.length) {
    throw new Error('measured 0 pixels — the sample box fell outside the canvas, so nothing here is a result')
  }
  let total = 0
  let worst = 0
  for (const i of indices) {
    const delta = Math.abs(luma(a.pixels, i) - luma(b.pixels, i))
    total += delta
    if (delta > worst) worst = delta
  }
  return { mean: total / indices.length, max: worst, samples: indices.length }
}

/**
 * How far one difference-image has to slide to sit on top of another.
 *
 * This is how the cloud shadow's offset is proved. A shadow that sits directly under its cloud is
 * a decal; a shadow displaced away from the sun is a shadow. Cross-correlating the shadow the
 * ground received against the deck that cast it recovers that displacement in pixels, and its
 * direction is the thing to check — it must point away from the sun.
 */
const bestShift = (a, b, frame, centre, halfWidth, search) => {
  const { x: cx, y: cy } = centre
  const STEP = 4

  const window = []
  for (let py = cy - halfWidth; py <= cy + halfWidth; py += STEP) {
    for (let px = cx - halfWidth; px <= cx + halfWidth; px += STEP) {
      window.push(py * frame.width + px)
    }
  }

  /**
   * Zero-mean, unit-norm — a plain sum of products is not a correlation.
   *
   * The first version of this used the raw sum, and it pinned at the corner of the search window
   * every time, in both directions, which reads exactly like "the shadow does not move". It was
   * not measuring the shadow at all: both difference images sit on a broad brightness gradient
   * across the globe, and sliding the window toward the brighter side kept the sum rising. Only
   * after subtracting each window's own mean does the correlation see the CLOUDS rather than the
   * lighting they sit on.
   */
  const normalised = (image, offset) => {
    const values = new Float64Array(window.length)
    let total = 0
    for (let k = 0; k < window.length; k++) {
      const index = window[k] + offset
      const value = index >= 0 && index < image.length ? image[index] : 0
      values[k] = value
      total += value
    }
    const mean = total / window.length
    let energy = 0
    for (let k = 0; k < values.length; k++) { values[k] -= mean; energy += values[k] * values[k] }
    const norm = Math.sqrt(energy)
    if (norm > 0) for (let k = 0; k < values.length; k++) values[k] /= norm
    return { values, energy }
  }

  const reference = normalised(a, 0)
  let best = { dx: 0, dy: 0, score: -Infinity }
  for (let dy = -search; dy <= search; dy++) {
    for (let dx = -search; dx <= search; dx++) {
      const candidate = normalised(b, dy * frame.width + dx)
      let score = 0
      for (let k = 0; k < window.length; k++) score += reference.values[k] * candidate.values[k]
      if (score > best.score) best = { dx, dy, score: round(score, 4) }
    }
  }
  // A peak on the edge of the search box is not a peak — it means the true offset is further out,
  // or there was nothing to find. Say so rather than reporting the boundary as an answer.
  best.interior = Math.abs(best.dx) < search && Math.abs(best.dy) < search
  return best
}

/**
 * How much STRUCTURE a difference-image still has at the pixel scale.
 *
 * Mean absolute change is blind to blur — a normal map magnified sixteen times shades the ground
 * by exactly the same amount, it just does it smoothly. Measured that way relief looked immortal:
 * 11.1 at one pixel per texel and 12.3 at sixteen, which is nonsense as a resolution answer and
 * very nearly went out as one.
 *
 * What magnification actually destroys is detail between neighbouring pixels, so that is what this
 * measures: the mean step from one pixel to the next. Ridges have it, a smooth bulge does not.
 */
const detailOver = (image, frame, centre, halfWidth) => {
  let total = 0
  let samples = 0
  for (let py = centre.y - halfWidth; py <= centre.y + halfWidth; py++) {
    for (let px = centre.x - halfWidth; px < centre.x + halfWidth; px++) {
      if (px < 0 || py < 0 || px + 1 >= frame.width || py >= frame.height) continue
      const k = py * frame.width + px
      total += Math.abs(image[k + 1] - image[k])
      samples++
    }
  }
  return samples ? total / samples : 0
}

/** A frame reduced to "how much did this pixel change", which is what every measurement wants. */
const differenceImage = (a, b) => {
  const out = new Float32Array(a.width * a.height)
  for (let k = 0; k < out.length; k++) {
    out[k] = Math.abs(luma(a.pixels, k * 4) - luma(b.pixels, k * 4))
  }
  return out
}

// ── The absolute anchor ──────────────────────────────────────────────────────────────────────

/** A whole equirectangular normal map holding one normal, as a data URL the loader can take. */
const uniformNormalMap = (bytes) => {
  const width = 64
  const height = 32
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const context = canvas.getContext('2d')
  const image = context.createImageData(width, height)
  for (let k = 0; k < width * height; k++) {
    image.data[k * 4] = bytes[0]
    image.data[k * 4 + 1] = bytes[1]
    image.data[k * 4 + 2] = bytes[2]
    image.data[k * 4 + 3] = 255
  }
  context.putImageData(image, 0, 0)
  return canvas.toDataURL('image/png')
}

const waitForRelief = () => new Promise((resolve) => {
  const check = () => (daylight.hasRelief ? resolve() : setTimeout(check, 40))
  check()
})

const smoothstep = (edge0, edge1, x) => {
  const t = Math.min(1, Math.max(0, (x - edge0) / (edge1 - edge0)))
  return t * t * (3 - 2 * t)
}

/**
 * The whole daylight shader, on the CPU, for one point.
 *
 * Written from the physics rather than transcribed from the GLSL — the point is to have two
 * independent statements of the same thing. Transcribing it would make this a test that the shader
 * equals itself.
 */
const predictShadedPixel = ({ point, sun, tangent, power, background }) => {
  const normal = planetSpacePosition(point[0], point[1], 0)
  const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2]

  // The frame equirectangular UV implies: east, south, up.
  const cross = (a, b) => [
    a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0],
  ]
  const norm = (v) => { const l = Math.hypot(...v); return v.map((c) => c / l) }
  const east = norm(cross(normal, [0, 1, 0]))
  const south = cross(normal, east)
  const perturbed = norm([0, 1, 2].map((c) =>
    east[c] * tangent[0] + south[c] * tangent[1] + normal[c] * tangent[2]))

  const sunlight = smoothstep(-0.31, 0.09, dot(normal, sun))
  const change = sunlight * power * (dot(perturbed, sun) - dot(normal, sun))
  const day = Math.min(1, Math.max(0, sunlight + Math.min(0, change)))
  const lit = Math.max(0, change)
  const night = 1 - day

  const twilight = (1 - Math.abs(sunlight * 2 - 1)) ** 2
  const NIGHT = [0.02, 0.035, 0.07]
  const TWILIGHT = [0.85, 0.35, 0.12]
  const shade = NIGHT.map((c, i) => c + (TWILIGHT[i] - c) * twilight * 0.85)
  const alpha = night * 0.965
  const emitted = [1, 0.97, 0.92].map((c) => c * lit)

  // Premultiplied over whatever the flat frame left there: src + dst*(1-a).
  return shade.map((c, i) =>
    Math.round(c * alpha * 255 + emitted[i] * 255 + background[i] * (1 - alpha)))
}

// ── The measurements ─────────────────────────────────────────────────────────────────────────

const EARTH_RADIUS_KM = 6371.0088
const CLOUD_ALTITUDE_RADII = 90 / EARTH_RADIUS_KM   // matches CLOUD_ALTITUDE_M in cloud-field.js

/**
 * The screen scale where it is actually being measured, rather than assumed.
 *
 * The obvious formula — 156543·cos(lat)/2^zoom — is the MERCATOR scale, and this map is a globe.
 * Assuming it here would have turned a correct shadow into a two-times-too-large one on paper,
 * with nothing to show which of the two was wrong. Two projected points and a subtraction settles
 * it against whatever MapLibre is really doing.
 */
const metresPerPixelAt = (lng, lat) => {
  const step = 0.5
  const a = map.project([lng - step / 2, lat])
  const b = map.project([lng + step / 2, lat])
  const groundM = (step * Math.PI / 180) * Math.cos(lat * Math.PI / 180) * EARTH_RADIUS_KM * 1000
  return groundM / Math.hypot(b.x - a.x, b.y - a.y)
}

/**
 * Where the shadowing cloud is, worked out on the CPU from the same geometry the shader uses.
 *
 * This is the check that says the offset is right rather than merely present: a shader that
 * displaced shadows by an arbitrary constant would pass every direction test above and fail this.
 *
 * @returns {number} the horizontal displacement of the cloud from the ground point, in kilometres
 */
const predictedShadowOffsetKm = (lng, lat, sun) => {
  const latRad = lat * Math.PI / 180
  const lngRad = lng * Math.PI / 180
  const n = [Math.cos(latRad) * Math.cos(lngRad), Math.sin(latRad), Math.cos(latRad) * Math.sin(lngRad)]
  const cosZenith = n[0] * sun[0] + n[1] * sun[1] + n[2] * sun[2]
  const reach = CLOUD_ALTITUDE_RADII * (2 + CLOUD_ALTITUDE_RADII)
  const along = -cosZenith + Math.sqrt(cosZenith * cosZenith + reach)
  // Only the part parallel to the ground moves the shadow; the vertical part just reaches the deck.
  const tangential = Math.hypot(...sun.map((s, i) => s - n[i] * cosZenith))
  return along * tangential * EARTH_RADIUS_KM
}

const HIMALAYA = [86.9, 28.0]     // the range itself
const BAY_OF_BENGAL = [88.0, 15.0] // flat water at almost the same longitude, so the same light
const TIBET = [88.0, 33.0]

const round = (v, places = 2) => Math.round(v * 10 ** places) / 10 ** places

const measurements = async () => {
  const report = {}

  /**
   * Before anything: does the canvas exist, and does a point on the map land inside it?
   *
   * MapLibre sizes its canvas once, from whatever the container measured at construction. If that
   * was zero — a hidden pane reports innerWidth 0 — it falls back to a small canvas and never
   * re-reads the container, so `map.project` returns coordinates in a space the framebuffer does
   * not cover. Everything still runs. Every number comes back 0.
   */
  const canvas = map.getCanvas()
  const probe = pixelAt(...HIMALAYA)
  if (!(canvas.width > 0 && canvas.height > 0)) {
    throw new Error(`the canvas is ${canvas.width}x${canvas.height} — nothing can be measured`)
  }
  if (!Number.isFinite(probe.x) || probe.x < 0 || probe.x >= canvas.width
      || !Number.isFinite(probe.y) || probe.y < 0 || probe.y >= canvas.height) {
    throw new Error(
      `the map centre projects to (${probe.x}, ${probe.y}), outside a ${canvas.width}x${canvas.height} ` +
      `framebuffer (css ${canvas.clientWidth}x${canvas.clientHeight}). The canvas and the container ` +
      'disagree about their size, so every sample would fall outside the frame and read as zero.')
  }

  // ── 1. Relief shows on terrain and nowhere else ────────────────────────────────────────────
  // The whole claim of the difference formulation: flat ground must be untouched. If the ocean
  // moves at all, the shader is shading by the terrain normal rather than by its disagreement
  // with the sphere.
  const noRelief = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: sunFor(-3.1, 0) },
    clouds: { opacity: 0 },
  })
  const withRelief = await frameWith({
    daylight: { reliefPower: 1.5, cloudShadow: 0, sun: sunFor(-3.1, 0) },
    clouds: { opacity: 0 },
  })

  report.terminatorRidge = {
    himalaya: differenceOver(noRelief, withRelief, boxAround(noRelief, ...HIMALAYA, 24)),
    tibet: differenceOver(noRelief, withRelief, boxAround(noRelief, ...TIBET, 24)),
    ocean: differenceOver(noRelief, withRelief, boxAround(noRelief, ...BAY_OF_BENGAL, 24)),
  }

  // ── 2. Relief peaks at the terminator, not under a high sun ────────────────────────────────
  // Nothing in the shader arranges this — it falls out of shading by the difference. At the
  // subsolar point the sphere's normal already points at the sun, so a tilt costs only a
  // second-order cosine; at the terminator it is first order.
  const overheadOff = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: sunFor(...HIMALAYA) },
    clouds: { opacity: 0 },
  })
  const overheadOn = await frameWith({
    daylight: { reliefPower: 1.5, cloudShadow: 0, sun: sunFor(...HIMALAYA) },
    clouds: { opacity: 0 },
  })
  report.sunOverhead = {
    himalaya: differenceOver(overheadOff, overheadOn, boxAround(overheadOff, ...HIMALAYA, 24)),
  }

  // ── 3. The relief retires as the camera descends ───────────────────────────────────────────
  const atZoom = async (zoom) => {
    map.setZoom(zoom)
    await capture()
    const off = await frameWith({
      daylight: { reliefPower: 0, cloudShadow: 0, sun: sunFor(-3.1, 0) }, clouds: { opacity: 0 },
    })
    const on = await frameWith({
      daylight: { reliefPower: 1.5, cloudShadow: 0, sun: sunFor(-3.1, 0) }, clouds: { opacity: 0 },
    })
    return round(differenceOver(off, on, boxAround(off, ...HIMALAYA, 24)).mean, 3)
  }
  // The shipped fade curve, as actually rendered. Full strength well past one texel per pixel now
  // that amplitude is known not to decay — see the magnification table below for why.
  report.zoomFade = { z4: await atZoom(4), z7: await atZoom(7), z9: await atZoom(9), z11: await atZoom(11) }
  map.setZoom(4)
  await capture()

  // ── 4. Cloud shadows land away from the sun, not under the cloud ───────────────────────────
  // Sun to the WEST of the region, low: the shadows must be thrown EAST.
  const lowSun = sunFor(HIMALAYA[0] - 65, 5)
  const shadowOff = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: lowSun }, clouds: { opacity: 0, sun: lowSun },
  })
  const shadowOn = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0.5, sun: lowSun }, clouds: { opacity: 0, sun: lowSun },
  })
  const deckOn = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: lowSun }, clouds: { opacity: 0.6, sun: lowSun },
  })

  const shadowMask = differenceImage(shadowOff, shadowOn)
  const deckMask = differenceImage(shadowOff, deckOn)
  const centre = pixelAt(...HIMALAYA)
  const shift = bestShift(shadowMask, deckMask, shadowOff, centre, 80, 65)

  const scale = metresPerPixelAt(...HIMALAYA)
  report.screen = { metresPerPixel: Math.round(scale) }

  report.cloudShadow = {
    strength: round(differenceOver(shadowOff, shadowOn, boxAround(shadowOff, ...HIMALAYA, 60)).mean, 2),
    measuredOffsetKm: round(Math.hypot(shift.dx, shift.dy) * scale / 1000, 1),
    predictedOffsetKm: round(predictedShadowOffsetKm(...HIMALAYA, lowSun), 1),
    // How far the deck had to travel to sit on top of its own shadow. Negative dx means the deck
    // is WEST of the shadow, i.e. the shadow was thrown east, away from a sun in the west. This is
    // the whole point of the offset lookup.
    shiftEastPx: shift.dx,
    shiftNorthPx: shift.dy,
    correlation: shift.score,
    interior: shift.interior,
  }

  // The same geometry with the sun in the EAST: the shadow has to swing the other way.
  const eastSun = sunFor(HIMALAYA[0] + 65, 5)
  const eastOff = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: eastSun }, clouds: { opacity: 0, sun: eastSun },
  })
  const eastOn = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0.5, sun: eastSun }, clouds: { opacity: 0, sun: eastSun },
  })
  const eastDeck = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: eastSun }, clouds: { opacity: 0.6, sun: eastSun },
  })
  const eastShift = bestShift(
    differenceImage(eastOff, eastOn), differenceImage(eastOff, eastDeck), eastOff, centre, 80, 65,
  )
  report.cloudShadowSunInEast = {
    shiftEastPx: eastShift.dx,
    shiftNorthPx: eastShift.dy,
    measuredOffsetKm: round(Math.hypot(eastShift.dx, eastShift.dy) * scale / 1000, 1),
    predictedOffsetKm: round(predictedShadowOffsetKm(...HIMALAYA, eastSun), 1),
    correlation: eastShift.score,
    interior: eastShift.interior,
  }

  // ── 4b. How much magnification relief actually survives ────────────────────────────────────
  /**
   * The number the tiling system sizes itself on, and the one I got wrong the first time.
   *
   * The earlier answer came from reliefDetailFor, which measures magnification against the
   * MERCATOR scale — and this is a globe, where the real scale is about 2.3x finer. Worse, the
   * zoom sweep above was measuring my own CPU-side fade curve rather than the texture running out
   * of pixels: the fade had already cut the term to 0.90 at z6, so what looked like "relief
   * degrading" was mostly policy I had chosen.
   *
   * So: fade forced off (a huge reliefWidth pins its strength at 1), the real 8192 texture, and
   * magnification computed from the scale measured on the actual projection. That isolates the
   * only thing in question — how soft a normal map can get before the terminator stops raking.
   */
  const NO_FADE = 1e9
  const sun = sunFor(-3.1, 0)
  report.magnification = { devicePixelRatio: window.devicePixelRatio, samples: [] }

  for (const zoom of [4, 5, 6, 7, 8]) {
    map.setZoom(zoom)
    await capture()
    const scale = metresPerPixelAt(...HIMALAYA)
    const texelMetres = equirectMetresPerPixel(RELIEF_WIDTH, HIMALAYA[1])
    // Keep the SAME GROUND under the box at every zoom, or this measures different mountains.
    const halfWidth = Math.min(300, Math.round(24 * 2 ** (zoom - 4)))

    const off = await frameWith({
      daylight: { reliefPower: 0, reliefWidth: NO_FADE, cloudShadow: 0, sun }, clouds: { opacity: 0 },
    })
    const on = await frameWith({
      daylight: { reliefPower: 1.5, reliefWidth: NO_FADE, cloudShadow: 0, sun }, clouds: { opacity: 0 },
    })
    const measured = differenceOver(off, on, boxAround(off, ...HIMALAYA, halfWidth))
    report.magnification.samples.push({
      zoom,
      metresPerDevicePixel: Math.round(scale),
      // Screen pixels one texel covers — the tiling system's own selection metric.
      pixelsPerTexel: round(texelMetres / scale, 2),
      // How hard the ground is shaded. Insensitive to blur, so NOT the resolution answer.
      amplitude: round(measured.mean, 2),
      // How much of that shading survives at the pixel scale. This is the resolution answer.
      detail: round(detailOver(differenceImage(off, on), off, pixelAt(...HIMALAYA), halfWidth), 3),
    })
  }
  map.setZoom(4)
  await capture()

  // ── 4c. An ABSOLUTE anchor for the relief term ─────────────────────────────────────────────
  /**
   * Every other relief figure here is relative — this ridge against that plateau, this
   * magnification against that one. A uniformly wrong scale factor satisfies all of them, which is
   * exactly how moon.js carried a twenty-degree error through a suite of self-consistent tests.
   *
   * So: a normal map with ONE known tilt in every texel, a known sun, and the whole shader worked
   * out in closed form on the CPU. If the rendered pixel matches the arithmetic, the relief term
   * has the right magnitude and not merely the right shape.
   */
  const KNOWN_TILT_DEG = 12
  const anchorPoint = [40, 10]
  const anchorSun = sunFor(anchorPoint[0] + 80, 0)   // low in the east, so the west-leaning face shades

  const tilt = KNOWN_TILT_DEG * Math.PI / 180
  // Leaning WEST: negative east component, which is what ground rising to the east produces.
  const ideal = new Float32Array([-Math.sin(tilt), 0, Math.cos(tilt)])
  // Predict from the bytes the texture will actually hold, not from the ideal — the encode rounds.
  const bytes = encodeNormals(ideal)
  const tangent = [0, 1, 2].map((c) => (bytes[c] / 255 - 128 / 255) * 2)

  map.jumpTo({ center: anchorPoint, zoom: 3 })
  daylight.setOptions({ reliefUrl: uniformNormalMap(bytes), reliefWidth: NO_FADE })
  await waitForRelief()

  const flat = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: anchorSun }, clouds: { opacity: 0 },
  })
  const tilted = await frameWith({
    daylight: { reliefPower: 1.5, cloudShadow: 0, sun: anchorSun }, clouds: { opacity: 0 },
  })

  const at = pixelAt(...anchorPoint)
  const index = (at.y * flat.width + at.x) * 4
  report.absoluteAnchor = {
    tiltDegrees: KNOWN_TILT_DEG,
    predicted: predictShadedPixel({
      point: anchorPoint, sun: anchorSun, tangent, power: 1.5,
      background: [...flat.pixels.slice(index, index + 3)],
    }),
    measured: [...tilted.pixels.slice(index, index + 3)],
  }

  // Back to the real map for everything that follows.
  daylight.setOptions({ reliefUrl: RELIEF_URL, reliefWidth: RELIEF_WIDTH })
  await waitForRelief()
  map.jumpTo({ center: HIMALAYA, zoom: 4 })
  await capture()

  // ── 5. What each setting of reliefPower actually buys ──────────────────────────────────────
  // A dial with numbers on it, so the default is a judgement rather than a guess. Watch `max` as
  // much as `mean`: the term is added to imagery that is already near white on snow, so the point
  // where the peaks stop gaining is the point where it has started clipping.
  report.reliefPowerSweep = {}
  for (const power of [0.75, 1.5, 2.5, 4]) {
    const off = await frameWith({
      daylight: { reliefPower: 0, cloudShadow: 0, sun: sunFor(-3.1, 0) }, clouds: { opacity: 0 },
    })
    const on = await frameWith({
      daylight: { reliefPower: power, cloudShadow: 0, sun: sunFor(-3.1, 0) }, clouds: { opacity: 0 },
    })
    const measured = differenceOver(off, on, boxAround(off, ...HIMALAYA, 24))
    report.reliefPowerSweep[power] = { mean: round(measured.mean, 2), max: round(measured.max, 1) }
  }

  // ── 6. A cloud shadow at noon is grey, not a private sunset ────────────────────────────────
  // The twilight band is keyed to the SUN's angle. Key it to the light that actually lands, and a
  // cloud shadow — which halves that light — lands exactly on the peak of the warm band and paints
  // itself orange in the middle of the afternoon. It renders beautifully and it is nonsense; a
  // luminance-only measurement never sees it, because the shadow is the right DARKNESS throughout.
  const highSun = sunFor(...HIMALAYA)
  const noonClear = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: highSun }, clouds: { opacity: 0, sun: highSun },
  })
  const noonShadowed = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0.5, sun: highSun }, clouds: { opacity: 0, sun: highSun },
  })

  const wide = boxAround(noonClear, ...HIMALAYA, 200)
  const shadowed = wide.filter((i) =>
    Math.abs(luma(noonClear.pixels, i) - luma(noonShadowed.pixels, i)) > 8)
  const warmth = (frame, indices) => indices.reduce(
    (total, i) => total + (frame.pixels[i] - frame.pixels[i + 2]), 0) / Math.max(1, indices.length)

  report.noonShadowColour = {
    shadowedPixels: shadowed.length,
    // How much redder than blue the shadow is, against the same ground unshadowed. Positive means
    // the shadow is warm, which at this sun angle can only have come from the twilight band.
    warmerThanGroundBy: round(warmth(noonShadowed, shadowed) - warmth(noonClear, shadowed), 2),
  }

  // ── 7. The moon's shadow, on a date it really happened ─────────────────────────────────────
  // 12 August 2026: Greenland, Iceland, Spain at sunset. The umbra has to be a SMALL dark patch in
  // the middle of an otherwise ordinary daylit hemisphere — if the whole day side dims, the shader
  // is using one global moon direction instead of the moon's position, and the parallax that makes
  // totality a hundred-kilometre track has been thrown away.
  const eclipseDate = new Date(Date.UTC(2026, 7, 12, 17, 46))
  const eclipseSun = sunDirection(eclipseDate)
  const umbra = deepestEclipse(eclipseDate)
  // Far enough out that the WHOLE disc is on screen. At z3 the globe is about 1,500 px across and
  // the canvas sees only its middle — which is roughly the width of a real penumbra, so the first
  // run of this reported the shadow covering 100% of the visible planet and looked like the exact
  // bug it was meant to catch. The framing was wrong, not the shader.
  map.jumpTo({ center: [umbra.lng, umbra.lat], zoom: 1.8 })
  await capture()

  const noEclipse = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: eclipseSun, eclipse: false, date: eclipseDate },
    clouds: { opacity: 0, sun: eclipseSun },
  })
  const withEclipse = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: eclipseSun, eclipse: true, date: eclipseDate },
    clouds: { opacity: 0, sun: eclipseSun },
  })

  /**
   * How much of the visible planet the shadow actually covers.
   *
   * A fixed control box is the wrong instrument here — at globe zoom it falls off the disc and
   * silently measures nothing, which is what the first version of this did (441 samples at the
   * umbra, 0 samples for the control, and a report that looked like a pass). Counting changed
   * pixels against lit pixels asks the question directly: is this a patch, or is it the
   * hemisphere? One global moon direction gives the hemisphere.
   */
  let litPixels = 0
  let penumbra = 0
  let totality = 0
  for (let k = 0; k < noEclipse.width * noEclipse.height; k++) {
    const base = luma(noEclipse.pixels, k * 4)
    if (base < 20) continue                     // space, or the night side
    litPixels++
    const lost = Math.abs(base - luma(withEclipse.pixels, k * 4))
    if (lost > 5) penumbra++
    if (lost > 100) totality++
  }

  report.eclipse = {
    predictedSunLeft: round(umbra.fraction, 4),
    umbraLngLat: [round(umbra.lng, 2), round(umbra.lat, 2)],
    atUmbra: differenceOver(noEclipse, withEclipse, boxAround(noEclipse, umbra.lng, umbra.lat, 10)),
    litPixels,
    // The penumbra is genuinely enormous — thousands of kilometres — so a large share here is
    // correct. It is TOTALITY that has to be a speck: that is the part the moon's parallax buys,
    // and the part a single global moon direction would smear across the hemisphere.
    penumbraShare: round(penumbra / Math.max(1, litPixels), 4),
    totalityShare: round(totality / Math.max(1, litPixels), 5),
  }

  const clear = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun: eclipseSun, eclipse: true, date: new Date(Date.UTC(2026, 7, 26, 12)) },
    clouds: { opacity: 0, sun: eclipseSun },
  })
  report.eclipse.onAnOrdinaryDay =
    differenceOver(noEclipse, clear, boxAround(noEclipse, umbra.lng, umbra.lat, 10))

  map.jumpTo({ center: HIMALAYA, zoom: 4 })
  await capture()

  // Back to something worth photographing.
  await frameWith({
    daylight: { reliefPower: 1.5, cloudShadow: 0.5, sun: sunFor(-3.1, 0) },
    clouds: { opacity: 0.5, sun: sunFor(-3.1, 0) },
  })
  return report
}

// ── Wiring ───────────────────────────────────────────────────────────────────────────────────

/**
 * Wait for everything the measurements need, and SAY WHAT IS MISSING while waiting.
 *
 * The first version printed one frozen line and waited forever. In the hidden browser pane that is
 * indistinguishable from a broken feature: the assets were all 200, the relief map decoded in
 * 224 ms, there were no console errors, and the page simply sat there. It cost someone else an
 * hour before the pane being hidden was the answer.
 *
 * A hidden document fires no requestAnimationFrame at all, so MapLibre never completes a frame and
 * `map.loaded()` never turns true however healthy everything else is. That is not a bug in the
 * page, but a rig that hangs without saying so is the same failure class as everything else here —
 * no error, nothing to explain it, and it looks like the thing under test is broken.
 */
const OUTSTANDING = [
  ['relief map', () => daylight.hasRelief],
  ['cloud field', () => clouds.hasField],
  ['first rendered frame', () => map.loaded()],
]

const ready = () => new Promise((resolve, reject) => {
  const startedAt = performance.now()
  const check = () => {
    const missing = OUTSTANDING.filter(([, done]) => !done()).map(([name]) => name)
    if (!missing.length) return resolve()

    const seconds = (performance.now() - startedAt) / 1000
    say(`waiting for: ${missing.join(', ')}   (${seconds.toFixed(0)}s)`)

    if (seconds > 20) {
      const hidden = document.hidden || document.visibilityState !== 'visible'
      return reject(new Error(
        `gave up after ${seconds.toFixed(0)}s waiting for: ${missing.join(', ')}\n\n` +
        (hidden
          ? 'THIS TAB IS HIDDEN. A hidden document fires no requestAnimationFrame, so MapLibre\n' +
            'never finishes a frame and never reports itself loaded — however healthy the assets\n' +
            'are. Nothing here is broken. Bring the tab to the front, or run the measurements\n' +
            'under Playwright:\n\n' +
            '    npx playwright test tests/playwright/globe-relief.spec.ts'
          : 'The tab is visible, so this is NOT the hidden-pane problem. Check the network panel\n' +
            'for the asset named above, and the console for a shader compile error.')))
    }
    setTimeout(check, 250)
  }
  check()
})

/**
 * A watchdog that does NOT hang off `load`.
 *
 * MapLibre's `load` fires only after a frame has been rendered, so in a hidden tab it may never
 * fire at all — and then nothing downstream of it runs, including anything that would have
 * explained why. The one thing that must always report is the thing that says nothing is
 * reporting, so it starts here rather than inside the handler.
 */
let started = false
setTimeout(() => {
  if (started || window.__measured) return
  const hidden = document.hidden || document.visibilityState !== 'visible'
  say(`the map never finished loading — its 'load' event has not fired after 20s.\n\n` +
    (hidden
      ? 'THIS TAB IS HIDDEN, and a hidden document fires no requestAnimationFrame, so MapLibre\n' +
        'never renders a frame and never emits load. Nothing is broken. Bring the tab to the\n' +
        'front, or run the measurements under Playwright:\n\n' +
        '    npx playwright test tests/playwright/globe-relief.spec.ts'
      : 'The tab is visible, so this is not the hidden-tab problem. Check the console for a\n' +
        'style or shader error.'))
}, 20000)

map.on('load', async () => {
  started = true
  map.addLayer(daylight)
  map.addLayer(clouds)
  map.addLayer(atmosphere)
  map.addLayer(probe)
  try {
    await ready()
    say('measuring…')
    window.__report = await measurements()
    say(JSON.stringify(window.__report, null, 2))
    window.__measured = true
    if (params.get('imagery')) readout.classList.add('hidden')
  } catch (error) {
    // Loudly, and on the page — a rig that fails silently is the failure class this whole harness
    // exists to catch.
    say(String(error.message || error))
    window.__harnessError = String(error.message || error)
    console.error(error)
  }
})

/**
 * How much light the haze band adds just outside the planet's edge, on the lit limb and on the
 * unlit one, in a single frame.
 *
 * WHAT MAKES THIS AN ABSOLUTE READING rather than another relative one. The quantity is excess over
 * the KNOWN background colour the harness paints, so "the night limb is black" is a statement about
 * a number near zero and not about one side being darker than the other. A uniform brightening of
 * the whole band — exactly the failure being fixed — moves this reading and would not move a
 * day-versus-night ratio.
 *
 * The camera sits 90° from the subsolar point, so the two ends of the horizontal diameter are the
 * subsolar limb and the antisolar limb: the brightest air on the planet and air in full shadow, in
 * the same frame, at the same grazing angle, under the same everything else.
 */
const limbProfile = async () => {
  const SUBSOLAR = 0
  atmosphere.setOptions({ sun: sunFor(SUBSOLAR, 0), strength: 1 })
  // ONLY the haze band draws. The night shell and the cloud deck reach the limb too, and a reading
  // that includes them measures three layers while claiming to measure one — the first run of this
  // rig scored 13.0 on the unlit limb with the band already correct, because it was reading the
  // dark side of the planet a few pixels further in.
  daylight.setOptions({ sun: sunFor(SUBSOLAR, 0), nightDarkness: 0, reliefPower: 0 })
  clouds.setOptions({ sun: sunFor(SUBSOLAR, 0), opacity: 0 })
  map.jumpTo({ center: [SUBSOLAR + 90, 0], zoom: 1.4, bearing: 0, pitch: 0 })
  await capture()
  const frame = await capture()

  const { pixels, width, height } = frame
  const row = Math.round(height / 2)
  const lumaAt = (buffer, x) => luma(buffer, (row * width + x) * 4)

  /**
   * WHAT IS BEING MEASURED, precisely: the light the band adds to EMPTY SPACE beside the planet.
   *
   * Under a globe projection the style's background paints the sphere and nothing else, so the void
   * around the disc is black and the band is the only thing that can put light there. Reading the
   * difference between a frame with the band and one without it, restricted to pixels that are void
   * in the frame without it, isolates the band completely — no planet surface, no night shell, no
   * dependence on knowing where the silhouette falls.
   *
   * The first version of this rig scanned inward from the frame edge and reported 138 on both
   * limbs. It had walked past the band and was reading the grey planet.
   */
  const withBand = pixels
  atmosphere.setOptions({ strength: 0 })
  await capture()
  const withoutBand = (await capture()).pixels

  /**
   * THE READING HAS TO GO ALL THE WAY ROUND THE LIMB, and the first version of it did not.
   *
   * Scanning the equatorial row samples exactly two points — the subsolar limb and the antisolar
   * one — and those are the two places the broken build and the fixed one AGREE. Straight behind
   * the planet the old angular gate returned 0 too, so the row scored an identical 83.4 / 0.00 on
   * both and looked like proof. The halo lives at the top and bottom of the disc, where the limb
   * runs over the poles: air there sits 90° from the sun, which the old gate scored at 0.62.
   *
   * So the band is sampled by ANGLE around the disc, and what is reported is the profile. Bucket 0
   * points at the sun; bucket 180 points directly away from it.
   */
  const lumaXY = (buffer, x, y) => luma(buffer, (y * width + x) * 4)
  const isVoid = (x, y) => lumaXY(withoutBand, x, y) <= 1.5

  // The disc, as the centroid and extent of everything that is not void.
  let sumX = 0; let sumY = 0; let count = 0
  let minX = width; let maxX = 0
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (isVoid(x, y)) continue
      sumX += x; sumY += y; count++
      if (x < minX) minX = x
      if (x > maxX) maxX = x
    }
  }
  const centre = { x: sumX / count, y: sumY / count }
  const radius = (maxX - minX) / 2

  // The sun is at bucket 0. The camera sits 90° east of the subsolar point, so the sun is off the
  // LEFT edge of the frame — measured above: the lit limb peaked on the left at x=266.
  const BUCKETS = 36
  const profile = new Array(BUCKETS).fill(0)
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (!isVoid(x, y)) continue
      const dx = x - centre.x
      const dy = y - centre.y
      const r = Math.hypot(dx, dy)
      if (r < radius || r > radius * 1.12) continue     // the shell's annulus only
      const added = lumaXY(withBand, x, y) - lumaXY(withoutBand, x, y)
      if (added <= 0) continue
      // atan2 from the -x axis (toward the sun), so bucket 0 is the subsolar limb.
      const angle = (Math.atan2(dy, -dx) * 180 / Math.PI + 360) % 360
      const bucket = Math.min(BUCKETS - 1, Math.floor(angle / (360 / BUCKETS)))
      if (added > profile[bucket]) profile[bucket] = added
    }
  }
  const round = (v) => Math.round(v * 100) / 100
  const byAngle = profile.map(round)

  // Buckets whose centre is more than 90° from the sun: air the earth stands in front of.
  const nightBuckets = byAngle.filter((_, i) => {
    const centreAngle = (i + 0.5) * (360 / BUCKETS)
    return centreAngle > 100 && centreAngle < 260
  })

  atmosphere.setOptions({ strength: 1 })
  return {
    litLimb: round(Math.max(...byAngle)),
    unlitLimb: round(Math.max(...nightBuckets)),
    ratio: Math.max(...byAngle) > 0
      ? Math.round((Math.max(...nightBuckets) / Math.max(...byAngle)) * 1000) / 1000
      : null,
    byAngle,
    disc: { centre: { x: Math.round(centre.x), y: Math.round(centre.y) }, radius: Math.round(radius) },
    canvas: { width, height },
  }
}

/**
 * Whether the cloud deck repeats — the acceptance test for the tiled source, measured.
 *
 * The deck is built from six patches of real cloud, 626 km each, laid across the whole planet. Laid
 * down on a plain grid that repeats every 626 km, and a visible repeat over open ocean is a worse
 * artefact than the blur it replaces. So "is the repeat invisible" is the question the whole change
 * turns on, and it is not one to answer by looking: a periodicity the eye only half-notices is
 * exactly the kind of thing that gets argued about instead of measured.
 *
 * WHAT THIS READS. The deck alone, as the difference between a frame with it and a frame without —
 * so the ground, the terminator and the lighting gradient all cancel and what is left is cloud. Then
 * its autocorrelation along each axis. A repeat at period P puts a peak at lag P; a stochastic
 * tiling has nothing anywhere but the origin. The number reported is the WORST sidelobe outside a
 * small exclusion around zero, which is the strongest periodicity present at any scale.
 *
 * Zero-mean and unit-norm, or this measures the brightness gradient across the globe rather than the
 * clouds on it — the same trap bestShift above documents.
 */
/**
 * Zoom 3, not the close view. A repeat can only be SEEN where several tiles are on screen at once:
 * closer in, less than one patch fills the window and there is nothing to repeat against, so a
 * measurement there would pass for want of anything to find. At z3 a pixel covers about 6 km and the
 * 300 px window spans roughly 1,800 km — near three patches side by side, which is where a plain
 * tiling would announce itself.
 */
const cloudRepeat = async ({ lng = -20, lat = 53, zoom = 3, half = 150 } = {}) => {
  const sun = sunFor(lng - 40, 20)
  map.jumpTo({ center: [lng, lat], zoom, bearing: 0, pitch: 0 })
  await capture()

  // Advection off: the flow-map cross-fade blurs the field along the wind, which would soften a
  // real repeat and flatter the result. The source is judged as it is, not as the wind leaves it.
  const bare = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun }, clouds: { opacity: 0, sun, windAmount: 0 },
  })
  const decked = await frameWith({
    daylight: { reliefPower: 0, cloudShadow: 0, sun }, clouds: { opacity: 1, sun, windAmount: 0 },
  })

  const mask = differenceImage(bare, decked)
  const centre = pixelAt(lng, lat)

  /** Rows or columns through the box, as arrays of samples. */
  const lines = (alongX) => {
    const out = []
    for (let a = -half; a <= half; a += 2) {
      const line = []
      for (let b = -half; b <= half; b++) {
        const px = alongX ? centre.x + b : centre.x + a
        const py = alongX ? centre.y + a : centre.y + b
        if (px < 0 || py < 0 || px >= bare.width || py >= bare.height) { line.length = 0; break }
        line.push(mask[py * bare.width + px])
      }
      if (line.length) out.push(line)
    }
    return out
  }

  /** Mean autocorrelation over a set of lines, as an array indexed by lag. */
  const autocorrelation = (set, maxLag) => {
    const total = new Float64Array(maxLag + 1)
    let counted = 0
    for (const line of set) {
      const mean = line.reduce((s, v) => s + v, 0) / line.length
      const centred = line.map((v) => v - mean)
      const energy = centred.reduce((s, v) => s + v * v, 0)
      // A line with no variation has no autocorrelation, and dividing by its zero energy would
      // return NaN — or worse, a 1 that reads as a perfect repeat.
      if (energy < 1e-6) continue
      for (let lag = 0; lag <= maxLag; lag++) {
        let sum = 0
        for (let i = 0; i + lag < centred.length; i++) sum += centred[i] * centred[i + lag]
        total[lag] += sum / energy
      }
      counted++
    }
    if (!counted) throw new Error('no cloud variation in the sample box — nothing here is a result')
    return Array.from(total, (v) => v / counted)
  }

  const MAX_LAG = 120
  // Below this lag the curve is still the central peak coming down, not a repeat.
  const EXCLUDE = 6

  const report = {}
  for (const [name, alongX] of [['east', true], ['north', false]]) {
    const curve = autocorrelation(lines(alongX), MAX_LAG)

    /**
     * PROMINENCE, not the raw correlation — and the difference is the whole measurement.
     *
     * The first version of this took the largest correlation outside a small exclusion around zero,
     * and it could not tell the two cases apart: a plain repeating tiling scored 0.36 on one axis
     * and 0.11 on the other, while the stochastic one scored 0.24 and 0.27. The stochastic figures
     * were HIGHER on one axis than the artefact they were supposed to be ruling out.
     *
     * Nothing was wrong with the render. Cloud features here are hundreds of kilometres across, so
     * the central peak is wide and decays slowly, and at any lag near it the raw correlation is
     * mostly that shoulder. It swamped the thing being looked for. Pushing the exclusion out past
     * the shoulder does not work either: the repeat sits at 167 km, INSIDE the width of one cloud.
     *
     * What actually distinguishes them is the SHAPE. An aperiodic field's autocorrelation falls and
     * stays fallen. A repeat makes it come back up. So this measures how far the curve rises above
     * its own running minimum — zero for any monotone decay, whatever height it decays from, and
     * positive only where something recurs.
     */
    let worst = { lag: 0, value: 0, at: 0 }
    let floor = curve[EXCLUDE]
    for (let lag = EXCLUDE; lag <= MAX_LAG; lag++) {
      if (curve[lag] < floor) floor = curve[lag]
      const rise = curve[lag] - floor
      if (rise > worst.value) worst = { lag, value: round(rise, 4), at: round(curve[lag], 4) }
    }
    report[name] = {
      recurrence: worst.value,
      atLagPx: worst.lag,
      correlationThere: worst.at,
      // Kept so a suspicious number can be looked at rather than taken on trust.
      curve: curve.filter((_, i) => i % 6 === 0).map((v) => round(v, 3)),
    }
  }

  report.metresPerPixel = Math.round(metresPerPixelAt(lng, lat))
  report.where = { lng, lat, zoom }
  return report
}

/** A coarse slice through the middle of the frame, so the scene's shape can be read rather than assumed. */
const rowSlice = async () => {
  const frame = await capture()
  const { pixels, width, height } = frame
  const row = Math.round(height / 2)
  const out = []
  for (let x = 0; x < width; x += 30) {
    const i = (row * width + x) * 4
    out.push([x, pixels[i], pixels[i + 1], pixels[i + 2]])
  }
  return { width, height, row, samples: out }
}

// Driven from the browser tools as well as by eye.
window.__rowSlice = rowSlice
window.__limbProfile = limbProfile
window.__cloudRepeat = cloudRepeat
window.__map = map
window.__daylight = daylight
window.__clouds = clouds
window.__sunFor = sunFor
window.__frameWith = frameWith
// The real solar direction for a date, so a screenshot can be of a moment rather than of a look.
window.__sunOn = (iso) => sunDirection(new Date(iso))
window.__deepestEclipse = (iso) => deepestEclipse(new Date(iso))

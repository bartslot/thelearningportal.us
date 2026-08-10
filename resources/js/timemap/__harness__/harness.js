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
import { sunDirection } from '../sun.js'

const RELIEF_URL = '/img/map/earth-normal-8192.webp'
const RELIEF_WIDTH = 8192
const FIELD_URL = '/img/map/clouds-field.webp'
const WIND_URL = '/img/map/wind-field.png'
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

const daylight = createDaylightLayer({
  sun: sunFor(-3.1, 0),
  reliefUrl: RELIEF_URL,
  reliefWidth: RELIEF_WIDTH,
  fieldUrl: FIELD_URL,
  windUrl: WIND_URL,
  animate: false,          // a frozen clock, so two captures are comparable
})
const clouds = createCloudLayer({
  sun: sunFor(-3.1, 0),
  fieldUrl: FIELD_URL,
  windUrl: WIND_URL,
  animate: false,
})

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

const differenceOver = (a, b, indices) => {
  let total = 0
  let worst = 0
  for (const i of indices) {
    const delta = Math.abs(luma(a.pixels, i) - luma(b.pixels, i))
    total += delta
    if (delta > worst) worst = delta
  }
  return { mean: total / Math.max(1, indices.length), max: worst, samples: indices.length }
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

/** A frame reduced to "how much did this pixel change", which is what every measurement wants. */
const differenceImage = (a, b) => {
  const out = new Float32Array(a.width * a.height)
  for (let k = 0; k < out.length; k++) {
    out[k] = Math.abs(luma(a.pixels, k * 4) - luma(b.pixels, k * 4))
  }
  return out
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
  report.zoomFade = { z4: await atZoom(4), z6: await atZoom(6), z7: await atZoom(7), z9: await atZoom(9) }
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

  // Back to something worth photographing.
  await frameWith({
    daylight: { reliefPower: 1.5, cloudShadow: 0.5, sun: sunFor(-3.1, 0) },
    clouds: { opacity: 0.5, sun: sunFor(-3.1, 0) },
  })
  return report
}

// ── Wiring ───────────────────────────────────────────────────────────────────────────────────

const ready = () => new Promise((resolve) => {
  const check = () => {
    if (daylight.hasRelief && clouds.hasField && map.loaded()) return resolve()
    setTimeout(check, 60)
  }
  check()
})

map.on('load', async () => {
  map.addLayer(daylight)
  map.addLayer(clouds)
  map.addLayer(probe)
  say('waiting for the relief map and the cloud field…')
  await ready()
  say('measuring…')
  window.__report = await measurements()
  say(JSON.stringify(window.__report, null, 2))
  window.__measured = true
  if (params.get('imagery')) readout.classList.add('hidden')
})

// Driven from the browser tools as well as by eye.
window.__map = map
window.__daylight = daylight
window.__clouds = clouds
window.__sunFor = sunFor
window.__frameWith = frameWith

/**
 * harness.js — a globe with the sun on it, and a way to measure what actually landed on the canvas.
 *
 * WHY A PROBE LAYER AND NOT A SCREENSHOT. The browser pane runs MapLibre without
 * `preserveDrawingBuffer`, so the colour buffer is thrown away the instant the frame is composited.
 * `canvas.toDataURL()` and `drawImage(canvas, …)` after the fact both come back solid black — which
 * looks exactly like a layer that drew nothing, and will happily convince you to go and debug a
 * shader that was working the whole time. The only reading you can trust is `gl.readPixels` taken
 * INSIDE a render pass, so the measurement is itself a custom layer, added last so everything else
 * has already drawn.
 *
 *   await window.sunHarness.measure()          → stats for the current frame
 *   await window.sunHarness.setSun({ ... })     → change layer options, then measure again
 *   await window.sunHarness.view({ beyondLimb: 0.4, radii: 8 })
 *
 * `view` is the useful one: it places the camera so the sun's centre sits a chosen number of
 * degrees outside the earth's silhouette. Hunting for that pose by hand is most of an afternoon,
 * because the sun is only in frame at all when the camera is within about a field of view of
 * antisolar.
 */

import maplibregl from 'maplibre-gl'
import { cameraInPlanetSpace } from '../../resources/js/timemap/planet-mesh.js'
import { createSunLayer } from '../../resources/js/timemap/sun-disc.js'
import { createBloomLayer } from '../../resources/js/timemap/bloom.js'
import { createStarfieldLayer } from '../../resources/js/timemap/starfield.js'
import { createAtmosphereLayer } from '../../resources/js/timemap/atmosphere.js'
import { createDaylightLayer } from '../../resources/js/timemap/daylight.js'
import { sunDirection, subsolarPoint, sunAngularRadius } from '../../resources/js/timemap/sun.js'

const DATE = new Date(Date.UTC(2026, 2, 20, 12))
const sun = sunDirection(DATE)

/**
 * A canvas of a size we chose, not one the pane happened to give us.
 *
 * A hidden page lays out to zero, so MapLibre falls back to its own 400×300 default and every
 * measurement in pixels silently changes meaning between runs. `?w=&h=` pins it — and note the
 * device pixel ratio: `gl.readPixels` works in device pixels, so a 1280-wide box is a 2560-wide
 * buffer on this display and the expected disc size has to be quoted against the buffer.
 */
const params = new URLSearchParams(location.search)
const container = document.getElementById('map')
container.style.inset = 'auto'
container.style.width = `${Number(params.get('w') || 1280)}px`
container.style.height = `${Number(params.get('h') || 720)}px`

const map = new maplibregl.Map({
  container: 'map',
  antialias: true,
  attributionControl: false,
  fadeDuration: 0,
  style: {
    version: 8,
    projection: { type: 'globe' },
    sources: {
      land: { type: 'vector', tiles: [`${location.origin}/land-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
    },
    layers: [
      { id: 'ocean', type: 'background', paint: { 'background-color': '#12263f' } },
      { id: 'land', type: 'fill', source: 'land', 'source-layer': 'land', paint: { 'fill-color': '#2f4a34' } },
    ],
  },
  center: [0, 0],
  zoom: 0.4,
})

const layers = {
  starfield: createStarfieldLayer({ textureUrl: null, brightness: 1 }),
  daylight: createDaylightLayer({ sun, nightDarkness: 0.82 }),
  atmosphere: createAtmosphereLayer({ sun, strength: 1 }),
  sun: createSunLayer({ date: DATE }),
  // Last of the drawing layers, because it blooms whatever is underneath it.
  bloom: createBloomLayer(),
}

/**
 * Reads the frame back from inside the pass that drew it. Nothing else can: see the note at the top.
 * Costs a full pipeline stall, so it only runs when something has asked for a measurement.
 */
const probe = {
  id: 'tm-probe',
  type: 'custom',
  renderingMode: '3d',
  pending: null,
  onAdd(_map, gl) { this.gl = gl },
  render(gl) {
    if (!this.pending) return
    const resolve = this.pending
    this.pending = null
    const canvas = map.getCanvas()
    const { width, height } = canvas
    const pixels = new Uint8Array(width * height * 4)
    gl.readPixels(0, 0, width, height, gl.RGBA, gl.UNSIGNED_BYTE, pixels)
    resolve({ pixels, width, height })
  },
}

/** Perceived brightness per pixel, 0..1, in the row order readPixels uses (bottom row first). */
const luminanceOf = ({ pixels, width, height }) => {
  const out = new Float32Array(width * height)
  for (let i = 0; i < out.length; i++) {
    out[i] = (0.2126 * pixels[i * 4] + 0.7152 * pixels[i * 4 + 1] + 0.0722 * pixels[i * 4 + 2]) / 255
  }
  return out
}

const round = (v, places = 4) => Number(v.toFixed(places))

/**
 * Frame timing that is actually worth quoting.
 *
 * A CPU timer wrapped around a repaint is worthless here: WebGL commands are queued, so the timer
 * stops long before the GPU has done anything and reports a 49 ms layer as 0.28 ms.
 * `EXT_disjoint_timer_query_webgl2` is no better in a hidden page — measured, it produced incoherent
 * results, including a frame that got FASTER when a layer was removed.
 *
 * So the frame is bracketed by two custom layers, one added first and one added last, each calling
 * `gl.finish()`. That blocks until the GPU has actually drained, which is expensive and exactly why
 * it is only done while benchmarking: it makes the number honest. What comes out is the wall-clock
 * cost of one full frame, everything included, and it is a per-frame cost rather than a frame RATE
 * — the pane's throttling changes how OFTEN frames happen, never how long one takes.
 */
const clock = { active: false, start: 0, samples: [] }

const frameStart = {
  id: 'tm-frame-start',
  type: 'custom',
  renderingMode: '2d',
  onAdd() {},
  render(gl) {
    if (!clock.active) return
    gl.finish()                    // drain the previous frame so it cannot land in this one
    clock.start = performance.now()
  },
}

const frameEnd = {
  id: 'tm-frame-end',
  type: 'custom',
  renderingMode: '2d',
  onAdd() {},
  render(gl) {
    if (!clock.active || !clock.start) return
    gl.finish()
    clock.samples.push(performance.now() - clock.start)
    clock.start = 0
  },
}

/**
 * Time `count` frames and return the distribution.
 *
 * The median rather than the mean: one scheduling hiccup in a hidden page skews an average badly,
 * and the 95th percentile is reported separately because that is the number a stutter comes from.
 */
const benchmark = async (count = 90, { move = true } = {}) => {
  clock.active = true
  clock.samples = []
  clock.start = 0
  const bearing = map.getBearing()
  for (let i = 0; i < count + 10; i++) {          // ten warm-up frames, discarded below
    // NUDGE THE CAMERA. `triggerRepaint` alone lets MapLibre decide there is nothing to redraw, and
    // a skipped frame still runs the clock layers — so the sample set fills with sub-millisecond
    // readings that drag the median down and make everything look free. A hundredth of a degree of
    // bearing is invisible and forces a genuine frame.
    if (move) map.setBearing(bearing + i * 0.01)
    map.triggerRepaint()
    await frames(1)
  }
  if (move) map.setBearing(bearing)
  clock.active = false
  const samples = clock.samples.slice(10).sort((a, b) => a - b)
  if (!samples.length) return { frames: 0 }
  const at = (q) => samples[Math.min(samples.length - 1, Math.floor(samples.length * q))]
  return {
    frames: samples.length,
    medianMs: round(at(0.5), 3),
    /**
     * The mean is here for one specific reason: `performance.now()` is clamped to 100 microseconds
     * in Chromium, so a pass costing less than that measures as either 0 or 0.1 and its median is
     * whichever it hit more often. Averaging hundreds of samples dithers across the clamp and
     * recovers the sub-tick figure, which is the only way to say how much a cheap pass really costs
     * rather than "under the clock's resolution".
     */
    meanMs: round(samples.reduce((sum, v) => sum + v, 0) / samples.length, 4),
    p95Ms: round(at(0.95), 3),
    minMs: round(samples[0], 3),
    impliedFps: Math.round(1000 / at(0.5)),
    canvas: [map.getCanvas().width, map.getCanvas().height],
  }
}

/**
 * One frame, read back from inside the pass that drew it.
 *
 * The watchdog is not paranoia: a dropped repaint leaves the promise pending forever and the whole
 * measurement run hangs with a perfectly healthy page, which is indistinguishable from a crash from
 * the outside. Asking again costs one frame.
 */
const capture = () => new Promise((resolve, reject) => {
  let tries = 0
  probe.pending = resolve
  map.triggerRepaint()
  const watchdog = setInterval(() => {
    if (!probe.pending) return clearInterval(watchdog)
    if (++tries > 20) {
      clearInterval(watchdog)
      probe.pending = null
      return reject(new Error('capture: no frame after 20 repaints'))
    }
    map.triggerRepaint()
  }, 50)
})

/**
 * What the sun ITSELF put on the canvas: the frame with it, minus the frame without it.
 *
 * A whole-frame reading cannot answer this. The starfield is thousands of fully saturated single
 * pixels scattered across the sky, so "the brightest pixel" is a star, "pixels above 80% of peak"
 * is a star count, and the number does not move when the sun is switched off — which reads as a
 * layer that draws nothing and is in fact a measurement that looks at the wrong thing.
 *
 * The difference has none of that. Everything else in the scene is identical between the two
 * frames and subtracts to zero, so what is left is exactly the sun's contribution, and the disc's
 * size and the glow's falloff can be read straight off it.
 */
const contribution = async ({
  layer = 'sun', option = 'brightness', discThreshold = 0.5, glowThreshold = 0.01,
} = {}) => {
  const subject = layers[layer]
  const before = subject.getOptions()[option]
  subject.setOptions({ [option]: 0 })
  await frames(2)
  const dark = luminanceOf(await capture())
  subject.setOptions({ [option]: before || 1 })
  await frames(2)
  const frame = await capture()
  const lit = luminanceOf(frame)

  const { width, height } = frame
  const diff = new Float32Array(width * height)
  let peak = 0
  let peakIndex = 0
  let added = 0
  for (let i = 0; i < diff.length; i++) {
    const d = Math.max(0, lit[i] - dark[i])
    diff[i] = d
    added += d
    if (d > peak) { peak = d; peakIndex = i }
  }
  const cx = peakIndex % width
  const cy = Math.floor(peakIndex / width)

  let discPixels = 0
  let glowPixels = 0
  let minX = width, maxX = -1, minY = height, maxY = -1
  let sumX = 0
  let sumY = 0
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const d = diff[y * width + x]
      // `peak === 0` means the layer added nothing. Without this guard every pixel clears a
      // threshold of zero and the report claims a disc the size of the canvas.
      if (peak > 0 && d >= discThreshold * peak) {
        discPixels++
        sumX += x
        sumY += y
        if (x < minX) minX = x
        if (x > maxX) maxX = x
        if (y < minY) minY = y
        if (y > maxY) maxY = y
      } else if (d >= glowThreshold) glowPixels++
    }
  }
  // The CENTROID of the disc, not its brightest pixel. A limb-darkened disc clips flat across its
  // middle, so "the brightest pixel" is an arbitrary one somewhere in that plateau, and a scan
  // started there is off-centre by an unknown few pixels — which is exactly enough to make the
  // darkening curve look like it is not there.
  const centre = discPixels ? [Math.round(sumX / discPixels), Math.round(sumY / discPixels)] : [cx, cy]

  return {
    canvas: { width, height },
    peakGain: round(peak),
    peakAt: [cx, cy],
    /** Total luminance the layer added to the frame — the honest "brightness on vs off". */
    addedLuminance: round(added, 2),
    meanGain: round(added / diff.length, 6),
    disc: {
      pixels: discPixels,
      // Area, not a chord: at nine pixels across, one miscounted pixel on a diameter is a 20% error.
      diameterPx: round(2 * Math.sqrt(discPixels / Math.PI)),
      boundsPx: maxX < 0 ? null : [maxX - minX + 1, maxY - minY + 1],
    },
    glowPixels,
    profile: radialProfile(diff, width, height, centre[0], centre[1]),
    /** A horizontal cut through the disc's centre — where limb darkening is legible. */
    scan: lineScan(diff, width, centre, Math.max(12, Math.ceil(discPixels ? 0.8 * (maxX - minX) : 12))),
  }
}

/** The differential along a horizontal line through `centre`, out to `reach` pixels either side. */
const lineScan = (diff, width, [cx, cy], reach) => {
  const row = []
  for (let dx = -reach; dx <= reach; dx++) {
    row.push({ dx, gain: round(diff[cy * width + cx + dx] ?? 0, 4) })
  }
  return row
}

/**
 * The glow's falloff, averaged over rings around the sun. This is the shape a bloom pass would
 * have produced, and the only way to tell a real aureole from a soft blob is to read it.
 */
const radialProfile = (diff, width, height, cx, cy, maxRadius = 220, step = 4) => {
  const bins = Math.ceil(maxRadius / step)
  const totals = new Float64Array(bins)
  const counts = new Float64Array(bins)
  for (let y = Math.max(0, cy - maxRadius); y < Math.min(height, cy + maxRadius); y++) {
    for (let x = Math.max(0, cx - maxRadius); x < Math.min(width, cx + maxRadius); x++) {
      const r = Math.hypot(x - cx, y - cy)
      if (r >= maxRadius) continue
      const bin = Math.floor(r / step)
      totals[bin] += diff[y * width + x]
      counts[bin]++
    }
  }
  return Array.from({ length: bins }, (_, i) => ({
    radiusPx: i * step + step / 2,
    gain: round(counts[i] ? totals[i] / counts[i] : 0, 5),
  })).filter((row) => row.gain > 0.0002)
}

/**
 * The camera pose that puts the sun `beyondLimb` degrees clear of the earth's silhouette.
 *
 * The sun is only ever on screen when the camera is close to antisolar, because the view axis
 * points at the planet and the sun has to be within half a field of view of it. Negative values put
 * it behind the planet.
 */
const view = async ({ beyondLimb = 0.4, radii = 8, date = DATE } = {}) => {
  const { lng: subsolarLng, lat: declination } = subsolarPoint(date)
  const limbDeg = Math.asin(1 / radii) * 180 / Math.PI
  const separation = limbDeg + beyondLimb
  const cosOffset = Math.cos((180 - separation) * Math.PI / 180) / Math.cos(declination * Math.PI / 180)
  const lng = subsolarLng + Math.acos(Math.min(1, Math.max(-1, cosOffset))) * 180 / Math.PI

  // MapLibre's globe zoom is in mercator units; solve for the one that puts the camera at `radii`.
  const altitude = (radii - 1) * 6371008.8
  map.jumpTo({ center: [((lng + 540) % 360) - 180, 0], zoom: zoomForAltitude(altitude), pitch: 0, bearing: 0 })
  await settled()
  return { cameraLng: lng, limbDeg, separationDeg: separation }
}

/**
 * The zoom that puts the camera at a given altitude — the exact inverse of what
 * `cameraAltitudeMetres` computes, so the two cannot drift apart.
 *
 * MIND THE PIXELS. `cameraToCenterDistance` and MapLibre's world size are both in CSS pixels;
 * `canvas.width/height` and everything `gl.readPixels` returns are DEVICE pixels, twice as many on
 * a retina display. Deriving the camera distance from the canvas rather than reading it off the
 * transform put the camera at 4.5 earth radii when it had been asked for 8 — which quietly moved
 * the earth's limb from 7.2° out to 12.8°, swallowed the sun whole, and looked precisely like a
 * layer that draws nothing.
 */
const zoomForAltitude = (altitudeMetres) => {
  const distancePx = map.transform.cameraToCenterDistance     // CSS pixels, and zoom-independent
  const metresPerMercatorUnit = 1 / maplibregl.MercatorCoordinate.fromLngLat(map.getCenter(), 0).meterInMercatorCoordinateUnits()
  return Math.log2(distancePx * metresPerMercatorUnit / (512 * altitudeMetres))
}

/** N animation frames. Enough for anything that only changed a uniform. */
const frames = (count = 2) => new Promise((resolve) => {
  const step = () => (--count <= 0 ? resolve() : requestAnimationFrame(step))
  requestAnimationFrame(step)
})

/**
 * Wait for tiles after a camera move.
 *
 * `once('idle')` cannot be waited on alone: one failed tile request leaves `map.loaded()` false
 * for good, and an already-idle map never fires `idle` again — so the harness hangs with a
 * perfectly healthy page. It is a race against a deadline, never a dependency.
 *
 * It matters only across a camera move. A differential reading takes two frames of the SAME view,
 * and a tile arriving between them would land in the difference as if the sun had drawn it.
 */
const settled = (timeoutMs = 1200) => new Promise((resolve) => {
  let done = false
  const finish = () => {
    if (done) return
    done = true
    clearTimeout(timer)
    frames(2).then(resolve)
  }
  const timer = setTimeout(finish, timeoutMs)
  map.once('idle', finish)
})

// Every failure this harness exists to catch is silent, so nothing is allowed to stay silent here.
window.sunHarnessErrors = []
map.on('error', (e) => window.sunHarnessErrors.push(String(e && e.error ? e.error.message : e)))
window.sunHarnessMap = map

map.on('load', () => {
  map.addLayer(frameStart)   // first, so the clock brackets everything
  map.addLayer(layers.starfield)
  map.addLayer(layers.daylight)
  map.addLayer(layers.atmosphere)
  map.addLayer(layers.sun)
  map.addLayer(layers.bloom)
  map.addLayer(probe)      // after the bloom, so a reading is of the finished frame
  map.addLayer(frameEnd)   // last of all, so the clock closes on a drained GPU
  window.sunHarness = {
    map,
    layers,
    capture,
    contribution,
    benchmark,
    view,
    settled,
    frames,
    /**
     * Run a whole measurement set in the background and leave it on `window.sunHarnessReport`.
     * A long `await` from outside the page trips the evaluator's timeout and abandons the run
     * half-finished; polling a field does not.
     */
    run: (job) => {
      window.sunHarnessReport = { status: 'running' }
      Promise.resolve()
        .then(job)
        .then((result) => { window.sunHarnessReport = { status: 'done', result } })
        .catch((error) => { window.sunHarnessReport = { status: 'failed', error: String(error && error.stack || error) } })
      return 'started'
    },
    setSun: async (options) => { layers.sun.setOptions(options); await frames(2); return contribution() },
    /** Save the current frame as a PNG under tools/sun-harness/shots. */
    shot: async (file) => {
      const { pixels, width, height } = await capture()

      /**
       * Flatten onto the page background, by hand, and mind two things at once.
       *
       * ROW ORDER: readPixels hands back rows bottom-up, ImageData wants them top-down.
       *
       * PREMULTIPLIED ALPHA: the framebuffer holds `colour × alpha`, not `colour`. Hand that to
       * putImageData — which expects straight alpha — and then let drawImage composite it, and the
       * alpha is applied twice. The glow, whose whole existence is low alpha, comes out roughly
       * squared: perfectly plausible, several times too faint, and nothing about the image says so.
       * A premultiplied source composites as `src + background × (1 − alpha)`, so do exactly that.
       */
      const [bgR, bgG, bgB] = (getComputedStyle(document.body).backgroundColor.match(/\d+/g) || [5, 7, 15]).map(Number)
      const flat = new Uint8ClampedArray(width * height * 4)
      for (let y = 0; y < height; y++) {
        const source = (height - 1 - y) * width * 4
        const target = y * width * 4
        for (let x = 0; x < width * 4; x += 4) {
          const alpha = pixels[source + x + 3] / 255
          flat[target + x] = pixels[source + x] + bgR * (1 - alpha)
          flat[target + x + 1] = pixels[source + x + 1] + bgG * (1 - alpha)
          flat[target + x + 2] = pixels[source + x + 2] + bgB * (1 - alpha)
          flat[target + x + 3] = 255
        }
      }
      const scratch = Object.assign(document.createElement('canvas'), { width, height })
      scratch.getContext('2d').putImageData(new ImageData(flat, width, height), 0, 0)

      const response = await fetch('/shot', {
        method: 'POST',
        body: JSON.stringify({ file, dataUrl: scratch.toDataURL('image/png') }),
      })
      return response.json()
    },
    /** Where the camera actually ended up, in the units the layer reasons in. */
    geometry: () => {
      const camera = cameraInPlanetSpace(map, maplibregl)
      const radii = Math.hypot(...camera)
      return {
        cameraRadii: round(radii, 3),
        cameraAltitudeKm: round((radii - 1) * 6371.0088, 1),
        earthAngularRadiusDeg: round(Math.asin(Math.min(1, 1 / radii)) * 180 / Math.PI, 3),
        sunAngularRadiusDeg: round(sunAngularRadius(DATE) * 180 / Math.PI, 4),
      }
    },
    /** What the disc SHOULD measure, from the geometry alone — the number to check against. */
    expected: (date = DATE) => {
      const canvas = map.getCanvas()
      const fov = map.transform.fov ? map.transform.fov * Math.PI / 180 : 0.6435
      const radius = sunAngularRadius(date)
      return {
        angularDiameterDeg: round(2 * radius * 180 / Math.PI, 4),
        diameterPx: round(canvas.height * Math.tan(radius) / Math.tan(fov / 2)),
        fovDeg: round(fov * 180 / Math.PI, 3),
        canvas: { width: canvas.width, height: canvas.height },
      }
    },
  }
  window.sunHarnessReady = true
})

// A layer that throws in onAdd takes the whole map down with no message anywhere; catch it where it
// can still be read back.
window.addEventListener('error', (e) => window.sunHarnessErrors.push(`window: ${e.message}`))
window.addEventListener('unhandledrejection', (e) => window.sunHarnessErrors.push(`promise: ${e.reason}`))

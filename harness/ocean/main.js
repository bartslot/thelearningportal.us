/**
 * Ocean layer harness — a globe, the layer, and a way to read what actually reached the framebuffer.
 *
 * SCREENSHOTS ARE NOT EVIDENCE for this layer. Half of what it does is a few luma of colour on
 * water that is nearly black to begin with, and a JPEG of a dark blue sea will happily look
 * identical whether the layer ran or not. So everything here is built around measurement.
 *
 * THE ONE TRAP WORTH THE WHOLE FILE. `canvas.toDataURL` and `drawImage` return pure black unless
 * the context was created with `preserveDrawingBuffer`, and MapLibre does not set it. That black
 * is not an error — it is a valid, plausible-looking image, and it will confirm any hypothesis you
 * bring to it. The only honest read is `gl.readPixels` from INSIDE a render pass, while the
 * drawing buffer still exists, which is what the probe layer below is for. It is added last so it
 * runs after the ocean has drawn.
 *
 * THE SECOND TRAP, which is really two. An automated browser pane spends most of its life hidden,
 * and a hidden document gets no `requestAnimationFrame` at all — measured here, not assumed: zero
 * callbacks in two seconds. MapLibre's entire render loop hangs off rAF, so `triggerRepaint`
 * schedules a frame that never arrives and `idle` never fires. Every render below is therefore
 * forced with `map.redraw()`, which draws synchronously inside the call.
 *
 * And `setTimeout` is not the way out of it: a hidden document clamps timers to roughly one per
 * second — five nominal 100 ms waits measured 4,917 ms — so any poll loop written with them blows
 * whatever timeout the driver has long before the map is ready. Waiting here goes through a
 * `MessageChannel` instead, which posts an ordinary task the throttle does not touch, and still
 * yields to the event loop so image decodes and tile responses can land.
 */

import './frame-shim.js'          // FIRST: MapLibre cannot even parse a style without frames
import maplibregl from 'maplibre-gl'
import { createOceanWaterLayer } from '../../resources/js/timemap/ocean-water.js'
import { createDaylightLayer } from '../../resources/js/timemap/daylight.js'
import { sunDirection } from '../../resources/js/timemap/sun.js'
import { SATELLITE_SOURCE, SATELLITE_DETAIL_SOURCE, satelliteLayers } from '../../resources/js/map-imagery.js'

const PROBE_ID = 'harness-probe'

/**
 * Reads the framebuffer from inside a render pass, which is the only place it exists.
 *
 * It queues a request and answers it on the next frame rather than reading on demand, because
 * "now" outside a frame is precisely when there is nothing to read.
 */
const createProbeLayer = () => {
  let gl = null
  let map = null
  let pending = null

  return {
    id: PROBE_ID,
    type: 'custom',
    renderingMode: '3d',
    onAdd(m, glContext) { map = m; gl = glContext },
    onRemove() { gl = null; map = null },
    render() {
      if (!pending || !gl) return
      const { x, y, width, height, resolve } = pending
      pending = null
      const pixels = new Uint8Array(width * height * 4)
      // readPixels' origin is bottom-left; callers think in CSS pixels from the top.
      gl.readPixels(x, y, width, height, gl.RGBA, gl.UNSIGNED_BYTE, pixels)
      resolve({ pixels, width, height })
    },
    /** @returns {Promise<{pixels: Uint8Array, width: number, height: number}>} */
    read(x, y, width, height) {
      return new Promise((resolve, reject) => {
        pending = { x, y, width, height, resolve }
        map.redraw()          // synchronous: the frame happens inside this call, hidden or not
        if (pending) { pending = null; reject(new Error('probe layer did not render')) }
      })
    },
  }
}

const style = {
  version: 8,
  sources: { satellite: SATELLITE_SOURCE, 'satellite-detail': SATELLITE_DETAIL_SOURCE },
  layers: [
    { id: 'space', type: 'background', paint: { 'background-color': '#000008' } },
    ...satelliteLayers(),
  ],
}

const map = new maplibregl.Map({
  container: 'map',
  style,
  center: [-40, 20],
  zoom: 2,
  projection: { type: 'globe' },
  attributionControl: false,
  // Deliberately NOT set. Leaving it off is what forces every measurement through readPixels
  // inside a render pass, which is the only reading that cannot be wrong.
  preserveDrawingBuffer: false,
})

// A fixed date, so the sun does not move between a measurement and the one it is compared against.
const SUN_DATE = new Date(Date.UTC(2026, 5, 21, 12, 0, 0))
const sun = sunDirection(SUN_DATE)

const ocean = createOceanWaterLayer({ sun })
const daylight = createDaylightLayer({ sun })
const probe = createProbeLayer()

map.on('style.load', () => {
  map.addLayer(ocean)
  map.addLayer(daylight)
  map.addLayer(probe)          // last, so it reads a finished frame
})

/** One turn of the event loop, at full speed even when the document is hidden. */
const yieldToBrowser = () => new Promise((resolve) => {
  const channel = new MessageChannel()
  channel.port1.onmessage = () => resolve()
  channel.port2.postMessage(0)
})

/**
 * Wait until every tile for the current view is loaded AND uploaded.
 *
 * Polled and pumped with `redraw()`, never on `idle`: tiles arrive over the network on their own,
 * but they are only uploaded to the GPU during a render, and a hidden pane renders only when told
 * to. Waiting on `idle` here deadlocks — the event needs a frame and the frame needs the event.
 */
const settled = async (timeoutMs = 20000) => {
  const deadline = Date.now() + timeoutMs
  for (;;) {
    map.redraw()
    if (map.loaded() && map.areTilesLoaded()) break
    if (Date.now() > deadline) return { timedOut: true }
    await yieldToBrowser()
  }
  // Raster fade-in is driven by elapsed time, so the first settled frame can still be mid-crossfade
  // and would read darker than the picture really is. Spin out the fade, then draw once more.
  const fadeDone = Date.now() + 400
  while (Date.now() < fadeDone) { map.redraw(); await yieldToBrowser() }
  map.redraw()
  return { timedOut: false }
}

/** Everything the driving script needs, and nothing that hides a decision from it. */
window.oceanHarness = {
  map,
  ocean,
  sun,

  async view({ center, zoom, pitch = 0, bearing = 0 }) {
    map.jumpTo({ center, zoom, pitch, bearing })
    const { timedOut } = await settled()
    return {
      timedOut,
      zoom: map.getZoom(),
      center: map.getCenter(),
      altitude: map.transform.getCameraAltitude?.(),
      metresPerPixel: 156543.03392 * Math.cos(map.getCenter().lat * Math.PI / 180) / 2 ** map.getZoom(),
    }
  },

  /** Change the layer's options. No tiles move, so one forced frame is the whole wait. */
  set(options) {
    ocean.setOptions(options)
    map.redraw()
  },

  /** A rectangle of the canvas, in CSS pixels from the top-left, as it was actually drawn. */
  async pixels({ x = 0, y = 0, width = null, height = null } = {}) {
    const canvas = map.getCanvas()
    const w = width ?? canvas.width
    const h = height ?? canvas.height
    const flippedY = canvas.height - y - h
    const { pixels } = await probe.read(x, flippedY, w, h)
    return { pixels: Array.from(pixels), width: w, height: h }
  },

  /**
   * Write the frame to disk as a PNG, through the harness server.
   *
   * Built from readPixels rather than from the canvas, for the reason at the top of this file: the
   * canvas is empty outside a render pass, and a screenshot of it is convincingly, uselessly black.
   * The rows are flipped back on the way in — GL counts from the bottom, PNG from the top.
   */
  async save(name, rect = {}) {
    const { pixels, width, height } = await this.pixels(rect)
    const surface = document.createElement('canvas')
    surface.width = width
    surface.height = height
    const image = surface.getContext('2d').createImageData(width, height)
    for (let row = 0; row < height; row++) {
      const from = (height - 1 - row) * width * 4
      image.data.set(pixels.slice(from, from + width * 4), row * width * 4)
    }
    surface.getContext('2d').putImageData(image, 0, 0)
    const blob = await new Promise((r) => surface.toBlob(r, 'image/png'))
    const written = await fetch(`/shot/${name}`, { method: 'POST', body: blob }).then((r) => r.text())
    return { written, width, height, bytes: blob.size }
  },

  /** Mean linear-ish luma and per-channel means over a rectangle. Enough to compare two frames. */
  async stats(rect = {}) {
    const { pixels, width, height } = await this.pixels(rect)
    let r = 0, g = 0, b = 0
    const n = width * height
    for (let i = 0; i < pixels.length; i += 4) { r += pixels[i]; g += pixels[i + 1]; b += pixels[i + 2] }
    return {
      width, height, samples: n,
      r: r / n, g: g / n, b: b / n,
      luma: (0.2126 * r + 0.7152 * g + 0.0722 * b) / n,
    }
  },

  /** Resolves once the distance field is on the GPU and the first view has settled. */
  async ready(timeoutMs = 20000) {
    map.resize()
    const deadline = Date.now() + timeoutMs
    // redraw() through the wait, not just after it: tiles are only REQUESTED from inside a render
    // pass, so a page that never draws never asks for imagery and waits forever for it to arrive.
    while (!ocean.hasField && Date.now() < deadline) { map.redraw(); await yieldToBrowser() }
    const { timedOut } = await settled(Math.max(2000, deadline - Date.now()))
    return { hasField: ocean.hasField, timedOut }
  },
}

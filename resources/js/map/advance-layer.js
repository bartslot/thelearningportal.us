import { buildArrivalField, frontRadius, paintFrame } from './advance-field.js'

/**
 * Draws the advancing-front effect over one territory as a canvas source.
 *
 * A canvas rather than a custom WebGL layer, for one reason that matters: the arrival field is
 * computed ONCE and a frame is then a single compare per pixel, so there is nothing a shader would
 * buy here. It also means the effect is ordinary map furniture — it sits in the layer stack, warps
 * with the globe, and disappears with a removeLayer, instead of being another 3D layer with its own
 * depth and draw-order rules to get wrong.
 *
 * The field is sized in pixels, not metres, so a big country and a small one both get the same
 * amount of lobing. That is the right call for a map effect: it should look the same whatever you
 * are looking at.
 */

const SOURCE_ID = 'tm-advance-src'
const LAYER_ID = 'tm-advance'
const FIELD_PX = 256 // 65k pixels a frame — the field is soft, so more resolution buys nothing

const hexToRgb = (hex) => {
  const h = String(hex).replace('#', '')
  const full = h.length === 3 ? h.split('').map((c) => c + c).join('') : h
  return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16) || 0)
}

const bboxOf = (geometry) => {
  let west = 180
  let south = 90
  let east = -180
  let north = -90
  const visit = (coords) => {
    if (typeof coords[0] === 'number') {
      const [lng, lat] = coords
      if (lng < west) west = lng
      if (lng > east) east = lng
      if (lat < south) south = lat
      if (lat > north) north = lat
      return
    }
    coords.forEach(visit)
  }
  visit(geometry.coordinates)
  return [west, south, east, north]
}

/** Rings as [outer, ...holes] lists, whichever polygon type arrived. */
const polygonsOf = (geometry) =>
  geometry.type === 'Polygon' ? [geometry.coordinates]
    : geometry.type === 'MultiPolygon' ? geometry.coordinates
      : []

export const createAdvance = (map, {
  beforeId,
  colour = '#b31217',
  rimColour = '#4a0505',
  opacity = 0.85,
  seconds = 4,
  curve = 0.5,
  lobeScale = 6,
  lobeAmount = 45,
  mergeKm = 120,
  rimKm = 40,
  featherKm = 10,
} = {}) => {
  let options = { colour, rimColour, opacity, seconds, curve, lobeScale, lobeAmount, mergeKm, rimKm, featherKm }
  let canvas = null
  let ctx = null
  let image = null
  let built = null // { field, maxKm, bbox }
  let raf = null
  let startedAt = null
  let holdAtEnd = true

  const ensureCanvas = () => {
    if (canvas) return
    canvas = document.createElement('canvas')
    canvas.width = FIELD_PX
    canvas.height = FIELD_PX
    ctx = canvas.getContext('2d', { willReadFrequently: true })
    image = ctx.createImageData(FIELD_PX, FIELD_PX)
  }

  /** Rasterise the target country into a mask, so the front can never leave it. */
  const maskFor = (geometry, bbox) => {
    const [west, south, east, north] = bbox
    const mask = new Uint8Array(FIELD_PX * FIELD_PX)
    const scratch = document.createElement('canvas')
    scratch.width = FIELD_PX
    scratch.height = FIELD_PX
    const sctx = scratch.getContext('2d', { willReadFrequently: true })
    const x = (lng) => ((lng - west) / (east - west)) * FIELD_PX
    const y = (lat) => ((north - lat) / (north - south)) * FIELD_PX

    sctx.fillStyle = '#fff'
    for (const rings of polygonsOf(geometry)) {
      sctx.beginPath()
      rings.forEach((ring) => {
        ring.forEach(([lng, lat], i) => (i ? sctx.lineTo(x(lng), y(lat)) : sctx.moveTo(x(lng), y(lat))))
        sctx.closePath()
      })
      // evenodd so holes stay holes — an enclave is not conquered by being surrounded.
      sctx.fill('evenodd')
    }
    const px = sctx.getImageData(0, 0, FIELD_PX, FIELD_PX).data
    for (let i = 0; i < mask.length; i++) mask[i] = px[i * 4 + 3] > 40 ? 1 : 0
    return mask
  }

  /**
   * Prepare the advance. Separate from play() because building the field is the expensive part and
   * the whole point of the design is that it happens once, not per frame or per replay.
   *
   * @param {object} geometry  the target country's polygon
   * @param {Array}  seeds     [[lng, lat], …] where the front enters
   */
  const prepare = (geometry, seeds) => {
    if (!geometry || !seeds?.length) return false
    ensureCanvas()
    const bbox = bboxOf(geometry)
    const mask = maskFor(geometry, bbox)
    const { field, maxKm, spanKm } = buildArrivalField({
      width: FIELD_PX,
      height: FIELD_PX,
      bbox,
      inside: (px, py) => mask[py * FIELD_PX + px] === 1,
      seeds,
      lobeScale: options.lobeScale,
      lobeAmount: options.lobeAmount,
      mergeKm: options.mergeKm,
    })
    built = { field, maxKm, spanKm, bbox }

    const [west, south, east, north] = bbox
    const coordinates = [[west, north], [east, north], [east, south], [west, south]]
    if (map.getSource(SOURCE_ID)) {
      map.getSource(SOURCE_ID).setCoordinates(coordinates)
    } else {
      map.addSource(SOURCE_ID, { type: 'canvas', canvas, coordinates, animate: true })
      map.addLayer({
        id: LAYER_ID,
        type: 'raster',
        source: SOURCE_ID,
        paint: { 'raster-opacity': 1, 'raster-fade-duration': 0, 'raster-resampling': 'linear' },
      }, beforeId && map.getLayer(beforeId) ? beforeId : undefined)
    }
    return true
  }

  const drawAt = (radiusKm) => {
    if (!built) return
    paintFrame(image.data, built.field, {
      radiusKm,
      body: hexToRgb(options.colour),
      rim: hexToRgb(options.rimColour),
      rimKm: options.rimKm,
      opacity: options.opacity,
      featherKm: options.featherKm,
    })
    ctx.putImageData(image, 0, 0)
  }

  const stop = () => {
    if (raf) cancelAnimationFrame(raf)
    raf = null
  }

  const play = () => {
    if (!built) return false
    stop()
    startedAt = performance.now()
    const tick = (now) => {
      const elapsed = (now - startedAt) / 1000
      // Paced against spanKm so the front is still moving at the end of the duration; the final
      // frame goes all the way to maxKm so the last stragglers are actually taken.
      drawAt(frontRadius(elapsed, options.seconds, built.spanKm, options.curve))
      if (elapsed < options.seconds) raf = requestAnimationFrame(tick)
      else { raf = null; drawAt(built.maxKm); if (!holdAtEnd) clear() }
    }
    raf = requestAnimationFrame(tick)
    return true
  }

  const clear = () => {
    stop()
    if (ctx) ctx.clearRect(0, 0, FIELD_PX, FIELD_PX)
  }

  return {
    prepare,
    play,
    clear,
    /** Redraw the finished state so a colour or rim change is visible without replaying. */
    setOptions(next = {}) {
      const rebuild = ['lobeScale', 'lobeAmount', 'mergeKm'].some(
        (k) => next[k] !== undefined && next[k] !== options[k],
      )
      options = { ...options, ...next }
      if (rebuild) return 'needs-rebuild'
      if (built && !raf) drawAt(built.maxKm)
      return 'ok'
    },
    get maxKm() { return built ? built.maxKm : 0 },
    /** Painted pixels in the current frame — how much ground has been taken. For tests and tuning. */
    get painted() {
      if (!image) return 0
      let n = 0
      for (let i = 3; i < image.data.length; i += 4) if (image.data[i] > 8) n++
      return n
    },
    get running() { return raf !== null },
    set holdAtEnd(v) { holdAtEnd = !!v },
    remove() {
      stop()
      if (map.getLayer(LAYER_ID)) map.removeLayer(LAYER_ID)
      if (map.getSource(SOURCE_ID)) map.removeSource(SOURCE_ID)
      built = null
    },
  }
}

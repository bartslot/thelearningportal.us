import { describe, test, expect, vi, beforeEach } from 'vitest'
import { oceanMaskFC, signedArea, waveFrames, createOcean, OCEAN_LAYER_IDS } from '../map-ocean.js'

/**
 * The Satellite style's sea is a polygon of the whole world with every landmass punched out, drawn
 * over Blue Marble's painted ocean depths and animated by swapping the frame under a fill-pattern.
 *
 * Two things here are quietly load-bearing and both fail SILENTLY — the map just renders wrong:
 *   - hole winding and hole ORDER (MapLibre reads the first ring as the exterior and classifies the
 *     rest by winding, so a badly wound ring turns every ring after it into dry land turned sea);
 *   - the wave textures being periodic in x, y and time, or the sea shows a grid of seams and a
 *     visible jump every time the loop restarts.
 */

// A square island, clockwise so it arrives the "wrong" way round for a hole.
const island = (x, y, s) => [[x, y], [x, y + s], [x + s, y + s], [x + s, y], [x, y]]
const line = (coords) => ({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } })
const holesOf = (fc) => fc.features[0].geometry.coordinates.slice(1)

describe('oceanMaskFC', () => {
  test('wraps the world around the land, exterior first and counter-clockwise', () => {
    const fc = oceanMaskFC({ type: 'FeatureCollection', features: [line(island(0, 0, 2))] })

    expect(fc.features).toHaveLength(1)
    const [exterior] = fc.features[0].geometry.coordinates
    expect(exterior[0]).toEqual([-180, -90])
    expect(signedArea(exterior)).toBeGreaterThan(0)
  })

  test('winds every hole against the exterior, whichever way it arrived', () => {
    const fc = oceanMaskFC({
      type: 'FeatureCollection',
      features: [line(island(0, 0, 2)), line(island(10, 10, 3).slice().reverse())],
    })

    const holes = holesOf(fc)
    expect(holes).toHaveLength(2)
    for (const hole of holes) expect(signedArea(hole)).toBeLessThan(0)
  })

  test('puts the biggest landmass first, so a collapsed island can only cost smaller ones', () => {
    const fc = oceanMaskFC({
      type: 'FeatureCollection',
      features: [line(island(0, 0, 1)), line(island(20, 20, 9)), line(island(40, 40, 4))],
    })

    const areas = holesOf(fc).map((h) => Math.abs(signedArea(h)))
    expect(areas).toEqual([...areas].sort((a, b) => b - a))
  })

  test('joins a coastline that arrives as two arcs sharing both ends', () => {
    const fc = oceanMaskFC({
      type: 'FeatureCollection',
      features: [
        line([[0, 0], [0, 4], [4, 4]]),
        line([[4, 4], [4, 0], [0, 0]]),
      ],
    })

    const holes = holesOf(fc)
    expect(holes).toHaveLength(1)
    expect(Math.abs(signedArea(holes[0]))).toBeCloseTo(16, 5)
  })

  test('closes an island cut at the antimeridian along the cut itself', () => {
    const fc = oceanMaskFC({
      type: 'FeatureCollection',
      features: [line([[180, -16], [178, -16.5], [180, -17]])],
    })

    const [hole] = holesOf(fc)
    expect(hole[0]).toEqual(hole[hole.length - 1])
    // Nothing may sit exactly on the world edge: a hole touching the exterior is a degenerate
    // case for the tessellator.
    for (const [lng] of hole) expect(Math.abs(lng)).toBeLessThan(180)
  })

  test('closes Antarctica around the pole, not straight across the continent', () => {
    const fc = oceanMaskFC({
      type: 'FeatureCollection',
      features: [line([[-180, -70], [-90, -75], [0, -72], [90, -75], [180, -70]])],
    })

    const [hole] = holesOf(fc)
    expect(Math.min(...hole.map((p) => p[1]))).toBeLessThan(-89)
  })

  test('reads polygons too, in case the coastline ever ships as areas', () => {
    const fc = oceanMaskFC({
      type: 'FeatureCollection',
      features: [{ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [island(0, 0, 5)] } }],
    })

    expect(holesOf(fc)).toHaveLength(1)
  })
})

describe('waveFrames', () => {
  const config = {
    size: 32,
    frames: 6,
    alpha: 0.5,
    sharpness: 0.5,
    warp: 3,
    waves: [{ kx: 2, ky: 1, amp: 1, cycles: 1, phase: 0 }],
    chop: [{ kx: -1, ky: 2, amp: 1, cycles: 1, phase: 1 }],
  }
  const alphaAt = (frame, x, y) => frame.data[(y * config.size + x) * 4 + 3]

  test('renders the asked-for loop, as RGBA the map can take straight to addImage', () => {
    const frames = waveFrames(config)

    expect(frames).toHaveLength(6)
    for (const f of frames) {
      expect(f.width).toBe(32)
      expect(f.height).toBe(32)
      expect(f.data).toHaveLength(32 * 32 * 4)
    }
  })

  test('keeps the crests inside the alpha the caller asked for', () => {
    const [frame] = waveFrames(config)

    for (let i = 3; i < frame.data.length; i += 4) expect(frame.data[i]).toBeLessThanOrEqual(255 * config.alpha)
  })

  test('tiles without a seam — the last column runs into the first', () => {
    const [frame] = waveFrames(config)

    const step = (a, b) => Math.abs(alphaAt(frame, a, 8) - alphaAt(frame, b, 8))
    const inside = Math.max(...Array.from({ length: config.size - 1 }, (_, x) => step(x, x + 1)))

    expect(step(config.size - 1, 0)).toBeLessThanOrEqual(inside)
  })

  test('the loop closes — the last frame runs into the first', () => {
    const frames = waveFrames(config)

    const step = (a, b) => Math.abs(alphaAt(frames[a], 5, 5) - alphaAt(frames[b], 5, 5))
    const inside = Math.max(...frames.slice(1).map((_, i) => step(i, i + 1)))

    expect(step(frames.length - 1, 0)).toBeLessThanOrEqual(inside)
  })

  test('actually moves', () => {
    const [first, second] = waveFrames(config)

    expect(Array.from(second.data)).not.toEqual(Array.from(first.data))
  })
})

// A MapLibre-ish stub, in the same spirit as map-terrain-teardown.test.js: live until remove()
// clears .style.
function makeMap () {
  const source = { setData: vi.fn() }
  const map = {
    style: {},
    _removed: false,
    layout: {},
    paint: {},
    images: {},
    getLayer: (id) => (OCEAN_LAYER_IDS.includes(id) ? { id } : undefined),
    getSource: () => source,
    setLayoutProperty (id, _prop, value) { this.layout[id] = value },
    setPaintProperty (id, prop, value) { this.paint[`${id}.${prop}`] = value },
    hasImage (id) { return !!this.images[id] },
    addImage (id, image) { this.images[id] = image },
    updateImage: vi.fn(),
    triggerRepaint: vi.fn(),
    remove () { this._removed = true; this.style = undefined },
    source,
  }
  return map
}

describe('createOcean', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => ({
      json: async () => ({ type: 'FeatureCollection', features: [line(island(0, 0, 4))] }),
    })))
  })

  test('costs nothing until a style asks for the sea', async () => {
    const map = makeMap()

    createOcean(map, { coastlineUrl: '/coastline.geojson' })

    expect(fetch).not.toHaveBeenCalled()
  })

  test('shows the sea: mask on the source, wave patterns on the layers', async () => {
    const map = makeMap()
    const ocean = createOcean(map, { coastlineUrl: '/coastline.geojson' })

    ocean.show(true)
    await vi.waitFor(() => expect(map.paint['ocean-ripple.fill-pattern']).toBeDefined())

    for (const id of OCEAN_LAYER_IDS) expect(map.layout[id]).toBe('visible')
    expect(map.source.setData).toHaveBeenCalledWith(
      expect.objectContaining({ features: [expect.objectContaining({ geometry: expect.anything() })] }),
    )
    expect(map.paint['ocean-swell.fill-pattern']).toBeDefined()
    expect(map.paint['ocean-ripple.fill-opacity']).toBe(1)
    ocean.destroy()
  })

  test('hides again without tearing anything down', async () => {
    const map = makeMap()
    const ocean = createOcean(map, { coastlineUrl: '/coastline.geojson' })

    ocean.show(true)
    await vi.waitFor(() => expect(map.paint['ocean-ripple.fill-pattern']).toBeDefined())
    ocean.show(false)

    for (const id of OCEAN_LAYER_IDS) expect(map.layout[id]).toBe('none')
    ocean.destroy()
  })

  test('survives the map being removed mid-fetch — the wizard re-mounts its preview constantly', async () => {
    const map = makeMap()
    const ocean = createOcean(map, { coastlineUrl: '/coastline.geojson' })

    ocean.show(true)
    map.remove()
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    await Promise.resolve()

    expect(map.source.setData).not.toHaveBeenCalled()
    ocean.destroy()
  })
})

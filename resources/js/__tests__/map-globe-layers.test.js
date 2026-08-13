import { describe, it, expect, vi, afterEach } from 'vitest'
import { addGlobeLayers, globeAnchorId } from '../map-globe-layers.js'
import { OCEAN_LAYER_ID } from '../timemap/ocean-water.js'
import { STARFIELD_LAYER_ID } from '../timemap/starfield.js'
import { ATMOSPHERE_LAYER_ID } from '../timemap/atmosphere.js'

/**
 * Where the globe stack lands, which is a licence-to-print-bugs sort of question.
 *
 * MapLibre's addLayer appends to the TOP when it is handed no anchor. So a caller that passes no
 * `beforeId` gets the SEA over every label on the map — and the way that shows up is not as a
 * draw-order bug at all. The half of each name that crosses water is erased and the half over land
 * survives, so it reads as a font problem: "Constantinople" cut in two at the Bosphorus, "Carthage"
 * stopping at the coast, "Athens" down to "Ath". It shipped that way on the lesson map, and it was
 * chased on the OTHER map — the Time-Map, which does pass an anchor and whose layer order was right
 * the whole time.
 *
 * These are unit tests because the failure is a pure function of the style: which index each layer
 * lands at. The picture is worth having too, and lives in tests/playwright/globe-under-labels.spec.ts.
 */

/** A style, as a list. Enough map for addGlobeLayers: it only ever reads and splices this. */
const fakeMap = (layers = []) => {
  const order = layers.map((l) => ({ ...l }))
  return {
    order,
    ids: () => order.map((l) => l.id),
    getLayer: (id) => order.find((l) => l.id === id),
    getStyle: () => ({ layers: order }),
    addLayer(layer, before) {
      const at = before ? order.findIndex((l) => l.id === before) : -1
      if (at === -1) order.push(layer)
      else order.splice(at, 0, layer)
    },
    removeLayer(id) {
      const at = order.findIndex((l) => l.id === id)
      if (at >= 0) order.splice(at, 1)
    },
    triggerRepaint() {},
  }
}

/** The shape of the lesson map that shipped the bug: ground, then decoration, then the writing. */
const lessonMapStyle = () => ([
  { id: 'satellite', type: 'raster' },
  { id: 'land', type: 'fill' },
  { id: 'boundaries-line', type: 'line' },
  // Icon-only symbol layers. These are scenery lying ON the ground and belong UNDER the sea; if the
  // anchor rule went looking for "the first symbol layer" it would stop here and put the whole
  // globe below the trees, which is a different wrong answer that still passes a naive test.
  { id: 'land-scatter', type: 'symbol', layout: { 'icon-image': 'tree' } },
  { id: 'mountains', type: 'symbol', layout: { 'icon-image': 'peak' } },
  { id: 'city-dots', type: 'circle' },
  { id: 'city-labels', type: 'symbol', layout: { 'text-field': ['get', 'name'] } },
  { id: 'lesson-labels', type: 'symbol', layout: { 'text-field': ['get', 'name'] } },
])

const indexOf = (map, id) => map.ids().indexOf(id)
const GLOBE_IDS = ['tm-starfield', 'tm-sun', 'tm-moon', 'tm-ocean', 'tm-daylight', 'tm-clouds', 'tm-atmosphere']

afterEach(() => { vi.restoreAllMocks() })

describe('globeAnchorId', () => {
  it('uses the anchor it was given when that layer is on the map', () => {
    const map = fakeMap(lessonMapStyle())
    expect(globeAnchorId(map, 'city-dots')).toBe('city-dots')
  })

  it('falls back to the first layer that draws TEXT, not the first symbol layer', () => {
    const map = fakeMap(lessonMapStyle())
    expect(globeAnchorId(map, undefined)).toBe('city-labels')
  })

  it('says so when the anchor it was asked for is not there, and still keeps the text on top', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const map = fakeMap(lessonMapStyle())

    expect(globeAnchorId(map, 'a-layer-that-never-existed')).toBe('city-labels')
    expect(warn).toHaveBeenCalledTimes(1)
    expect(warn.mock.calls[0][0]).toContain('a-layer-that-never-existed')
  })

  it('is quiet when no anchor was asked for — "under the writing" is the right default, not an error', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    globeAnchorId(fakeMap(lessonMapStyle()), undefined)
    expect(warn).not.toHaveBeenCalled()
  })

  it('returns undefined when nothing on the map draws text', () => {
    const map = fakeMap([{ id: 'satellite', type: 'raster' }, { id: 'land', type: 'fill' }])
    expect(globeAnchorId(map, undefined)).toBeUndefined()
  })

  it('survives a style that cannot be read yet rather than taking the map down', () => {
    const map = fakeMap(lessonMapStyle())
    map.getStyle = () => { throw new Error('Style is not done loading') }
    expect(globeAnchorId(map, undefined)).toBeUndefined()
  })
})

describe('addGlobeLayers: the sea goes under the writing', () => {
  it('puts every globe layer below every text layer when given no anchor at all', () => {
    const map = fakeMap(lessonMapStyle())
    addGlobeLayers(map, { date: new Date(Date.UTC(1600, 5, 21, 12)), reduceMotion: true })

    const lastGlobe = Math.max(...GLOBE_IDS.map((id) => indexOf(map, id)))
    const firstText = Math.min(indexOf(map, 'city-labels'), indexOf(map, 'lesson-labels'))

    // Absolute, not a relation between two things that could both be wrong: every globe id is
    // present, and the highest of them sits below the lowest label.
    expect(GLOBE_IDS.every((id) => indexOf(map, id) >= 0)).toBe(true)
    expect(lastGlobe).toBeLessThan(firstText)
    expect(indexOf(map, OCEAN_LAYER_ID)).toBeLessThan(indexOf(map, 'city-labels'))
  })

  it('honours the anchor the caller names, so the lesson map keeps its dots above the sea too', () => {
    const map = fakeMap(lessonMapStyle())
    addGlobeLayers(map, { date: new Date(), reduceMotion: true, beforeId: 'city-dots' })

    expect(indexOf(map, ATMOSPHERE_LAYER_ID)).toBeLessThan(indexOf(map, 'city-dots'))
    // The ground stays ground: the sea is still drawn over the borders beneath it.
    expect(indexOf(map, OCEAN_LAYER_ID)).toBeGreaterThan(indexOf(map, 'boundaries-line'))
  })

  it('keeps the stack in its own back-to-front order under the anchor', () => {
    const map = fakeMap(lessonMapStyle())
    addGlobeLayers(map, { date: new Date(), reduceMotion: true, beforeId: 'city-dots' })

    // Sky bodies first so the planet occludes them, then the sea, then the light, then the weather,
    // then the haze — the order map-globe-layers.js sets out and the reason it sets it out.
    const placed = GLOBE_IDS.map((id) => indexOf(map, id))
    expect(placed).toEqual([...placed].sort((a, b) => a - b))
    expect(indexOf(map, STARFIELD_LAYER_ID)).toBeLessThan(indexOf(map, OCEAN_LAYER_ID))
    expect(indexOf(map, OCEAN_LAYER_ID)).toBeLessThan(indexOf(map, ATMOSPHERE_LAYER_ID))
  })

  it('re-adding does not leave the stack stranded on top of the labels', () => {
    const map = fakeMap(lessonMapStyle())
    addGlobeLayers(map, { date: new Date(), reduceMotion: true, beforeId: 'city-dots' })
    addGlobeLayers(map, { date: new Date(), reduceMotion: true, beforeId: 'city-dots' })

    expect(map.ids().filter((id) => id === OCEAN_LAYER_ID)).toHaveLength(1)
    expect(Math.max(...GLOBE_IDS.map((id) => indexOf(map, id))))
      .toBeLessThan(indexOf(map, 'city-labels'))
  })
})

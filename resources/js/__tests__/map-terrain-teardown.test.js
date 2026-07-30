import { describe, test, expect, vi, beforeEach, afterEach } from 'vitest'
import { mapGone } from '../map-alive.js'
import { addScatterLayer } from '../map-scatter.js'
import { addVolcanoLayer } from '../map-volcanoes.js'
import { addMountainLayer } from '../map-mountains.js'
import { addForestLayer } from '../map-forests.js'

/**
 * Regression for the prod console error
 *   "Uncaught (in promise) TypeError: Cannot read properties of undefined (reading 'getSource')".
 *
 * The terrain decorators await several fetches before touching the map. When the map is removed
 * during those awaits (scene switch, wizard preview re-mount), MapLibre's Map.getSource reads
 * this.style.getSource with style already cleared — the decorators must notice and bail instead.
 */

// A MapLibre-ish stub: behaves like a live map until remove() clears .style, after which
// getSource/addSource throw exactly like the real Map does.
function makeMap () {
  const map = {
    style: {},
    _removed: false,
    getLayer: () => undefined,
    hasImage: () => false,
    addImage: () => {},
    updateImage: () => {},
    getSource (id) {
      return this.style.getSource(id)
    },
    addSource: vi.fn(),
    addLayer: vi.fn(),
    remove () {
      this._removed = true
      this.style = undefined
    },
  }
  return map
}

// jsdom's Image never fires onload, which would hang loadIcon's rasterize step forever.
class InstantImage {
  set src (_v) { setTimeout(() => this.onload && this.onload(), 0) }
}

// fetch stub: manifests and geojson both resolve; tearDownAfterFirstFetch removes the map as soon
// as the first fetch settles, so every later map access happens against a dead map.
function stubFetch (map, { tearDownAfterFirstFetch = true } = {}) {
  vi.stubGlobal('Image', InstantImage)
  let first = true
  vi.stubGlobal('fetch', vi.fn(async (url) => {
    if (tearDownAfterFirstFetch && first) {
      first = false
      map.remove()
    }
    if (String(url).endsWith('manifest.json')) {
      return { ok: true, json: async () => ({ ids: ['icon-1'], small: ['icon-1'] }) }
    }
    if (String(url).endsWith('.svg')) {
      return { ok: true, text: async () => '<svg viewBox="0 0 16 16"></svg>' }
    }
    return { ok: true, json: async () => ({ type: 'FeatureCollection', features: [] }) }
  }))
}

describe('mapGone', () => {
  test('is false for a live map and true after remove()', () => {
    const map = makeMap()
    expect(mapGone(map)).toBe(false)
    map.remove()
    expect(mapGone(map)).toBe(true)
  })

  test('is true for a missing map', () => {
    expect(mapGone(null)).toBe(true)
    expect(mapGone(undefined)).toBe(true)
  })
})

describe.each([
  ['addScatterLayer', addScatterLayer],
  ['addVolcanoLayer', addVolcanoLayer],
  ['addMountainLayer', addMountainLayer],
  ['addForestLayer', addForestLayer],
])('%s', (_name, addLayerFn) => {
  beforeEach(() => vi.restoreAllMocks())
  afterEach(() => vi.unstubAllGlobals())

  test('does not throw when the map is torn down mid-fetch', async () => {
    const map = makeMap()
    stubFetch(map)
    await expect(addLayerFn(map, {})).resolves.toBeUndefined()
    expect(map.addSource).not.toHaveBeenCalled()
    expect(map.addLayer).not.toHaveBeenCalled()
  })

  test('still adds its source and layer on a live map', async () => {
    const map = makeMap()
    map.style = { getSource: () => undefined }
    stubFetch(map, { tearDownAfterFirstFetch: false })
    await addLayerFn(map, {})
    expect(map.addSource).toHaveBeenCalledTimes(1)
    expect(map.addLayer).toHaveBeenCalledTimes(1)
  })
})

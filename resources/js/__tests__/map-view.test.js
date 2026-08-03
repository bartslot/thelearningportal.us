import { describe, it, expect } from 'vitest'
import { openingView, contentBox, boxView, FALLBACK_VIEW } from '../map-view.js'

// A US Civil War block: focus cities on the eastern seaboard, no European coordinate anywhere.
const US_FOCUS = [
  { type: 'focus', lng: -89.65, lat: 39.8, label: 'Springfield, Illinois' },
  { type: 'focus', lng: -77.04, lat: 38.9, label: 'Washington' },
  { type: 'focus', lng: -79.93, lat: 32.78, label: 'Charleston' },
]

describe('openingView', () => {
  /**
   * The regression this guards: the map opened on Switzerland regardless of content, and stayed
   * there whenever the polity fit couldn't run — a US lesson looking at Europe.
   */
  it('opens on the block\'s own cities, not Europe', () => {
    const { center, zoom } = openingView({ annotations: US_FOCUS })

    expect(center[0]).toBeLessThan(-70)   // western hemisphere
    expect(center[1]).toBeGreaterThan(30) // northern mid-latitudes
    expect(zoom).toBeGreaterThan(FALLBACK_VIEW.zoom)
  })

  it('frames pinned place labels the same way as focus cities', () => {
    const labels = US_FOCUS.map((a) => ({ text: a.label, lng: a.lng, lat: a.lat }))

    expect(openingView({ labels })).toEqual(openingView({ annotations: US_FOCUS }))
  })

  it('falls back to the default view when the block names no place', () => {
    expect(openingView({})).toEqual(FALLBACK_VIEW)
    expect(openingView({ annotations: [], labels: [] })).toEqual(FALLBACK_VIEW)
  })

  // A single city has no span at all, so the raw maths would slam the camera to its zoom cap.
  it('keeps a single focus city at a regional zoom, not pressed against its dot', () => {
    const { center, zoom } = openingView({ annotations: [US_FOCUS[1]] })

    expect(center).toEqual([-77.04, 38.9])
    expect(zoom).toBe(5)
  })
})

describe('contentBox', () => {
  it('ignores annotations that are not focus pins and labels with no text', () => {
    const box = contentBox({
      annotations: [...US_FOCUS, { type: 'arrow', lng: 4.9, lat: 52.4 }],
      labels: [{ lng: 2.35, lat: 48.86 }],
    })

    expect(box).toEqual({ minX: -89.65, minY: 32.78, maxX: -77.04, maxY: 39.8 })
  })

  it('skips coordinates that are missing or unparseable', () => {
    expect(contentBox({ annotations: [{ type: 'focus', lng: 'x', lat: 12 }] })).toBeNull()
  })
})

describe('boxView', () => {
  it('centres on the box and zooms out as the box grows', () => {
    const small = boxView({ minX: -10, minY: 40, maxX: 10, maxY: 50 })
    const large = boxView({ minX: -120, minY: -40, maxX: 120, maxY: 60 })

    expect(small.center).toEqual([0, 45])
    expect(small.zoom).toBeGreaterThan(large.zoom)
  })
})

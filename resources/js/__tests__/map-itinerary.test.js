import { describe, it, expect } from 'vitest'
import { itinerarySchedule, stopsBox, ITINERARY_TIMING } from '../map-itinerary.js'

/**
 * The itinerary tour is paced by the narration. Everything that can go wrong with it is arithmetic:
 * a camera that arrives at Chang'an while the narrator is still on Venice teaches the class the
 * wrong city, and one that never reaches the last stop before the scene ends simply loses it.
 */
describe('itinerarySchedule', () => {
  it('gives every stop a turn, in order, starting after the opening overview', () => {
    const s = itinerarySchedule(4, 40000)

    expect(s).toHaveLength(4)
    expect(s.map((x) => x.index)).toEqual([0, 1, 2, 3])
    expect(s[0].departAt).toBe(ITINERARY_TIMING.overviewMs)
    // Strictly increasing: nobody visits two cities at once.
    for (let i = 1; i < s.length; i++) {
      expect(s[i].departAt).toBeGreaterThan(s[i - 1].departAt)
      expect(s[i].arriveAt).toBeGreaterThan(s[i].departAt)
    }
  })

  it('lands the last stop inside the narration, not after it', () => {
    // The Marco Polo scene: four cities, and a script that runs about 55 seconds.
    const s = itinerarySchedule(4, 55000)

    expect(s[s.length - 1].arriveAt).toBeLessThan(55000)
  })

  it('shrinks the beats rather than dropping stops when the script is short', () => {
    // Six cities in twenty seconds cannot have 2.2s of flight and 1.8s of hold each.
    const s = itinerarySchedule(6, 20000)

    expect(s).toHaveLength(6)
    expect(s[s.length - 1].arriveAt).toBeLessThanOrEqual(20000)
    const flight = s[0].arriveAt - s[0].departAt
    expect(flight).toBeLessThan(ITINERARY_TIMING.flyMs)
    expect(flight).toBeGreaterThanOrEqual(ITINERARY_TIMING.minFlyMs)
  })

  it('never stretches a flight to fill a long silence — it holds instead', () => {
    // Two cities in three minutes. A camera drifting for ninety seconds between them reads as
    // broken; the slack has to become time spent LOOKING at each city.
    const s = itinerarySchedule(2, 180000)

    const flight = s[0].arriveAt - s[0].departAt
    expect(flight).toBeLessThanOrEqual(ITINERARY_TIMING.flyMs)

    const gapBetweenArrivals = s[1].departAt - s[0].arriveAt
    expect(gapBetweenArrivals).toBeGreaterThan(ITINERARY_TIMING.holdMs)
  })

  it('falls back to its natural length when there is no narration to pace against', () => {
    const s = itinerarySchedule(3, null)

    expect(s).toHaveLength(3)
    expect(s[0].arriveAt - s[0].departAt).toBe(ITINERARY_TIMING.flyMs)
  })

  it('has nothing to schedule for a scene with no cities', () => {
    expect(itinerarySchedule(0, 30000)).toEqual([])
  })
})

describe('stopsBox', () => {
  it('spans every stop', () => {
    // Venice → Chang'an, the Silk Road scene.
    const box = stopsBox([
      { lng: 12.34, lat: 45.44 },
      { lng: 28.98, lat: 41.01 },
      { lng: 66.98, lat: 39.65 },
      { lng: 108.94, lat: 34.34 },
    ])

    expect(box).toEqual({ minX: 12.34, minY: 34.34, maxX: 108.94, maxY: 45.44 })
  })

  it('ignores a stop with no usable coordinates instead of poisoning the box', () => {
    const box = stopsBox([
      { lng: 12.34, lat: 45.44 },
      { lng: null, lat: undefined },
      { lng: 'nowhere', lat: 'nowhere' },
      { lng: 28.98, lat: 41.01 },
    ])

    expect(box).toEqual({ minX: 12.34, minY: 41.01, maxX: 28.98, maxY: 45.44 })
  })

  it('is null when there is nothing to frame', () => {
    expect(stopsBox([])).toBeNull()
    expect(stopsBox(null)).toBeNull()
  })
})

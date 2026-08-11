import { describe, it, expect } from 'vitest'
import { unitWorldSize } from '../voyage-ships.js'

/**
 * How a route marker scales with zoom.
 *
 * The reported bug: "zooming in from space doesn't zoom into the boat, the boat looks really big
 * and becomes smaller the more you zoom in." That is what a constant-PIXEL marker does — the map
 * grows around it while it stays the same size, so it reads as shrinking. These lock in the fix.
 */

const EARTH = 40_075_000
const mppAt = (zoom) => EARTH / (512 * Math.pow(2, zoom))
/** What the teacher actually sees: the marker's size in screen pixels at a given zoom. */
const onScreenPx = (zoom, basePx = 46, scale = 1, anchored = true) =>
  unitWorldSize(basePx, scale, mppAt(zoom), anchored) / mppAt(zoom)

describe('unitWorldSize', () => {
  it('never shrinks on screen as you zoom in — the reported bug', () => {
    let previous = 0
    for (let zoom = 0; zoom <= 18; zoom++) {
      const px = onScreenPx(zoom)
      expect(px).toBeGreaterThanOrEqual(previous - 1e-6)
      previous = px
    }
  })

  it('actually grows across the zoom range rather than staying flat', () => {
    expect(onScreenPx(12)).toBeGreaterThan(onScreenPx(2) * 2)
  })

  it('stays visible from space instead of collapsing to a speck', () => {
    expect(onScreenPx(0)).toBeGreaterThanOrEqual(15)
    expect(onScreenPx(1)).toBeGreaterThanOrEqual(15)
  })

  it('never swamps the map when zoomed right in', () => {
    expect(onScreenPx(18)).toBeLessThanOrEqual(110)
    expect(onScreenPx(22)).toBeLessThanOrEqual(110)
  })

  it('keeps a mount smaller than a tall ship at the same zoom', () => {
    // Only meaningful between the clamps, where the base sizes still separate them.
    const zoom = 6
    expect(onScreenPx(zoom, 26)).toBeLessThan(onScreenPx(zoom, 46))
  })

  it('honours the ship-scale multiplier', () => {
    expect(onScreenPx(6, 46, 2)).toBeGreaterThan(onScreenPx(6, 46, 1))
  })

  it('reproduces the old constant-pixel behaviour when not anchored', () => {
    // Same pixel size at every zoom: this is exactly what made the boat look wrong.
    expect(onScreenPx(2, 46, 1, false)).toBeCloseTo(46, 6)
    expect(onScreenPx(12, 46, 1, false)).toBeCloseTo(46, 6)
  })

  /**
   * Asking for smaller ships has to make them smaller — everywhere.
   *
   * The floor exists so a marker does not vanish from orbit, and it was applied AFTER the scale, so
   * it swallowed the request: from space, every scale below about 0.75 produced the identical 15px
   * ship. Bart turned the size down, nothing happened, and the ship still spanned a thousand
   * kilometres of ocean. A control that silently does nothing is the thing the whole settings panel
   * exists to stop.
   */
  it('honours a smaller scale even at globe zoom, where the floor used to win', () => {
    const full = onScreenPx(0, 46, 1)
    for (const scale of [0.75, 0.5, 0.3]) {
      expect(onScreenPx(0, 46, scale)).toBeLessThan(full)
    }
    // And still monotonic in scale, not merely different.
    expect(onScreenPx(0, 46, 0.3)).toBeLessThan(onScreenPx(0, 46, 0.5))
  })

  it('still keeps a default-size ship visible from orbit', () => {
    expect(onScreenPx(0)).toBeGreaterThan(10)
  })
})

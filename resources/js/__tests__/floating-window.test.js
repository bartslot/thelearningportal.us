import { describe, it, expect } from 'vitest'
import {
  MIN_VISIBLE_PX,
  isViewportUsable,
  clampToViewport,
  restorePosition,
  savePosition,
  defaultPosition,
} from '../ui/floating-window.js'

const SIZE = { width: 288, height: 320 }
const LAPTOP = { width: 1280, height: 800 }

const storageStub = (initial = null, { throws = false } = {}) => {
  let held = initial
  return {
    getItem: () => { if (throws) throw new Error('blocked'); return held },
    setItem: (_k, v) => { if (throws) throw new Error('blocked'); held = v },
    get raw() { return held },
  }
}

describe('knowing when there is a viewport to position against', () => {
  /**
   * FOUND IN A BROWSER, not here. The panel was placed during init() while the pane still reported
   * innerWidth 0, which put it at left -224 with sixty-four pixels poking off the left edge — and
   * it stayed there, because every later re-clamp agreed -224 was a legal position. A zero viewport
   * is not a small screen, it is a screen nobody has measured yet, and the difference is the whole
   * bug: one is a value to clamp against, the other is a reason to wait.
   */
  it('refuses a viewport that has not been measured', () => {
    expect(isViewportUsable({ width: 0, height: 0 })).toBe(false)
    expect(isViewportUsable({ width: 1280, height: 0 })).toBe(false)
    expect(isViewportUsable(null)).toBe(false)
    expect(isViewportUsable({ width: NaN, height: 800 })).toBe(false)
  })

  it('accepts a real one, however small', () => {
    expect(isViewportUsable(LAPTOP)).toBe(true)
    expect(isViewportUsable({ width: 320, height: 480 })).toBe(true)
  })
})

describe('a window can always be grabbed again', () => {
  /**
   * This screen already lost a floating panel once — teachers could not find it. Every case here
   * is a way for a window to become unreachable while nothing errors and nothing looks broken.
   */

  it('leaves the position alone when it is already comfortably on screen', () => {
    expect(clampToViewport({ left: 400, top: 200 }, SIZE, LAPTOP)).toEqual({ left: 400, top: 200 })
  })

  it('will not let it be dragged off the right edge', () => {
    const clamped = clampToViewport({ left: 5000, top: 100 }, SIZE, LAPTOP)
    expect(clamped.left).toBe(LAPTOP.width - MIN_VISIBLE_PX)
    expect(clamped.left).toBeLessThan(LAPTOP.width)
  })

  it('will not let it be dragged off the left edge', () => {
    // Most of the window may hang off to the left, but not all of it.
    const clamped = clampToViewport({ left: -5000, top: 100 }, SIZE, LAPTOP)
    expect(clamped.left).toBe(MIN_VISIBLE_PX - SIZE.width)
    expect(clamped.left + SIZE.width).toBe(MIN_VISIBLE_PX)
  })

  it('never puts the title bar above the viewport, because that is the only part you can drag', () => {
    expect(clampToViewport({ left: 100, top: -400 }, SIZE, LAPTOP).top).toBe(0)
  })

  it('will not let it be dragged below the bottom edge', () => {
    const clamped = clampToViewport({ left: 100, top: 5000 }, SIZE, LAPTOP)
    expect(clamped.top).toBe(LAPTOP.height - MIN_VISIBLE_PX)
  })

  it('still clamps when the window has not been measured yet', () => {
    /**
     * FOUND BY DRAGGING ONE, not here — every test above handed it a real size. A rect of height 0
     * (mid-layout, or while display is still none) made `Math.min(64, 288, 0)` zero, which made
     * every bound degenerate, and the window sailed off the bottom of the screen: measured at
     * top 859 in a 900px viewport, 23px past where the clamp should have stopped it. A zero extent
     * means "not measured", not "zero tall".
     */
    const clamped = clampToViewport({ left: 5000, top: 5000 }, { width: 288, height: 0 }, LAPTOP)
    expect(clamped.top).toBe(LAPTOP.height - MIN_VISIBLE_PX)
    expect(clamped.left).toBe(LAPTOP.width - MIN_VISIBLE_PX)
  })

  it('keeps a window smaller than the minimum fully on screen', () => {
    // The min is "at least this much, or all of it if it is smaller" — not a flat 64.
    const tiny = { width: 20, height: 20 }
    const clamped = clampToViewport({ left: 5000, top: 5000 }, tiny, LAPTOP)
    expect(clamped.left).toBe(LAPTOP.width - tiny.width)
    expect(clamped.top).toBe(LAPTOP.height - tiny.height)
  })

  it('survives a viewport of zero rather than producing NaN', () => {
    // The browser pane reports innerWidth 0 until it is resized — see
    // docs/browser-pane-measurement.md. A NaN here would place the window nowhere at all.
    const clamped = clampToViewport({ left: 100, top: 100 }, SIZE, { width: 0, height: 0 })
    expect(Number.isFinite(clamped.left)).toBe(true)
    expect(Number.isFinite(clamped.top)).toBe(true)
  })

  it('survives a position that is not a number', () => {
    expect(clampToViewport({ left: NaN, top: undefined }, SIZE, LAPTOP)).toEqual({ left: 0, top: 0 })
  })
})

describe('reopening where you left it', () => {
  it('restores a saved position', () => {
    const store = storageStub(null)
    savePosition('k', { left: 300, top: 150 }, store)
    expect(restorePosition('k', SIZE, LAPTOP, { left: 0, top: 0 }, store)).toEqual({ left: 300, top: 150 })
  })

  it('drags a position saved on a bigger monitor back into view', () => {
    /**
     * THE ONE THAT ACTUALLY LOSES THE WINDOW. left: 2200 was perfectly legal on the 2560px screen
     * it was written on, so nothing was wrong at save time and nothing re-checks it at load time.
     * Open the same lesson on a laptop and the panel is simply gone.
     */
    const store = storageStub(JSON.stringify({ left: 2200, top: 300 }))
    const restored = restorePosition('k', SIZE, LAPTOP, { left: 0, top: 0 }, store)
    expect(restored.left).toBe(LAPTOP.width - MIN_VISIBLE_PX)
  })

  it('falls back when nothing is stored', () => {
    expect(restorePosition('k', SIZE, LAPTOP, { left: 40, top: 90 }, storageStub(null)))
      .toEqual({ left: 40, top: 90 })
  })

  it('falls back when the stored value is corrupt, rather than throwing', () => {
    const store = storageStub('{not json')
    expect(restorePosition('k', SIZE, LAPTOP, { left: 40, top: 90 }, store)).toEqual({ left: 40, top: 90 })
  })

  it('falls back when the stored value is the right shape but the wrong type', () => {
    const store = storageStub(JSON.stringify({ left: '300', top: null }))
    expect(restorePosition('k', SIZE, LAPTOP, { left: 40, top: 90 }, store)).toEqual({ left: 40, top: 90 })
  })

  it('keeps working when the browser refuses storage', () => {
    const blocked = storageStub(null, { throws: true })
    expect(() => savePosition('k', { left: 1, top: 2 }, blocked)).not.toThrow()
    expect(restorePosition('k', SIZE, LAPTOP, { left: 40, top: 90 }, blocked)).toEqual({ left: 40, top: 90 })
  })
})

describe('where a window opens the first time', () => {
  it('sits inset from the top right, clear of the app header', () => {
    const at = defaultPosition(SIZE, LAPTOP)
    expect(at.left).toBe(LAPTOP.width - SIZE.width - 24)
    expect(at.top).toBe(72)
  })

  it('stays reachable on a narrow window rather than starting off-screen', () => {
    const narrow = { width: 320, height: 600 }
    const at = defaultPosition(SIZE, narrow)
    expect(at.left).toBeGreaterThanOrEqual(MIN_VISIBLE_PX - SIZE.width)
    expect(at.left).toBeLessThanOrEqual(narrow.width - MIN_VISIBLE_PX)
  })
})

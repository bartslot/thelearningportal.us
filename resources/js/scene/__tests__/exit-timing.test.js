import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ArtworkOverlay } from '../ArtworkOverlay.js'

/**
 * The player hands the scene over to the next one only after its layers have left. This covers the
 * contract between the two: what playExits() reports, and what a caller does with it.
 *
 * The wait is the player's half, reproduced here rather than imported — lesson-player.js is one
 * Alpine component around a live DOM and can't be instantiated in jsdom.
 */
const MAX_EXIT_WAIT_MS = 4000

const afterExits = (overlay, done) => {
  const wait = overlay?.playExits?.() || 0
  if (wait <= 0) { done(); return 0 }
  setTimeout(done, Math.min(wait, MAX_EXIT_WAIT_MS))

  return wait
}

const host = () => document.createElement('div')
const layer = (extra = {}) => ({ asset_id: 1, url: '/a.png', x: 50, y: 58, scale: 1, height: 40, ...extra })

describe('waiting for a scene\'s layers to leave', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  // An undecorated lesson must keep its exact pacing — no scene may sit a moment longer than before.
  it('advances immediately when nothing is animating out', () => {
    const overlay = new ArtworkOverlay(host())
    overlay.setLayers([layer()])
    const done = vi.fn()

    expect(afterExits(overlay, done)).toBe(0)
    expect(done).toHaveBeenCalledOnce()
  })

  it('holds the scene for as long as the exit takes', () => {
    const overlay = new ArtworkOverlay(host())
    overlay.setLayers([layer({ anim_out: 'fade', anim_out_duration: 800 })])
    const done = vi.fn()

    afterExits(overlay, done)
    expect(done).not.toHaveBeenCalled()

    vi.advanceTimersByTime(799)
    expect(done).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(done).toHaveBeenCalledOnce()
  })

  it('waits for the LAST layer, not the first', () => {
    const overlay = new ArtworkOverlay(host())
    overlay.setLayers([
      layer({ anim_out: 'fade', anim_out_duration: 300 }),
      layer({ asset_id: 2, anim_out: 'fade', anim_out_delay: 1, anim_out_duration: 500 }),
    ])

    expect(afterExits(overlay, vi.fn())).toBe(1500)
  })

  // A teacher can put a 10s delay on a layer. A class must not sit on a finished scene that long.
  it('caps the wait so one late layer cannot strand the class', () => {
    const overlay = new ArtworkOverlay(host())
    overlay.setLayers([layer({ anim_out: 'fade', anim_out_delay: 10, anim_out_duration: 2000 })])
    const done = vi.fn()

    expect(afterExits(overlay, done)).toBe(12000)

    vi.advanceTimersByTime(MAX_EXIT_WAIT_MS)
    expect(done).toHaveBeenCalledOnce()
  })

  it('reports nothing for a layer told to stay', () => {
    const overlay = new ArtworkOverlay(host())
    overlay.setLayers([layer({ anim_out: 'none', anim_out_duration: 900 })])

    expect(overlay.playExits()).toBe(0)
  })
})

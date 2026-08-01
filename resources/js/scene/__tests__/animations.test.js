import { describe, it, expect, vi } from 'vitest'
import {
  ENTRANCES, SCENE_TRANSITIONS, EASINGS,
  easingBezier, easingCurve, entranceFrames, playEntrance, sceneTransitionFrames,
} from '../animations.js'

describe('animation vocabulary', () => {
  // The Blade picker, the whitelist in EditsSceneArtwork and setSceneTransition all repeat these
  // keys. If one drifts, a teacher picks an option the server rejects and nothing happens.
  it('offers the entrances the inspector lists', () => {
    expect(ENTRANCES.map(e => e.key)).toEqual(
      ['none', 'fade', 'slide-left', 'slide-right', 'slide-up', 'slide-down', 'zoom', 'pop'],
    )
  })

  it('offers the scene transitions the inspector lists', () => {
    expect(SCENE_TRANSITIONS.map(t => t.key)).toEqual(
      ['crossfade', 'slide-left', 'slide-right', 'slide-up', 'slide-down', 'cut'],
    )
  })

  it('names every easing and gives each a curve and a bezier', () => {
    expect(EASINGS.map(e => e.key)).toEqual(['enter', 'move', 'exit', 'pop', 'linear'])
    for (const e of EASINGS) {
      expect(typeof e.curve).toBe('function')
      expect(e.bezier).toBeTruthy()
    }
  })

  it('falls back to a real easing when handed an unknown key', () => {
    expect(easingBezier('nonsense')).toBe(easingBezier('enter'))
    expect(typeof easingCurve('nonsense')).toBe('function')
  })

  /** Every curve must start at rest and finish at rest, or a layer lands off-position. */
  it('every easing curve runs from 0 to 1', () => {
    for (const e of EASINGS) {
      expect(e.curve(0)).toBeCloseTo(0, 5)
      expect(e.curve(1)).toBeCloseTo(1, 5)
    }
  })

  it('overshoot actually overshoots, which is the whole point of picking it', () => {
    const pop = EASINGS.find(e => e.key === 'pop').curve
    const samples = Array.from({ length: 40 }, (_, i) => pop(i / 39))

    expect(Math.max(...samples)).toBeGreaterThan(1)
  })
})

describe('entrances', () => {
  it('has no frames for "none" — the layer is simply there', () => {
    expect(entranceFrames('none')).toBeNull()
    expect(entranceFrames('unknown')).toBeNull()
  })

  it('starts every entrance invisible and ends it at rest', () => {
    for (const key of ['fade', 'slide-left', 'slide-right', 'slide-up', 'slide-down', 'zoom', 'pop']) {
      const f = entranceFrames(key)
      expect(f.from.opacity).toBe(0)
      expect(f.to).toEqual({ opacity: 1, offset: '0px, 0px', scale: 1 })
    }
  })

  it('slides in from the side it is named after', () => {
    expect(entranceFrames('slide-left').from.offset).toBe('-12%, 0px')
    expect(entranceFrames('slide-right').from.offset).toBe('12%, 0px')
    expect(entranceFrames('slide-up').from.offset).toBe('0px, 12%')
    expect(entranceFrames('slide-down').from.offset).toBe('0px, -12%')
  })
})

describe('playEntrance', () => {
  const elWith = () => ({ animate: vi.fn(() => ({ cancel: vi.fn() })) })

  it('keeps the layer’s own positioning transform and appends the entrance offset', () => {
    const el = elWith()
    playEntrance(el, { anim: 'slide-left', ease: 'enter', baseTransform: 'translate(-50%, -50%)' })

    const [frames] = el.animate.mock.calls[0]
    expect(frames[0].transform).toBe('translate(-50%, -50%) translate(-12%, 0px) scale(1)')
    expect(frames[1].transform).toBe('translate(-50%, -50%) translate(0px, 0px) scale(1)')
  })

  it('turns the delay into milliseconds and holds the start state through it', () => {
    const el = elWith()
    playEntrance(el, { anim: 'fade', delay: 1.5 })

    const [, opts] = el.animate.mock.calls[0]
    expect(opts.delay).toBe(1500)
    // Without fill:backwards the layer shows at full opacity until its delay elapses.
    expect(opts.fill).toBe('backwards')
  })

  it('animates nothing when the layer has no entrance', () => {
    const el = elWith()

    expect(playEntrance(el, { anim: 'none' })).toBeNull()
    expect(el.animate).not.toHaveBeenCalled()
  })

  it('is safe where Element.animate does not exist', () => {
    expect(playEntrance({}, { anim: 'fade' })).toBeNull()
    expect(playEntrance(null, { anim: 'fade' })).toBeNull()
  })
})

describe('scene transitions', () => {
  it('moves only the incoming layer, from the edge it is named after', () => {
    expect(sceneTransitionFrames('slide-left')).toEqual({ from: 'translateX(100%)', to: 'translateX(0)' })
    expect(sceneTransitionFrames('slide-down')).toEqual({ from: 'translateY(-100%)', to: 'translateY(0)' })
  })

  it('leaves crossfade and cut to opacity alone', () => {
    expect(sceneTransitionFrames('crossfade')).toBeNull()
    expect(sceneTransitionFrames('cut')).toBeNull()
  })
})

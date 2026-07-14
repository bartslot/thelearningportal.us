import { describe, it, expect, vi, afterEach } from 'vitest'
import { computeLayerTransforms, ParallaxScene } from '../ParallaxScene.js'

const BG_URL = 'https://cdn.example/lessons/7/shots/1/bg.jpg'
const HERO_URL = 'https://cdn.example/lessons/7/shots/1/hero.png'

// jsdom has no matchMedia — install a mock per test, remove it after.
function mockReducedMotion (matches) {
  window.matchMedia = vi.fn().mockReturnValue({ matches })
}

const host = () => {
  const el = document.createElement('div')
  document.body.appendChild(el)
  return el
}

afterEach(() => {
  document.body.innerHTML = ''
  delete window.matchMedia
  delete window.__parallax
})

describe('computeLayerTransforms (pure)', () => {
  const MOTION = { panX: -4, panY: 2, zoom: 1.1 }

  it('pans the hero at ~0.6× the background rate on both axes', () => {
    const { bg, hero } = computeLayerTransforms(0.5, MOTION)
    expect(hero.translateX).toBeCloseTo(bg.translateX * 0.6, 10)
    expect(hero.translateY).toBeCloseTo(bg.translateY * 0.6, 10)
    expect(bg.translateX).toBeCloseTo(-2, 10)   // -4 * 0.5
    expect(bg.translateY).toBeCloseTo(1, 10)    //  2 * 0.5
  })

  it('scales the hero slightly nearer than the background at every progress', () => {
    for (const p of [0, 0.25, 0.5, 1]) {
      const { bg, hero } = computeLayerTransforms(p, MOTION)
      expect(hero.scale).toBeGreaterThan(bg.scale)
      expect(hero.scale).toBeCloseTo(bg.scale * 1.03, 10)
    }
  })

  it('is identity-ish at progress 0', () => {
    const { bg, hero } = computeLayerTransforms(0, MOTION)
    expect(bg).toEqual({ translateX: 0, translateY: 0, scale: 1 })
    expect(hero.translateX).toBe(0)
    expect(hero.translateY).toBe(0)
    expect(hero.scale).toBeCloseTo(1.03, 10)
  })

  it('reaches the full pan and zoom at progress 1', () => {
    const { bg } = computeLayerTransforms(1, MOTION)
    expect(bg.translateX).toBeCloseTo(-4, 10)
    expect(bg.translateY).toBeCloseTo(2, 10)
    expect(bg.scale).toBeCloseTo(1.1, 10)
  })

  it('is deterministic — same input, same output', () => {
    expect(computeLayerTransforms(0.37, MOTION)).toEqual(computeLayerTransforms(0.37, MOTION))
  })

  it('clamps progress to 0..1 and tolerates garbage', () => {
    expect(computeLayerTransforms(2, MOTION)).toEqual(computeLayerTransforms(1, MOTION))
    expect(computeLayerTransforms(-1, MOTION)).toEqual(computeLayerTransforms(0, MOTION))
    expect(computeLayerTransforms(NaN, MOTION)).toEqual(computeLayerTransforms(0, MOTION))
  })

  it('defaults to no motion when opts are omitted', () => {
    const { bg, hero } = computeLayerTransforms(0.8)
    expect(bg).toEqual({ translateX: 0, translateY: 0, scale: 1 })
    expect(hero.scale).toBeCloseTo(1.03, 10)
  })
})

describe('ParallaxScene.show', () => {
  it('renders a background and a hero layer with the given urls', () => {
    const el = host()
    const scene = new ParallaxScene(el)
    scene.show({ bgUrl: BG_URL, heroUrl: HERO_URL })

    const imgs = el.querySelectorAll('img')
    expect(imgs).toHaveLength(2)
    expect(el.querySelector('.px-layer-bg img').getAttribute('src')).toBe(BG_URL)
    expect(el.querySelector('.px-layer-hero img').getAttribute('src')).toBe(HERO_URL)
  })

  it('renders a single bg layer when the hero is omitted — no broken img', () => {
    const el = host()
    const scene = new ParallaxScene(el)
    scene.show({ bgUrl: BG_URL })

    const imgs = el.querySelectorAll('img')
    expect(imgs).toHaveLength(1)
    expect(imgs[0].getAttribute('src')).toBe(BG_URL)
    expect(el.querySelector('.px-layer-hero')).toBeNull()
    // update() must not throw with no hero layer
    expect(() => scene.update(0.5)).not.toThrow()
  })

  it('does nothing without a bgUrl', () => {
    const el = host()
    new ParallaxScene(el).show({ heroUrl: HERO_URL })
    expect(el.querySelector('.px-scene')).toBeNull()
  })

  it('re-show replaces the previous layers instead of stacking', () => {
    const el = host()
    const scene = new ParallaxScene(el)
    scene.show({ bgUrl: BG_URL, heroUrl: HERO_URL })
    scene.show({ bgUrl: BG_URL })
    expect(el.querySelectorAll('.px-scene')).toHaveLength(1)
    expect(el.querySelectorAll('img')).toHaveLength(1)
  })

  it('exposes the debug handle', () => {
    const scene = new ParallaxScene(host())
    scene.show({ bgUrl: BG_URL })
    expect(window.__parallax).toBe(scene)
  })

  it('throws early on a missing host', () => {
    expect(() => new ParallaxScene(null)).toThrow(TypeError)
  })
})

describe('ParallaxScene.update', () => {
  it('applies GPU transforms with the hero at 0.6× the bg pan', () => {
    const el = host()
    const scene = new ParallaxScene(el)
    scene.show({ bgUrl: BG_URL, heroUrl: HERO_URL, motion: { panX: -4, panY: -4, zoom: 0.95 } })
    scene.update(0.5)

    const bg = el.querySelector('.px-layer-bg')
    const heroEl = el.querySelector('.px-layer-hero')
    expect(bg.style.transform).toBe('translate3d(-2%, -2%, 0) scale(0.975)')
    expect(heroEl.style.transform).toContain('translate3d(-1.2')       // -2 * 0.6
    expect(heroEl.style.transform).toContain('scale(1.00425')          // 0.975 * 1.03
  })

  it('is a no-op after destroy', () => {
    const scene = new ParallaxScene(host())
    scene.show({ bgUrl: BG_URL })
    scene.destroy()
    expect(() => scene.update(0.5)).not.toThrow()
  })
})

describe('ParallaxScene.destroy', () => {
  it('removes all layer DOM and clears the debug handle', () => {
    const el = host()
    const scene = new ParallaxScene(el)
    scene.show({ bgUrl: BG_URL, heroUrl: HERO_URL })
    scene.destroy()

    expect(el.querySelector('.px-scene')).toBeNull()
    expect(el.querySelectorAll('img')).toHaveLength(0)
    expect(window.__parallax).toBeUndefined()
    expect(() => scene.destroy()).not.toThrow()   // idempotent
  })
})

describe('reduced motion', () => {
  it('skips the breathing sway when prefers-reduced-motion matches', () => {
    mockReducedMotion(true)
    const el = host()
    new ParallaxScene(el).show({ bgUrl: BG_URL, heroUrl: HERO_URL })
    expect(el.querySelector('.px-layer-hero img').classList.contains('px-sway')).toBe(false)
  })

  it('applies the sway when motion is allowed', () => {
    mockReducedMotion(false)
    const el = host()
    new ParallaxScene(el).show({ bgUrl: BG_URL, heroUrl: HERO_URL })
    expect(el.querySelector('.px-layer-hero img').classList.contains('px-sway')).toBe(true)
  })

  it('defaults to sway on when matchMedia is unavailable (jsdom)', () => {
    const el = host()
    new ParallaxScene(el).show({ bgUrl: BG_URL, heroUrl: HERO_URL })
    expect(el.querySelector('.px-layer-hero img').classList.contains('px-sway')).toBe(true)
  })
})

// ── Multiplane (E3c) ─────────────────────────────────────────────────────────
import { computePlaneTransform } from '../ParallaxScene.js'

describe('computePlaneTransform (multiplane depth)', () => {
  const motion = { panX: 4, panY: -4, zoom: 1.06 }

  it('scales pan linearly with depth', () => {
    const focal = computePlaneTransform(1, 1, motion)
    const fg = computePlaneTransform(1, 1.5, motion)
    const sky = computePlaneTransform(1, 0, motion)
    expect(fg.translateX).toBeCloseTo(focal.translateX * 1.5, 5)
    expect(fg.translateY).toBeCloseTo(focal.translateY * 1.5, 5)
    expect(sky.translateX).toBe(0)
    expect(sky.translateY).toBe(0)
  })

  it('back-compat: computeLayerTransforms equals plane math at depths 1 and 0.6', () => {
    const both = computeLayerTransforms(0.7, motion)
    expect(both.bg).toEqual(computePlaneTransform(0.7, 1, motion))
    expect(both.hero).toEqual(computePlaneTransform(0.7, 0.6, motion, 1.03))
  })

  it('applies the constant base scale on top of the zoom interpolation', () => {
    const t = computePlaneTransform(1, 1, motion, 1.1)
    expect(t.scale).toBeCloseTo(1.06 * 1.1, 5)
  })
})

describe('ParallaxScene multiplane rendering', () => {
  it('renders N layers back-to-front with kind-specific markup', () => {
    const scene = new ParallaxScene(host())
    scene.show({
      layers: [
        { url: 'sky.png', kind: 'cover', depth: 0 },
        { url: 'fort.png', kind: 'cover', depth: 1 },
        { url: 'hero.png', kind: 'figure', depth: 0.6, scale: 1.03, sway: true },
        { url: 'grass.svg', kind: 'strip', depth: 1.6, height: 24 },
      ],
      motion: { panX: 4, panY: 0, zoom: 1 },
    })
    const root = document.querySelector('.px-scene')
    expect(root.children).toHaveLength(4)
    expect(root.children[0].className).toContain('px-layer-bg')
    expect(root.children[2].className).toContain('px-layer-hero')
    expect(root.children[3].className).toContain('px-layer-strip')

    scene.update(1)
    // sky pinned, strip moves 1.6× the focal pan
    expect(root.children[0].style.transform).toContain('translate3d(0%, 0%')
    expect(root.children[3].style.transform).toContain(`translate3d(${4 * 1.6}%`)
    scene.destroy()
  })

  it('strip images tuck past both side edges (no exposed borders on fast planes)', () => {
    const scene = new ParallaxScene(host())
    scene.show({ layers: [{ url: 'grass.svg', kind: 'strip', depth: 2 }] })
    const img = document.querySelector('.px-layer-strip img')
    expect(img.style.width).toBe('124%')   // 100 + 2 × (6 × 2)
    expect(img.style.left).toBe('-12%')
    scene.destroy()
  })

  it('classic bgUrl + heroUrl form still renders two layers (back-compat)', () => {
    const scene = new ParallaxScene(host())
    scene.show({ bgUrl: 'bg.png', heroUrl: 'hero.png' })
    const root = document.querySelector('.px-scene')
    expect(root.children).toHaveLength(2)
    expect(root.children[0].className).toContain('px-layer-bg')
    expect(root.children[1].className).toContain('px-layer-hero')
    scene.destroy()
  })
})

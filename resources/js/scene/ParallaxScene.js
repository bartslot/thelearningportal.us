/**
 * ParallaxScene — 2-layer webcomic shot: background image + optional transparent-PNG hero.
 *
 * A shot with `bg_url` renders as two absolutely-positioned layers inside the host:
 *   - background: oversized cover image, pans at the full Ken Burns rate
 *   - hero:       transparent PNG, centered + bottom-anchored (~80% height), pans at
 *                 ~0.6× the background rate and sits at a slightly nearer scale (1.03×)
 *
 * The depth illusion comes from that rate difference (parallax). All motion is CSS
 * transform only (translate3d for GPU compositing) — no three.js, per 3d-direction.
 * A subtle "breathing" sway on the hero is skipped under prefers-reduced-motion.
 *
 * `computeLayerTransforms` is pure and unit-tested; the class is thin DOM glue around it.
 * No globals except the optional `window.__parallax` debug handle.
 */

// Hero pans slower than the background (further from the "camera" edge = nearer to viewer).
const HERO_PAN_FACTOR = 0.6
// Hero sits slightly nearer than the background — a touch larger at every progress point.
const HERO_BASE_SCALE = 1.03
// Layers bleed past every edge (%) so a full pan never reveals a raw layer edge.
const LAYER_BLEED_PCT = 6
const FADE_IN_MS = 900

/**
 * Pure transform math for both layers.
 *
 * @param {number} progress  Playback progress 0..1 (clamped; non-finite → 0).
 * @param {{panX?: number, panY?: number, zoom?: number}} opts
 *        panX/panY: total background pan across the shot, in % (same unit the flat
 *        Ken Burns uses: end translate minus start translate). zoom: background scale
 *        at progress 1 (1 = none, mirrors toScale − fromScale of the Ken Burns move).
 * @returns {{bg: Transform, hero: Transform}} where Transform is
 *          {translateX: %, translateY: %, scale: number}. progress 0 → identity-ish
 *          (zero pan, bg scale 1, hero at its constant 1.03 base).
 */
export function computeLayerTransforms (progress, opts = {}) {
  const { panX = 0, panY = 0, zoom = 1 } = opts || {}
  const p = Number.isFinite(progress) ? Math.min(Math.max(progress, 0), 1) : 0
  const noNegZero = (n) => (n === 0 ? 0 : n)   // -4 * 0 → -0; keep CSS/asserts clean

  const bgScale = 1 + (zoom - 1) * p
  return {
    bg: {
      translateX: noNegZero(panX * p),
      translateY: noNegZero(panY * p),
      scale: bgScale,
    },
    hero: {
      translateX: noNegZero(panX * p * HERO_PAN_FACTOR),
      translateY: noNegZero(panY * p * HERO_PAN_FACTOR),
      scale: bgScale * HERO_BASE_SCALE,
    },
  }
}

let stylesInjected = false
function injectStyles () {
  if (stylesInjected) return
  stylesInjected = true
  const style = document.createElement('style')
  style.textContent = `
    @keyframes px-sway {
      0%, 100% { transform: translateX(-50%) translateY(0) rotate(0deg); }
      50%      { transform: translateX(-50%) translateY(-0.6%) rotate(0.35deg); }
    }
    .px-sway { animation: px-sway 5.2s ease-in-out infinite; }
    @media (prefers-reduced-motion: reduce) {
      .px-sway { animation: none; }
    }
  `
  document.head.appendChild(style)
}

function prefersReducedMotion () {
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

export class ParallaxScene {
  /** @param {HTMLElement} host  Element the layered scene is appended into. */
  constructor (host) {
    if (!host) throw new TypeError('ParallaxScene: host element is required')
    this.host = host
    this._root = null
    this._bgLayer = null
    this._heroLayer = null
    this._motion = { panX: 0, panY: 0, zoom: 1 }
  }

  /**
   * Build and fade in the layered shot. Re-showing replaces the previous layers.
   * @param {{bgUrl: string, heroUrl?: string|null, motion?: {panX?: number, panY?: number, zoom?: number}}} shot
   */
  show ({ bgUrl, heroUrl = null, motion = null } = {}) {
    if (!bgUrl) return
    injectStyles()
    this.destroy()
    if (motion) this._motion = { panX: 0, panY: 0, zoom: 1, ...motion }

    const root = document.createElement('div')
    root.className = 'px-scene'
    root.style.cssText = 'position:absolute;inset:0;overflow:hidden;opacity:0;'
      + `transition:opacity ${FADE_IN_MS}ms ease-in-out;`

    root.appendChild(this._buildBgLayer(bgUrl))
    if (heroUrl) root.appendChild(this._buildHeroLayer(heroUrl))

    this.host.appendChild(root)
    this._root = root
    this.update(0)

    // Fade in on the next frame so the opacity transition actually runs.
    requestAnimationFrame(() => { if (this._root) this._root.style.opacity = '1' })
    window.__parallax = this
  }

  _buildBgLayer (bgUrl) {
    const layer = document.createElement('div')
    layer.className = 'px-layer px-layer-bg'
    layer.style.cssText = `position:absolute;inset:-${LAYER_BLEED_PCT}%;will-change:transform;`

    const img = document.createElement('img')
    img.src = bgUrl
    img.alt = ''
    img.draggable = false
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;'
    layer.appendChild(img)

    this._bgLayer = layer
    return layer
  }

  _buildHeroLayer (heroUrl) {
    const layer = document.createElement('div')
    layer.className = 'px-layer px-layer-hero'
    layer.style.cssText = 'position:absolute;inset:0;will-change:transform;pointer-events:none;'

    // Centered + bottom-anchored figure; translateX(-50%) lives in the sway keyframes
    // too, so the breathing animation composes with the centering instead of fighting it.
    const img = document.createElement('img')
    img.src = heroUrl
    img.alt = ''
    img.draggable = false
    img.style.cssText = 'position:absolute;bottom:0;left:50%;transform:translateX(-50%);'
      + 'height:80%;max-width:92%;object-fit:contain;object-position:bottom;'
    if (!prefersReducedMotion()) img.classList.add('px-sway')
    layer.appendChild(img)

    this._heroLayer = layer
    return layer
  }

  /** Drive both layers from playback progress 0..1 (called on every timeupdate tick). */
  update (progress) {
    if (!this._root) return
    const t = computeLayerTransforms(progress, this._motion)
    applyTransform(this._bgLayer, t.bg)
    applyTransform(this._heroLayer, t.hero)
  }

  /** Remove all layer DOM. Safe to call twice. */
  destroy () {
    if (this._root) this._root.remove()
    this._root = null
    this._bgLayer = null
    this._heroLayer = null
    if (window.__parallax === this) delete window.__parallax
  }
}

function applyTransform (layer, { translateX, translateY, scale }) {
  if (!layer) return
  layer.style.transform = `translate3d(${translateX}%, ${translateY}%, 0) scale(${scale})`
}

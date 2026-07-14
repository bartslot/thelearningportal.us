/**
 * ParallaxScene — multiplane webcomic shot: N depth-sorted layers (E3c).
 *
 * A shot renders as absolutely-positioned planes inside the host, back to front.
 * Each plane has a DEPTH — how strongly it follows the camera pan:
 *   0   = pinned (far sky / horizon)
 *   1   = the focal background plane (reference Ken Burns rate)
 *   >1  = foreground (trees, grass — nearest to the viewer, moves the most)
 * and a KIND:
 *   'cover'  — full-bleed image plane (backgrounds); bleed grows with depth so a
 *              fast-moving foreground plane never reveals its edges
 *   'figure' — centered, bottom-anchored transparent PNG (the hero)
 *   'strip'  — full-width, bottom-anchored band (SVG/PNG foreground vegetation)
 *
 * The classic 2-layer shot (bg_url + hero_url) still works unchanged: it maps to
 * [{cover, depth 1}, {figure, depth 0.6, scale 1.03, sway}].
 *
 * All motion is CSS transform only (translate3d for GPU compositing) — no three.js,
 * per 3d-direction. Sway/breeze animations are skipped under prefers-reduced-motion.
 *
 * `computePlaneTransform` is pure and unit-tested; the class is thin DOM glue.
 * No globals except the optional `window.__parallax` debug handle.
 */

// Hero pans slower than the background (further from the "camera" edge = nearer to viewer).
const HERO_PAN_FACTOR = 0.6
// Hero sits slightly nearer than the background — a touch larger at every progress point.
const HERO_BASE_SCALE = 1.03
// Layers bleed past every edge (%) so a full pan never reveals a raw layer edge.
// Multiplied by a plane's depth (min 1) — faster planes need more headroom.
const LAYER_BLEED_PCT = 6
const FADE_IN_MS = 900

/**
 * Pure per-plane transform math.
 *
 * @param {number} progress  Playback progress 0..1 (clamped; non-finite → 0).
 * @param {number} depth     Pan multiplier: 0 pinned, 1 focal plane, >1 foreground.
 * @param {{panX?: number, panY?: number, zoom?: number}} motion
 *        panX/panY: total focal-plane pan across the shot, in % (same unit the flat
 *        Ken Burns uses). zoom: focal-plane scale at progress 1 (1 = none).
 * @param {number} baseScale Constant scale bump for "nearer" planes (hero 1.03).
 * @returns {{translateX: number, translateY: number, scale: number}}
 */
export function computePlaneTransform (progress, depth, motion = {}, baseScale = 1) {
  const { panX = 0, panY = 0, zoom = 1 } = motion || {}
  const p = Number.isFinite(progress) ? Math.min(Math.max(progress, 0), 1) : 0
  const d = Number.isFinite(depth) ? depth : 1
  const noNegZero = (n) => (n === 0 ? 0 : n)   // -4 * 0 → -0; keep CSS/asserts clean

  return {
    translateX: noNegZero(panX * p * d),
    translateY: noNegZero(panY * p * d),
    scale: (1 + (zoom - 1) * p) * baseScale,
  }
}

/**
 * Back-compat wrapper: the classic bg/hero pair expressed through the plane math.
 * @returns {{bg: Transform, hero: Transform}}
 */
export function computeLayerTransforms (progress, opts = {}) {
  return {
    bg: computePlaneTransform(progress, 1, opts),
    hero: computePlaneTransform(progress, HERO_PAN_FACTOR, opts, HERO_BASE_SCALE),
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
    @keyframes px-breeze {
      0%, 100% { transform: skewX(0deg); }
      50%      { transform: skewX(0.6deg); }
    }
    .px-breeze { animation: px-breeze 7s ease-in-out infinite; transform-origin: bottom center; }
    @media (prefers-reduced-motion: reduce) {
      .px-sway, .px-breeze { animation: none; }
    }
  `
  document.head.appendChild(style)
}

function prefersReducedMotion () {
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * @typedef {Object} PlaneSpec
 * @property {string} url            Image/SVG URL.
 * @property {number} [depth=1]      0 pinned … 1 focal … >1 foreground.
 * @property {'cover'|'figure'|'strip'} [kind='cover']
 * @property {number} [scale=1]      Constant "nearness" scale bump (hero: 1.03).
 * @property {number} [height]       figure/strip height in % of stage (figure 80, strip 28).
 * @property {boolean} [sway]        Breathing (figure) / breeze (strip) animation.
 */

export class ParallaxScene {
  /** @param {HTMLElement} host  Element the layered scene is appended into. */
  constructor (host) {
    if (!host) throw new TypeError('ParallaxScene: host element is required')
    this.host = host
    this._root = null
    this._bgLayer = null
    this._heroLayer = null
    this._planes = []           // [{el, depth, baseScale}] back → front
    this._motion = { panX: 0, panY: 0, zoom: 1 }
  }

  /**
   * Build and fade in the layered shot. Re-showing replaces the previous layers.
   * Multiplane form: pass `layers` (back → front). Classic form: bgUrl (+ heroUrl)
   * maps to [{cover, depth 1}, {figure, depth 0.6, scale 1.03, sway}].
   *
   * @param {{bgUrl?: string, heroUrl?: string|null, layers?: PlaneSpec[],
   *          motion?: {panX?: number, panY?: number, zoom?: number}}} shot
   */
  show ({ bgUrl = null, heroUrl = null, layers = null, motion = null } = {}) {
    // Classic form requires a background — a hero floating on nothing is never intended.
    const specs = Array.isArray(layers) && layers.length
      ? layers.filter(l => l && l.url)
      : (bgUrl
          ? [
              { url: bgUrl, kind: 'cover', depth: 1 },
              ...(heroUrl ? [{ url: heroUrl, kind: 'figure', depth: HERO_PAN_FACTOR, scale: HERO_BASE_SCALE, sway: true }] : []),
            ]
          : [])
    if (!specs.length) return

    injectStyles()
    this.destroy()
    if (motion) this._motion = { panX: 0, panY: 0, zoom: 1, ...motion }

    const root = document.createElement('div')
    root.className = 'px-scene'
    root.style.cssText = 'position:absolute;inset:0;overflow:hidden;opacity:0;'
      + `transition:opacity ${FADE_IN_MS}ms ease-in-out;`

    this._planes = specs.map(spec => {
      const el = this._buildPlane(spec)
      root.appendChild(el)
      return { el, depth: spec.depth ?? 1, baseScale: spec.scale ?? 1 }
    })
    // Debug/back-compat aliases: first cover plane + first figure plane.
    this._bgLayer = this._planes.find(p => p.el.classList.contains('px-layer-bg'))?.el ?? null
    this._heroLayer = this._planes.find(p => p.el.classList.contains('px-layer-hero'))?.el ?? null

    this.host.appendChild(root)
    this._root = root
    this.update(0)

    // Fade in on the next frame so the opacity transition actually runs.
    requestAnimationFrame(() => { if (this._root) this._root.style.opacity = '1' })
    window.__parallax = this
  }

  /** @param {PlaneSpec} spec */
  _buildPlane (spec) {
    const { url, kind = 'cover', depth = 1, height, sway } = spec
    const layer = document.createElement('div')
    const animate = sway && ! prefersReducedMotion()

    if (kind === 'cover') {
      // Bleed grows with depth: a foreground plane pans depth× as far.
      const bleed = LAYER_BLEED_PCT * Math.max(1, depth)
      layer.className = 'px-layer px-layer-bg'
      layer.style.cssText = `position:absolute;inset:-${bleed}%;will-change:transform;`
      const img = document.createElement('img')
      img.src = url
      img.alt = ''
      img.draggable = false
      img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;'
      layer.appendChild(img)
      return layer
    }

    layer.className = `px-layer ${kind === 'figure' ? 'px-layer-hero' : 'px-layer-strip'}`
    layer.style.cssText = 'position:absolute;inset:0;will-change:transform;pointer-events:none;'

    const img = document.createElement('img')
    img.src = url
    img.alt = ''
    img.draggable = false

    if (kind === 'figure') {
      // Centered + bottom-anchored figure; translateX(-50%) lives in the sway keyframes
      // too, so the breathing animation composes with the centering instead of fighting it.
      img.style.cssText = 'position:absolute;bottom:0;left:50%;transform:translateX(-50%);'
        + `height:${height ?? 80}%;max-width:92%;object-fit:contain;object-position:bottom;`
      if (animate) img.classList.add('px-sway')
    } else {
      // strip: full-width foreground band (SVG trees/grass), tucked past both side
      // edges + slightly below the bottom so its own pan never exposes a border.
      const over = LAYER_BLEED_PCT * Math.max(1, depth)
      img.style.cssText = `position:absolute;bottom:-2%;left:-${over}%;width:${100 + 2 * over}%;`
        + `height:${height ?? 28}%;object-fit:cover;object-position:bottom;`
      if (animate) img.classList.add('px-breeze')
    }

    layer.appendChild(img)
    return layer
  }

  /** Drive all planes from playback progress 0..1 (called on every timeupdate tick). */
  update (progress) {
    if (!this._root) return
    for (const plane of this._planes) {
      applyTransform(plane.el, computePlaneTransform(progress, plane.depth, this._motion, plane.baseScale))
    }
  }

  /** Remove all layer DOM. Safe to call twice. */
  destroy () {
    if (this._root) this._root.remove()
    this._root = null
    this._bgLayer = null
    this._heroLayer = null
    this._planes = []
    if (window.__parallax === this) delete window.__parallax
  }
}

function applyTransform (layer, { translateX, translateY, scale }) {
  if (!layer) return
  layer.style.transform = `translate3d(${translateX}%, ${translateY}%, 0) scale(${scale})`
}

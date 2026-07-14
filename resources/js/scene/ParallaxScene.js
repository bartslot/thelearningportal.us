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

function injectStyles () {
  // Idempotence by DOM presence, not a module flag — test environments (and
  // SPA navigations) can wipe the document while the module stays loaded.
  // Head (styles) and body (SVG filter defs) are checked independently:
  // some environments clear one but not the other.
  if (! document.getElementById('px-scene-styles')) injectKeyframes()
  if (! document.getElementById('px-wobble-defs')) injectWobbleDefs()
}

function injectKeyframes () {
  const style = document.createElement('style')
  style.id = 'px-scene-styles'
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

// Hand-drawn "wobbly line" filters (feTurbulence displaces edges). The seed
  // animates at 3 steps/s — the "boiling line" of traditional ink animation.
  // Referenced via CSS filter: url(#px-wobble-N); SMIL is dropped for reduced motion.
  // Built through an HTML wrapper: the HTML parser handles inline-SVG content
  // reliably everywhere (innerHTML on a namespaced <svg> does not).
function injectWobbleDefs () {
  const freeze = prefersReducedMotion()
  const wrap = document.createElement('div')
  wrap.innerHTML = `<svg id="px-wobble-defs" aria-hidden="true" style="position:absolute;width:0;height:0;">${[1, 2].map(level => `
    <filter id="px-wobble-${level}" x="-5%" y="-5%" width="110%" height="110%">
      <feTurbulence type="turbulence" baseFrequency="0.012" numOctaves="2" seed="1" result="n">
        ${freeze ? '' : '<animate attributeName="seed" values="1;5;9;1" dur="1s" repeatCount="indefinite" calcMode="discrete"/>'}
      </feTurbulence>
      <feDisplacementMap in="SourceGraphic" in2="n" scale="${level * 4}" xChannelSelector="R" yChannelSelector="G"/>
    </filter>`).join('')}</svg>`
  document.body.appendChild(wrap.firstElementChild)
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
 *
 * Artistic controls (Photoshop-style, all GPU-cheap):
 * @property {number} [z]            Explicit stacking override; default = array order.
 * @property {number} [blur]         px. Overrides the dof-computed blur when set.
 * @property {number} [opacity=1]    0..1.
 * @property {string} [blend]        CSS mix-blend-mode ('multiply' suits stacked ink).
 * @property {0|1|2}  [wobble=0]     Hand-drawn boiling-line displacement intensity.
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
   * `dof` gives free depth-of-field: planes blur by their distance from the focus
   * depth (blur = strength × |depth − focus|), unless a layer sets its own `blur`.
   *
   * @param {{bgUrl?: string, heroUrl?: string|null, layers?: PlaneSpec[],
   *          motion?: {panX?: number, panY?: number, zoom?: number},
   *          dof?: {focus?: number, strength?: number}}} shot
   */
  show ({ bgUrl = null, heroUrl = null, layers = null, motion = null, dof = null } = {}) {
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
      // Depth-of-field: unfocused planes blur with distance, unless explicitly set.
      // Rounded to 0.1px — clean CSS values, no float-noise like blur(1.9999px).
      const blur = spec.blur ?? (dof
        ? Math.round(Math.abs((spec.depth ?? 1) - (dof.focus ?? 1)) * (dof.strength ?? 3) * 10) / 10
        : 0)
      const el = this._buildPlane({ ...spec, blur })
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
      return this._applyArtisticProps(layer, spec)
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
    return this._applyArtisticProps(layer, spec)
  }

  /**
   * Photoshop-style per-layer settings, all compositor-cheap. The wobble filter
   * and blur go on the layer's FILTER (displacement first, then blur); z overrides
   * the natural array-order stacking; blend modes let ink layers multiply onto
   * the paper tones beneath them.
   * @param {HTMLElement} layer @param {PlaneSpec} spec
   */
  _applyArtisticProps (layer, { blur = 0, opacity = 1, blend = null, wobble = 0, z = null } = {}) {
    const filters = []
    const wobbleLevel = Math.min(2, Math.max(0, Math.round(wobble)))
    if (wobbleLevel > 0) filters.push(`url(#px-wobble-${wobbleLevel})`)
    if (blur > 0) filters.push(`blur(${Math.min(20, blur)}px)`)
    if (filters.length) layer.style.filter = filters.join(' ')
    if (opacity !== 1) layer.style.opacity = String(Math.min(1, Math.max(0, opacity)))
    if (blend) layer.style.mixBlendMode = blend
    if (z !== null && Number.isFinite(z)) layer.style.zIndex = String(z)

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

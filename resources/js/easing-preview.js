// Live easing thumbnails for the wizard's Animate tab.
//
// Each swatch draws the curve once as an SVG path and then runs a dot along it on a loop, so the
// teacher sees the SHAPE and feels the TIMING before choosing — the graph alone doesn't tell you
// what "Overshoot" does. Drawn rather than shipped as GIFs: these inherit the theme, stay crisp on
// any display, and cost nothing to add a curve to.
//
// Registered as an Alpine component (x-data="easingPreview(...)") by resources/js/app.js.

import { EASINGS, easingCurve } from './scene/animations.js'

const W = 72          // viewBox units — the swatch is sized in CSS, this is just the drawing grid
const H = 44
const PAD = 6
const SAMPLES = 48
const CYCLE_MS = 1400  // one run, then a short rest before the next

/** The curve as an SVG path, y flipped because SVG grows downward. */
export function curvePath(fn) {
  const x0 = PAD, x1 = W - PAD, y0 = H - PAD, y1 = PAD
  const pts = []
  for (let i = 0; i <= SAMPLES; i++) {
    const t = i / SAMPLES
    const v = fn(t)
    pts.push(`${(x0 + (x1 - x0) * t).toFixed(2)},${(y0 + (y1 - y0) * v).toFixed(2)}`)
  }

  return 'M' + pts.join(' L')
}

/** Where the travelling dot sits at progress `t` (0..1). */
export function dotAt(fn, t) {
  const x0 = PAD, x1 = W - PAD, y0 = H - PAD, y1 = PAD
  const v = fn(t)

  return { x: x0 + (x1 - x0) * t, y: y0 + (y1 - y0) * v }
}

export function easingPreview(initial = 'enter') {
  return {
    options: EASINGS.map(e => ({ key: e.key, label: e.label, path: curvePath(e.curve) })),
    selected: initial,
    // Progress of the looping dot, 0..1. One shared clock drives every swatch, so the row reads
    // as a comparison — the same moment on every curve — instead of a jumble of loose loops.
    t: 0,
    _raf: null,
    _start: 0,

    init() {
      const step = (now) => {
        if (!this._start) this._start = now
        const elapsed = (now - this._start) % (CYCLE_MS + 500)
        this.t = Math.min(1, elapsed / CYCLE_MS)
        this._raf = requestAnimationFrame(step)
      }
      // Respect a teacher who has asked the OS for less motion: draw the curves, hold the dot at
      // the end rather than looping it forever in the corner of their eye.
      if (window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches) {
        this.t = 1
      } else {
        this._raf = requestAnimationFrame(step)
      }
      this.$watch?.('$el', () => {})
    },

    destroy() {
      if (this._raf) cancelAnimationFrame(this._raf)
      this._raf = null
    },

    /** Dot coordinates for one option at the shared clock position. */
    dot(key) {
      return dotAt(easingCurve(key), this.t)
    },

    choose(key, onChange) {
      this.selected = key
      if (typeof onChange === 'function') onChange(key)
    },
  }
}


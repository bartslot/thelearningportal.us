// Scene and layer motion — one vocabulary, shared by the wizard's Animate tab, the editor
// preview and the student player. Adding a movement here makes it available in all three; the
// picker builds its options from these lists rather than repeating them in Blade.
//
// Easing is chosen by INTENT, per the easing conventions: a teacher picks "Settle" or "Overshoot",
// not a cubic-bezier. The curves come from resources/js/easing.js so motion in a lesson matches
// motion in the app chrome.

import { BEZIER, EASE, EASING } from '../easing.js'

/** How a layer arrives on the scene. `none` shows it immediately. */
export const ENTRANCES = [
  { key: 'none', label: 'None' },
  { key: 'fade', label: 'Fade in' },
  { key: 'slide-left', label: 'In from left' },
  { key: 'slide-right', label: 'In from right' },
  { key: 'slide-up', label: 'In from below' },
  { key: 'slide-down', label: 'In from above' },
  { key: 'zoom', label: 'Zoom in' },
  { key: 'pop', label: 'Pop' },
]

/**
 * How a layer LEAVES, at the end of the scene — Keynote's Build Out.
 *
 * Deliberately mirrored from ENTRANCES so "in from left / out to left" reads as one movement
 * continuing, not two unrelated effects.
 */
export const EXITS = [
  { key: 'none', label: 'Stay' },
  { key: 'fade', label: 'Fade out' },
  { key: 'slide-left', label: 'Out to left' },
  { key: 'slide-right', label: 'Out to right' },
  { key: 'slide-up', label: 'Out upwards' },
  { key: 'slide-down', label: 'Out downwards' },
  { key: 'zoom', label: 'Zoom out' },
  { key: 'pop', label: 'Pop out' },
]

/** How one scene becomes the next. */
export const SCENE_TRANSITIONS = [
  { key: 'crossfade', label: 'Crossfade' },
  { key: 'slide-left', label: 'Slide left' },
  { key: 'slide-right', label: 'Slide right' },
  { key: 'slide-up', label: 'Slide up' },
  { key: 'slide-down', label: 'Slide down' },
  { key: 'cut', label: 'Cut' },
]

/**
 * The easing menu. `curve` drives the live preview graph, `bezier` drives the real animation —
 * the two are the same shape, so what a teacher previews is what plays.
 */
export const EASINGS = [
  { key: 'enter', label: 'Settle', bezier: EASE.enter, curve: EASING.easeOutCubic },
  { key: 'move', label: 'Smooth', bezier: EASE.move, curve: EASING.easeInOutCubic },
  { key: 'exit', label: 'Accelerate', bezier: EASE.exit, curve: EASING.easeInCubic },
  { key: 'pop', label: 'Overshoot', bezier: EASE.pop, curve: overshoot },
  { key: 'linear', label: 'Constant', bezier: 'linear', curve: EASING.linear },
]

/** Back-ease that matches BEZIER.pop closely enough for the preview to be honest about it. */
function overshoot(t) {
  const c = 1.70158, s = c * 1.525
  return t < 0.5
    ? (Math.pow(2 * t, 2) * ((s + 1) * 2 * t - s)) / 2
    : (Math.pow(2 * t - 2, 2) * ((s + 1) * (2 * t - 2) + s) + 2) / 2
}

export const DEFAULT_ENTRANCE = 'none'
export const DEFAULT_EASE = 'enter'
export const DEFAULT_DURATION_MS = 600
export const MAX_DELAY_S = 10
export const MIN_DURATION_MS = 100
export const MAX_DURATION_MS = 5000

export function easingBezier(key) {
  return (EASINGS.find(e => e.key === key) ?? EASINGS[0]).bezier
}

export function easingCurve(key) {
  return (EASINGS.find(e => e.key === key) ?? EASINGS[0]).curve
}

/**
 * WAAPI keyframes for an entrance. Transforms are composed with the layer's own centring
 * translate by the caller, so these describe the OFFSET from the resting position only.
 *
 * @returns {?{from: object, to: object}} null when the layer should simply be there
 */
export function entranceFrames(key) {
  switch (key) {
    case 'fade': return { from: { opacity: 0, offset: '0px, 0px', scale: 1 }, to: { opacity: 1, offset: '0px, 0px', scale: 1 } }
    case 'slide-left': return { from: { opacity: 0, offset: '-12%, 0px', scale: 1 }, to: { opacity: 1, offset: '0px, 0px', scale: 1 } }
    case 'slide-right': return { from: { opacity: 0, offset: '12%, 0px', scale: 1 }, to: { opacity: 1, offset: '0px, 0px', scale: 1 } }
    case 'slide-up': return { from: { opacity: 0, offset: '0px, 12%', scale: 1 }, to: { opacity: 1, offset: '0px, 0px', scale: 1 } }
    case 'slide-down': return { from: { opacity: 0, offset: '0px, -12%', scale: 1 }, to: { opacity: 1, offset: '0px, 0px', scale: 1 } }
    case 'zoom': return { from: { opacity: 0, offset: '0px, 0px', scale: 0.82 }, to: { opacity: 1, offset: '0px, 0px', scale: 1 } }
    case 'pop': return { from: { opacity: 0, offset: '0px, 0px', scale: 0.6 }, to: { opacity: 1, offset: '0px, 0px', scale: 1 } }
    default: return null
  }
}

/**
 * Play a layer's entrance.
 *
 * `baseTransform` is whatever transform already positions the element (the centre-anchored
 * translate); the entrance offset is appended so the two never fight. Returns the Animation so a
 * caller can cancel it when the scene moves on — an entrance still running over a scene that has
 * already changed is how a layer ends up stuck half-transparent.
 *
 * @returns {?Animation}
 */
export function playEntrance(el, { anim = DEFAULT_ENTRANCE, delay = 0, ease = DEFAULT_EASE, duration = DEFAULT_DURATION_MS, baseTransform = '' } = {}) {
  const frames = entranceFrames(anim)
  if (!el || !frames || typeof el.animate !== 'function') return null

  const compose = (f) => ({
    opacity: f.opacity,
    transform: `${baseTransform} translate(${f.offset}) scale(${f.scale})`.trim(),
  })

  return el.animate([compose(frames.from), compose(frames.to)], {
    duration: Math.max(0, duration),
    delay: Math.max(0, delay) * 1000,
    easing: easingBezier(ease),
    fill: 'backwards',   // hold the FROM state during the delay, or the layer flashes at full opacity first
  })
}

/**
 * From/to transforms for the incoming half of a scene transition. The outgoing layer simply
 * fades; sliding both directions at once reads as a shove rather than a change of scene.
 */
export function sceneTransitionFrames(type) {
  switch (type) {
    case 'slide-left': return { from: 'translateX(100%)', to: 'translateX(0)' }
    case 'slide-right': return { from: 'translateX(-100%)', to: 'translateX(0)' }
    case 'slide-up': return { from: 'translateY(100%)', to: 'translateY(0)' }
    case 'slide-down': return { from: 'translateY(-100%)', to: 'translateY(0)' }
    default: return null   // crossfade and cut need no transform
  }
}

export { BEZIER, EASE }

/**
 * Play a layer's exit — the same movement as its entrance, run backwards.
 *
 * `fill: 'forwards'` because the layer must STAY gone once it has left; without it the layer
 * snaps back to full opacity for the last moments of the scene.
 *
 * @returns {?Animation}
 */
export function playExit(el, { anim = 'none', delay = 0, ease = DEFAULT_EASE, duration = DEFAULT_DURATION_MS, baseTransform = '' } = {}) {
  const frames = entranceFrames(anim)
  if (!el || !frames || typeof el.animate !== 'function') return null

  const compose = (f) => ({
    opacity: f.opacity,
    transform: `${baseTransform} translate(${f.offset}) scale(${f.scale})`.trim(),
  })

  // to → from: the entrance in reverse. An exit eases the other way round too, so a layer
  // accelerates away rather than creeping off.
  return el.animate([compose(frames.to), compose(frames.from)], {
    duration: Math.max(0, duration),
    delay: Math.max(0, delay) * 1000,
    easing: ease === DEFAULT_EASE ? easingBezier('exit') : easingBezier(ease),
    fill: 'forwards',
  })
}

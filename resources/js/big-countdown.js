/**
 * big-countdown.js — the one big number the app counts down with, centred on what it covers.
 *
 * The strategy game already had one: when a challenge starts, the remaining time appears across
 * the middle of the stage for five seconds and then shrinks away to the corner HUD. The quiz's
 * read-gate wanted exactly the same thing — a number, in the middle, counting down to the moment
 * the answers unlock — so this is that timer lifted out rather than written a second time.
 *
 * What is shared is the whole look and the whole exit: centred over the host, tabular figures so
 * the digits do not jitter as they change, a shadow heavy enough to sit on a photograph, a tick
 * that swaps one number for the next, and a leave that scales 100 → 75 while it fades.
 *
 * What is NOT shared is the text and the colour, because the two callers count different things:
 * the game shows a clock ("9:58") in amber going red at two minutes, the quiz shows a bare second
 * ("3") in white. Both pass their own `digitClass`.
 *
 *   const timer = mountBigCountdown(host, { text: '3', digitClass: 'text-5xl text-base-content' })
 *   timer.setText('2')      // ticks: the new number fades up in place of the old
 *   await timer.leave()     // scale down, fade, remove
 */
import { EASE } from './easing.js'

/** Centred over the host and never in the way of a click — it is a label, not a control. */
const FRAME_CLASS = 'pointer-events-none absolute inset-0 z-10 flex items-center justify-center'

// `tabular-nums` matters: without it "1" is narrower than "3" and the number twitches sideways.
// Typeface is deliberately NOT set here — the game clock is display type, the quiz gate is the UI
// face, and both are right. It comes in with the size and the colour, from the caller.
const DIGIT_CLASS = 'font-bold leading-none tabular-nums drop-shadow-[0_8px_16px_rgba(0,0,0,0.9)]'

/** One number replacing another. Short enough to read as a tick rather than a transition. */
export const BIG_COUNTDOWN_TICK_MS = 160

/** The exit, long enough to register as "that is over" without holding anyone up. */
export const BIG_COUNTDOWN_LEAVE_MS = 500

/**
 * `el.animate(...)`, where there is a Web Animations API to animate with.
 *
 * jsdom has none, so the quiz's unit tests drive a countdown whose every tick would otherwise
 * throw. The number still counts and the element is still removed — only the motion is missing,
 * which is exactly what a renderer without WAAPI should get.
 *
 * @returns {Promise<void>} settles when the motion is over, immediately when there was none
 */
function animate (el, keyframes, options) {
  if (typeof el.animate !== 'function') return Promise.resolve()

  // Resolve on finish OR on cancel: an element torn down mid-animation must not leave a caller
  // awaiting a promise that will never settle.
  return el.animate(keyframes, options).finished.then(() => {}, () => {})
}

/**
 * Put a big centred number over `host` and hand back the two things a caller ever does to it.
 *
 * @param {HTMLElement} host       positioned ancestor to centre on
 * @param {object}      [opts]
 * @param {string}      [opts.text]        first value to show
 * @param {string}      [opts.digitClass]  size and colour, chosen by the caller
 * @returns {{ el: HTMLElement, digit: HTMLElement, setText: (text: string, digitClass?: string) => void, leave: () => Promise<void> }}
 */
export function mountBigCountdown (host, { text = '', digitClass = '' } = {}) {
  const el = document.createElement('div')
  el.dataset.bigCountdown = '1'
  el.className = FRAME_CLASS

  let styling = digitClass
  const digit = document.createElement('span')
  digit.className = `${DIGIT_CLASS} ${styling}`.trim()
  digit.textContent = String(text)
  el.appendChild(digit)
  host.appendChild(el)

  return {
    el,
    /** The number itself, for a caller that needs to hang its own hook on it. */
    digit,

    /** `digitClass` is optional and only for a caller whose colour changes as it counts. */
    setText (next, nextClass = styling) {
      if (nextClass !== styling) {
        styling = nextClass
        digit.className = `${DIGIT_CLASS} ${styling}`.trim()
      }
      const value = String(next)
      if (digit.textContent === value) return   // a tick nobody asked for reads as a flicker
      digit.textContent = value
      // The Figma crossfades one digit out and the next in. On a single element that is the same
      // read: the new number arrives slightly large and settles, so the change is felt, not just seen.
      animate(
        digit,
        [{ opacity: 0, transform: 'scale(1.18)' }, { opacity: 1, transform: 'scale(1)' }],
        { duration: BIG_COUNTDOWN_TICK_MS, easing: EASE.enter },
      )
    },

    leave () {
      return animate(
        el,
        [{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(0.75)' }],
        { duration: BIG_COUNTDOWN_LEAVE_MS, easing: EASE.exit, fill: 'forwards' },
      ).then(() => el.remove())
    },
  }
}

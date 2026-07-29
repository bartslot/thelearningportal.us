/**
 * How a scene background fills the 16:9 stage.
 *
 * Shared by the wizard's WebGL editor (wizard-bridge.js) and the CSS player (lesson-player.js) so a
 * teacher sees exactly the crop the class will see. The rule used to live in both files separately
 * and they drifted.
 */

/** Taller than wide by this much ⇒ a portrait. Above 1.0 so square-ish art is not caught. */
export const PORTRAIT_ASPECT = 1.05

/**
 * Where a top-anchored crop starts, as a fraction of the slack being cropped away:
 * 0 = dead centre, 1 = flush with the image's top edge. 0.9 keeps the sitter's face with a little
 * headroom instead of jamming it against the frame.
 */
export const PORTRAIT_TOP_BIAS = 0.9

/** The same anchor expressed for CSS `background-position` (10% down from the top). */
export const PORTRAIT_TOP_CSS = 'center 10%'

/** Mirrors App\Support\PortraitFocus::PORTRAIT_ASPECT — keep the two in step. */
export function isPortraitShape (width, height) {
  return width > 0 && height > 0 && height > width * PORTRAIT_ASPECT
}

/** Only two fits are supported; anything unknown means the default. */
export function normalizeFit (fit) {
  return fit === 'contain' ? 'contain' : 'cover'
}

/**
 * Should this background be cropped from the top rather than the centre?
 *
 * Two independent signals, either one sufficient:
 *  - the stored `background_focus` hint, which catches WIDE images whose subject sits high
 *    (a landscape canvas titled "Portrait of…"), something shape alone can never detect;
 *  - the image's real pixel shape, which catches every portrait no matter how it was sourced —
 *    upload, Commons picker, corpus painting, or a lesson built before the hint existed.
 *
 * Relying on the stored hint alone is what put a 736×1475 standing portrait of Philip II on screen
 * cropped to his waist: the corpus tagged it {battle, soldiers, war}, never "portrait".
 *
 * Never true for `contain` — the whole image is already visible, so there is nothing to anchor.
 */
export function isTopAnchored (fit, focus, width, height) {
  if (normalizeFit(fit) !== 'cover') return false

  return focus === 'top' || isPortraitShape(width, height)
}

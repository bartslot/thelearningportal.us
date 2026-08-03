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
 * 0 = dead centre, 1 = flush with the image's top edge.
 *
 * 0.6 is the same anchor as PORTRAIT_TOP_CSS below, on the mapping bias = 1 - percent/50:
 * flush-top is 0%, dead-centre is 50%.
 */
export const PORTRAIT_TOP_BIAS = 0.6

/** The same anchor for CSS `background-position` — a face sits 20% down from the top. */
export const PORTRAIT_TOP_CSS = 'center 20%'

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
 * Only when something actually says there is a FACE up there — the stored `background_focus`
 * hint. A tall image is not evidence of a face: a tall engraving of a ship anchored top showed
 * nothing but mast and flag, with the ship itself cropped away below the frame.
 *
 * Shape used to be a second, independent signal here, and it is the reason this went wrong. It
 * does catch portraits the corpus mislabels (a standing Philip II tagged {battle, soldiers}), but
 * it also catches every tall landscape, engraving and map, and centre is the right default for
 * all of those. A face wrongly centred loses some forehead; a ship wrongly topped loses the ship.
 *
 * Never true for `contain` — the whole image is already visible, so there is nothing to anchor.
 */
export function isTopAnchored (fit, focus, width, height) {
  if (normalizeFit(fit) !== 'cover') return false

  return focus === 'top'
}

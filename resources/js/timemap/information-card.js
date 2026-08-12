/**
 * information-card.js — the territory card's geometry, as tuner knobs.
 *
 * The card is CSS, so there is no layer object to call setOptions on. What it has instead is three
 * custom properties on :root, and this turns each into a slider — which is the whole point of the
 * rule that visual work ships with its controls: "the body is too tall" is an argument, and a
 * slider that shows you 280 next to 320 is not.
 *
 * DEFAULTS MUST MATCH THE COMMITTED CSS. They are read from the stylesheet rather than typed out
 * here, so a value changed in brand-kit.css can never leave the panel opening on a different number
 * than the card is actually drawn at — the failure that makes a tuned value untrustworthy.
 */

/** The knobs, in the order the card is built: outside in. */
export const CARD_CONTROLS = [
  { key: 'cardWidth', property: '--card-width', label: 'Width', min: 240, max: 420, step: 1 },
  { key: 'cardBodyHeight', property: '--card-body-height', label: 'Body height', min: 120, max: 520, step: 4 },
  { key: 'cardFadeHeight', property: '--card-fade-height', label: 'Fade height', min: 0, max: 160, step: 2 },
]

/**
 * Read a length custom property in PIXELS.
 *
 * getComputedStyle returns whatever the author wrote — `19.375rem` here — and a slider fed a rem
 * string reads it as NaN, which renders as a card with no width at all and no error anywhere. So
 * the rem is resolved against the root font size rather than parsed as if it were px.
 */
export const readLengthPx = (property, element = document.documentElement) => {
  const raw = getComputedStyle(element).getPropertyValue(property).trim()
  const value = parseFloat(raw)
  if (!Number.isFinite(value)) return null
  if (raw.endsWith('rem')) return value * parseFloat(getComputedStyle(document.documentElement).fontSize || '16')
  return value
}

/**
 * Register the card's geometry with the tuner. Returns a teardown, like every other group.
 *
 * No-ops without a tuner: the panel is local-only, and the card must not depend on it existing.
 */
export const registerCardControls = (target = globalThis) => {
  if (!target.__tune?.register) return () => {}

  const root = document.documentElement
  const controls = CARD_CONTROLS.map((control) => ({
    key: control.key,
    label: control.label,
    min: control.min,
    max: control.max,
    step: control.step,
    value: readLengthPx(control.property) ?? control.min,
    apply: (v) => root.style.setProperty(control.property, `${Number(v)}px`),
  }))

  const off = target.__tune.register('Information card', controls, { tab: 'Map' })

  return () => {
    off?.()
    // Hand the card back to the stylesheet. A tuned value left on :root would outlive the panel and
    // read as a committed default to whoever opened the map next.
    for (const control of CARD_CONTROLS) root.style.removeProperty(control.property)
  }
}

// Per-layer colour treatment: knock the paper out, drain the colour, tint what is left.
//
// These three go together. A scanned engraving or map arrives as black ink on white paper; the
// paper is the problem. `multiply` hides it against the scene but drags the artwork's own colour
// down with it, and it does nothing at all over a dark map. Keying the white to TRANSPARENT
// leaves just the ink, which can then be recoloured to sit with the lesson's palette.
//
// CSS filters can't do the first part — there is no "make near-white transparent" function — so
// the chain is a small SVG filter, which composites on the GPU like any other filter and applies
// identically in the editor and in playback.
//
//   1. grayscale   feColorMatrix saturate 0
//   2. white key   alpha = (1 - luminance) / tolerance, clamped — so anything within `tolerance`
//                  of white lands on alpha 0 and everything darker stays fully opaque
//   3. tint        flood the chosen colour and keep it only where the alpha survived

/** Rec. 709 luminance — the same weighting the eye uses, so mid greys key predictably. */
const LUMA = { r: 0.2126, g: 0.7152, b: 0.0722 }

export const WHITE_KEY_MAX = 0.5     // 50%: past this a mid-grey starts disappearing too
export const DEFAULT_WHITE_KEY = 0

/** Stable per-layer filter id — the markup is regenerated whenever the layer re-renders. */
export const layerFilterId = (assetId) => `lyrfx-${assetId}`

export function hasTreatment({ white_key = 0, grayscale = false, tint = null } = {}) {
  return white_key > 0 || !!grayscale || !!tint
}

/**
 * The <filter> body for one layer, or null when the layer asks for nothing.
 *
 * @returns {?string} SVG markup for a <filter> element's children
 */
export function filterMarkup({ white_key = 0, grayscale = false, tint = null } = {}) {
  if (!hasTreatment({ white_key, grayscale, tint })) return null

  const steps = []

  if (grayscale) {
    steps.push('<feColorMatrix type="saturate" values="0"/>')
  }

  if (white_key > 0) {
    const tol = Math.min(WHITE_KEY_MAX, Math.max(0.001, white_key))
    // alpha = 1 - luminance, RGB untouched. White → 0, black → 1, mid grey → 0.5.
    steps.push(
      '<feColorMatrix type="matrix" values="' +
      '1 0 0 0 0 ' +
      '0 1 0 0 0 ' +
      '0 0 1 0 0 ' +
      `${-LUMA.r} ${-LUMA.g} ${-LUMA.b} 0 1"/>`
    )
    // Then stretch that alpha so only the top `tol` of the luminance range is soft: everything
    // darker saturates to fully opaque instead of going translucent along with the paper.
    steps.push(
      `<feComponentTransfer><feFuncA type="linear" slope="${(1 / tol).toFixed(3)}" intercept="0"/></feComponentTransfer>`
    )
  }

  if (tint) {
    // Paint every surviving pixel one colour — "make the ink blue" rather than "shift the hue",
    // which is what a hue-rotate would do and would leave a scan's warm paper cast behind.
    steps.push(`<feFlood flood-color="${tint}" result="lyr-tint"/>`)
    steps.push('<feComposite in="lyr-tint" in2="SourceGraphic" operator="in" result="lyr-tinted"/>')
    if (white_key > 0) {
      // Keep the keyed alpha rather than the source's: the flood must not bring the paper back.
      steps.pop()
      steps.push('<feComposite in="lyr-tint" operator="in"/>')
    }
  }

  return steps.join('')
}

/**
 * Ensure a <defs> filter exists for this layer inside `host`, and return the CSS `filter` value
 * to put on the element — including the blur, so callers set one property rather than two that
 * overwrite each other.
 */
export function applyLayerFilter(host, item) {
  const id = layerFilterId(item.asset_id)
  const markup = filterMarkup(item)
  const existing = host.querySelector(`#${id}`)

  if (!markup) {
    if (existing) existing.closest('svg')?.remove()

    return item.blur > 0 ? `blur(${Math.min(2.5, item.blur)}px)` : ''
  }

  let svg = existing?.closest('svg')
  if (!svg) {
    svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
    // The carrier must not take part in layout or catch a pointer — it only holds the filter.
    svg.setAttribute('aria-hidden', 'true')
    svg.style.cssText = 'position:absolute; width:0; height:0; pointer-events:none;'
    host.appendChild(svg)
  }
  svg.innerHTML = `<defs><filter id="${id}" color-interpolation-filters="sRGB">${markup}</filter></defs>`

  const blur = item.blur > 0 ? ` blur(${Math.min(2.5, item.blur)}px)` : ''

  return `url(#${id})${blur}`
}

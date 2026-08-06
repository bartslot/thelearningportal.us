/**
 * map-place-label.js — ONE way to write a place name on a map.
 *
 * There were three: the voyage overview drew a light pill with dark text (a DOM element), the map
 * block's focus cities were deep-red calligraphy with a parchment halo (a MapLibre symbol), and a
 * voyage's waypoint titles were a third thing again. Same idea in every case — "this place, named"
 * — and a teacher switching between them had to re-learn what a label looks like each time.
 *
 * The pill won as the target look — it carries its own contrast, so it reads on parchment, on
 * satellite photography and on a night ground without a per-style colour scheme, and it is the one a
 * class already sees on the itinerary.
 *
 * UNFINISHED: the DOM pins (voyage itinerary) use it; the MAP layers do not yet. Drawing it behind a
 * MapLibre label means `icon-text-fit` with a nine-patch image, and in two attempts the fitted icon
 * wrapped only the note line while the place name rendered outside it — with a fixed anchor as well
 * as a variable one, so it is not the variable-anchor interaction I first assumed. Until that is
 * understood, the map layers keep the placement rules below (which do verify) and plain ink; the
 * tokens here are still the single source of truth for the pins, so the two cannot drift further.
 *
 *   ensurePlacePill(map)                       // register the nine-patch background
 *   map.addLayer({ ..., layout: placeLabelLayout({ font, textField }) })
 */

/**
 * The pill, as numbers. `voyage-tour`'s DOM pins read these too, so the itinerary and the map
 * cannot drift apart the next time one of them is touched.
 */
export const PLACE_LABEL = {
  fill: 'rgba(255,255,255,0.88)',
  ink: '#0f172a',            // slate-900 — the same ink the overview pin uses
  // The cap radius is also the pill's MINIMUM half-height: a nine-patch can stretch but never
  // shrink, so caps taller than a one-line label leave the pill floating around the wrong line
  // (it fitted itself to the note and left the place name outside). 7 is half of a 15px line plus
  // its padding — full-round on one line, a stretched capsule on two.
  radius: 7,
  padX: 7,
  padY: 2,
  weight: 600,
  /** Zoom ramp for the map layers. The DOM pin is a fixed 11px, which is this ramp at its floor. */
  size: ['interpolate', ['linear'], ['zoom'], 2, 11, 6, 15],
}

const PILL_IMAGE_ID = 'place-pill'

/**
 * Register the pill as a stretchable (nine-patch) image, once per map.
 *
 * MapLibre grows a background behind text with `icon-text-fit`, but only if the image says WHICH
 * parts may stretch: the flat middle, never the rounded ends. Hence the stretch/content metadata —
 * without it the corners smear as the label gets longer.
 */
export function ensurePlacePill (map, { id = PILL_IMAGE_ID, ratio = 2 } = {}) {
  if (!map || typeof map.addImage !== 'function') return id
  if (map.hasImage?.(id)) return id

  const r = PLACE_LABEL.radius * ratio
  const size = r * 2 + 2 * ratio          // just wide enough to hold both round ends + a middle strip
  const canvas = typeof document !== 'undefined' ? document.createElement('canvas') : null
  if (!canvas) return id
  canvas.width = size
  canvas.height = r * 2
  const ctx = canvas.getContext('2d')
  if (!ctx) return id

  ctx.fillStyle = PLACE_LABEL.fill
  ctx.beginPath()
  // roundRect is not in older Safari; the two-arc path below draws the same capsule everywhere.
  ctx.moveTo(r, 0)
  ctx.lineTo(size - r, 0)
  ctx.arc(size - r, r, r, -Math.PI / 2, Math.PI / 2)
  ctx.lineTo(r, r * 2)
  ctx.arc(r, r, r, Math.PI / 2, -Math.PI / 2)
  ctx.closePath()
  ctx.fill()

  try {
    map.addImage(id, ctx.getImageData(0, 0, canvas.width, canvas.height), {
      pixelRatio: ratio,
      // Only the middle column/row may stretch — the caps keep their curve at any label length.
      stretchX: [[r, size - r]],
      stretchY: [[r - ratio, r + ratio]],
      content: [r, 0, size - r, r * 2],
    })
  } catch (_) { /* already added by another map on the same style */ }

  return id
}

/**
 * The layout every place label shares.
 *
 * Placement is the part that took the longest to get right, so it lives here rather than being
 * re-derived per layer: labels go BESIDE their dot before above or below it (towns on a coast stack
 * vertically, so the sides are where the room is), they never switch collision off, and a long note
 * wraps instead of running across the map.
 *
 * @param {{ font?: string[], textField: any, size?: any, sortKey?: any }} opts
 */
export function placeLabelLayout ({ font = ['inter'], textField, size = PLACE_LABEL.size, sortKey } = {}) {
  return {
    'text-field': textField,
    'text-font': font,
    'text-size': size,
    // NO pill on the map layers yet — see the note at the top of this file. Placement wins for now.
    'text-variable-anchor': ['left', 'right', 'top-left', 'bottom-left', 'top-right', 'bottom-right', 'top', 'bottom'],
    // Close enough that the name is unmistakably ITS dot's. At 1.05 a label placed on the far side
    // could land beside a neighbour's name — Samarkand's red dot read as belonging to Bukhārā, two
    // labels away. The dot is ~5px; 0.6em at a 14px label clears it by a couple of pixels and no
    // more.
    'text-radial-offset': 0.6,
    'text-justify': 'auto',
    'text-max-width': 9,
    'text-line-height': 1.15,
    'text-padding': 2,
    ...(sortKey ? { 'symbol-sort-key': sortKey } : {}),
  }
}

/**
 * Ink for a map label. Until the pill is drawn behind it (see above), the halo does that job: a
 * light plate on the atlas styles, and light-on-dark handled by the caller's style palette.
 */
export function placeLabelPaint ({ color = PLACE_LABEL.ink, halo = 'rgba(255,255,255,0.85)' } = {}) {
  return { 'text-color': color, 'text-halo-color': halo, 'text-halo-width': 1.6, 'text-halo-blur': 0.3 }
}

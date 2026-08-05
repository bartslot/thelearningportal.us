/**
 * map-annotations.js — teacher-placed map annotations rendered as MapLibre layers.
 *
 * The ONLY place annotation rendering lives. Reused by the lesson composer (editable)
 * and the lesson player (read-only). An annotation is a plain object stored in
 * `scene.config.annotations` (a JSON array). Phase 1 supports a single type:
 *
 *   { type: 'focus', lng: <float>, lat: <float>, label: <string> }
 *
 * A focus city reuses the map's calligraphy (Eagle Lake) style but BIGGER, with a small centred
 * dot — no dark chip, no offset DOM marker. It's drawn with
 * native MapLibre symbol/circle layers (a GeoJSON source `focus-src`), so it aligns to the
 * point exactly and the host can suppress the normal duplicate label for that city.
 *
 * Later phases (arrows, army/person markers) will add more `type`s — the build loop
 * skips unknown types so older clients never crash on data they don't understand.
 *
 *   const anno = renderAnnotations(map, annotations, { editable: true, onChange, onFocusNames })
 *   anno.beginAddFocus(); anno.update(newArray); anno.getFocusNames(); anno.destroy()
 */

import { placeLabelLayout, placeLabelPaint, PLACE_LABEL } from './map-place-label.js'

// Focus-city styling. Kept as constants so the calligraphy look stays consistent and tweakable.
const FOCUS_SRC = 'focus-src'
const FOCUS_LABEL_LAYER = 'focus-label'
const FOCUS_DOT_LAYER = 'focus-dot'
// Same Tolkien-style calligraphy as the map's city labels. It is the STARTING hand only: the host
// map re-fonts these two layers per style (lesson-map.js applyStyle), so Satellite and Night get
// the modern sans instead.
const FOCUS_FONT = ['Eagle Lake']
const FOCUS_LABEL_COLOR = '#7a1f12'      // deep historical red
const FOCUS_HALO_COLOR = '#f3ead6'       // parchment halo
const FOCUS_DOT_RING = '#f3ead6'         // parchment ring around the dot
const FOCUS_CAPITAL_RING = '#f5c518'     // gold ring around a territory's auto-added capital
const FOCUS_LABEL_PLACEHOLDER = 'New place'
const LABEL_MAX_LENGTH = 80

// Zoom-interpolated label size — bigger than the normal city label so the focus city reads as primary.
// Annotations are read from the back of a classroom, but they are notes ON a map, not headlines
// over it: at the old 13–20 four of them covered the province they were describing. Small enough to
// sit inside the coastline they belong to, large enough to project.
const FOCUS_TEXT_SIZE = PLACE_LABEL.size
// The note under a place name — always the app sans, whatever hand the map is written in. A place
// is a NAME and gets the map's own lettering; what happened there is an annotation, and annotations
// are set in type. On the drawn atlases that reads as a caption pencilled under a hand-written
// name; it also keeps the small line legible, where calligraphy at 8px turns to mush.
const NOTE_FONT = ['inter']
const NOTE_SCALE = 0.78

// Zoom-interpolated dot radius — a small centred marker, not the old oversized DOM dot.
const FOCUS_DOT_RADIUS = ['interpolate', ['linear'], ['zoom'], 2, 3, 6, 5]

/**
 * The shared text-field expression: a dual name when `historical` is set
 * (historical name big, "(modern)" smaller) — otherwise just the single label.
 * @returns {Array} a MapLibre `text-field` expression
 */
const NOTE_SEPARATOR = ' — '

const focusTextField = (font = FOCUS_FONT) => ['case',
  ['has', 'historical'],
  ['format',
    ['get', 'historical'], { 'font-scale': 1 },
    '\n(', {},
    ['get', 'label'], {},
    ')', { 'font-scale': 0.72 },
  ],
  // "Leiden — relieved by flood, 1574" is two different things: a PLACE, and a note about it. Set
  // as one run of text they carry equal weight, so four annotations read as four paragraphs and the
  // map fills with prose. Split at the dash: the place name keeps full size and the note drops
  // behind it. The class scans four names; the detail is there when they look closer — and the
  // smaller second line is also what lets four labels fit around a crowded Holland at all.
  ['>', ['index-of', NOTE_SEPARATOR, ['get', 'label']], 0],
  ['format',
    ['slice', ['get', 'label'], 0, ['index-of', NOTE_SEPARATOR, ['get', 'label']]],
    { 'font-scale': 1, 'text-font': ['literal', font] },
    '\n', {},
    ['slice', ['get', 'label'], ['+', ['index-of', NOTE_SEPARATOR, ['get', 'label']], 3]],
    { 'font-scale': NOTE_SCALE, 'text-font': ['literal', NOTE_FONT] },
  ],
  ['get', 'label'],
]

/**
 * A focus label is a place label like any other — the SHARED one (map-place-label.js): a light pill
 * with dark ink, the same object the voyage itinerary pins. It used to be deep-red calligraphy with
 * a parchment halo, which meant a teacher met three different-looking labels across three screens
 * that all mean "this place, named".
 *
 * What stays local to focus cities is what is written (place + note) and who wins when the map is
 * crowded (a capital outranks an ordinary stop).
 */
export const focusLabelLayout = (font = FOCUS_FONT) => placeLabelLayout({
  font,
  textField: focusTextField(font),
  sortKey: ['case', ['boolean', ['get', 'capital'], false], 0, 1],
})

/**
 * Coerce one focus annotation into a clean shape; returns null for anything malformed.
 * Tolerates the extended fields (`historical`, `capital`) so dual-name + auto-capital markers
 * survive a round-trip — they're normalised here, not stripped.
 * @param {any} a
 * @returns {{type:'focus', lng:number, lat:number, label:string, historical:string|null, capital:boolean}|null}
 */
function sanitizeFocus (a) {
  if (!a || a.type !== 'focus') return null
  const lng = Number(a.lng)
  const lat = Number(a.lat)
  if (!Number.isFinite(lng) || !Number.isFinite(lat)) return null
  const label = String(a.label ?? '').trim().slice(0, LABEL_MAX_LENGTH) || FOCUS_LABEL_PLACEHOLDER
  const historicalRaw = typeof a.historical === 'string' ? a.historical.trim().slice(0, LABEL_MAX_LENGTH) : ''
  const historical = historicalRaw || null
  return { type: 'focus', lng, lat, label, historical, capital: a.capital === true }
}

/**
 * Build a GeoJSON FeatureCollection from the focus annotations. Each feature carries the
 * display fields the layers read: `label`, `historical` (omitted when absent so `['has','historical']`
 * is false), and `capital`.
 * @param {Array<object>} annotations
 * @returns {{collection: object, names: string[]}}
 */
function buildFocusData (annotations) {
  const features = []
  const names = []
  ;(Array.isArray(annotations) ? annotations : []).forEach((raw) => {
    const a = sanitizeFocus(raw)
    if (!a) return
    const properties = { label: a.label, capital: a.capital }
    // Only set `historical` when present so `['has','historical']` cleanly drives the dual-name path.
    if (a.historical) properties.historical = a.historical
    features.push({
      type: 'Feature',
      geometry: { type: 'Point', coordinates: [a.lng, a.lat] },
      properties,
    })
    names.push(a.label)
  })
  return { collection: { type: 'FeatureCollection', features }, names }
}

/**
 * Render annotations as MapLibre layers on top of `map`.
 *
 * @param {import('maplibre-gl').Map} map
 * @param {Array<object>} annotations  initial annotation array
 * @param {{ editable?: boolean, onChange?: ((annotations: Array<object>) => void) | null, onFocusNames?: ((names: string[]) => void) | null }} [options]
 * @returns {{ update: Function, beginAddFocus: Function, getFocusNames: Function, destroy: Function }}
 */
export function renderAnnotations (map, annotations, { editable = false, onChange = null, onFocusNames = null } = {}) {
  // Working copy — never mutate the caller's array in place.
  let current = Array.isArray(annotations) ? annotations.slice() : []
  let focusNames = []
  let addPending = false

  const emitChange = () => {
    if (typeof onChange === 'function') onChange(current.slice())
  }

  const emitFocusNames = () => {
    if (typeof onFocusNames === 'function') onFocusNames(focusNames.slice())
  }

  // Add the two focus layers ONCE (guarded): `focus-label` is the name, `focus-dot` a small centred
  // marker
  // (circle layers auto-centre on the point, which fixes the old DOM-dot misalignment).
  const ensureLayers = () => {
    if (!map.getSource(FOCUS_SRC)) {
      map.addSource(FOCUS_SRC, { type: 'geojson', data: { type: 'FeatureCollection', features: [] } })
    }

    if (!map.getLayer(FOCUS_LABEL_LAYER)) {
      map.addLayer({
        id: FOCUS_LABEL_LAYER, type: 'symbol', source: FOCUS_SRC,
        layout: focusLabelLayout(),
        paint: placeLabelPaint({ color: FOCUS_LABEL_COLOR, halo: FOCUS_HALO_COLOR }),
      })
    }

    if (!map.getLayer(FOCUS_DOT_LAYER)) {
      map.addLayer({
        id: FOCUS_DOT_LAYER, type: 'circle', source: FOCUS_SRC,
        paint: {
          'circle-radius': FOCUS_DOT_RADIUS,
          'circle-color': FOCUS_LABEL_COLOR,
          // Capital cities get a gold ring; everyone else a parchment ring.
          'circle-stroke-color': ['case', ['boolean', ['get', 'capital'], false], FOCUS_CAPITAL_RING, FOCUS_DOT_RING],
          'circle-stroke-width': ['case', ['boolean', ['get', 'capital'], false], 2, 1],
        },
      })
    }
  }

  const render = () => {
    ensureLayers()
    const { collection, names } = buildFocusData(current)
    focusNames = names
    const src = map.getSource(FOCUS_SRC)
    if (src) src.setData(collection)
    emitFocusNames()
  }

  render()

  return {
    /**
     * Replace the rendered set from a fresh array (e.g. after a server round-trip).
     * @param {Array<object>} newAnnotations
     */
    update (newAnnotations) {
      current = Array.isArray(newAnnotations) ? newAnnotations.slice() : []
      render()
    },

    /**
     * Enter "drop a focus city" mode: the next map click adds a focus annotation
     * at the clicked point, then re-renders and notifies the host.
     */
    beginAddFocus () {
      if (!editable || addPending) return
      addPending = true
      map.getCanvas().style.cursor = 'crosshair'
      map.once('click', (e) => {
        addPending = false
        map.getCanvas().style.cursor = ''
        current = [
          ...current,
          { type: 'focus', lng: e.lngLat.lng, lat: e.lngLat.lat, label: FOCUS_LABEL_PLACEHOLDER },
        ]
        render()
        emitChange()
      })
    },

    /** Current focus display names (each focus item's `label`), in render order. */
    getFocusNames () {
      return focusNames.slice()
    },

    /** True while a pin-drop click is pending — lets other click handlers stand down. */
    isAddingFocus () {
      return addPending
    },

    /** Remove the focus layers + source (call before discarding the map). */
    destroy () {
      [FOCUS_DOT_LAYER, FOCUS_LABEL_LAYER].forEach((id) => {
        try { if (map.getLayer(id)) map.removeLayer(id) } catch (_) {}
      })
      try { if (map.getSource(FOCUS_SRC)) map.removeSource(FOCUS_SRC) } catch (_) {}
    },
  }
}

// Exported helper so the host (or future tooling) can sanitize focus items consistently.
export { sanitizeFocus }

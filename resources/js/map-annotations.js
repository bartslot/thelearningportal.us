/**
 * map-annotations.js — teacher-placed map annotations rendered as MapLibre layers.
 *
 * The ONLY place annotation rendering lives. Reused by the lesson composer (editable)
 * and the lesson player (read-only). An annotation is a plain object stored in
 * `scene.config.annotations` (a JSON array). Phase 1 supports a single type:
 *
 *   { type: 'focus', lng: <float>, lat: <float>, label: <string> }
 *
 * A focus city reuses the map's calligraphy (Eagle Lake) style but BIGGER, with a drop
 * shadow and a small centred dot — no dark chip, no offset DOM marker. It's drawn with
 * native MapLibre symbol/circle layers (a GeoJSON source `focus-src`), so it aligns to the
 * point exactly and the host can suppress the normal duplicate label for that city.
 *
 * Later phases (arrows, army/person markers) will add more `type`s — the build loop
 * skips unknown types so older clients never crash on data they don't understand.
 *
 *   const anno = renderAnnotations(map, annotations, { editable: true, onChange, onFocusNames })
 *   anno.beginAddFocus(); anno.update(newArray); anno.getFocusNames(); anno.destroy()
 */

// Focus-city styling. Kept as constants so the calligraphy look stays consistent and tweakable.
const FOCUS_SRC = 'focus-src'
const FOCUS_SHADOW_LAYER = 'focus-shadow'
const FOCUS_LABEL_LAYER = 'focus-label'
const FOCUS_DOT_LAYER = 'focus-dot'
const FOCUS_FONT = ['Eagle Lake']        // same Tolkien-style calligraphy as the map's city labels
const FOCUS_SHADOW_COLOR = '#1c140b'     // near-black ink, offset = drop shadow
const FOCUS_LABEL_COLOR = '#7a1f12'      // deep historical red
const FOCUS_HALO_COLOR = '#f3ead6'       // parchment halo
const FOCUS_DOT_RING = '#f3ead6'         // parchment ring around the dot
const FOCUS_CAPITAL_RING = '#f5c518'     // gold ring around a territory's auto-added capital
const FOCUS_LABEL_PLACEHOLDER = 'New place'
const LABEL_MAX_LENGTH = 80

// Zoom-interpolated label size — bigger than the normal city label so the focus city reads as primary.
const FOCUS_TEXT_SIZE = ['interpolate', ['linear'], ['zoom'], 2, 15, 6, 22]
// Zoom-interpolated dot radius — a small centred marker, not the old oversized DOM dot.
const FOCUS_DOT_RADIUS = ['interpolate', ['linear'], ['zoom'], 2, 3, 6, 5]

/**
 * The shared text-field expression: a dual name when `historical` is set
 * (historical name big, "(modern)" smaller) — otherwise just the single label.
 * @returns {Array} a MapLibre `text-field` expression
 */
const focusTextField = () => ['case',
  ['has', 'historical'],
  ['format',
    ['get', 'historical'], { 'font-scale': 1 },
    '\n(', {},
    ['get', 'label'], {},
    ')', { 'font-scale': 0.72 },
  ],
  ['get', 'label'],
]

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

  // Add the three focus layers ONCE (guarded). `focus-shadow` is the offset dark copy (drop
  // shadow), `focus-label` the deep-red calligraphy on top, `focus-dot` a small centred marker
  // (circle layers auto-centre on the point, which fixes the old DOM-dot misalignment).
  const ensureLayers = () => {
    if (!map.getSource(FOCUS_SRC)) {
      map.addSource(FOCUS_SRC, { type: 'geojson', data: { type: 'FeatureCollection', features: [] } })
    }

    if (!map.getLayer(FOCUS_SHADOW_LAYER)) {
      map.addLayer({
        id: FOCUS_SHADOW_LAYER, type: 'symbol', source: FOCUS_SRC,
        layout: {
          'text-field': focusTextField(),
          'text-font': FOCUS_FONT,
          'text-size': FOCUS_TEXT_SIZE,
          'text-anchor': 'center',
          'text-allow-overlap': true,
        },
        paint: {
          'text-color': FOCUS_SHADOW_COLOR,
          'text-translate': [1.6, 1.6],
        },
      })
    }

    if (!map.getLayer(FOCUS_LABEL_LAYER)) {
      map.addLayer({
        id: FOCUS_LABEL_LAYER, type: 'symbol', source: FOCUS_SRC,
        layout: {
          'text-field': focusTextField(),
          'text-font': FOCUS_FONT,
          'text-size': FOCUS_TEXT_SIZE,
          'text-anchor': 'center',
          'text-allow-overlap': true,
        },
        paint: {
          'text-color': FOCUS_LABEL_COLOR,
          'text-halo-color': FOCUS_HALO_COLOR,
          'text-halo-width': 1.6,
        },
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

    /** Remove the focus layers + source (call before discarding the map). */
    destroy () {
      [FOCUS_DOT_LAYER, FOCUS_LABEL_LAYER, FOCUS_SHADOW_LAYER].forEach((id) => {
        try { if (map.getLayer(id)) map.removeLayer(id) } catch (_) {}
      })
      try { if (map.getSource(FOCUS_SRC)) map.removeSource(FOCUS_SRC) } catch (_) {}
    },
  }
}

// Exported helper so the host (or future tooling) can sanitize focus items consistently.
export { sanitizeFocus }

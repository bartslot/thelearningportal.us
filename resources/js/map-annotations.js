/**
 * map-annotations.js — teacher-placed map annotations rendered as MapLibre markers.
 *
 * The ONLY place annotation rendering lives. Reused by the lesson composer (editable)
 * and the lesson player (read-only). An annotation is a plain object stored in
 * `scene.config.annotations` (a JSON array). Phase 1 supports a single type:
 *
 *   { type: 'focus', lng: <float>, lat: <float>, label: <string> }
 *
 * Later phases (arrows, army/person markers) will add more `type`s — the render loop
 * skips unknown types so older clients never crash on data they don't understand.
 *
 *   const anno = renderAnnotations(map, annotations, { editable: true, onChange })
 *   anno.beginAddFocus(); anno.update(newArray); anno.destroy()
 */
import maplibregl from 'maplibre-gl'

// Focus-city dot styling. Kept as constants so the look stays consistent and tweakable.
const FOCUS_DOT_SIZE_PX = 18
const FOCUS_DOT_FILL = '#c0392b'        // matches the selected-polity red in lesson-map.js
const FOCUS_DOT_RING = '#ffffff'
const FOCUS_LABEL_PLACEHOLDER = 'New place'
const LABEL_MAX_LENGTH = 80

/**
 * Build the custom HTML element for a focus-city marker: a red dot with a white ring
 * and a bold label chip beside it.
 *
 * @param {string} label
 * @returns {HTMLElement}
 */
function buildFocusElement (label) {
  const wrap = document.createElement('div')
  wrap.style.display = 'flex'
  wrap.style.alignItems = 'center'
  wrap.style.gap = '6px'
  wrap.style.pointerEvents = 'auto'
  wrap.style.whiteSpace = 'nowrap'

  const dot = document.createElement('div')
  dot.style.width = `${FOCUS_DOT_SIZE_PX}px`
  dot.style.height = `${FOCUS_DOT_SIZE_PX}px`
  dot.style.borderRadius = '50%'
  dot.style.background = FOCUS_DOT_FILL
  dot.style.border = `3px solid ${FOCUS_DOT_RING}`
  dot.style.boxShadow = '0 1px 6px rgba(0,0,0,0.45)'
  dot.style.flex = '0 0 auto'

  const chip = document.createElement('span')
  chip.textContent = label || FOCUS_LABEL_PLACEHOLDER
  chip.style.font = '700 13px/1.2 system-ui, sans-serif'
  chip.style.color = '#ffffff'
  chip.style.background = 'rgba(15,23,42,0.78)'   // dark translucent pill (slate-900-ish)
  chip.style.padding = '2px 8px'
  chip.style.borderRadius = '9999px'
  chip.style.textShadow = '0 1px 2px rgba(0,0,0,0.6)'

  wrap.appendChild(dot)
  wrap.appendChild(chip)
  return wrap
}

/**
 * Coerce one focus annotation into a clean shape; returns null for anything malformed.
 * @param {any} a
 * @returns {{type:'focus', lng:number, lat:number, label:string}|null}
 */
function sanitizeFocus (a) {
  if (!a || a.type !== 'focus') return null
  const lng = Number(a.lng)
  const lat = Number(a.lat)
  if (!Number.isFinite(lng) || !Number.isFinite(lat)) return null
  const label = String(a.label ?? '').trim().slice(0, LABEL_MAX_LENGTH) || FOCUS_LABEL_PLACEHOLDER
  return { type: 'focus', lng, lat, label }
}

/**
 * Render annotations as MapLibre markers on top of `map`.
 *
 * @param {import('maplibre-gl').Map} map
 * @param {Array<object>} annotations  initial annotation array
 * @param {{ editable?: boolean, onChange?: ((annotations: Array<object>) => void) | null }} [options]
 * @returns {{ update: Function, beginAddFocus: Function, destroy: Function }}
 */
export function renderAnnotations (map, annotations, { editable = false, onChange = null } = {}) {
  // Working copy — never mutate the caller's array in place.
  let current = Array.isArray(annotations) ? annotations.slice() : []
  /** @type {import('maplibre-gl').Marker[]} */
  let markers = []
  let addPending = false

  const emitChange = () => {
    if (typeof onChange === 'function') onChange(current.slice())
  }

  const clearMarkers = () => {
    markers.forEach((m) => { try { m.remove() } catch (_) {} })
    markers = []
  }

  const render = () => {
    clearMarkers()
    current.forEach((anno, index) => {
      // Skip unknown types so future arrow/marker data never crashes phase-1 clients.
      if (!anno || anno.type !== 'focus') return
      const lng = Number(anno.lng)
      const lat = Number(anno.lat)
      if (!Number.isFinite(lng) || !Number.isFinite(lat)) return

      const element = buildFocusElement(anno.label)
      const marker = new maplibregl.Marker({ element, draggable: editable, anchor: 'center' })
        .setLngLat([lng, lat])
        .addTo(map)

      if (editable) {
        marker.on('dragend', () => {
          const pos = marker.getLngLat()
          // Immutable update of the moved item; keep everything else untouched.
          current = current.map((item, i) =>
            i === index ? { ...item, lng: pos.lng, lat: pos.lat } : item
          )
          emitChange()
        })
      }

      markers.push(marker)
    })
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

    /** Remove every marker (call before discarding the map). */
    destroy () {
      clearMarkers()
    },
  }
}

// Exported helper so the host (or future tooling) can sanitize focus items consistently.
export { sanitizeFocus }

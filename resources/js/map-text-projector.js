/**
 * Projector for map-pinned text labels: converts lng/lat ⇄ percentages of `hostEl`.
 *
 * The text-overlay host does not necessarily share the map container's box, so both directions go
 * via page coordinates rather than assuming the two rectangles line up.
 *
 * Shared by every renderer that puts a MapLibre map under the text layer — the standalone map scene
 * and the voyage route map. It lived inside lesson-map.js, which is why "pin to map" silently did
 * nothing on voyage scenes: they had a map on screen but no projector, so the pin control had no
 * way to turn a screen position into a place.
 *
 * @param {object} map     live MapLibre instance
 * @param {HTMLElement} hostEl  the text-overlay host the percentages are relative to
 */
export function mapTextProjector(map, hostEl) {
  return {
    project: (lng, lat) => {
      try {
        const pt = map.project([lng, lat])
        const mr = map.getContainer().getBoundingClientRect()
        const hr = hostEl.getBoundingClientRect()
        if (!hr.width || !hr.height) return null
        return {
          x: ((mr.left + pt.x - hr.left) / hr.width) * 100,
          y: ((mr.top + pt.y - hr.top) / hr.height) * 100,
        }
      } catch (_) { return null }
    },
    unproject: (xPct, yPct) => {
      try {
        const mr = map.getContainer().getBoundingClientRect()
        const hr = hostEl.getBoundingClientRect()
        const ll = map.unproject([
          hr.left + (xPct / 100) * hr.width - mr.left,
          hr.top + (yPct / 100) * hr.height - mr.top,
        ])
        return { lng: ll.lng, lat: ll.lat }
      } catch (_) { return null }
    },
  }
}

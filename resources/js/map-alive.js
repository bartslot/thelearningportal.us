/**
 * map-alive.js — one shared answer to "is this MapLibre map still usable?".
 *
 * The terrain decorators (scatter, forests, mountains, volcanoes) fetch manifests, sprites and
 * geojson before touching the map. If the map is torn down during those awaits — a scene switch,
 * a style swap, the wizard re-mounting its preview — any later map.getSource()/addLayer() throws
 * "Cannot read properties of undefined (reading 'getSource')" from inside MapLibre, because
 * remove() clears map.style. Check this after every await, before touching the map again.
 */
export const mapGone = (map) => !map || map._removed || !map.style

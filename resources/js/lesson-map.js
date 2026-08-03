/**
 * lesson-map.js — a self-contained MapLibre "map block" for lessons.
 *
 * Renders the historical atlas (Natural-Earth land + Cliopatria polity borders) at a given year
 * and fits/highlights one polity by its Wikidata QID. Reused by the lesson composer preview and
 * the lesson player. Mirrors the Time-Map's sources/filter so the look is consistent.
 *
 *   const map = renderLessonMap(el, { qid: 'Q12544', year: 900, interactive: true })
 *   map.setYear(1200); map.destroy()
 */
import maplibregl from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'
import { addMountainLayer } from './map-mountains.js'
import { addForestLayer } from './map-forests.js'
import { addScatterLayer } from './map-scatter.js'
import { addVolcanoLayer } from './map-volcanoes.js'
import { renderAnnotations } from './map-annotations.js'
import { mapTextProjector } from './map-text-projector.js'
import { SATELLITE_SOURCE, DEM_SOURCE, MAX_RELIEF } from './map-imagery.js'
import { OCEAN_SOURCE, oceanLayers, createOcean } from './map-ocean.js'

const PALETTE = {
  land: '#f3ead6',
  water: '#d8e9f3',
  fill: '#c9b79c',
  highlight: '#c0392b',      // selected-polity border (red)
  highlightFill: '#c0392b',  // selected-polity red wash, painted over the terrain (~0.3 opacity)
  line: '#5b4a36',
  river: '#6a8fa0',
  city: '#3a2c1a',
  cityHalo: '#f3ead6',
  coast: '#241a10',        // near-black ink shore (Tolkien-chart look)
  coastShadow: '#8a7a5e',
}

// Map styles — the same five the Time-Map's palette offers (window.__applyMapStyle), reduced to
// the layers the lesson map actually has. `terrain` hides the ink hill/forest glyphs on the dark
// Night style (they vanish on a dark ground). Applied by applyStyle(); the block's chosen style
// rides on scene config.map_style and renders identically in the wizard preview and the player.
//
// `imagery` swaps the drawn atlas for real satellite imagery: the raster + hillshade layers come on
// and the painted ground (land fill, sea grid, coast shadow, lakes, ink glyphs) goes off, because
// the photo already shows all of it. Labels flip to light-on-dark so they stay readable over it.
const MAP_STYLES = {
  'soft-atlas': { land: '#efe6d0', water: '#c7d4c6', coast: '#2b2013', coastShadow: '#9fb0b4', line: '#6b5640', river: '#6a8fa0', text: '#3b3326', halo: '#f3ead6', grid: '#93a18f', terrain: true },
  'antique': { land: '#e8d6ac', water: '#dcdcba', coast: '#2a1d0c', coastShadow: '#8f7d5c', line: '#4a3420', river: '#8a9aa0', text: '#3a2c1a', halo: '#ecdcb8', grid: '#9b9277', terrain: true },
  'pen-ink': { land: '#e6d6ad', water: '#dedec0', coast: '#211809', coastShadow: '#574631', line: '#3a2c1c', river: '#6a7c74', text: '#33271a', halo: '#efe2c4', grid: '#8f8c6e', terrain: true },
  'night': { land: '#1b2230', water: '#0f1420', coast: '#aeb9d4', coastShadow: '#070b12', line: '#8a99b8', river: '#3a5570', text: '#e6ecf7', halo: '#10151f', grid: '#3a5570', terrain: false },
  // Dark label ink on a bright halo, like the atlas styles — over a photo that runs from dark ocean
  // to bright desert, that pairing is the one that stays readable everywhere (and it survives a
  // lesson's own dark label colour, which would vanish into a dark halo).
  'satellite': { imagery: true, land: '#26331d', water: '#08131f', coast: '#f6efdc', coastShadow: '#000000', line: '#ffd9a0', river: '#8fc3e8', text: '#241a10', halo: '#f2e9d4', grid: '#7f9ab0', terrain: false },
}
const DEFAULT_STYLE = 'soft-atlas'

// Cities valid at `year` (gazetteer entries carry valid_from/valid_to; missing = always valid).
const cityFilter = (year) => ['all',
  ['<=', ['to-number', ['coalesce', ['get', 'valid_from'], -99999]], year],
  ['>=', ['to-number', ['coalesce', ['get', 'valid_to'], 99999]], year],
]

// Cliopatria polities valid at `year` (Type=POLITY, skip composite "(…)" names, within lifespan).
const polityFilter = (year) => ['all',
  ['==', ['get', 'Type'], 'POLITY'],
  ['!=', ['slice', ['get', 'Name'], 0, 1], '('],
  ['<=', ['to-number', ['get', 'FromYear']], year],
  ['>=', ['to-number', ['get', 'ToYear']], year],
]

/**
 * @param {HTMLElement} el
 * @param {{ qid?: string, year?: number, interactive?: boolean }} opts
 */
export function renderLessonMap (el, opts = {}) {
  // `terrain` = the drawn ink hill/forest glyphs. `relief` = real 3D ground from the height map
  // (0 = flat). Two different things that both mean "terrain" in English, hence the two names.
  const { qid = null, interactive = true, annotations = [], editable = false, onAnnotationsChange = null, onPolityClick = null, projection = 'mercator', style = DEFAULT_STYLE, terrain = true, relief = 0 } = opts
  // Voyage maps can hide anachronistic detail (modern city dots/labels + political borders that
  // didn't exist yet) and pin their own period place labels. Defaults keep the normal atlas.
  const { showCities = true, showBorders = true } = opts
  let layerToggles = { cities: showCities, borders: showBorders }
  let placeLabels = Array.isArray(opts.labels) ? opts.labels : []
  // Coerce — the inspector saves the year through a JSON config, so it can arrive as a string.
  let year = Number(opts.year)
  if (!Number.isFinite(year)) year = 1600

  // Feature `id` (index) lets us drive per-label reveal via feature-state → text-opacity, so a
  // landfall name can FADE IN when the ship arrives instead of all names showing at once.
  const labelsFC = (arr) => ({
    type: 'FeatureCollection',
    features: (arr || [])
      .filter((l) => l && l.text && Number.isFinite(Number(l.lng)) && Number.isFinite(Number(l.lat)))
      .map((l, i) => ({ type: 'Feature', id: i, properties: { name: String(l.text) }, geometry: { type: 'Point', coordinates: [Number(l.lng), Number(l.lat)] } })),
  })
  // Indices of labels that have been "arrived at" — kept so re-setting the source data (e.g. on a
  // map-detail toggle) doesn't wipe the reveal (setData clears feature-state).
  const revealedLabels = new Set()
  let revealAll = false
  // Re-assert the shown state onto the source (feature-state is wiped by setData).
  const applyLabelReveal = () => {
    try {
      const n = (placeLabels || []).length
      for (let i = 0; i < n; i++) {
        const shown = revealAll || revealedLabels.has(i)
        if (shown) map.setFeatureState({ source: 'lesson-labels', id: i }, { shown: true })
      }
    } catch (_) { /* source not ready */ }
  }

  const map = new maplibregl.Map({
    container: el,
    interactive,
    attributionControl: false,
    style: {
      version: 8,
      // 2D flat (mercator) vs 3D globe (MapLibre v5) — set on the style at init so the map starts
      // in the right projection. Calling setProjection mid-load disrupts tile/layer loading.
      projection: { type: projection },
      glyphs: `${location.origin}/fonts/{fontstack}/{range}.pbf`, // calligraphy labels (see build-glyphs.mjs)
      sources: {
        land: { type: 'vector', tiles: [`${location.origin}/land-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        graticule: { type: 'geojson', data: `${location.origin}/timemap/graticule.geojson` },
        lakes: { type: 'vector', tiles: [`${location.origin}/lake-tiles/{z}/{x}/{y}.pbf`], maxzoom: 6 },
        rivers: { type: 'vector', tiles: [`${location.origin}/river-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        cities: { type: 'vector', tiles: [`${location.origin}/city-tiles/{z}/{x}/{y}.pbf`], maxzoom: 6 },
        cliopatria: {
          type: 'vector',
          tiles: [`${location.origin}/cliopatria-tiles/{z}/{x}/{y}.pbf`],
          maxzoom: 4,
          promoteId: { boundaries: 'Wikidata' },
        },
        // True coastline for the bold shore line + its southern drop-shadow.
        coastline: { type: 'geojson', data: `${location.origin}/timemap/coastline.geojson` },
        // Satellite style only — tiles are requested lazily, so the other styles pay nothing for it.
        satellite: SATELLITE_SOURCE,
        // The shape of the sea, for the moving water that covers Blue Marble's painted ocean
        // depths. Empty until the Satellite style is picked — see map-ocean.js.
        ocean: OCEAN_SOURCE,
        // Height map behind the 3D terrain (`relief`). Only fetched once terrain is switched on.
        dem: DEM_SOURCE,
        // Teacher-authored period place labels (voyages) — filled via setLabels()/the `labels` opt.
        'lesson-labels': { type: 'geojson', data: labelsFC(placeLabels) },
      },
      layers: [
        { id: 'bg', type: 'background', paint: { 'background-color': PALETTE.water } },
        // Real satellite ground (Satellite style only — hidden otherwise, so no tiles are fetched).
        // Sits directly on the background: everything else in the atlas draws over it.
        { id: 'satellite', type: 'raster', source: 'satellite', layout: { visibility: 'none' }, paint: { 'raster-fade-duration': 250 } },
        // Moving water over that photographed ocean — Satellite style only, straight on top of the
        // imagery so the rest of the atlas still draws above it.
        ...oceanLayers(),
        // Sketched sea grid (old-chart graticule), water-only (clipped at build time), beneath the coast/land.
        { id: 'graticule', type: 'line', source: 'graticule', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#9b9277', 'line-width': 0.55, 'line-opacity': 0.5 } },
        // Coast drop-shadow: thick coastline shifted DOWN, beneath the land fill — peeks out only on
        // south-facing shores for relief.
        { id: 'coast-shadow', type: 'line', source: 'coastline', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': PALETTE.coastShadow, 'line-width': 2.4, 'line-translate': [0, 2], 'line-blur': 0.4 } },
        { id: 'land', type: 'fill', source: 'land', 'source-layer': 'land', paint: { 'fill-color': PALETTE.land } },
        // Inland lakes — water fill over land, beneath rivers (rivers feed them).
        { id: 'lakes', type: 'fill', source: 'lakes', 'source-layer': 'lakes', paint: { 'fill-color': PALETTE.water, 'fill-outline-color': PALETTE.river } },
        {
          id: 'rivers', type: 'line', source: 'rivers', 'source-layer': 'rivers',
          paint: {
            'line-color': PALETTE.river,
            'line-opacity': 0.7,
            // Thicker for major rivers (low scalerank).
            'line-width': ['interpolate', ['linear'], ['to-number', ['coalesce', ['get', 'scalerank'], 6]], 1, 1.4, 6, 0.4],
          },
        },
        // Bold coast outline — confident ink shore (Tolkien-chart hand-drawn look), above
        // land/lakes/rivers, below the political borders. Zoom-scaled so the shore stays a strong
        // ink line at every zoom instead of a hairline that fades out when zoomed out.
        { id: 'coast-bold', type: 'line', source: 'coastline', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': PALETTE.coast, 'line-width': ['interpolate', ['linear'], ['zoom'], 1, 1.3, 4, 2.0, 7, 3.2] } },
        {
          // No fill overlay: the selected polity is shown as an amber RING; other territories
          // are just faint 30%-opacity borders so the terrain reads through.
          id: 'boundaries-line', type: 'line', source: 'cliopatria', 'source-layer': 'boundaries',
          filter: polityFilter(year),
          layout: { 'line-join': 'round' },
          paint: {
            'line-color': ['case', ['boolean', ['feature-state', 'highlight'], false], PALETTE.highlight, PALETTE.line],
            'line-width': ['case', ['boolean', ['feature-state', 'highlight'], false], 2.6, 0.6],
            'line-opacity': ['case', ['boolean', ['feature-state', 'highlight'], false], 1, 0.3],
          },
        },
        {
          id: 'city-dots', type: 'circle', source: 'cities', 'source-layer': 'cities',
          filter: cityFilter(year),
          paint: {
            'circle-radius': ['interpolate', ['linear'], ['zoom'], 2, 1.6, 6, 3.5],
            'circle-color': PALETTE.city,
            'circle-stroke-color': PALETTE.cityHalo,
            'circle-stroke-width': 1,
            'circle-opacity': 0.9,
          },
        },
        {
          id: 'city-labels', type: 'symbol', source: 'cities', 'source-layer': 'cities',
          filter: cityFilter(year),
          layout: {
            'text-field': ['get', 'name'],
            'text-size': ['interpolate', ['linear'], ['zoom'], 2, 9, 6, 13],
            'text-anchor': 'left', 'text-offset': [0.6, 0], 'text-optional': true,
            'text-font': ['Eagle Lake'], // Tolkien-style calligraphy for city names
            'text-letter-spacing': 0.02,
          },
          paint: {
            'text-color': PALETTE.city,
            'text-halo-color': PALETTE.cityHalo,
            'text-halo-width': 1.4,
          },
        },
        // Teacher-authored place labels — above the atlas labels, same Tolkien calligraphy but
        // bolder (these are the point of a voyage map). Coloured by applyStyle().
        {
          id: 'lesson-labels', type: 'symbol', source: 'lesson-labels',
          layout: {
            'text-field': ['get', 'name'],
            'text-size': ['interpolate', ['linear'], ['zoom'], 2, 12, 6, 17],
            'text-font': ['Eagle Lake'],
            'text-anchor': 'center',
            'text-letter-spacing': 0.03,
            'text-allow-overlap': true,   // it's THE point of a voyage map — never let it collide away
            'text-ignore-placement': true,
          },
          paint: {
            'text-color': PALETTE.city,
            'text-halo-color': PALETTE.cityHalo,
            'text-halo-width': 2,
            // Hidden until "arrived at" (feature-state shown), then fades in.
            'text-opacity': ['case', ['boolean', ['feature-state', 'shown'], false], 1, 0],
            'text-opacity-transition': { duration: 650, delay: 0 },
          },
        },
      ],
    },
    center: [8.23, 46.8],
    zoom: 3,
    maxZoom: 6,
  })

  if (interactive) {
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right')
  }

  // The moving sea. Idle until applyStyle asks for it, which only the Satellite style does.
  const ocean = createOcean(map, { coastlineUrl: `${location.origin}/timemap/coastline.geojson` })

  // Highlight + fit to the target polity once tiles for this area have loaded.
  // `activeQid` is mutable so a territory can be re-linked in place (setPolity) without
  // tearing down and rebuilding the whole map — a remount blinks and yanks the camera.
  let activeQid = qid
  let highlighted = null
  let didFit = false
  const setHighlight = (id, on) => {
    if (!id) return
    map.setFeatureState({ source: 'cliopatria', sourceLayer: 'boundaries', id }, { highlight: on })
  }

  // Bounding box (+ area) of a polygon's outer ring.
  const ringBox = (ring) => {
    let minX = 180, minY = 90, maxX = -180, maxY = -90
    for (const c of ring) {
      const x = c[0], y = c[1]
      if (x < minX) minX = x; if (x > maxX) maxX = x
      if (y < minY) minY = y; if (y > maxY) maxY = y
    }
    return { minX, minY, maxX, maxY, area: (maxX - minX) * (maxY - minY) }
  }

  const fitToPolity = () => {
    if (!activeQid) return
    const feats = map.querySourceFeatures('cliopatria', {
      sourceLayer: 'boundaries',
      filter: ['==', ['get', 'Wikidata'], activeQid],
    })
    if (!feats.length) return

    // Highlight every matched part (once).
    if (highlighted !== activeQid) {
      setHighlight(highlighted, false)
      setHighlight(activeQid, true)
      highlighted = activeQid
    }

    // Fit to the LARGEST polygon part so far-flung overseas territories don't zoom the map
    // out to the whole globe (e.g. France 1815–1830 still carried Guiana in South America).
    if (didFit) return
    const parts = []
    feats.forEach((f) => {
      const g = f.geometry
      if (!g) return
      if (g.type === 'Polygon' && g.coordinates[0]) parts.push(ringBox(g.coordinates[0]))
      else if (g.type === 'MultiPolygon') g.coordinates.forEach((poly) => poly[0] && parts.push(ringBox(poly[0])))
    })
    if (!parts.length) return
    const b = parts.reduce((a, p) => (p.area > a.area ? p : a))
    if (b.minX > b.maxX || b.minY > b.maxY) return
    // NOTE: map.fitBounds() is unreliable under the globe projection (it can solve to a wildly wrong
    // centre — e.g. snapping Europe to South America). Compute centre + zoom ourselves and easeTo,
    // which behaves the same in both projections.
    const spanX = Math.max(0.4, b.maxX - b.minX)
    const spanY = Math.max(0.4, b.maxY - b.minY)
    const center = [(b.minX + b.maxX) / 2, (b.minY + b.maxY) / 2]
    const zoom = Math.min(6, Math.max(1.6, Math.log2(300 / Math.max(spanX, spanY * 1.7))))
    map.easeTo({ center, zoom, duration: 800 })
    didFit = true
  }

  // Does a polity overlap the current viewport? Uses the polity's BOUNDING BOX vs the map
  // bounds — not queryRenderedFeatures on the border line, which misses a large polity whose
  // interior fills the screen but whose borders sit off-screen (that false-negative made the
  // camera jump when picking a big in-view territory). Empty (tiles not loaded for a far,
  // off-screen polity) → treat as "in view" so we never yank the camera on an unlocatable pick.
  const polityInView = (id) => {
    if (!id) return true
    const feats = map.querySourceFeatures('cliopatria', {
      sourceLayer: 'boundaries',
      filter: ['==', ['get', 'Wikidata'], id],
    })
    if (!feats.length) return true
    let minX = 180, minY = 90, maxX = -180, maxY = -90
    for (const f of feats) {
      const g = f.geometry
      if (!g) continue
      const rings = g.type === 'Polygon' ? g.coordinates : g.type === 'MultiPolygon' ? g.coordinates.flat() : []
      for (const ring of rings) {
        for (const c of ring) {
          if (c[0] < minX) minX = c[0]; if (c[0] > maxX) maxX = c[0]
          if (c[1] < minY) minY = c[1]; if (c[1] > maxY) maxY = c[1]
        }
      }
    }
    const b = map.getBounds()
    return !(maxX < b.getWest() || minX > b.getEast() || maxY < b.getSouth() || minY > b.getNorth())
  }

  // Re-link the highlighted territory WITHOUT remounting. Moves the red highlight to
  // `newQid` in place; only pans the camera when the polity is entirely off-screen (a
  // search for a far-away territory), so a territory clicked on the visible map — already
  // highlighted client-side by the click handler — never makes the view jump.
  const setPolity = (newQid) => {
    newQid = newQid || null
    if (highlighted !== newQid) {
      setHighlight(highlighted, false)
      setHighlight(newQid, true)
      highlighted = newQid
    }
    activeQid = newQid
    if (newQid && !polityInView(newQid)) {
      didFit = false
      fitToPolity()
    } else {
      // Already in view (or nothing linked): keep the camera. Mark the fit handled so the
      // `idle` auto-fit handler doesn't fire fitToPolity a tick later and yank the view.
      didFit = true
    }
  }

  // Click-to-link territory (configurator only — the host passes onPolityClick; the player never
  // does). Hover shows a name tag + soft wash so the teacher can identify a polity without knowing
  // its name; click hands { qid, name } to the host, which links it and re-mounts with the red fit.
  const fmtEraYear = (y) => (y < 0 ? `${Math.abs(y)} BCE` : `${y}`)
  const wirePolityPicking = () => {
    if (!onPolityClick) return
    let hoverId = null
    const hoverState = (id, on) => {
      if (id === undefined || id === null) return
      map.setFeatureState({ source: 'cliopatria', sourceLayer: 'boundaries', id }, { hover: on })
    }
    // className strips MapLibre's default white bubble + tip (see .lesson-map-tag in app.css)
    // so the hover label reads as a subtle dark chip on the parchment map, not a white box.
    const tag = new maplibregl.Popup({ closeButton: false, closeOnClick: false, offset: 10, className: 'lesson-map-tag' })
    map.on('mousemove', 'boundaries-fill', (e) => {
      const f = e.features && e.features[0]
      if (!f) return
      map.getCanvas().style.cursor = 'pointer'
      if (hoverId !== f.id) {
        hoverState(hoverId, false)
        hoverId = f.id
        hoverState(hoverId, true)
      }
      const p = f.properties || {}
      const from = Number(p.FromYear); const to = Number(p.ToYear)
      const era = Number.isFinite(from) && Number.isFinite(to) ? ` · ${fmtEraYear(from)}–${fmtEraYear(to)}` : ''
      tag.setLngLat(e.lngLat).setText(`${p.Name || ''}${era}`).addTo(map)
    })
    map.on('mouseleave', 'boundaries-fill', () => {
      map.getCanvas().style.cursor = ''
      hoverState(hoverId, false)
      hoverId = null
      tag.remove()
    })
    map.on('click', (e) => {
      // A pending pin-drop owns this click; and a click on an existing focus dot is a
      // marker interaction, not a territory pick.
      if (anno && anno.isAddingFocus && anno.isAddingFocus()) return
      if (map.getLayer('focus-dot') && map.queryRenderedFeatures(e.point, { layers: ['focus-dot'] }).length) return
      const hit = map.queryRenderedFeatures(e.point, { layers: ['boundaries-fill'] })[0]
      const clicked = hit && hit.properties && hit.properties.Wikidata
      if (!clicked) return
      // Instant feedback — move the red highlight now; the host's save re-mounts with this QID.
      setHighlight(highlighted, false)
      setHighlight(clicked, true)
      highlighted = clicked
      onPolityClick({ qid: clicked, name: (hit.properties.Name || '').trim() })
    })
  }

  // Paint the palette the moment the style is parsed, not at 'load' — 'load' also waits on every
  // source, and a slow (or failing) tileset would leave the map sitting in the raw default colours
  // with no satellite ground under a voyage. Idempotent, so the 'load' pass below still runs.
  map.once('styledata', () => { applyStyle(activeStyle); applyRelief(activeRelief) })

  map.on('load', () => {
    setYear(year)
    applyStyle(activeStyle) // colour the base layers now (avoids a flash of the default palette)
    applyRelief(activeRelief)
    requestAfterTiles(fitToPolity)

    // Vector terrain decoration (same Tolkien glyph set as the Time-Map): hills, forests, peaks,
    // all below the city labels. Peaks are softened (opacity 0.7) so dense ranges read as a
    // mountain field rather than a black wall, and the territory's red wash stays legible.
    // `terrain: false` skips all four (incl. the ~1 MB volcanoes chunk) — the voyage tour uses it so
    // the sea-route map loads fast and the fly-in from space doesn't stutter under their weight.
    const terrainReady = terrain
      ? addScatterLayer(map, { beforeId: 'city-dots' })
        .then(() => addForestLayer(map, { beforeId: 'city-dots', landColor: PALETTE.land }))
        .then(() => addMountainLayer(map, { beforeId: 'city-dots', landColor: PALETTE.land, opacity: 0.7 }))
        .then(() => addVolcanoLayer(map, { beforeId: 'city-dots' }))
      : Promise.resolve()
    terrainReady
      .then(() => {
        // Red territory wash ABOVE the terrain so it tints the whole selected polity — hills
        // and peaks included. Only the highlighted (selected) polity is painted; others stay clear.
        if (map.getLayer('boundaries-fill')) return
        map.addLayer({
          id: 'boundaries-fill', type: 'fill', source: 'cliopatria', 'source-layer': 'boundaries',
          filter: polityFilter(year),
          paint: {
            'fill-color': PALETTE.highlightFill,
            // hover = the soft pre-selection wash used by the configurator's click-to-link
            // (nothing ever sets `hover` in the player, so it stays invisible there).
            'fill-opacity': ['case',
              ['boolean', ['feature-state', 'highlight'], false], 0.32,
              ['boolean', ['feature-state', 'hover'], false], 0.12,
              0],
          },
        }, map.getLayer('city-dots') ? 'city-dots' : undefined)
        wirePolityPicking()
      })
      .then(() => applyStyle(activeStyle)) // re-apply once terrain layers exist (toggles their visibility)
      .then(() => applyLayerToggles())     // hide cities/borders last, after every layer exists
  })
  // Labelled overlay of the curated HISTORICAL cities (e.g. "Constantinople (Istanbul)"), fetched
  // as GeoJSON and drawn ABOVE the normal city labels. Append-only and fully guarded: any fetch /
  // add failure is swallowed so the map still renders. Same on the composer preview and the player.
  map.on('load', async () => {
    try {
      const res = await fetch(`${location.origin}/map/historical-cities.geojson`)
      if (!res.ok) return
      const data = await res.json()
      if (map.getSource('hcities')) return
      map.addSource('hcities', { type: 'geojson', data })
      map.addLayer({
        id: 'hcity-dot', type: 'circle', source: 'hcities',
        paint: {
          'circle-radius': ['interpolate', ['linear'], ['zoom'], 2, 2.6, 6, 4.5],
          'circle-color': '#7a1f12',          // deep historical red
          'circle-stroke-color': '#f3ead6',   // parchment halo
          'circle-stroke-width': 1,
        },
      })
      map.addLayer({
        id: 'hcity-label', type: 'symbol', source: 'hcities',
        layout: {
          // Historical name in calligraphy (a touch smaller), then the modern name MUCH smaller and
          // in the app sans (Inter) on the line below, so the two read as distinct registers.
          'text-field': ['format',
            ['get', 'historical'], { 'font-scale': 0.9 },
            '\n', {},
            '(', { 'font-scale': 0.52, 'text-font': ['literal', ['inter']] },
            ['get', 'name'], { 'font-scale': 0.52, 'text-font': ['literal', ['inter']] },
            ')', { 'font-scale': 0.52, 'text-font': ['literal', ['inter']] },
          ],
          'text-font': ['Eagle Lake'],
          'text-size': ['interpolate', ['linear'], ['zoom'], 2, 10, 6, 14],
          'text-anchor': 'top', 'text-offset': [0, 0.6], 'text-optional': true,
          'symbol-sort-key': ['to-number', ['coalesce', ['get', 'scalerank'], 5]],
        },
        paint: {
          'text-color': '#3a2c1a',
          'text-halo-color': '#f3ead6',
          'text-halo-width': 1.4,
        },
      })
      // Render ABOVE the normal city labels but BELOW the voyage waypoint titles (lesson-labels), which
      // must always stay on top. The hcities tiles load async and would otherwise jump above them.
      if (map.getLayer('lesson-labels')) {
        if (map.getLayer('hcity-dot')) map.moveLayer('hcity-dot', 'lesson-labels')
        map.moveLayer('hcity-label', 'lesson-labels')
      } else if (map.getLayer('city-labels')) {
        map.moveLayer('hcity-label')
      }
      // This overlay can load AFTER the annotations set its focus names, so re-apply the current
      // exclusion now that `hcity-label` exists (no-op when no focus cities are present).
      applyFocusExclusion('hcity-label', lastFocusNames)
      applyStyle(activeStyle) // colour the just-added historical labels for the active style
      applyLayerToggles()     // …and honour a cities-hidden voyage toggle (this layer loads late)
    } catch (_) { /* overlay is decorative — never break the map */ }
  })

  // Teacher map annotations (focus cities, etc.) — rendered as MapLibre layers once the style is up.
  // A focus city is re-drawn BIG (calligraphy + drop shadow) by map-annotations, so its normal small
  // label would duplicate it. We suppress the duplicate by excluding the focus display names from the
  // `city-labels` and `hcity-label` layers, keeping each layer's original filter as the base.
  let anno = null
  const labelLayers = ['city-labels', 'hcity-label']
  // Saved once the layers exist; null = "not captured yet". An entry can also be undefined,
  // meaning the layer had no filter (its base is `true`).
  const baseFilters = {}

  const captureBaseFilter = (id) => {
    if (id in baseFilters) return
    if (!map.getLayer(id)) return
    baseFilters[id] = map.getFilter(id)
  }

  // Exclude `names` (focus display names) from a label layer, ANDed onto its original filter.
  // Empty names → restore the original filter.
  const applyFocusExclusion = (id, names) => {
    if (!map.getLayer(id)) return
    captureBaseFilter(id)
    const base = baseFilters[id] ?? true
    if (!names || names.length === 0) {
      map.setFilter(id, baseFilters[id] ?? null)
      return
    }
    map.setFilter(id, ['all', base, ['!', ['in', ['get', 'name'], ['literal', names]]]])
  }

  // Last names handed to us — re-applied to layers that load after the annotations (e.g. hcity-label).
  let lastFocusNames = []
  const onFocusNames = (names) => {
    lastFocusNames = Array.isArray(names) ? names : []
    labelLayers.forEach((id) => applyFocusExclusion(id, lastFocusNames))
  }

  map.on('load', () => {
    anno = renderAnnotations(map, annotations, { editable, onChange: onAnnotationsChange, onFocusNames })
  })

  // Re-fit only until the first successful fit — never yank the view after the teacher pans.
  map.on('idle', () => { if (activeQid && !didFit) fitToPolity() })

  // Re-attempt fit a few times while tiles stream in.
  function requestAfterTiles (fn) {
    let tries = 0
    const t = setInterval(() => {
      fn()
      if (++tries > 8 || didFit) clearInterval(t)
    }, 400)
  }

  function setYear (y) {
    year = Math.round(Number(y))
    if (!map.getLayer('boundaries-line')) return
    map.setFilter('boundaries-line', polityFilter(year))
    if (map.getLayer('boundaries-fill')) map.setFilter('boundaries-fill', polityFilter(year))
    // Cities are period-specific — re-filter them too.
    if (map.getLayer('city-dots')) map.setFilter('city-dots', cityFilter(year))
    if (map.getLayer('city-labels')) {
      // The new period filter becomes `city-labels`' base; re-apply the focus exclusion on top of it
      // so a focus city's small duplicate label stays suppressed across year changes.
      baseFilters['city-labels'] = cityFilter(year)
      applyFocusExclusion('city-labels', lastFocusNames)
    }
  }

  // Recolour the map to one of the five styles (soft-atlas / antique / pen-ink / night / satellite).
  // Idempotent and layer-guarded, so it is safe to re-run as the async terrain + historical-city
  // layers arrive.
  let activeStyle = MAP_STYLES[style] ? style : DEFAULT_STYLE
  function applyStyle (name) {
    activeStyle = MAP_STYLES[name] ? name : DEFAULT_STYLE
    const s = MAP_STYLES[activeStyle]
    const paint = (layer, prop, val) => { if (map.getLayer(layer)) { try { map.setPaintProperty(layer, prop, val) } catch (_) {} } }
    const vis = (layer, on) => { if (map.getLayer(layer)) { try { map.setLayoutProperty(layer, 'visibility', on ? 'visible' : 'none') } catch (_) {} } }
    // Satellite: show the photographed ground and hide everything that draws a substitute for it.
    const photo = !!s.imagery
    vis('satellite', photo)
    // …and the imagery's painted ocean depths go under a flat, slowly moving sea.
    ocean.show(photo)
    for (const drawn of ['land', 'graticule', 'coast-shadow', 'lakes']) vis(drawn, !photo)
    // The ink shore would fence off a real coastline; keep it as a faint guide line instead.
    paint('coast-bold', 'line-opacity', photo ? 0.4 : 1)
    paint('coast-bold', 'line-width', photo
      ? ['interpolate', ['linear'], ['zoom'], 1, 0.6, 4, 1.0, 7, 1.6]
      : ['interpolate', ['linear'], ['zoom'], 1, 1.3, 4, 2.0, 7, 3.2])
    paint('bg', 'background-color', s.water)
    paint('graticule', 'line-color', s.grid)
    paint('coast-shadow', 'line-color', s.coastShadow)
    paint('land', 'fill-color', s.land)
    paint('lakes', 'fill-color', s.water)
    paint('rivers', 'line-color', s.river)
    paint('coast-bold', 'line-color', s.coast)
    // Borders keep the red highlight case — only the base (unselected) colour tracks the style.
    paint('boundaries-line', 'line-color',
      ['case', ['boolean', ['feature-state', 'highlight'], false], PALETTE.highlight, s.line])
    paint('city-dots', 'circle-color', s.text)
    paint('city-dots', 'circle-stroke-color', s.halo)
    paint('city-labels', 'text-color', s.text)
    paint('city-labels', 'text-halo-color', s.halo)
    paint('hcity-label', 'text-color', s.text)
    paint('hcity-label', 'text-halo-color', s.halo)
    paint('lesson-labels', 'text-color', s.text)
    paint('lesson-labels', 'text-halo-color', s.halo)
    // Ink hill/forest glyphs are dark drawings — hide them on the dark Night ground.
    for (const t of ['land-scatter', 'forests', 'mountains', 'volcanoes']) {
      if (map.getLayer(t)) { try { map.setLayoutProperty(t, 'visibility', s.terrain ? 'visible' : 'none') } catch (_) {} }
    }
    // Letterbox bars (map narrower than the stage) match the sea.
    try { el.style.backgroundColor = s.water } catch (_) {}
  }

  /**
   * 3D terrain: drape the map over the height map so mountains and valleys stand up.
   *
   * `v` is the exaggeration — 0 switches terrain off entirely (and stops the DEM tiles being
   * fetched). MapLibre lifts fills, lines, rasters and labels onto the mesh on its own; the one
   * thing it cannot do is the voyage's 3D traveller, which is a custom layer and has to be told
   * the ground height itself (voyage-ships.js reads it back via queryTerrainElevation).
   */
  let activeRelief = Math.max(0, Math.min(MAX_RELIEF, Number(relief) || 0))
  // setTerrain throws while the style is still parsing. Unlike the paint properties there is no
  // later pass that would set it anyway, so a failed attempt re-arms itself on the next style event
  // — otherwise a slow style load left the ground permanently flat.
  const setTerrainNow = () => {
    try {
      map.setTerrain(activeRelief > 0 ? { source: 'dem', exaggeration: activeRelief } : null)
      return true
    } catch (_) { return false }
  }
  function applyRelief (v) {
    activeRelief = Math.max(0, Math.min(MAX_RELIEF, Number(v) || 0))
    if (!setTerrainNow()) map.once('styledata', setTerrainNow)
  }

  // Show/hide anachronistic detail. Cities = both the dots and their labels (historical + modern);
  // borders = the political outlines + red fill. Voyages hide these so the map reads as period-blank.
  function applyLayerToggles () {
    const vis = (layer, on) => { if (map.getLayer(layer)) { try { map.setLayoutProperty(layer, 'visibility', on ? 'visible' : 'none') } catch (_) {} } }
    for (const l of ['city-dots', 'city-labels', 'hcity-label']) vis(l, layerToggles.cities)
    for (const l of ['boundaries-line', 'boundaries-fill']) vis(l, layerToggles.borders)
  }

  window.__lessonMap = map // debugging + test assertions (same idea as the Time-Map's __tmMap)

  return {
    map,
    setYear,
    flyToPolity: fitToPolity,
    /**
     * Projector for map-pinned text labels: converts lng/lat ⇄ percentages of `hostEl`
     * (the text-overlay host, which may not share the map container's box).
     */
    textProjector: (hostEl) => mapTextProjector(map, hostEl),
    setAnnotations: (a) => anno?.update(a),
    setPolity: (id) => setPolity(id),
    setStyle: (name) => applyStyle(name),
    /** 3D terrain exaggeration; 0 = flat. Applied live, no re-mount. */
    setRelief: (v) => applyRelief(v),
    /** Ground height (metres, exaggeration included) under a point — 0 when terrain is off. */
    groundAt: (lng, lat) => {
      if (activeRelief <= 0) return 0
      try { return map.queryTerrainElevation([lng, lat]) || 0 } catch (_) { return 0 }
    },
    /** Toggle anachronistic detail live, e.g. setLayerToggles({ cities:false, borders:false }). */
    setLayerToggles: (t) => { layerToggles = { ...layerToggles, ...(t || {}) }; applyLayerToggles() },
    /**
     * Per-element style overrides for a voyage map — place-label / city-name colour+size and the
     * political-border colour/width/opacity. Size is a MULTIPLIER over the base zoom ramp so labels
     * still grow with zoom. Applied live (setPaint/LayoutProperty); missing keys keep the palette.
     */
    setDetailStyle: (o = {}) => {
      const set = (fn) => { try { fn() } catch (_) { /* layer/prop not ready */ } }
      // The size multiplier is baked INTO the interpolation stops — a ['zoom'] expression may only
      // sit at the top level of interpolate/step, so ['*', m, interpolate(...)] is rejected.
      const lblSize = (m) => ['interpolate', ['linear'], ['zoom'], 2, 12 * m, 6, 17 * m]
      const citySize = (m) => ['interpolate', ['linear'], ['zoom'], 2, 9 * m, 6, 13 * m]
      if (o.label_color) set(() => map.setPaintProperty('lesson-labels', 'text-color', o.label_color))
      if (Number(o.label_size) > 0) set(() => map.setLayoutProperty('lesson-labels', 'text-size', lblSize(Number(o.label_size))))
      if (o.city_color) set(() => map.setPaintProperty('city-labels', 'text-color', o.city_color))
      if (Number(o.city_size) > 0) set(() => map.setLayoutProperty('city-labels', 'text-size', citySize(Number(o.city_size))))
      if (o.border_color) set(() => map.setPaintProperty('boundaries-line', 'line-color', o.border_color))
      if (Number(o.border_width) > 0) set(() => map.setPaintProperty('boundaries-line', 'line-width', Number(o.border_width)))
      if (o.border_opacity != null) set(() => map.setPaintProperty('boundaries-line', 'line-opacity', Number(o.border_opacity)))
    },
    /** Replace the pinned place labels ([{text,lng,lat}]) and re-apply the reveal (setData clears state). */
    setLabels: (arr) => {
      placeLabels = Array.isArray(arr) ? arr : []
      try { map.getSource('lesson-labels')?.setData(labelsFC(placeLabels)) } catch (_) {}
      applyLabelReveal()
    },
    /** Fade in the label nearest a coordinate (called when the ship arrives at a landfall). */
    revealLabelNear: (lng, lat) => {
      if (!placeLabels.length) return
      let best = -1; let bestD = Infinity
      placeLabels.forEach((l, i) => {
        const d = (Number(l.lng) - lng) ** 2 + (Number(l.lat) - lat) ** 2
        if (d < bestD) { bestD = d; best = i }
      })
      if (best >= 0) { revealedLabels.add(best); try { map.setFeatureState({ source: 'lesson-labels', id: best }, { shown: true }) } catch (_) {} }
    },
    /** Reveal every label at once (e.g. the editor may prefer to show them all). */
    revealAllLabels: () => { revealAll = true; applyLabelReveal() },
    setProjection: (type) => { try { map.setProjection({ type }) } catch (_) {} },
    beginAddFocus: () => anno?.beginAddFocus(),
    destroy: () => { try { anno?.destroy() } catch (_) {} try { ocean.destroy() } catch (_) {} try { map.remove() } catch (_) {} },
  }
}

// Expose for inline Alpine/blade use without a bundler import.
window.renderLessonMap = renderLessonMap

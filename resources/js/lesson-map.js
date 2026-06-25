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
  coast: '#3f3020',
  coastShadow: '#8a7a5e',
}

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
  const { qid = null, interactive = true, annotations = [], editable = false, onAnnotationsChange = null, projection = 'mercator' } = opts
  // Coerce — the inspector saves the year through a JSON config, so it can arrive as a string.
  let year = Number(opts.year)
  if (!Number.isFinite(year)) year = 1600

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
      },
      layers: [
        { id: 'bg', type: 'background', paint: { 'background-color': PALETTE.water } },
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
        // Bold coast outline — crisp ink shore above land/lakes/rivers, below the political borders.
        { id: 'coast-bold', type: 'line', source: 'coastline', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': PALETTE.coast, 'line-width': 1.0 } },
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
      ],
    },
    center: [8.23, 46.8],
    zoom: 3,
    maxZoom: 6,
  })

  if (interactive) {
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right')
  }

  // Highlight + fit to the target polity once tiles for this area have loaded.
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
    if (!qid) return
    const feats = map.querySourceFeatures('cliopatria', {
      sourceLayer: 'boundaries',
      filter: ['==', ['get', 'Wikidata'], qid],
    })
    if (!feats.length) return

    // Highlight every matched part (once).
    if (highlighted !== qid) {
      setHighlight(highlighted, false)
      setHighlight(qid, true)
      highlighted = qid
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
    if (b.minX <= b.maxX && b.minY <= b.maxY) {
      map.fitBounds([[b.minX, b.minY], [b.maxX, b.maxY]], { padding: 48, duration: 800, maxZoom: 6 })
      didFit = true
    }
  }

  map.on('load', () => {
    setYear(year)
    requestAfterTiles(fitToPolity)

    // Vector terrain decoration (same Tolkien glyph set as the Time-Map): hills, forests, peaks,
    // all below the city labels. Peaks are softened (opacity 0.7) so dense ranges read as a
    // mountain field rather than a black wall, and the territory's red wash stays legible.
    addScatterLayer(map, { beforeId: 'city-dots' })
      .then(() => addForestLayer(map, { beforeId: 'city-dots', landColor: PALETTE.land }))
      .then(() => addMountainLayer(map, { beforeId: 'city-dots', landColor: PALETTE.land, opacity: 0.7 }))
      .then(() => addVolcanoLayer(map, { beforeId: 'city-dots' }))
      .then(() => {
        // Red territory wash ABOVE the terrain so it tints the whole selected polity — hills
        // and peaks included. Only the highlighted (selected) polity is painted; others stay clear.
        if (map.getLayer('boundaries-fill')) return
        map.addLayer({
          id: 'boundaries-fill', type: 'fill', source: 'cliopatria', 'source-layer': 'boundaries',
          filter: polityFilter(year),
          paint: {
            'fill-color': PALETTE.highlightFill,
            'fill-opacity': ['case', ['boolean', ['feature-state', 'highlight'], false], 0.32, 0],
          },
        }, map.getLayer('city-dots') ? 'city-dots' : undefined)
      })
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
          // Historical name bold on top, then a smaller "(modern)" line below.
          'text-field': ['format',
            ['get', 'historical'], { 'font-scale': 1.0 },
            '\n(', {},
            ['get', 'name'], {},
            ')', { 'font-scale': 0.72 },
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
      // Render ABOVE the normal city labels: addLayer(layer, beforeId) inserts BELOW beforeId, so we
      // append on top (no beforeId) — moving it after city-labels if that layer exists.
      if (map.getLayer('city-labels')) map.moveLayer('hcity-label')
    } catch (_) { /* overlay is decorative — never break the map */ }
  })

  // Teacher map annotations (focus cities, etc.) — rendered as DOM markers once the style is up.
  let anno = null
  map.on('load', () => {
    anno = renderAnnotations(map, annotations, { editable, onChange: onAnnotationsChange })
  })

  // Re-fit only until the first successful fit — never yank the view after the teacher pans.
  map.on('idle', () => { if (qid && !didFit) fitToPolity() })

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
    if (map.getLayer('city-labels')) map.setFilter('city-labels', cityFilter(year))
  }

  return {
    map,
    setYear,
    flyToPolity: fitToPolity,
    setAnnotations: (a) => anno?.update(a),
    setProjection: (type) => { try { map.setProjection({ type }) } catch (_) {} },
    beginAddFocus: () => anno?.beginAddFocus(),
    destroy: () => { try { anno?.destroy() } catch (_) {} try { map.remove() } catch (_) {} },
  }
}

// Expose for inline Alpine/blade use without a bundler import.
window.renderLessonMap = renderLessonMap

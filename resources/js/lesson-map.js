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
import { renderAnnotations, focusLabelLayout } from './map-annotations.js'
import { placeLabelLayout, placeLabelPaint } from './map-place-label.js'
import { mapTextProjector } from './map-text-projector.js'
import { SATELLITE_SOURCE, SATELLITE_DETAIL_SOURCE, DETAIL_FADE_START, DETAIL_FADE_END, DEM_SOURCE, RELIEF, PITCH } from './map-imagery.js'
import { boxView, openingView } from './map-view.js'
import { itineraryTour } from './map-itinerary.js'
import { cityPriority, cityTextSize, cityDotRadius } from './map-city-scale.js'

const PALETTE = {
  highlight: '#c0392b',      // selected-polity border (red)
  highlightFill: '#c0392b',  // selected-polity red wash, painted over the ground (~0.3 opacity)
}

// Labels are set in the app sans. The map used to offer four hand-lettered drawn atlases as well,
// where calligraphy belonged; over satellite photography it reads as a costume rather than a map.
// The fontstack is built locally by scripts/build-glyphs.mjs and served from /fonts.
const MODERN_FONT = ['inter']
// Every label layer that gets the hand: the atlas's own labels plus the teacher's.
const LABEL_LAYERS = ['city-labels', 'hcity-label', 'lesson-labels', 'focus-label']

/**
 * The ground. There is one, and it is the real earth.
 *
 * This used to be a table of five palettes — Soft Atlas, Antique, Tolkien, Night, Satellite — with
 * a swatch picker in the inspector. They are gone, and the teacher no longer chooses. A drawn atlas
 * of 1271 still draws its coastline the way 2026 knows it, so the parchment bought no historical
 * truth; it only added ink hills and hand-lettering a class has to see past. The Tolkien one never
 * looked the way it was meant to at all. Photography of the actual terrain, tilted so the ground
 * has depth, is what makes an army crossing the Alps read as crossing the Alps.
 *
 * `fog` — voyage fog-of-war paints undiscovered world black rather than in the sea colour. On a
 * drawn atlas undiscovered world IS blank paper, but over imagery a navy wash still looks like sea
 * and the photo's ocean-depth banding reads straight through the feathered edge. Undiscovered means
 * nothing is there, not "shallower water here".
 *
 * Label ink is dark on a bright halo. Over a photo running from dark ocean to bright desert that is
 * the pairing which stays readable everywhere, and it survives a lesson's own dark label colour,
 * which would vanish into a dark halo.
 */
const GROUND = {
  // Focus cities (the teacher's own pins). They stay primary through size and their dot rather than
  // by shouting: contrast is the halo's job alone. The offset dark copy that used to sit behind each
  // word is gone — a word cannot collide-avoid against a second copy of itself (see map-annotations).
  focus: { color: '#fbe9dd', halo: 'rgba(12,9,6,0.55)', haloWidth: 1.2 },
  fog: '#000000',
  water: '#08131f',
  coast: '#f6efdc',
  line: '#ffd9a0',
  river: '#8fc3e8',
  text: '#241a10',
  halo: '#f2e9d4',
}

// Cities valid at `year` (gazetteer entries carry valid_from/valid_to; missing = always valid).
const cityFilter = (year) => ['all',
  ['<=', ['to-number', ['coalesce', ['get', 'valid_from'], -99999]], year],
  ['>=', ['to-number', ['coalesce', ['get', 'valid_to'], 99999]], year],
]

// A curated historical name is only true for its own period: Constantinople from 330 to 1453,
// Stalingrad from 1925 to 1961. Drawn unfiltered — as this overlay was — a single map showed
// Persepolis, Vindobona, Stalingrad and Bombay together, which tells a class they coexisted.
// `from`/`to` are parsed server-side from the curated prose (App\Support\HistoricalPeriod); a
// period nobody could parse arrives as ±99999 and stays visible, rather than disappearing.
const historicalCityFilter = (year) => ['all',
  ['<=', ['to-number', ['coalesce', ['get', 'from'], -99999]], year],
  ['>=', ['to-number', ['coalesce', ['get', 'to'], 99999]], year],
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
  const { qid = null, interactive = true, annotations = [], editable = false, onAnnotationsChange = null, onPolityClick = null, projection = 'mercator' } = opts
  // Voyage maps can hide anachronistic detail (modern city dots/labels + political borders that
  // didn't exist yet) and pin their own period place labels. Defaults keep the normal atlas.
  //
  // A block that pins its OWN cities is making the same statement a voyage does — these places, and
  // not the atlas's. The Silk Road map named four (Venice, Constantinople, Samarkand, Chang'an) and
  // the atlas answered with Berlin, Brussels, Frankfurt, Munich, Zürich and Kyiv on a map of 1271;
  // the four that carried the lesson were four labels among thirty. Year-filtering does not help,
  // because those cities really did exist — they are simply not what the scene is about.
  //
  // So the atlas steps back once a block names two or more cities of its own. An explicit
  // showCities from the caller still wins, and a block that pins nothing keeps the full atlas.
  const focusCount = (Array.isArray(annotations) ? annotations : []).filter((a) => a && a.type === 'focus').length
  const { showCities = focusCount < 2, showBorders = true } = opts
  // A voyage knows where it is going, and the student cannot pan away from it. Bounding the imagery
  // and elevation sources to the route's box means MapLibre never asks for a tile the lesson will
  // not show — no requests for the far side of the world when the camera dips wide, and no 404s
  // from the corners of a sparse tileset. [w, s, e, n]; omitted, the whole world stays available.
  const tileBounds = Array.isArray(opts.tileBounds) && opts.tileBounds.length === 4 ? opts.tileBounds : null
  const bounded = (source) => (tileBounds ? { ...source, bounds: tileBounds } : source)
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

  // Where this block points before anything loads — its own focus cities and pinned place labels,
  // not a hardcoded Europe (see map-view.js). The polity fit still refines it once the territory's
  // polygon streams in; this is what the teacher and the student see in the meantime, and what
  // they keep when no territory is linked.
  const opening = openingView({ annotations, labels: placeLabels })

  const map = new maplibregl.Map({
    container: el,
    interactive,
    attributionControl: false,
    // Lean the camera over from the start. Set here rather than eased in after load, because the
    // opening view is fitted to the block's own cities and a pitch applied afterwards re-frames
    // the shot — the class would watch the map settle instead of arriving already composed.
    //
    // Every projection, globe included: MapLibre's globe leans perfectly well, and a voyage seen
    // over the curve of the earth is the best shot the app has.
    pitch: PITCH,
    style: {
      version: 8,
      // 2D flat (mercator) vs 3D globe (MapLibre v5) — set on the style at init so the map starts
      // in the right projection. Calling setProjection mid-load disrupts tile/layer loading.
      projection: { type: projection },
      glyphs: `${location.origin}/fonts/{fontstack}/{range}.pbf`, // calligraphy labels (see build-glyphs.mjs)
      sources: {
        // No land / lake / graticule tiles: the photograph already shows the land, the lakes and
        // the sea, and a drawn substitute for any of them sat underneath it doing nothing but
        // costing a tileset. Rivers stay — they are thin, and the imagery loses the small ones.
        rivers: { type: 'vector', tiles: [`${location.origin}/river-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        cities: { type: 'vector', tiles: [`${location.origin}/city-tiles/{z}/{x}/{y}.pbf`], maxzoom: 6 },
        cliopatria: {
          type: 'vector',
          tiles: [`${location.origin}/cliopatria-tiles/{z}/{x}/{y}.pbf`],
          maxzoom: 4,
          promoteId: { boundaries: 'Wikidata' },
        },
        // True coastline, for the faint shore guide over the imagery.
        coastline: { type: 'geojson', data: `${location.origin}/timemap/coastline.geojson` },
        satellite: bounded(SATELLITE_SOURCE),
        // Close-range ground: Sentinel-2 at ~10 m/px against Blue Marble's ~500. Its layer's
        // opacity is a zoom expression, so MapLibre requests nothing from EOX while a lesson stays
        // at globe zoom — a map that never leans in costs exactly what it did before.
        'satellite-detail': bounded(SATELLITE_DETAIL_SOURCE),
        // Height map behind the 3D ground.
        dem: bounded(DEM_SOURCE),
        // Teacher-authored period place labels (voyages) — filled via setLabels()/the `labels` opt.
        'lesson-labels': { type: 'geojson', data: labelsFC(placeLabels) },
      },
      layers: [
        // Deep ocean, showing wherever a satellite tile has not arrived yet — empty space rather
        // than a grey plate or a wrong-coloured sea.
        { id: 'bg', type: 'background', paint: { 'background-color': GROUND.water } },
        // The ground. Sits directly on the background; everything else draws over it.
        // `raster-fade-duration` is the only easing MapLibre gives a raster, and it is what stops a
        // tile popping in. 700ms reads as the photograph developing rather than snapping on.
        { id: 'satellite', type: 'raster', source: 'satellite', paint: { 'raster-fade-duration': 700 } },
        // Sentinel-2 crossfades in OVER Blue Marble across one zoom level, rather than replacing it
        // at a threshold: the two disagree (no bathymetry, no relief shading in Sentinel), and a
        // hard swap reads as the earth changing colour instead of as detail arriving.
        {
          id: 'satellite-detail',
          type: 'raster',
          source: 'satellite-detail',
          paint: {
            'raster-fade-duration': 700,
            'raster-opacity': ['interpolate', ['linear'], ['zoom'], DETAIL_FADE_START, 0, DETAIL_FADE_END, 1],
          },
        },
        {
          id: 'rivers', type: 'line', source: 'rivers', 'source-layer': 'rivers',
          paint: {
            'line-color': GROUND.river,
            'line-opacity': 0.7,
            // Thicker for major rivers (low scalerank).
            'line-width': ['interpolate', ['linear'], ['to-number', ['coalesce', ['get', 'scalerank'], 6]], 1, 1.4, 6, 0.4],
          },
        },
        // Coast guide line. Faint on purpose: a bold ink shore over a real coastline fences the
        // photograph off from itself, so this only firms up where the land ends.
        { id: 'coast-bold', type: 'line', source: 'coastline', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': GROUND.coast, 'line-opacity': 0.4, 'line-width': ['interpolate', ['linear'], ['zoom'], 1, 0.6, 4, 1.0, 7, 1.6] } },
        {
          // No fill overlay: the selected polity is shown as an amber RING; other territories
          // are just faint 30%-opacity borders so the terrain reads through.
          id: 'boundaries-line', type: 'line', source: 'cliopatria', 'source-layer': 'boundaries',
          filter: polityFilter(year),
          layout: { 'line-join': 'round' },
          paint: {
            'line-color': ['case', ['boolean', ['feature-state', 'highlight'], false], PALETTE.highlight, GROUND.line],
            'line-width': ['case', ['boolean', ['feature-state', 'highlight'], false], 2.6, 0.6],
            'line-opacity': ['case', ['boolean', ['feature-state', 'highlight'], false], 1, 0.3],
          },
        },
        {
          id: 'city-dots', type: 'circle', source: 'cities', 'source-layer': 'cities',
          filter: cityFilter(year),
          paint: {
            'circle-radius': cityDotRadius(),
            'circle-color': GROUND.text,
            'circle-stroke-color': GROUND.halo,
            'circle-stroke-width': 1,
            'circle-opacity': 0.9,
          },
        },
        {
          id: 'city-labels', type: 'symbol', source: 'cities', 'source-layer': 'cities',
          filter: cityFilter(year),
          layout: {
            'text-field': ['get', 'name'],
            // Size AND collision priority both come from the city's importance — see map-city-scale.
            'text-size': cityTextSize(),
            'symbol-sort-key': cityPriority(),
            'text-anchor': 'left', 'text-offset': [0.6, 0], 'text-optional': true,
            'text-font': MODERN_FONT,
            'text-letter-spacing': 0.02,
          },
          paint: {
            'text-color': GROUND.text,
            'text-halo-color': GROUND.halo,
            'text-halo-width': 1.4,
          },
        },
        // Teacher-authored place labels — above the atlas labels and bolder, because on a voyage
        // map they are the point.
        {
          // A landfall name is a place label like the focus cities and the itinerary's own pins —
          // the same pill, from the same module. It keeps `allow-overlap`, though: on a voyage the
          // landfall name IS the scene, and one dropped mid-sail would leave the ship arriving
          // nowhere in particular.
          id: 'lesson-labels', type: 'symbol', source: 'lesson-labels',
          layout: {
            ...placeLabelLayout({ font: ['inter'], textField: ['get', 'name'] }),
            'text-allow-overlap': true,
            'text-ignore-placement': true,
          },
          paint: {
            ...placeLabelPaint(),
            // Hidden until "arrived at" (feature-state shown), then fades in.
            'text-opacity': ['case', ['boolean', ['feature-state', 'shown'], false], 1, 0],
            'text-opacity-transition': { duration: 650, delay: 0 },
          },
        },
      ],
    },
    center: opening.center,
    zoom: opening.zoom,
    maxZoom: 6,
    // A voyage revisits the same water on the way out and the way home, and the default cache is
    // small enough that a long crossing evicts the tiles the return leg is about to want — which
    // shows up as the sea reloading in a place the class has already been.
    maxTileCacheSize: 1500,
  })

  if (interactive) {
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right')
  }

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
    map.easeTo({ ...boxView(b), duration: 800 })
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
  map.once('styledata', () => { paintLateLayers(); applyRelief(activeRelief); applyGlobe() })

  // Anything that wants to move the CAMERA has to wait for the style — MapLibre silently drops an
  // easeTo issued before it, which is a nasty failure mode because every timer still fires on
  // schedule and nothing throws. 'styledata' rather than 'load' for the same reason as above: the
  // camera needs the style parsed, not every tile fetched.
  //
  // A flag, not a bare `once('load')` at the call site: by the time a caller asks, the event may
  // already have come and gone, and `once` on a past event never fires at all.
  let styleReady = false
  map.once('styledata', () => { styleReady = true })
  const whenStyleReady = (fn) => {
    if (styleReady || map.isStyleLoaded()) fn()
    else map.once('styledata', fn)
  }

  map.on('load', () => {
    setYear(year)
    paintLateLayers()
    applyRelief(activeRelief)
    requestAfterTiles(fitToPolity)

    // The ink hill, forest, peak and volcano glyphs used to be drawn here — the same Tolkien set the
    // Time-Map has. Over photography of the real mountains they were a drawing of the thing sitting
    // on top of the thing, so they are gone, and with them the ~1 MB volcanoes chunk every lesson
    // map used to download.
    Promise.resolve()
      .then(() => {
        // Red territory wash. Only the highlighted (selected) polity is painted; others stay clear.
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
      .then(() => paintLateLayers())
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
        filter: historicalCityFilter(year),
        paint: {
          'circle-radius': ['interpolate', ['linear'], ['zoom'], 2, 2.6, 6, 4.5],
          'circle-color': '#7a1f12',          // deep historical red
          'circle-stroke-color': '#f3ead6',   // parchment halo
          'circle-stroke-width': 1,
        },
      })
      map.addLayer({
        id: 'hcity-label', type: 'symbol', source: 'hcities',
        filter: historicalCityFilter(year),
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
      paintLateLayers() // the historical labels only exist now
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
  // The numbered-stop itinerary (map-itinerary.js), and the layer it draws its pins on. The host is
  // created lazily: most map blocks never run a tour, and an empty absolutely-positioned div over
  // the canvas is still a div that MapLibre has to composite.
  let itinerary = null
  let itineraryHostEl = null
  const itineraryHost = () => {
    if (itineraryHostEl) return itineraryHostEl
    const host = document.createElement('div')
    host.className = 'lp-map-itinerary'
    host.style.cssText = 'position:absolute;inset:0;pointer-events:none;overflow:hidden'
    el.appendChild(host)
    itineraryHostEl = host
    return host
  }

  let lastFocusNames = []
  const onFocusNames = (names) => {
    lastFocusNames = Array.isArray(names) ? names : []
    labelLayers.forEach((id) => applyFocusExclusion(id, lastFocusNames))
  }

  map.on('load', () => {
    anno = renderAnnotations(map, annotations, { editable, onChange: onAnnotationsChange, onFocusNames })
    paintLateLayers() // the focus-city layers only exist now
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
    // The historical names move with the year too — the whole point of the overlay.
    if (map.getLayer('hcity-dot')) map.setFilter('hcity-dot', historicalCityFilter(year))
    if (map.getLayer('hcity-label')) {
      baseFilters['hcity-label'] = historicalCityFilter(year)
      applyFocusExclusion('hcity-label', lastFocusNames)
    }
  }

  /**
   * Give the ground's ink to the label layers that did not exist at style time.
   *
   * The base layers are painted in the style spec above and never change again — there is one
   * ground now, so there is nothing to switch between. What does still need doing is the layers
   * that arrive later: the historical-city overlay after its fetch, and the teacher's focus
   * cities after the annotations render. Idempotent and layer-guarded, so it is safe to re-run
   * every time one of those lands.
   */
  function paintLateLayers () {
    const paint = (layer, prop, val) => { if (map.getLayer(layer)) { try { map.setPaintProperty(layer, prop, val) } catch (_) {} } }
    paint('hcity-label', 'text-color', GROUND.text)
    paint('hcity-label', 'text-halo-color', GROUND.halo)
    paint('lesson-labels', 'text-color', GROUND.text)
    paint('lesson-labels', 'text-halo-color', GROUND.halo)
    const font = (layer) => { if (map.getLayer(layer)) { try { map.setLayoutProperty(layer, 'text-font', MODERN_FONT) } catch (_) {} } }
    LABEL_LAYERS.forEach(font)
    // A focus label writes the PLACE and its note in separate sections of one text-field, so the
    // hand is baked into the field itself, not just the layer font.
    if (map.getLayer('focus-label')) {
      try { map.setLayoutProperty('focus-label', 'text-field', focusLabelLayout(MODERN_FONT)['text-field']) } catch (_) {}
    }
    paint('focus-label', 'text-color', GROUND.focus.color)
    paint('focus-label', 'text-halo-color', GROUND.focus.halo)
    paint('focus-label', 'text-halo-width', GROUND.focus.haloWidth + 0.6)
    paint('focus-label', 'text-halo-blur', 0.4)
    // Letterbox bars (map narrower than the stage) match the sea.
    try { el.style.backgroundColor = GROUND.water } catch (_) {}
  }

  /**
   * The globe render, over the one ground there is.
   *
   * The sea lit as water, the terminator, the haze band at the limb, the cloud deck, and the sky
   * bodies behind the planet. On the Time-Map this rides on a style called Earth, chosen from a
   * palette. Here there is no palette to choose from: the five drawn atlases were removed and the
   * single remaining ground is photography of the real planet, so the globe is simply what that
   * ground looks like rather than one option among several.
   *
   * Loaded as its own chunk because it carries shaders and texture fields. That is worth keeping
   * even now that it always runs — it stays out of the main bundle, so the wizard and every page
   * that never renders a lesson map pay nothing for it.
   *
   * The date decides where the sun is, and therefore which half of the planet is in daylight — so a
   * lesson set in 1600 is lit for 1600. Year alone is enough for the terminator to be somewhere
   * defensible; it is not a claim about the hour of day, which no scene carries.
   */
  let globe = null
  let globeLoading = false
  let globeWaiting = false

  /** Midsummer noon UTC of the scene's year — a defensible sun for a lesson that carries no hour. */
  const globeDate = () => new Date(Date.UTC(Number.isFinite(year) ? year : 2000, 5, 21, 12))

  function applyGlobe () {
    if (globe || globeLoading) return
    // addLayer throws "Style is not done loading" outright rather than queueing, and this is called
    // from the styledata pass that also runs before the async terrain and city layers arrive.
    //
    // A one-shot `once('idle')` was not enough: two identical runs disagreed about whether the
    // layers arrived at all, because whether that single event lands after the style settles is a
    // race with the async layers. So subscribe until it works. The guard above makes this
    // idempotent, so an idle tick once the globe exists costs one comparison.
    if (!map.isStyleLoaded()) {
      if (!globeWaiting) {
        globeWaiting = true
        map.on('idle', () => applyGlobe())
      }
      return
    }
    globeLoading = true
    import('./map-globe-layers.js')
      .then(({ addGlobeLayers }) => {
        globeLoading = false
        globe = addGlobeLayers(map, {
          date: globeDate(),
          reduceMotion: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true,
          /**
           * Everything from the city dots up is annotation and belongs above the planet; everything
           * below them — imagery, land, coast, borders, the territory wash — is the planet's
           * surface and belongs under the sea, exactly as on the Time-Map.
           *
           * This was missing once, and MapLibre's answer to a missing anchor is "put it on top". So
           * the sea went over the writing and erased each name where it crossed water:
           * Constantinople cut in half at the Bosphorus, Carthage stopping at the coast, Athens
           * down to "Ath".
           */
          beforeId: 'city-dots',
        })
      })
      .catch((e) => {
        globeLoading = false
        // Loudly: a silent failure here is a lesson that quietly renders as plain imagery, and
        // plain imagery is close enough to right that nobody would report it.
        console.error('[lesson-map] Earth layers failed to load — falling back to plain imagery', e)
      })
  }

  /**
   * 3D ground: drape the map over the height map so mountains and valleys stand up.
   *
   * MapLibre lifts fills, lines, rasters and labels onto the mesh on its own; the one thing it
   * cannot do is the voyage's 3D traveller, which is a custom layer and has to be told the ground
   * height itself (voyage-ships.js reads it back via queryTerrainElevation).
   */
  let activeRelief = RELIEF
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
    activeRelief = Math.max(0, Number(v) || 0)
    if (!setTerrainNow()) map.once('styledata', setTerrainNow)
  }

  // Show/hide anachronistic detail. Cities = both the dots and their labels (historical + modern);
  // borders = the political outlines + red fill. Voyages hide these so the map reads as period-blank.
  function applyLayerToggles () {
    const vis = (layer, on) => { if (map.getLayer(layer)) { try { map.setLayoutProperty(layer, 'visibility', on ? 'visible' : 'none') } catch (_) {} } }
    // `hcity-dot` belongs in this list as much as its label does. Left out, hiding the cities took
    // away every historical NAME and left the deep-red dots behind — anonymous marks a class has no
    // way to read, one of which sat in the Gulf of Guinea back when the curated cities had no
    // coordinates.
    for (const l of ['city-dots', 'city-labels', 'hcity-dot', 'hcity-label']) vis(l, layerToggles.cities)
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
    /** Colour the voyage fog-of-war paints undiscovered world in. */
    fogColor: () => GROUND.fog,
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

    /**
     * Visit the focus cities in order, the way a voyage visits its stops — see map-itinerary.js.
     *
     * A map block's script names its cities in order ("on the left, Venice … then Constantinople
     * …"), and until now the camera sat on the whole continent for the entire scene while the class
     * hunted for whichever one was being described. This walks the camera along the same list the
     * teacher pinned, numbering each city as it arrives.
     *
     * The names themselves stay where they were — the focus-label layer already draws them, in the
     * map's own hand. The pin adds ONLY the number, so a city never wears its name twice.
     *
     * @param {{ totalMs?: number|null }} [o] narration duration to pace against
     */
    startItinerary: ({ totalMs = null } = {}) => {
      itinerary?.destroy()
      const stops = (annotations || [])
        .filter((a) => a && a.type === 'focus')
        .map((a, i) => ({ lng: Number(a.lng), lat: Number(a.lat), number: i + 1 }))
      if (stops.length < 2) return null   // one city is not an itinerary; the opening view is enough
      itinerary = itineraryTour(map, stops, { hostEl: itineraryHost(), totalMs })
      whenStyleReady(() => itinerary?.start())
      return itinerary
    },
    pauseItinerary: () => itinerary?.pause(),
    resumeItinerary: () => itinerary?.resume(),

    destroy: () => {
      try { itinerary?.destroy() } catch (_) {}
      try { anno?.destroy() } catch (_) {}
      try { map.remove() } catch (_) {}
    },
  }
}

// Expose for inline Alpine/blade use without a bundler import.
window.renderLessonMap = renderLessonMap

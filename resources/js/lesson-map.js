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

const PALETTE = {
  land: '#f3ead6',
  water: '#d8e9f3',
  fill: '#c9b79c',
  highlight: '#f5c518',
  line: '#5b4a36',
}

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
  const { qid = null, interactive = true } = opts
  let year = Number.isFinite(opts.year) ? opts.year : 1600

  const map = new maplibregl.Map({
    container: el,
    interactive,
    attributionControl: false,
    style: {
      version: 8,
      glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
      sources: {
        land: { type: 'vector', tiles: [`${location.origin}/land-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        cliopatria: {
          type: 'vector',
          tiles: [`${location.origin}/cliopatria-tiles/{z}/{x}/{y}.pbf`],
          maxzoom: 4,
          promoteId: { boundaries: 'Wikidata' },
        },
      },
      layers: [
        { id: 'bg', type: 'background', paint: { 'background-color': PALETTE.water } },
        { id: 'land', type: 'fill', source: 'land', 'source-layer': 'land', paint: { 'fill-color': PALETTE.land } },
        {
          id: 'boundaries-fill', type: 'fill', source: 'cliopatria', 'source-layer': 'boundaries',
          filter: polityFilter(year),
          paint: {
            'fill-color': ['case', ['boolean', ['feature-state', 'highlight'], false], PALETTE.highlight, PALETTE.fill],
            'fill-opacity': ['case', ['boolean', ['feature-state', 'highlight'], false], 0.9, 0.55],
          },
        },
        {
          id: 'boundaries-line', type: 'line', source: 'cliopatria', 'source-layer': 'boundaries',
          filter: polityFilter(year),
          paint: { 'line-color': PALETTE.line, 'line-width': 0.6, 'line-opacity': 0.7 },
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
  const setHighlight = (id, on) => {
    if (!id) return
    map.setFeatureState({ source: 'cliopatria', sourceLayer: 'boundaries', id }, { highlight: on })
  }

  const fitToPolity = () => {
    if (!qid) return
    const feats = map.querySourceFeatures('cliopatria', {
      sourceLayer: 'boundaries',
      filter: ['==', ['get', 'Wikidata'], qid],
    })
    if (!feats.length) return

    let minX = 180, minY = 90, maxX = -180, maxY = -90
    const walk = (coords) => {
      if (typeof coords[0] === 'number') {
        const [x, y] = coords
        if (x < minX) minX = x; if (x > maxX) maxX = x
        if (y < minY) minY = y; if (y > maxY) maxY = y
        return
      }
      coords.forEach(walk)
    }
    feats.forEach((f) => f.geometry && walk(f.geometry.coordinates))

    if (minX <= maxX && minY <= maxY) {
      map.fitBounds([[minX, minY], [maxX, maxY]], { padding: 48, duration: 800, maxZoom: 6 })
    }
    if (highlighted !== qid) {
      setHighlight(highlighted, false)
      setHighlight(qid, true)
      highlighted = qid
    }
  }

  map.on('load', () => { setYear(year); requestAfterTiles(fitToPolity) })
  map.on('idle', () => { if (qid && highlighted !== qid) fitToPolity() })

  // Re-attempt fit a few times while tiles stream in.
  function requestAfterTiles (fn) {
    let tries = 0
    const t = setInterval(() => {
      fn()
      if (++tries > 8 || highlighted === qid) clearInterval(t)
    }, 400)
  }

  function setYear (y) {
    year = Math.round(Number(y))
    if (!map.getLayer('boundaries-fill')) return
    map.setFilter('boundaries-fill', polityFilter(year))
    map.setFilter('boundaries-line', polityFilter(year))
  }

  return {
    map,
    setYear,
    flyToPolity: fitToPolity,
    destroy: () => { try { map.remove() } catch (_) {} },
  }
}

// Expose for inline Alpine/blade use without a bundler import.
window.renderLessonMap = renderLessonMap

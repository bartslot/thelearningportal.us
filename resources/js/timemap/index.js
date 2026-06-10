import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { formatReadout } from './era.js';
import { mountTimeSlider } from './slider.js';
import supplementalMarkers from './markers.json';

// Mounted by the Blade view via x-init. `wire` is the Livewire component proxy ($wire).
window.initTimeMap = function initTimeMap(el, wire, initialYear) {
  const state = { year: initialYear, ready: false, selectedRegion: null };
  const sync = () => {
    window.__portal = { ready: state.ready, year: state.year, selectedRegion: state.selectedRegion };
  };
  sync();

  // Local OHM vector-tile mirror (no live dependency); z0-4 overview tiles.
  const map = new maplibregl.Map({
    container: el,
    style: {
      version: 8,
      glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
      sources: {
        land: { type: 'vector', tiles: [`${location.origin}/ohm-tiles/osm_land/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        ohm: { type: 'vector', tiles: [`${location.origin}/ohm-tiles/ohm_admin/{z}/{x}/{y}.pbf`], maxzoom: 4, promoteId: { boundaries: 'osm_id' } },
      },
      layers: [
        { id: 'water', type: 'background', paint: { 'background-color': '#d8e9f3' } },
        { id: 'land', type: 'fill', source: 'land', 'source-layer': 'land', paint: { 'fill-color': '#f3ead6' } },
      ],
    },
    center: [15, 50], // Europe
    zoom: 4,
    // Fixed-overview navigation tool: don't zoom in past the standard level (no detailed tiles
    // exist there anyway). Zoom-out stays open so other continents/markers come into view.
    maxZoom: 4,
    attributionControl: { customAttribution: 'Borders © OpenHistoricalMap (CC0)' },
  });

  // The container settles to its final height after Alpine/Livewire mount; without this the
  // map can initialise against a transient height and render short until the next resize.
  const resizeObserver = new ResizeObserver(() => map.resize());
  resizeObserver.observe(el);

  // Distinct muted "atlas" palette, chosen per polity via a stable hash of its osm_id.
  const ATLAS_PALETTE = [
    '#c9b79c', '#a8b9a0', '#cbb3a1', '#b6a8c0', '#c2c0a0', '#a9bcc4',
    '#d0bfa8', '#b9a99a', '#aebfa6', '#c8b6b0', '#b0b6a0', '#bcae9e',
  ];
  const FILL_COLOR = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], '#f5c518',
    ['boolean', ['feature-state', 'hover'], false], '#ecd9a0',
    // `match` returns colors directly; hash the (negative) osm_id into the palette.
    ['match', ['%', ['abs', ['to-number', ['get', 'osm_id']]], 12],
      ...ATLAS_PALETTE.flatMap((c, i) => [i, c]), ATLAS_PALETTE[0]],
  ];
  const FILL_OPACITY = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], 0.95,
    ['boolean', ['feature-state', 'hover'], false], 0.85,
    0.7,
  ];

  // Continuous time filter: keep features whose lifespan contains the year (BCE = negative).
  const dateFilter = (year) => ['all',
    ['<=', ['coalesce', ['to-number', ['get', 'start_decdate']], -1e6], year],
    ['>', ['coalesce', ['to-number', ['get', 'end_decdate']], 1e6], year],
  ];
  const applyYear = (year) => {
    if (!map.getLayer('boundaries-fill')) return;
    map.setFilter('boundaries-fill', dateFilter(year));
    map.setFilter('boundaries-line', dateFilter(year));
    // Supplemental markers (regions OHM leaves blank) filter by the same lifespan rule.
    map.setFilter('markers-dot', dateFilter(year));
    map.setFilter('markers-label', dateFilter(year));
    map.once('idle', refreshLabels); // recompute one label per visible polity for the new era
  };

  // OHM polities are multi-island MultiPolygons split across tiles; a vector-tile symbol layer
  // drops a label on EVERY part. Instead we derive ONE label per polity (osm_id) at the centroid
  // of its largest visible polygon and feed those points to a dedicated GeoJSON label layer.
  const ringCentroid = (ring) => {
    let a = 0, cx = 0, cy = 0;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const [x0, y0] = ring[j];
      const [x1, y1] = ring[i];
      const f = x0 * y1 - x1 * y0;
      a += f; cx += (x0 + x1) * f; cy += (y0 + y1) * f;
    }
    if (a === 0) return { area: 0, c: ring[0] };
    return { area: Math.abs(a / 2), c: [cx / (3 * a), cy / (3 * a)] };
  };
  const refreshLabels = () => {
    const src = map.getSource('labels');
    if (!src || !map.getLayer('boundaries-fill')) return;
    const best = new Map(); // osm_id -> { area, c, name }
    for (const f of map.queryRenderedFeatures({ layers: ['boundaries-fill'] })) {
      const name = f.properties.name_en || f.properties.name;
      if (name == null) continue;
      const id = f.properties.osm_id;
      const g = f.geometry;
      const polys = g.type === 'Polygon' ? [g.coordinates] : g.type === 'MultiPolygon' ? g.coordinates : [];
      for (const poly of polys) {
        const { area, c } = ringCentroid(poly[0]);
        const cur = best.get(id);
        if (!cur || area > cur.area) best.set(id, { area, c, name });
      }
    }
    src.setData({
      type: 'FeatureCollection',
      features: [...best.values()].map((b) => ({
        type: 'Feature', geometry: { type: 'Point', coordinates: b.c }, properties: { name: b.name },
      })),
    });
  };

  let hoveredId = null;
  let selectedId = null;
  const setHover = (id, on) => map.setFeatureState({ source: 'ohm', sourceLayer: 'boundaries', id }, { hover: on });
  const setSelected = (id, on) => map.setFeatureState({ source: 'ohm', sourceLayer: 'boundaries', id }, { selected: on });

  map.on('load', () => {
    map.addLayer({
      id: 'boundaries-fill', type: 'fill', source: 'ohm', 'source-layer': 'boundaries',
      paint: {
        'fill-color': FILL_COLOR,
        'fill-opacity': FILL_OPACITY,
        'fill-opacity-transition': { duration: 150 },
      },
    });
    map.addLayer({
      id: 'boundaries-line', type: 'line', source: 'ohm', 'source-layer': 'boundaries',
      paint: { 'line-color': '#9a7b4f', 'line-width': 0.7, 'line-opacity': 0.7 },
    });
    // One derived label point per polity (see refreshLabels) — never per tile-clipped part.
    map.addSource('labels', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addLayer({
      id: 'boundaries-label', type: 'symbol', source: 'labels',
      layout: {
        'text-field': ['get', 'name'],
        'text-size': 12, 'text-allow-overlap': false, 'text-optional': true,
      },
      paint: { 'text-color': '#3b3326', 'text-halo-color': '#f3ead6', 'text-halo-width': 1.2 },
    });

    // Supplemental markers for regions/peoples OHM leaves blank (label-only, no borders).
    // A muted hollow dot + brown label distinguishes them from real (filled) polities.
    map.addSource('markers', { type: 'geojson', data: supplementalMarkers });
    map.addLayer({
      id: 'markers-dot', type: 'circle', source: 'markers',
      paint: {
        'circle-radius': 4, 'circle-color': '#cbbfa6',
        'circle-stroke-color': '#6b5a3e', 'circle-stroke-width': 1.4, 'circle-opacity': 0.85,
      },
    });
    map.addLayer({
      id: 'markers-label', type: 'symbol', source: 'markers',
      layout: {
        'text-field': ['get', 'name'], 'text-size': 12,
        'text-offset': [0, 0.9], 'text-anchor': 'top',
        'text-allow-overlap': false, 'text-optional': true, 'text-letter-spacing': 0.08,
      },
      paint: { 'text-color': '#6b5a3e', 'text-halo-color': '#f3ead6', 'text-halo-width': 1.2 },
    });
    map.on('mouseenter', 'markers-dot', () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', 'markers-dot', () => { map.getCanvas().style.cursor = ''; });

    map.on('moveend', refreshLabels);

    applyYear(state.year);

    // Pointer cursor + hover highlight over historical regions.
    map.on('mousemove', 'boundaries-fill', (e) => {
      map.getCanvas().style.cursor = 'pointer';
      if (!e.features.length) return;
      if (hoveredId !== null) setHover(hoveredId, false);
      hoveredId = e.features[0].id;
      setHover(hoveredId, true);
    });
    map.on('mouseleave', 'boundaries-fill', () => {
      map.getCanvas().style.cursor = '';
      if (hoveredId !== null) setHover(hoveredId, false);
      hoveredId = null;
    });

    state.ready = true;
    sync();
  });

  map.on('click', (e) => {
    // Supplemental markers sit on top and carry an explicit QID — check them first (small hit box).
    const box = [[e.point.x - 7, e.point.y - 7], [e.point.x + 7, e.point.y + 7]];
    const marker = map.queryRenderedFeatures(box, { layers: ['markers-dot'] })[0];
    if (marker) {
      if (selectedId !== null) { setSelected(selectedId, false); selectedId = null; }
      const p = marker.properties;
      state.selectedRegion = p.id;
      sync();
      window.dispatchEvent(new CustomEvent('polity-selected', {
        // `qid` → Wikidata enrichment via the endpoint; `articleUrl` → curated external article
        // (e.g. worldhistory.org), rendered directly with no server call.
        detail: {
          id: p.id, name: p.name, qid: p.qid,
          articleUrl: p.article_url, summary: p.summary,
          inception: p.inception, dissolution: p.dissolution,
        },
      }));
      return;
    }

    // Identify the clicked polity client-side from the rendered (era-filtered) features.
    const hit = map.queryRenderedFeatures(e.point, { layers: ['boundaries-fill'] })[0];

    if (selectedId !== null) setSelected(selectedId, false);
    selectedId = hit ? hit.id : null;
    if (selectedId !== null) setSelected(selectedId, true);

    const osmId = hit ? hit.properties.osm_id : null;
    const name = hit ? (hit.properties.name_en || hit.properties.name) : null;
    state.selectedRegion = osmId;
    sync();
    window.dispatchEvent(new CustomEvent('polity-selected', { detail: { id: osmId, name } }));
  });

  // Called by the Alpine slider on input — continuous, no fetch.
  el._setYear = (year) => {
    state.year = year;
    wire.year = year;
    applyYear(year);
    sync();
    return formatReadout(year);
  };

  el._tmMap = map;

  return map;
};

window.mountAtlasSlider = function (el, mapEl, initialYear) {
  let timer = null;
  mountTimeSlider(el, {
    min: -4000, max: 2010, value: initialYear,
    onYear: (year) => {
      clearTimeout(timer);
      timer = setTimeout(() => { if (mapEl._setYear) mapEl._setYear(year); }, 150);
    },
  });
};

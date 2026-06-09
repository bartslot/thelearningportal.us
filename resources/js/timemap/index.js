import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { formatReadout } from './era.js';
import { mountTimeSlider } from './slider.js';

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
    const labelBase = ['<=', ['to-number', ['get', 'admin_level']], 4];
    map.setFilter('boundaries-fill', dateFilter(year));
    map.setFilter('boundaries-line', dateFilter(year));
    map.setFilter('boundaries-label', ['all', labelBase, dateFilter(year)]);
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
    map.addLayer({
      id: 'boundaries-label', type: 'symbol', source: 'ohm', 'source-layer': 'boundaries',
      layout: {
        // OHM names are native-language; prefer the English name for the schools atlas.
        'text-field': ['coalesce', ['get', 'name_en'], ['get', 'name']],
        'text-size': 11, 'text-allow-overlap': false, 'text-optional': true,
        'symbol-placement': 'point',
      },
      paint: { 'text-color': '#3b3326', 'text-halo-color': '#f3ead6', 'text-halo-width': 1.2 },
    });

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

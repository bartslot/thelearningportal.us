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

  const map = new maplibregl.Map({
    container: el,
    style: 'https://demotiles.maplibre.org/style.json',
    center: [20, 30],
    zoom: 2,
    attributionControl: { customAttribution: 'Borders © historical-basemaps (CC-BY-SA)' },
  });

  // The container settles to its final height after Alpine/Livewire mount; without this the
  // map can initialise against a transient height and render short until the next resize.
  const resizeObserver = new ResizeObserver(() => map.resize());
  resizeObserver.observe(el);

  // Some WebGL backends don't paint the GeoJSON fill until its paint is re-applied *after* the
  // worker finishes parsing the features. Re-applying color+opacity forces a full re-evaluation
  // and redraw; we do it on the next idle (parse+render settled) plus a timeout fallback.
  // Distinct muted "atlas" palette, chosen per polity via the exported color_key (0..11).
  const ATLAS_PALETTE = [
    '#c9b79c', '#a8b9a0', '#cbb3a1', '#b6a8c0', '#c2c0a0', '#a9bcc4',
    '#d0bfa8', '#b9a99a', '#aebfa6', '#c8b6b0', '#b0b6a0', '#bcae9e',
  ];
  const FILL_COLOR = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], '#f5c518',
    ['boolean', ['feature-state', 'hover'], false], '#ecd9a0',
    ['at', ['%', ['get', 'color_key'], ATLAS_PALETTE.length], ['literal', ATLAS_PALETTE]],
  ];
  const FILL_OPACITY = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], 0.95,
    ['boolean', ['feature-state', 'hover'], false], 0.85,
    0.7,
  ];
  const applyFillPaint = () => {
    if (!map.getLayer('boundaries-fill')) return;
    map.setPaintProperty('boundaries-fill', 'fill-color', FILL_COLOR);
    map.setPaintProperty('boundaries-fill', 'fill-opacity', FILL_OPACITY);
    map.triggerRepaint();
  };
  const nudgeFill = () => {
    map.once('idle', applyFillPaint);
    setTimeout(applyFillPaint, 800);
  };

  let hoveredId = null;
  let selectedId = null;

  // Seeded era snapshots (must match timemap:export-boundaries). Borders for any year come from
  // the snapshot whose era contains it: the latest snapshot at or before the year.
  const SNAPSHOTS = [-2000, -1500, -500, 200, 500, 1000, 1880];
  const snapshotFor = (year) => SNAPSHOTS.filter((s) => s <= year).pop() ?? SNAPSHOTS[0];

  const loadFlags = async (labelsFc) => {
    const ids = [...new Set(labelsFc.features.map((f) => f.properties.flag_id).filter(Boolean))];
    await Promise.all(ids.map(async (id) => {
      if (map.hasImage(`flag-${id}`)) return;
      try {
        const img = await map.loadImage(`/flags/${id}.png`);
        if (!map.hasImage(`flag-${id}`)) map.addImage(`flag-${id}`, img.data);
      } catch { /* missing flag */ }
    }));
  };

  const setLabels = async () => {
    const fc = await (await fetch(`/geo/labels/${snapshotFor(state.year)}.geojson`)).json();
    await loadFlags(fc);
    const src = map.getSource('labels');
    if (src) { src.setData(fc); return; }
    map.addSource('labels', { type: 'geojson', data: fc });
    map.addLayer({
      id: 'labels-symbol', type: 'symbol', source: 'labels',
      layout: {
        'icon-image': ['case', ['==', ['get', 'flag_id'], ''], '', ['concat', 'flag-', ['get', 'flag_id']]],
        'icon-size': 0.5, 'icon-allow-overlap': false,
        'text-field': ['concat', ['get', 'label'], '\n', ['get', 'date_label']],
        'text-size': 11, 'text-offset': [0, 1.4], 'text-anchor': 'top',
        'text-optional': true, 'text-allow-overlap': false,
      },
      paint: { 'text-color': '#3b3326', 'text-halo-color': '#f3ead6', 'text-halo-width': 1.2 },
    });
  };

  const setBoundaries = async () => {
    // Static, full-quality GeoJSON served from the app/CDN — no remote DB round-trip on load.
    const res = await fetch(`/geo/boundaries/${snapshotFor(state.year)}.geojson`);
    const fc = await res.json();
    const src = map.getSource('boundaries');
    if (src) {
      src.setData(fc);
      hoveredId = null;
      selectedId = null; // features are replaced; drop any persisted selection
      nudgeFill();
      await setLabels();

      return;
    }

    // promoteId gives each feature a stable id so feature-state hover works.
    map.addSource('boundaries', { type: 'geojson', data: fc, promoteId: 'polity_id' });

    map.addLayer({
      id: 'boundaries-fill', type: 'fill', source: 'boundaries',
      paint: {
        // Colour each region by its color_key so each polity gets a distinct atlas hue.
        'fill-color': FILL_COLOR,
        // Brighten the region under the cursor so it reads as clickable.
        'fill-opacity': FILL_OPACITY,
        'fill-opacity-transition': { duration: 150 },
      },
    });
    map.addLayer({
      id: 'boundaries-line', type: 'line', source: 'boundaries',
      paint: { 'line-color': '#9a7b4f', 'line-width': 0.7, 'line-opacity': 0.7 },
    });

    nudgeFill();
    await setLabels();

    // Pointer cursor + hover highlight over historical regions.
    map.on('mousemove', 'boundaries-fill', (e) => {
      map.getCanvas().style.cursor = 'pointer';
      if (!e.features.length) return;
      if (hoveredId !== null) {
        map.setFeatureState({ source: 'boundaries', id: hoveredId }, { hover: false });
      }
      hoveredId = e.features[0].id;
      map.setFeatureState({ source: 'boundaries', id: hoveredId }, { hover: true });
    });
    map.on('mouseleave', 'boundaries-fill', () => {
      map.getCanvas().style.cursor = '';
      if (hoveredId !== null) {
        map.setFeatureState({ source: 'boundaries', id: hoveredId }, { hover: false });
      }
      hoveredId = null;
    });
  };

  map.on('load', async () => {
    // Rustic, less-modern base in the brand palette: parchment land, light-blue water, soft
    // sepia coastlines, and no modern country borders / labels / graticule / crimea overlay.
    for (const id of ['countries-label', 'geolines-label', 'geolines', 'countries-boundary', 'crimea-fill']) {
      try { if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', 'none'); } catch { /* layer absent */ }
    }
    const tweak = (layer, prop, value) => {
      try { if (map.getLayer(layer)) map.setPaintProperty(layer, prop, value); } catch { /* layer absent */ }
    };
    tweak('background', 'background-color', '#d8e9f3'); // water = soft light blue
    tweak('countries-fill', 'fill-color', '#f3ead6');   // land = parchment cream
    tweak('countries-fill', 'fill-opacity', 1);
    tweak('coastline', 'line-color', '#b89b6e');        // soft sepia coast
    tweak('coastline', 'line-width', 0.8);
    await setBoundaries();
    state.ready = true;
    sync();
  });

  map.on('click', async (e) => {
    // Identify the clicked polity client-side from the already-loaded boundaries — instant,
    // no server round-trip — then fetch only that region's articles.
    const hit = map.queryRenderedFeatures(e.point, { layers: ['boundaries-fill'] })[0];

    // Persist the highlight on the clicked region (brand yellow).
    if (selectedId !== null) {
      map.setFeatureState({ source: 'boundaries', id: selectedId }, { selected: false });
    }
    selectedId = hit ? hit.id : null;
    if (selectedId !== null) {
      map.setFeatureState({ source: 'boundaries', id: selectedId }, { selected: true });
    }

    const polityId = hit ? hit.properties.polity_id : null;
    state.selectedRegion = polityId; // mirror for the test hook (exported props no longer carry `region`)
    sync();
    window.dispatchEvent(new CustomEvent('polity-selected', { detail: { id: polityId } }));
  });

  // Called by the Alpine slider on input.
  el._setYear = async (year) => {
    state.year = year;
    wire.year = year;
    await setBoundaries();
    sync();
    return formatReadout(year);
  };

  el._tmMap = map;

  return map;
};

window.mountAtlasSlider = function (el, mapEl, initialYear) {
  let timer = null;
  mountTimeSlider(el, {
    min: -2000, max: 1880, value: initialYear,
    onYear: (year) => {
      clearTimeout(timer);
      timer = setTimeout(() => { if (mapEl._setYear) mapEl._setYear(year); }, 150);
    },
  });
};

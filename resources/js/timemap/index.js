import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { formatReadout } from './era.js';

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
  const FILL_COLOR = [
    'match', ['get', 'region'],
    'Mediterranean', '#f59e0b',
    'Middle East', '#ef4444',
    'East Asia', '#8b5cf6',
    'South Asia', '#ec4899',
    'Northern Europe', '#3b82f6',
    'Africa', '#10b981',
    'Americas', '#f97316',
    /* other */ '#9ca3af',
  ];
  const FILL_OPACITY = ['case', ['boolean', ['feature-state', 'hover'], false], 0.92, 0.72];
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

  const setBoundaries = async () => {
    const fc = await wire.boundariesGeoJson();
    const src = map.getSource('boundaries');
    if (src) {
      src.setData(fc);
      hoveredId = null;
      nudgeFill();

      return;
    }

    // promoteId gives each feature a stable id so feature-state hover works.
    map.addSource('boundaries', { type: 'geojson', data: fc, promoteId: 'polity_id' });

    map.addLayer({
      id: 'boundaries-fill', type: 'fill', source: 'boundaries',
      paint: {
        // Colour each region by its macro-region so the historical world reads at a glance.
        'fill-color': FILL_COLOR,
        // Brighten the region under the cursor so it reads as clickable.
        'fill-opacity': FILL_OPACITY,
        'fill-opacity-transition': { duration: 150 },
      },
    });
    map.addLayer({
      id: 'boundaries-line', type: 'line', source: 'boundaries',
      paint: { 'line-color': '#1e293b', 'line-width': 0.6, 'line-opacity': 0.5 },
    });

    nudgeFill();

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
    // Calm the modern demotiles base so the historical regions are the visual focus:
    // hide labels/graticule, and neutralise the rainbow country fills to a soft grey.
    for (const id of ['countries-label', 'geolines-label', 'geolines']) {
      try { if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', 'none'); } catch { /* layer absent */ }
    }
    try { map.setPaintProperty('countries-fill', 'fill-color', '#e2e6eb'); } catch { /* layer absent */ }
    try { map.setPaintProperty('countries-fill', 'fill-opacity', 1); } catch { /* layer absent */ }
    try { map.setPaintProperty('countries-boundary', 'line-color', '#c7ccd4'); } catch { /* layer absent */ }
    await setBoundaries();
    state.ready = true;
    sync();
  });

  map.on('click', async (e) => {
    // Identify the clicked polity client-side from the already-loaded boundaries — instant,
    // no server round-trip — then fetch only that region's articles.
    const hit = map.queryRenderedFeatures(e.point, { layers: ['boundaries-fill'] })[0];
    const region = hit ? hit.properties.region : null;
    const polity = hit ? hit.properties.name : null;
    state.selectedRegion = region;
    sync();
    await wire.storiesForRegion(region, polity, state.year);
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

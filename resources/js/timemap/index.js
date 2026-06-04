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

  const setBoundaries = async () => {
    const fc = await wire.boundariesGeoJson();
    const src = map.getSource('boundaries');
    if (src) {
      src.setData(fc);
    } else {
      map.addSource('boundaries', { type: 'geojson', data: fc });
      map.addLayer({
        id: 'boundaries-fill', type: 'fill', source: 'boundaries',
        paint: { 'fill-color': '#f59e0b', 'fill-opacity': 0, 'fill-opacity-transition': { duration: 400 } },
      });
      map.addLayer({
        id: 'boundaries-line', type: 'line', source: 'boundaries',
        paint: { 'line-color': '#b45309', 'line-width': 1 },
      });
      requestAnimationFrame(() => map.setPaintProperty('boundaries-fill', 'fill-opacity', 0.35));
    }
  };

  map.on('load', async () => {
    await setBoundaries();
    state.ready = true;
    sync();
  });

  map.on('click', async (e) => {
    await wire.storiesAt(e.lngLat.lng, e.lngLat.lat, state.year);
    state.selectedRegion = wire.selectedRegion ?? null;
    sync();
  });

  // Called by the Alpine slider on input.
  el._setYear = async (year) => {
    state.year = year;
    wire.year = year;
    map.setPaintProperty('boundaries-fill', 'fill-opacity', 0);
    await setBoundaries();
    requestAnimationFrame(() => map.setPaintProperty('boundaries-fill', 'fill-opacity', 0.35));
    sync();
    return formatReadout(year);
  };

  return map;
};

import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { formatReadout } from './era.js';
import { mountTimeSlider } from './slider.js';
import supplementalMarkers from './markers.json';
import theme from './theme.json';

// Mounted by the Blade view via x-init. `wire` is the Livewire component proxy ($wire).
window.initTimeMap = function initTimeMap(el, wire, initialYear) {
  const state = { year: initialYear, ready: false, selectedRegion: null };
  const sync = () => {
    window.__portal = { ready: state.ready, year: state.year, selectedRegion: state.selectedRegion };
  };
  sync();

  // Borders: local Cliopatria vector tiles (Seshat, CC-BY). Land base: OHM osm_land (CC0). z0-4.
  const map = new maplibregl.Map({
    container: el,
    style: {
      version: 8,
      glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
      sources: {
        land: { type: 'vector', tiles: [`${location.origin}/land-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        cliopatria: { type: 'vector', tiles: [`${location.origin}/cliopatria-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4, promoteId: { boundaries: 'Wikidata' } },
      },
      layers: [
        { id: 'water', type: 'background', paint: { 'background-color': theme.water } },
        { id: 'land', type: 'fill', source: 'land', 'source-layer': 'land', paint: { 'fill-color': theme.land } },
      ],
    },
    center: [8.23, 46.8], // Switzerland
    zoom: 4,
    // Fixed-overview navigation tool: don't zoom in past the standard level (no detailed tiles
    // exist there anyway). Zoom-out stays open so other continents/markers come into view.
    maxZoom: 4,
    attributionControl: { customAttribution: 'Borders © Cliopatria / Seshat (CC-BY 4.0) · Land © OpenStreetMap (CC0)' },
  });

  // The container settles to its final height after Alpine/Livewire mount; without this the
  // map can initialise against a transient height and render short until the next resize.
  const resizeObserver = new ResizeObserver(() => map.resize());
  resizeObserver.observe(el);

  // Atlas styling comes from theme.json — edit colours/lines there, no JS changes needed.
  const ATLAS_PALETTE = theme.palette;
  const FILL_COLOR = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], theme.selected,
    ['boolean', ['feature-state', 'hover'], false], theme.hover,
    // `match` returns colors directly; hash the numeric part of the Wikidata QID into the palette.
    ['match', ['%', ['to-number', ['slice', ['coalesce', ['get', 'Wikidata'], 'Q0'], 1]], ATLAS_PALETTE.length],
      ...ATLAS_PALETTE.flatMap((c, i) => [i, c]), ATLAS_PALETTE[0]],
  ];
  const FILL_OPACITY = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], theme.fillOpacity.selected,
    ['boolean', ['feature-state', 'hover'], false], theme.fillOpacity.hover,
    theme.fillOpacity.normal,
  ];

  // Cliopatria polities valid at `year`: Type=POLITY, skip composite/alliance extents (names in
  // parentheses overlap their members), within the feature's FromYear..ToYear lifespan.
  const polityFilter = (year) => ['all',
    ['==', ['get', 'Type'], 'POLITY'],
    ['!=', ['slice', ['get', 'Name'], 0, 1], '('],
    ['<=', ['to-number', ['get', 'FromYear']], year],
    ['>=', ['to-number', ['get', 'ToYear']], year],
  ];
  // Supplemental markers (markers.json) use their own start/end decimal-year lifespan.
  const markerFilter = (year) => ['all',
    ['<=', ['coalesce', ['to-number', ['get', 'start_decdate']], -1e6], year],
    ['>', ['coalesce', ['to-number', ['get', 'end_decdate']], 1e6], year],
  ];
  const applyYear = (year) => {
    if (!map.getLayer('boundaries-fill')) return;
    map.setFilter('boundaries-fill', polityFilter(year));
    map.setFilter('boundaries-line', polityFilter(year));
    map.setFilter('markers-dot', markerFilter(year));
    map.setFilter('markers-label', markerFilter(year));
    for (let i = 0; i < 6; i++) { if (map.getLayer(`ink-${i}`)) map.setFilter(`ink-${i}`, polityFilter(year)); }
    scheduleSettle(); // recompute labels + prefetch articles for the new era (after tiles settle)
  };

  // Cliopatria polities can be multi-part MultiPolygons split across tiles; a vector-tile symbol
  // layer drops a label on EVERY part. Instead we derive ONE label per polity (by QID) at the
  // centroid of its largest visible polygon and feed those points to a dedicated GeoJSON layer.
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
  // Flag icons: a manifest lists which QIDs have a downloaded flag (so we never 404-probe).
  // Images load lazily for visible polities; a label references a flag only once its image is added.
  const flaggedIds = new Set();
  const triedFlag = new Set();
  const ensureFlag = (qid) => {
    if (triedFlag.has(qid) || map.hasImage(`flag-${qid}`)) return Promise.resolve(false);
    triedFlag.add(qid);
    return map.loadImage(`/flags/${qid}.png`)
      .then((img) => { if (!map.hasImage(`flag-${qid}`)) map.addImage(`flag-${qid}`, img.data); return true; })
      .catch(() => false);
  };
  const refreshLabels = () => {
    const src = map.getSource('labels');
    if (!src || !map.getLayer('boundaries-fill')) return;
    const best = new Map(); // qid -> { area, c, name }
    for (const f of map.queryRenderedFeatures({ layers: ['boundaries-fill'] })) {
      const name = f.properties.Name;
      if (name == null) continue;
      const id = String(f.properties.Wikidata);
      const g = f.geometry;
      const polys = g.type === 'Polygon' ? [g.coordinates] : g.type === 'MultiPolygon' ? g.coordinates : [];
      for (const poly of polys) {
        const { area, c } = ringCentroid(poly[0]);
        const cur = best.get(id);
        if (!cur || area > cur.area) best.set(id, { area, c, name });
      }
    }
    const apply = () => src.setData({
      type: 'FeatureCollection',
      features: [...best.entries()].map(([id, b]) => ({
        type: 'Feature', geometry: { type: 'Point', coordinates: b.c },
        properties: { name: b.name, flag: map.hasImage(`flag-${id}`) ? `flag-${id}` : '' },
      })),
    });
    apply();
    // Lazily load flag images for visible flagged polities, then redraw so icons appear above names.
    const toLoad = [...best.keys()].filter((id) => flaggedIds.has(id) && !map.hasImage(`flag-${id}`) && !triedFlag.has(id));
    if (toLoad.length) Promise.all(toLoad.map(ensureFlag)).then((res) => { if (res.some(Boolean)) apply(); });
  };
  // Which polities have a flag (from the sync's manifest); refresh once it arrives.
  fetch('/flags/manifest.json')
    .then((r) => (r.ok ? r.json() : []))
    .then((ids) => { ids.forEach((id) => flaggedIds.add(String(id))); if (map.getLayer('boundaries-fill')) refreshLabels(); })
    .catch(() => {});

  // Prefetch every enriched article once on load from a static snapshot (the corpus pooler is too
  // slow to query live). Clicking a territory then opens its panel instantly (read by the panel
  // via window.__polityCache). Regenerated by timemap:sync-cliopatria-polities.
  window.__polityCache = window.__polityCache || {};
  fetch('/timemap/articles.json')
    .then((r) => (r.ok ? r.json() : {}))
    .then((m) => Object.assign(window.__polityCache, m))
    .catch(() => {});

  const onSettle = () => refreshLabels();
  // Re-run labels whenever the border tiles finish loading or the view moves (debounced).
  let settleTimer = null;
  const scheduleSettle = () => { clearTimeout(settleTimer); settleTimer = setTimeout(onSettle, 250); };

  let hoveredId = null;
  let selectedId = null;
  const setHover = (id, on) => map.setFeatureState({ source: 'cliopatria', sourceLayer: 'boundaries', id }, { hover: on });
  const setSelected = (id, on) => map.setFeatureState({ source: 'cliopatria', sourceLayer: 'boundaries', id }, { selected: on });

  // ---- Map styles: switched live from the palette dropdown (window.__applyMapStyle). ----
  const ATLAS_PAL = theme.palette;
  const NIGHT_PAL = ['#39496a', '#4a3b63', '#37614f', '#63503b', '#4c6140', '#63415a', '#3a5570', '#56426a', '#3f6657', '#665445', '#414f6e', '#5c4258'];
  // Greyscale paper-grain texture (multiply-blended) to break up the flat vector fills.
  const PAPER_URI = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='240' height='240'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='4' stitchTiles='stitch'/><feColorMatrix type='saturate' values='0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>";
  // Per-feature line width from a hash of the polity QID → adjacent borders get visibly different
  // weights (the main source of "hand-drawn" width variance; MapLibre can't vary width along a line).
  const inkWidth = (min, max) => ['interpolate', ['linear'],
    ['%', ['to-number', ['slice', ['coalesce', ['get', 'Wikidata'], 'Q7'], 1]], 89], 0, min, 88, max];
  const MAP_STYLES = {
    'soft-atlas': { palette: ATLAS_PAL, water: '#c7d4c6', land: '#efe6d0', fillOpacity: 0.55, selected: '#f5c518', hover: '#ecd9a0', line: { color: '#6b5640', width: 0.8, blur: 0.3 }, text: { color: '#3b3326', halo: '#f3ead6' }, paper: 0.08, vignette: 'rgba(80,55,30,0.14)' },
    'antique': { palette: ATLAS_PAL, water: '#dcdcba', land: '#e8d6ac', fillOpacity: 0.3, selected: '#e0a200', hover: '#d9c089', line: { color: '#4a3420', width: 1.7, blur: 0.25 }, text: { color: '#3a2c1a', halo: '#ecdcb8' }, paper: 0.2, vignette: 'rgba(80,55,30,0.3)' },
    'pen-ink': {
      palette: ATLAS_PAL, water: '#dedec0', land: '#e6d6ad', fillOpacity: 0.16, selected: '#c98a00', hover: '#d9c089',
      // Delicate main stroke — per-feature width variance, kept thin so dense regions stay readable.
      line: { color: '#3a2c1c', width: inkWidth(0.4, 1.2), blur: 0.25 },
      // One faint bleed + one offset rough underlayer (both width-varied) beneath, then a single
      // light broken accent on top. Restrained so borders read as pen work, not a black tangle.
      inkLayers: [
        { color: '#5a4630', width: inkWidth(1.2, 2.4), opacity: 0.12, blur: 1.4 },
        { color: '#4a3826', width: inkWidth(0.7, 1.5), opacity: 0.2, blur: 0.6, offset: 0.5 },
        { color: '#2a1f12', width: 0.6, opacity: 0.4, blur: 0.1, offset: 0.4, dash: [3, 3], above: true },
      ],
      text: { color: '#33271a', halo: '#efe2c4' }, paper: 0.22, vignette: 'rgba(80,55,30,0.3)',
    },
    'night': { palette: NIGHT_PAL, water: '#0f1420', land: '#1b2230', fillOpacity: 0.6, selected: '#f5c518', hover: '#5a6b8c', line: { color: '#8a99b8', width: 0.6, blur: 0.2 }, text: { color: '#e6ecf7', halo: '#10151f' }, paper: 0, vignette: 'rgba(0,0,0,0.45)' },
  };
  const applyOverlays = (s) => {
    const wrap = el.parentElement;
    if (!wrap) return;
    let pv = wrap.querySelector('#tm-paper');
    if (!pv) {
      pv = document.createElement('div');
      pv.id = 'tm-paper';
      pv.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:5;mix-blend-mode:multiply';
      pv.style.backgroundImage = `url("${PAPER_URI}")`;
      wrap.appendChild(pv);
    }
    pv.style.opacity = String(s.paper);
    let vg = wrap.querySelector('#tm-vignette');
    if (!vg) {
      vg = document.createElement('div');
      vg.id = 'tm-vignette';
      vg.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:6';
      wrap.appendChild(vg);
    }
    vg.style.background = `radial-gradient(ellipse 78% 78% at center, rgba(0,0,0,0) 52%, ${s.vignette} 100%)`;
  };
  const applyMapStyle = (name) => {
    const key = MAP_STYLES[name] ? name : 'soft-atlas';
    const s = MAP_STYLES[key];
    try { localStorage.setItem('tm-style', key); } catch (e) { /* private mode */ }
    const pal = s.palette;
    const fill = ['case',
      ['boolean', ['feature-state', 'selected'], false], s.selected,
      ['boolean', ['feature-state', 'hover'], false], s.hover,
      ['match', ['%', ['to-number', ['slice', ['coalesce', ['get', 'Wikidata'], 'Q0'], 1]], pal.length],
        ...pal.flatMap((c, i) => [i, c]), pal[0]]];
    map.setPaintProperty('water', 'background-color', s.water);
    map.setPaintProperty('land', 'fill-color', s.land);
    if (map.getLayer('boundaries-fill')) {
      map.setPaintProperty('boundaries-fill', 'fill-color', fill);
      // Keep hover/selected clearly visible even when the base fill is very faint (ink styles).
      map.setPaintProperty('boundaries-fill', 'fill-opacity', ['case',
        ['boolean', ['feature-state', 'selected'], false], Math.max(0.85, s.fillOpacity),
        ['boolean', ['feature-state', 'hover'], false], Math.max(0.6, s.fillOpacity + 0.18),
        s.fillOpacity]);
      map.setPaintProperty('boundaries-line', 'line-color', s.line.color);
      map.setPaintProperty('boundaries-line', 'line-width', s.line.width);
      map.setPaintProperty('boundaries-line', 'line-blur', s.line.blur);
      map.setLayoutProperty('boundaries-line', 'line-join', 'round');
      map.setPaintProperty('boundaries-label', 'text-color', s.text.color);
      map.setPaintProperty('boundaries-label', 'text-halo-color', s.text.halo);
    }
    if (map.getLayer('markers-label')) {
      map.setPaintProperty('markers-label', 'text-color', s.text.color);
      map.setPaintProperty('markers-label', 'text-halo-color', s.text.halo);
    }
    // Hand-drawn ink: several stacked strokes (per-feature varying width + bleed + broken dashes)
    // so borders read as uneven pen work rather than uniform vector lines.
    for (let i = 0; i < 6; i++) { if (map.getLayer(`ink-${i}`)) map.removeLayer(`ink-${i}`); }
    if (map.getLayer('boundaries-line')) {
      (s.inkLayers || []).forEach((L, i) => {
        const layer = {
          id: `ink-${i}`, type: 'line', source: 'cliopatria', 'source-layer': 'boundaries',
          filter: polityFilter(state.year),
          layout: { 'line-join': 'round', 'line-cap': 'round' },
          paint: {
            'line-color': L.color, 'line-width': L.width, 'line-opacity': L.opacity ?? 1, 'line-blur': L.blur ?? 0,
            ...(L.offset != null ? { 'line-offset': L.offset } : {}),
            ...(L.dash ? { 'line-dasharray': L.dash } : {}),
          },
        };
        // `above` strokes sit on top of the main border but BELOW the labels (insert before
        // boundaries-label) so text is never obscured; the rest bleed beneath the main border.
        if (L.above) map.addLayer(layer, map.getLayer('boundaries-label') ? 'boundaries-label' : undefined);
        else map.addLayer(layer, 'boundaries-line');
      });
    }
    applyOverlays(s);
  };
  window.__applyMapStyle = applyMapStyle;

  map.on('load', () => {
    map.addLayer({
      id: 'boundaries-fill', type: 'fill', source: 'cliopatria', 'source-layer': 'boundaries',
      paint: {
        'fill-color': FILL_COLOR,
        'fill-opacity': FILL_OPACITY,
        'fill-opacity-transition': { duration: 150 },
      },
    });
    map.addLayer({
      id: 'boundaries-line', type: 'line', source: 'cliopatria', 'source-layer': 'boundaries',
      paint: { 'line-color': theme.line.color, 'line-width': theme.line.width, 'line-opacity': theme.line.opacity },
    });
    // One derived label point per polity (see refreshLabels) — never per tile-clipped part.
    map.addSource('labels', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addLayer({
      id: 'boundaries-label', type: 'symbol', source: 'labels',
      layout: {
        // Flag stacked ON TOP of the territory name (flag ≤16px: 120px source × 0.13).
        'icon-image': ['get', 'flag'],
        'icon-size': 0.13,
        'icon-anchor': 'bottom',
        'icon-allow-overlap': false, 'icon-optional': true,
        'text-field': ['get', 'name'],
        'text-size': 12, 'text-anchor': 'top', 'text-offset': [0, 0.35],
        'text-allow-overlap': false, 'text-optional': true,
        'text-padding': 6, // space labels out so dense regions de-clutter
      },
      // Strong halo so names stay readable over borders/fills in every style.
      paint: { 'text-color': '#3b3326', 'text-halo-color': '#f3ead6', 'text-halo-width': 2, 'text-halo-blur': 0.5 },
    });

    // Supplemental markers for regions/peoples the dataset leaves blank (label-only, no borders).
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

    map.on('moveend', scheduleSettle);
    // Border tiles load asynchronously; re-settle once they're in so labels + prefetch see them.
    map.on('sourcedata', (e) => { if (e.sourceId === 'cliopatria' && e.isSourceLoaded) scheduleSettle(); });

    let savedStyle = null;
    try { savedStyle = localStorage.getItem('tm-style'); } catch (e) { /* private mode */ }
    applyMapStyle(savedStyle || 'soft-atlas');
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

    const qid = hit ? hit.properties.Wikidata : null;
    const name = hit ? hit.properties.Name : null;
    state.selectedRegion = qid;
    sync();
    // QID drives enrichment (Cliopatria carries it natively); pass it as both id and qid.
    window.dispatchEvent(new CustomEvent('polity-selected', { detail: { id: qid, name, qid } }));
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

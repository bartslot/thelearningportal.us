import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { formatReadout } from './era.js';
import { mountTimeSlider } from './slider.js';
import { addMountainLayer } from '../map-mountains.js';
import { addForestLayer } from '../map-forests.js';
import { addScatterLayer } from '../map-scatter.js';
import { addVolcanoLayer, setVolcanoVisibility } from '../map-volcanoes.js';
import supplementalMarkers from './markers.json';
import theme from './theme.json';
import qidOverrides from '../../../database/data/cliopatria-qid-overrides.json';
import { voyageStyleSources, voyageStyleLayers, initVoyages, applyVoyageYear, applyVoyageStyle, smooth } from './voyages.js';
import nationalColors from './national-colors.json';
import { SATELLITE_SOURCE, SATELLITE_DETAIL_SOURCE, DEM_SOURCE, satelliteLayers, EARTH_COLOURS } from '../map-imagery.js';
import { CLOUD_LAYER_ID } from './clouds.js';
// The whole globe stack, assembled in one place and shared with the lesson map's Earth style. The
// Time-Map used to build the cloud deck by hand and nothing else, which is how seven of these
// layers ended up merged, tested and never loaded by any page.
import { addGlobeLayers } from '../map-globe-layers.js';
import { sweepBorder, outlineOf } from '../map/border-sweep.js';
import { createAdvance } from '../map/advance-layer.js';
import { EASING } from '../easing.js';
import { planetSpacePosition } from './planet-mesh.js';
import { sunDirection } from './sun.js';

// Curated national fill colours (schoolbook hues: NL orange, France blue, Spain gold) keyed by the
// tile QID; a nation's regimes AND colonies share one hue, so Spanish Peru reads as Spain. Polities
// outside the list keep the per-style hash palette. See national-colors.json.
const NATIONAL_PAIRS = nationalColors.families.flatMap((f) => f.qids.flatMap((q) => [q, f.color]));

// Cliopatria reuses one QID across different polities (e.g. Q1068371 = Han Dynasty AND Chauhan
// Dynasty) — rewrite (QID, Name) to the right item before enrichment. QID-only overrides are
// applied server-side, where the enrichment row stays keyed by the tile QID.
const QID_FOR_NAME = new Map(
  qidOverrides.overrides.filter((o) => o.name).map((o) => [`${o.qid}|${o.name}`, o.use]),
);

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
      // Globe projection → a gentle earth curvature, so the parchment reads as a map laid on a 3D
      // table. Subtle at regional zoom; the warm `sky` below is the atmospheric fog/haze.
      projection: { type: 'globe' },
      sky: {
        // A faint atmosphere glow at the globe's limb; the dark "space" itself is the container
        // background gradient (set below). Warm ground haze on the land for depth; atmosphere fades
        // out as you zoom in.
        'atmosphere-blend': ['interpolate', ['linear'], ['zoom'], 2, 0.5, 4, 0.35, 6, 0.12, 7, 0.04],
        'sky-color': '#0a1230',
        'sky-horizon-blend': 0.5,
        'horizon-color': '#1a2c5a',
        'horizon-fog-blend': 0.5,
        'fog-color': '#dcc9a0',       // subtle warm haze on the land (depth)
        'fog-ground-blend': 0.7,
      },
      // Calligraphy labels: locally-built SDF glyphs (scripts/build-glyphs.mjs) — Eagle Lake +
      // Cinzel. No runtime CDN; non-Latin names fall back to nothing (data is romanized).
      glyphs: `${location.origin}/fonts/{fontstack}/{range}.pbf`,
      sources: {
        land: { type: 'vector', tiles: [`${location.origin}/land-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        graticule: { type: 'geojson', data: `${location.origin}/timemap/graticule.geojson` },
        // Elevation for the subtle relief hillshade (free AWS terrain tiles, terrarium-encoded).
        dem: DEM_SOURCE,
        // Real satellite ground for the Satellite style (keyless NASA GIBS Blue Marble). Hidden in
        // every other style, so its tiles are never requested there.
        satellite: SATELLITE_SOURCE,
        'satellite-detail': SATELLITE_DETAIL_SOURCE,
        coast: { type: 'vector', tiles: [`${location.origin}/coast-echo-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        rivers: { type: 'vector', tiles: [`${location.origin}/river-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        lakes: { type: 'vector', tiles: [`${location.origin}/lake-tiles/{z}/{x}/{y}.pbf`], maxzoom: 6 },
        'ink-borders': { type: 'vector', tiles: [`${location.origin}/ink-border-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4 },
        // True coastline (NE 50m, light-simplified) for the bold shore line + its southern drop-shadow.
        coastline: { type: 'geojson', data: `${location.origin}/timemap/coastline.geojson` },
        cliopatria: { type: 'vector', tiles: [`${location.origin}/cliopatria-tiles/{z}/{x}/{y}.pbf`], maxzoom: 4, promoteId: { boundaries: 'Wikidata' } },
        // Explorer voyage routes (curated GeoJSON, baked at import time from voyages.json).
        // Rounded borders: filled in from what is on screen, empty until the control asks for it.
        'boundaries-smooth': { type: 'geojson', data: { type: 'FeatureCollection', features: [] } },
        // The hover shine, as its own geometry rather than a feature-state on the raw polygons —
        // that is what lets it follow the ROUNDED outline when rounding is on.
        'boundaries-glow-src': { type: 'geojson', data: { type: 'FeatureCollection', features: [] } },
        ...voyageStyleSources(),
      },
      layers: [
        { id: 'water', type: 'background', paint: { 'background-color': theme.water } },
        // Satellite ground — bottom of the stack, under the whole atlas. Satellite style only.
        ...satelliteLayers({ visible: false }),
        // Sketched sea grid (old-chart graticule), water-only (clipped at build time). Beneath the
        // coast/land. Recolored + toggled per style by applyMapStyle (atlas styles only).
        { id: 'graticule', type: 'line', source: 'graticule', layout: { visibility: 'none', 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#8a9aa0', 'line-width': 0.6, 'line-opacity': 0.5 } },
        // Etched coast-echo lines sit on the sea, beneath the land fill (ink styles only).
        { id: 'coast-echo', type: 'line', source: 'coast', 'source-layer': 'coast', layout: { visibility: 'none', 'line-cap': 'round' }, paint: { 'line-color': '#6b563d', 'line-width': 0.6 } },
        // Coast drop-shadow: a thick coastline shifted DOWN, drawn BENEATH the land fill — so it only
        // peeks out on south-facing shores (the land fill hides it on north shores), giving relief.
        { id: 'coast-shadow', type: 'line', source: 'coastline', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#574631', 'line-width': 2.6, 'line-translate': [0, 2], 'line-blur': 0.4 } },
        { id: 'land', type: 'fill', source: 'land', 'source-layer': 'land', paint: { 'fill-color': theme.land } },
        // Subtle relief hillshade over the land for depth (atlas styles only; toggled by applyMapStyle).
        // Sun upper-right (~55°) to match the mountains' shaded side.
        { id: 'hillshade', type: 'hillshade', source: 'dem', layout: { visibility: 'none' }, paint: { 'hillshade-exaggeration': 0.45, 'hillshade-illumination-direction': 55, 'hillshade-shadow-color': '#4a3a28', 'hillshade-highlight-color': '#fbf2d8', 'hillshade-accent-color': '#6b563d' } },
        // Lake drop-shadow: the lake outline shifted DOWN, under the fill — peeks out on the southern
        // shore for relief (mirrors the coast treatment). Recolored per style by applyMapStyle.
        { id: 'lake-shadow', type: 'line', source: 'lakes', 'source-layer': 'lakes', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#574631', 'line-width': 2.0, 'line-translate': [0, 1.6], 'line-blur': 0.4 } },
        // Inland lakes — water fill over land (recolored per style by applyMapStyle).
        { id: 'lakes', type: 'fill', source: 'lakes', 'source-layer': 'lakes', paint: { 'fill-color': theme.water } },
        // Thin calligraphic ink edge around every lake, above the fill.
        { id: 'lake-line', type: 'line', source: 'lakes', 'source-layer': 'lakes', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#3a2c1a', 'line-width': 0.7, 'line-opacity': 0.8 } },
        // Bold coast outline — the crisp ink shore line, above land + lakes, below the political layers.
        { id: 'coast-bold', type: 'line', source: 'coastline', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#2f2418', 'line-width': 1.1 } },
        // Voyage routes — declared style-time (runtime-added GeoJSON line layers don't render in
        // this pipeline); initVoyages() lifts them above the runtime boundary layers on load.
        ...voyageStyleLayers(['Cinzel']),
      ],
    },
    /**
     * A CENTRED EARTH, seen straight on.
     *
     * It used to open on Switzerland at zoom 4 with 28° of pitch — a tilted close-up of the northern
     * hemisphere, which made the globe read as a map on a table rather than as a planet, and put the
     * southern hemisphere off the bottom of the frame entirely.
     *
     * Latitude 20 rather than 46.8: the content is northern-heavy and Europe should stay comfortably
     * in frame, but 20 keeps Africa whole and the southern hemisphere visible, where 46.8 did not.
     *
     * PITCH IS NOT ZERO, and that was a wrong turn worth recording. Flat-on, the camera points at
     * the centre of the earth and the horizon leaves the frame entirely — the globe reads as a disc
     * with a map on it. The curvature only exists when you are looking ALONG the surface, so some
     * tilt is what makes it a planet. 45 is enough to put a horizon in the picture without the far
     * hemisphere folding away; the limits are on sliders because this is a taste call.
     *
     * Where it sits VERTICALLY is a separate problem with a separate fix — see centreForChrome.
     */
    center: [8.23, 20],
    zoom: 1.9,
    pitch: 45,
    // 70 up from the 60 MapLibre defaults to, and it can go further now: the far-hemisphere cut
    // reads transform.cameraPosition, where the old lngLat reconstruction was 43° adrift at exactly
    // this kind of angle and drew the sea as a bowl hanging under the earth. 70 keeps a horizon in
    // shot without the globe folding to an edge; Camera → Tilt and turn raises it to 85 to try.
    maxPitch: 70,
    // Allow three extra zoom steps for regional detail. The vector sources cap at z4 but overzoom
    // crisply, and the terrain/label/line sizes interpolate up to z7 so the map gains detail as you
    // zoom. Zoom-out stays open so other continents/markers come into view.
    maxZoom: 7,
    attributionControl: { customAttribution: 'Borders © Cliopatria / Seshat (CC-BY 4.0) · Land © OpenStreetMap (CC0)' },
  });

  // Exposed for dev tooling and the Playwright spec (layer/feature introspection).
  window.__tmMap = map;

  // Dark "space" behind the globe: a very-dark-blue centre fading to near-black at the corners (a
  // vignette). The WebGL canvas is transparent outside the globe, so this container background shows
  // through as space.
  el.style.background = 'radial-gradient(ellipse 90% 90% at 50% 48%, #14224a 0%, #070c1e 55%, #02030a 100%)';

  // The container settles to its final height after Alpine/Livewire mount; without this the
  // map can initialise against a transient height and render short until the next resize.
  const resizeObserver = new ResizeObserver(() => map.resize());
  resizeObserver.observe(el);

  /**
   * Put the globe in the middle of the part of the map you can SEE.
   *
   * The time deck is drawn over the bottom of the map and MapLibre centres on the CONTAINER, so the
   * earth sat about 77px low with a band of empty sky above it and the southern hemisphere tucked
   * behind the deck. Measured, not estimated: projected centre y=457 against a visible middle of 381.
   *
   * IT SHRINKS THE CONTAINER RATHER THAN PADDING THE TRANSFORM. Padding was the obvious answer and
   * it broke the planet: the custom layers cut the far hemisphere using transform.cameraPosition,
   * which padding does not move, so the sea's horizon and the drawn globe disagreed and the ocean
   * rendered as a bowl hanging below the earth. Resizing the box changes nothing the shaders read.
   *
   * Measured from the deck rather than hard-coded — it is `w-176 max-w-[92vw]`, so its height
   * changes with the viewport as its controls wrap. Overridden by Camera → Framing when tuning.
   */
  let chromeInsetLocked = false;
  const centreForChrome = () => {
    if (chromeInsetLocked) return;
    const deck = document.querySelector('[data-timemap-deck]');
    if (!deck) return;
    const box = el.getBoundingClientRect();
    const bar = deck.getBoundingClientRect();
    // Clamped to a third: a deck taller than that means a viewport so short there would be nowhere
    // left to draw the map at all.
    const covered = Math.max(0, Math.min(box.bottom - bar.top, box.height / 3));
    if (Math.abs(parseFloat(el.style.bottom || '0') - covered) < 1) return;
    el.style.bottom = `${covered}px`;
    map.resize();
  };

  // The deck mounts on Alpine's $nextTick — after this module runs AND after the map's first idle,
  // both of which were tried and both of which measured an element that was not there yet. That
  // fails silently as "no inset", so wait for it, then watch it.
  const watchDeck = (attempt = 0) => {
    const deck = document.querySelector('[data-timemap-deck]');
    if (!deck) {
      if (attempt < 30) setTimeout(() => watchDeck(attempt + 1), 100);
      return;
    }
    centreForChrome();
    new ResizeObserver(centreForChrome).observe(deck);
  };
  watchDeck();

  // Atlas styling comes from theme.json — edit colours/lines there, no JS changes needed.
  const ATLAS_PALETTE = theme.palette;
  // HOVER DOES NOT TOUCH THE FILL. Lighting up a whole territory's interior on hover floods the
  // imagery underneath and turns a map into a chart of coloured blocks — the thing you are moving
  // the mouse across stops being the earth. The border glows instead (see boundaries-glow below),
  // which says the same thing about the same shape without covering anything up. Selection still
  // takes the fill: that is a state you chose, not one the pointer wandered into.
  const FILL_COLOR = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], theme.selected,
    // `match` returns colors directly; hash the numeric part of the Wikidata QID into the palette.
    ['match', ['%', ['to-number', ['slice', ['coalesce', ['get', 'Wikidata'], 'Q0'], 1]], ATLAS_PALETTE.length],
      ...ATLAS_PALETTE.flatMap((c, i) => [i, c]), ATLAS_PALETTE[0]],
  ];
  const FILL_OPACITY = [
    'case',
    ['boolean', ['feature-state', 'selected'], false], theme.fillOpacity.selected,
    theme.fillOpacity.normal,
  ];

  /** The hover shine: wide and blurred under the crisp border, invisible until pointed at. */
  const GLOW_COLOR = '#f59e0b';
  const GLOW_WIDTH = ['interpolate', ['linear'], ['zoom'], 0, 3, 4, 7, 8, 12];

  // Cliopatria polities valid at `year`: Type=POLITY, skip composite/alliance extents (names in
  // parentheses overlap their members), within the feature's FromYear..ToYear lifespan.
  const polityFilter = (year) => ['all',
    ['==', ['get', 'Type'], 'POLITY'],
    ['!=', ['slice', ['get', 'Name'], 0, 1], '('],
    ['<=', ['to-number', ['get', 'FromYear']], year],
    ['>=', ['to-number', ['get', 'ToYear']], year],
  ];

  // Smooth appearance/disappearance: instead of popping at the exact FromYear/ToYear, each polity
  // eases its opacity in over its first FADE_YEARS and out over its last FADE_YEARS, as a function of
  // the (continuously moving) year. MapLibre transitions don't apply to feature-property-driven
  // values, so the smoothness comes from the year advancing — not from a paint transition. Because
  // the ramp is already at 0 at the lifespan edges, the exact polityFilter cull stays invisible.
  const FADE_YEARS = 30;
  const fadeFactor = (year) => ['min',
    ['max', 0, ['min', 1, ['/', ['-', year, ['to-number', ['get', 'FromYear']]], FADE_YEARS]]],
    ['max', 0, ['min', 1, ['/', ['-', ['to-number', ['get', 'ToYear']], year], FADE_YEARS]]],
  ];
  // The style-driven base opacities (updated by applyMapStyle), scaled by the fade factor.
  let fillOpacityCase = FILL_OPACITY;
  let lineOpacityValue = 1;

  // Colour strength (bottom-right slider): scales the territory fill intensity around the style's
  // base opacity. 0.55 ≈ the style's designed look; 0 washes out to a faint atlas; 1 is vivid.
  let colorStrength = 0.55;
  try {
    const saved = parseFloat(localStorage.getItem('tm-color-strength'));
    if (Number.isFinite(saved)) colorStrength = Math.min(1, Math.max(0, saved));
  } catch (e) { /* private mode */ }
  const strengthOpacity = (base) => Math.min(0.92, Math.max(0.06, base * (0.45 + 1.1 * colorStrength)));
  /**
   * A territory is in one of three states — resting, under the pointer, or opened by a click — and
   * each has a colour and an opacity. Both are single expressions with a `case` per state, so there
   * is no way to set one state's value by writing a plain number: that would flatten all three.
   *
   * Which is exactly what the settings panel used to do. It called setPaintProperty directly, and
   * then applyBoundaryOpacity (every year tick) and applyMapStyle (every style switch) rewrote the
   * same two properties from the style. The controls appeared to work and were wiped a moment later
   * by an unrelated action — which is worse than not working, because nothing tells you it happened.
   *
   * So the panel owns values here, and the expression is BUILT from them. null means "whatever the
   * active map style says", which is the default and keeps every style looking as designed.
   */
  const fillOverride = {
    normal: { opacity: null },
    hover: { colour: null, opacity: null },
    active: { colour: null, opacity: null },
  };
  let usePalette = true;

  /**
   * The same problem for everything else applyMapStyle repaints: border colour, width, softness, the
   * label ink. A style switch used to reset all of them, so a tuned value survived only until the
   * next style change. Keyed `layer.property`; a value here wins over the style's.
   */
  const styleOverride = {};
  const ownedPaint = (layer, prop, styleValue) => {
    const v = styleOverride[`${layer}.${prop}`];
    if (map.getLayer(layer)) {
      try { map.setPaintProperty(layer, prop, v !== undefined ? v : styleValue); } catch (e) { /* parsing */ }
    }
  };

  /** The fill colour for all three states, from the active style unless the panel overrode it. */
  const territoryFillExpr = () => {
    const s = MAP_STYLES[currentStyleName] || {};
    const pal = s.palette || ATLAS_PALETTE;
    const palette = ['match', ['coalesce', ['get', 'Wikidata'], 'Q0'],
      ...NATIONAL_PAIRS,
      ['match', ['%', ['to-number', ['slice', ['coalesce', ['get', 'Wikidata'], 'Q0'], 1]], pal.length],
        ...pal.flatMap((c, i) => [i, c]), pal[0]]];
    return ['case',
      ['boolean', ['feature-state', 'selected'], false], fillOverride.active.colour ?? s.selected ?? theme.selected,
      ['boolean', ['feature-state', 'hover'], false], fillOverride.hover.colour ?? s.hover ?? theme.hover,
      usePalette ? palette : fillColour];
  };

  // Recompute the fill-opacity case (selected/hover boosted) from the active style + slider.
  const refreshFillOpacity = () => {
    const s = MAP_STYLES[currentStyleName] || {};
    const base = strengthOpacity(s.fillOpacity != null ? s.fillOpacity : theme.fillOpacity.normal);
    fillOpacityCase = ['case',
      ['boolean', ['feature-state', 'selected'], false], fillOverride.active.opacity ?? Math.max(0.85, base),
      ['boolean', ['feature-state', 'hover'], false], fillOverride.hover.opacity ?? Math.max(0.6, base + 0.18),
      fillOverride.normal.opacity ?? base];
  };

  /** The one way to repaint the territories. Everything that changes a fill value ends up here. */
  const applyTerritoryFill = () => {
    if (!map.getLayer('boundaries-fill')) return;
    try { map.setPaintProperty('boundaries-fill', 'fill-color', territoryFillExpr()); } catch (e) { /* parsing */ }
    refreshFillOpacity();
    applyBoundaryOpacity(state.year);
  };
  // Called by the colour slider (Blade, bottom right).
  window.__tmSetColorStrength = (v) => {
    colorStrength = Math.min(1, Math.max(0, Number(v) || 0));
    try { localStorage.setItem('tm-color-strength', String(colorStrength)); } catch (e) { /* private mode */ }
    refreshFillOpacity();
    applyBoundaryOpacity(state.year);
  };
  const fillFadeExpr = (year) => ['*', fillOpacityCase, fadeFactor(year)];
  const lineFadeExpr = (year) => ['*', lineOpacityValue, fadeFactor(year)];

  // Fade only while the timeline is auto-playing. When paused or manually scrubbing, every active
  // polity is shown at full base opacity — the exact polityFilter already limits to the current era,
  // so nothing is dimmed by lifespan proximity.
  let fadeMode = false;
  const applyBoundaryOpacity = (year) => {
    if (!map.getLayer('boundaries-fill')) return;
    map.setPaintProperty('boundaries-fill', 'fill-opacity', fadeMode ? fillFadeExpr(year) : fillOpacityCase);
    if (map.getLayer('boundaries-line')) {
      map.setPaintProperty('boundaries-line', 'line-opacity', fadeMode ? lineFadeExpr(year) : lineOpacityValue);
    }
  };
  // Wobbled ink-border lines (pre-filtered to POLITY at build time) carry only FromYear/ToYear.
  const borderDateFilter = (year) => ['all',
    ['<=', ['to-number', ['get', 'FromYear']], year],
    ['>=', ['to-number', ['get', 'ToYear']], year],
  ];
  // Supplemental markers (markers.json) use their own start/end decimal-year lifespan.
  const markerFilter = (year) => ['all',
    ['<=', ['coalesce', ['to-number', ['get', 'start_decdate']], -1e6], year],
    ['>', ['coalesce', ['to-number', ['get', 'end_decdate']], 1e6], year],
  ];
  const applyYear = (year) => {
    // Before the early return: the sun is not a map layer, so it must not be gated on the borders
    // having loaded. Scrubbing the slider during the first tile load would otherwise leave the
    // planet lit for whatever year the page opened on.
    globe?.setDate(dateForYear(year));
    if (!map.getLayer('boundaries-fill')) return;
    map.setFilter('boundaries-fill', polityFilter(year));
    map.setFilter('boundaries-line', polityFilter(year));
    // The hover glow tracks the same era as the border it sits under, or scrubbing the timeline
    // leaves it lighting up territories that no longer exist.
    if (map.getLayer('boundaries-glow')) map.setFilter('boundaries-glow', polityFilter(year));
    scheduleSmoothBorders();   // different era, different outlines to round
    applyBoundaryOpacity(year); // full opacity when static; eases in/out only while playing
    map.setFilter('markers-dot', markerFilter(year));
    map.setFilter('markers-label', markerFilter(year));
    applyVoyageYear(map, year);
    if (voyageShips) voyageShips.setYear(year);
    const inkF = MAP_STYLES[currentStyleName] && MAP_STYLES[currentStyleName].borderSource ? borderDateFilter(year) : polityFilter(year);
    for (let i = 0; i < 6; i++) { if (map.getLayer(`ink-${i}`)) map.setFilter(`ink-${i}`, inkF); }
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
  // Hand-tuned label anchors (QID → [lng, lat]) that override the auto centroid where it crowds a
  // neighbour. Crown of Castile's centroid sits central-Iberia and squeezes Portugal's name; pin it
  // up by Madrid so the two never collide.
  const LABEL_ANCHOR = {
    Q217196: [-3.7, 40.4], // Crown of Castile → Madrid
  };
  /** Last computed label points, kept so a hover can re-flag one without re-querying every tile. */
  let labelBest = new Map();
  const writeLabels = () => {
    const src = map.getSource('labels');
    if (!src) return;
    src.setData({
      type: 'FeatureCollection',
      features: [...labelBest.entries()].map(([id, b]) => ({
        type: 'Feature', geometry: { type: 'Point', coordinates: LABEL_ANCHOR[id] || b.c },
        properties: {
          name: b.name,
          flag: map.hasImage(`flag-${id}`) ? `flag-${id}` : '',
          sel: String(selectedId) === id,
          hov: b.name === labelHoverName,
        },
      })),
    });
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
    // `sel`/`hov` drive symbol-sort-key so the open territory's name — and the one under the
    // pointer — are placed first and never culled by a neighbour's label.
    labelBest = best;
    const apply = writeLabels;
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
  /** The amber light currently running around a clicked territory, so a new click replaces it. */
  let borderSweep = null;

  /**
   * ROUNDED CORNERS — a second border, drawn from the same polygons run through a spline.
   *
   * Cliopatria's outlines are generalised political shapes: straight runs meeting at hard vertices,
   * which is why the map reads as a digital thing rather than a drawn one. No line-join setting
   * fixes that, because the angles are in the GEOMETRY, not in how the line is capped.
   *
   * So the polygon keeps the job it is good at — telling us what you clicked — and a smoothed copy
   * of its outline becomes the visible border. Exactly the split Bart described.
   *
   * The curve is `smooth` from voyages.js: the centripetal Catmull-Rom that already rounds every
   * sailing route on this map. A route and a frontier are the same problem, so they get the same
   * spline rather than a second one written to look similar.
   *
   * Rebuilt from what is ON SCREEN (queryRenderedFeatures), so the cost tracks the view rather than
   * the dataset — at 0 it does nothing at all and the plain border comes back.
   */
  const SMOOTH_SRC = 'boundaries-smooth';
  const EMPTY_FC = { type: 'FeatureCollection', features: [] };
  let smoothSamples = 0;
  let smoothPending = null;
  let fillColour = '#5b7fa8';

  const rebuildSmoothBorders = () => {
    const src = map.getSource(SMOOTH_SRC);
    if (!src) return;
    if (smoothSamples < 1) { src.setData(EMPTY_FC); return; }

    const features = [];
    const seen = new Set();
    for (const f of map.queryRenderedFeatures({ layers: ['boundaries-fill'] })) {
      // queryRenderedFeatures returns a polity once per tile it touches; the rings are already
      // clipped per tile, so each piece is smoothed on its own and the seams stay where they were.
      const key = `${f.id}|${f.geometry?.coordinates?.[0]?.[0]?.[0] ?? ''}`;
      if (seen.has(key)) continue;
      seen.add(key);
      for (const ring of outlineOf(f.geometry)) {
        if (ring.length < 4) continue;
        features.push({
          type: 'Feature', properties: {},
          geometry: { type: 'LineString', coordinates: smooth(ring, smoothSamples) },
        });
      }
    }
    src.setData({ type: 'FeatureCollection', features });
  };

  /**
   * Samples per segment through the spline. 0 leaves the raw polygon border; anything above swaps in
   * the smoothed copy, so the two are never both drawn — which is the whole point, since drawing
   * both puts the sharp corners straight back over the rounded ones. applyMapStyle has to honour
   * this too; see the visibility line there.
   */
  const applyRounding = (v) => {
    smoothSamples = Math.round(v);
    const on = smoothSamples >= 1;
    for (const [layer, vis] of [['boundaries-line', !on], ['boundaries-smooth', on]]) {
      if (map.getLayer(layer)) { try { map.setLayoutProperty(layer, 'visibility', vis ? 'visible' : 'none'); } catch (e) { /* parsing */ } }
    }
    rebuildSmoothBorders();
  };

  /** Coalesce the rebuild: a pan fires moveend once, but a year scrub fires applyYear per tick. */
  const scheduleSmoothBorders = () => {
    if (smoothPending) clearTimeout(smoothPending);
    smoothPending = setTimeout(() => { smoothPending = null; rebuildSmoothBorders(); }, 120);
  };
  let selectedId = null;
  let voyageShips = null; // set once the lazy three.js ships chunk loads
  /**
   * Light the outline under the pointer.
   *
   * Drawn from the hovered feature's OWN geometry rather than as a feature-state on the raw
   * polygons, so it picks up the rounding for free: the same spline the visible border uses, on the
   * same shape. A feature-state glow could only ever trace the hard-cornered source, which is
   * exactly what it did — rounded borders with a sharp-cornered halo behind them.
   */
  /**
   * Hovering a territory grows its name where it already is.
   *
   * text-size is a LAYOUT property, so two ordinary routes are closed: MapLibre cannot transition
   * it, and it cannot read feature-state (that is paint-only). What it CAN do is read a feature
   * property, so the size is a `case` on the name and the tween is stepped here — which is also the
   * only way to honour a named curve rather than whatever MapLibre would have interpolated.
   *
   * Stepping a layout property re-places the symbols each frame. That is fine at this scale: the
   * labels source holds one point per polity on screen, a couple of dozen at most.
   */
  let labelBaseSize = 12;
  let labelHoverScale = 1.35;
  let labelHoverMs = 180;
  let labelHoverName = null;
  let labelScaleNow = 1;
  let labelTween = null;
  let labelSettle = null;

  const applyLabelSize = () => {
    if (!map.getLayer('boundaries-label')) return;
    const big = labelBaseSize * labelScaleNow;
    const size = labelHoverName && labelScaleNow !== 1
      ? ['case', ['==', ['get', 'name'], labelHoverName], big, labelBaseSize]
      : labelBaseSize;
    try { map.setLayoutProperty('boundaries-label', 'text-size', size); } catch (e) { /* parsing */ }
  };

  const hoverLabel = (name) => {
    if (name === labelHoverName) return;
    // Keep growing/shrinking from wherever the last tween got to, so flicking between neighbours
    // does not snap back to 1 before starting again.
    const from = labelScaleNow;
    const to = name ? labelHoverScale : 1;
    labelHoverName = name || labelHoverName; // hold the name while it shrinks back
    const settleTo = name;
    writeLabels(); // once per hover change, so `hov` (placement priority) is current
    if (labelTween) cancelAnimationFrame(labelTween);
    if (labelSettle) clearTimeout(labelSettle);
    const started = performance.now();
    const finish = () => {
      labelScaleNow = to;
      labelHoverName = settleTo;
      applyLabelSize();
      if (!settleTo) writeLabels();
    };
    const step = (now) => {
      const t = labelHoverMs > 0 ? Math.min(1, (now - started) / labelHoverMs) : 1;
      labelScaleNow = from + (to - from) * EASING.easeInOutQuad(t);
      applyLabelSize();
      if (t < 1) { labelTween = requestAnimationFrame(step); return; }
      labelTween = null;
      finish();
    };
    labelTween = requestAnimationFrame(step);
    // requestAnimationFrame does not fire in a backgrounded tab, so without this the tween never
    // starts and the name silently keeps its old size — hovering, switching tabs and coming back
    // would leave it wrong with nothing to show why. The timer lands the END state regardless; if
    // the tween did run, this is a no-op that sets what is already set.
    labelSettle = setTimeout(() => {
      labelSettle = null;
      if (labelTween) { cancelAnimationFrame(labelTween); labelTween = null; }
      finish();
    }, labelHoverMs + 40);
  };

  const showGlow = (geometry, name = null, qid = null) => {
    const src = map.getSource('boundaries-glow-src');
    if (!src) return;
    const rings = (geometry ? outlineOf(geometry) : []).filter((r) => r.length >= 4);

    const features = rings.map((ring) => ({
      type: 'Feature', properties: {},
      geometry: { type: 'LineString', coordinates: smoothSamples >= 1 ? smooth(ring, smoothSamples) : ring },
    }));

    src.setData({ type: 'FeatureCollection', features });

    // The NAME is not drawn here. It used to be — a second symbol layer over the same words — and
    // because that layer anchored differently from the real label, hovering visibly nudged the name
    // sideways. Two layers can be aligned, and I tried; they still cannot be kept aligned through a
    // font change, an offset change or a flag appearing above the text.
    //
    // So there is ONE name layer, always, and hovering only makes it bigger. Nothing moves.
    hoverLabel(name);
  };
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
    'soft-atlas': { palette: ATLAS_PAL, water: '#c7d4c6', shore: { color: '#5b4a36', width: 0.7, shadow: '#9fb0b4', shadowWidth: 1.8, dy: 1.6 }, land: '#efe6d0', fillOpacity: 0.55, selected: '#f5c518', hover: '#ecd9a0', line: { color: '#6b5640', width: 0.8, blur: 0.3 }, grid: { color: '#93a18f', opacity: 0.5, width: 0.5 }, hillshade: true, text: { color: '#3b3326', halo: '#f3ead6' }, voyage: '#1f5f8f', paper: 0.08, vignette: 'rgba(80,55,30,0.14)' },
    'antique': { palette: ATLAS_PAL, water: '#dcdcba', shore: { color: '#43301c', width: 1.0, shadow: '#8f7d5c', shadowWidth: 2.5, dy: 2 }, land: '#e8d6ac', fillOpacity: 0.3, selected: '#e0a200', hover: '#d9c089', line: { color: '#4a3420', width: 1.7, blur: 0.25 }, coast: { color: '#6a5238', opacity: 0.5, width: 0.85 }, river: { color: '#8a9aa0', opacity: 0.6, width: 0.7 }, mountains: true, forest: true, hillshade: true, grid: { color: '#9b9277', opacity: 0.55, width: 0.55 }, text: { color: '#3a2c1a', halo: '#ecdcb8' }, voyage: '#274f66', paper: 0.2, vignette: 'rgba(80,55,30,0.3)' },
    'pen-ink': {
      palette: ATLAS_PAL, water: '#dedec0', land: '#e6d6ad', fillOpacity: 0.16, selected: '#c98a00', hover: '#d9c089',
      shore: { color: '#2f2418', width: 1.15, shadow: '#574631', shadowWidth: 2.9, dy: 2 },
      line: { color: '#3a2c1c', width: inkWidth(0.4, 1.2), blur: 0.25 },
      // Draw borders from the wobbled ink-border tileset (hand-drawn jitter baked into geometry).
      borderSource: { source: 'ink-borders', sourceLayer: 'ink' },
      coast: { color: '#5e4a34', opacity: 0.55, width: 0.85 },
      river: { color: '#6a7c74', opacity: 0.55, width: 0.6 },
      grid: { color: '#8f8c6e', opacity: 0.45, width: 0.5 }, hillshade: true,
      mountains: true,
      forest: true,
      // Stacked passes on the wobbled lines: faint bleed, offset rough, the main dark stroke, then a
      // light broken accent — all width-varied so borders read as uneven pen work.
      inkLayers: [
        { color: '#5a4630', width: inkWidth(1.2, 2.4), opacity: 0.1, blur: 1.4 },
        { color: '#4a3826', width: inkWidth(0.7, 1.5), opacity: 0.18, blur: 0.6, offset: 0.4 },
        { color: '#33261a', width: inkWidth(0.4, 1.2), opacity: 0.8, blur: 0.2 },
        { color: '#2a1f12', width: 0.6, opacity: 0.32, blur: 0.1, offset: 0.4, dash: [3, 3], above: true },
      ],
      text: { color: '#33271a', halo: '#efe2c4' }, voyage: '#2f4e66', paper: 0.22, vignette: 'rgba(80,55,30,0.3)',
    },
    // Real satellite ground instead of a drawn one. `imagery` turns the raster on and the painted
    // atlas ground (land fill, sea grid, coast shadow, lakes, ink hills/forests) off — the photo
    // already shows all of that, relief and ocean depth included, so no hillshade either (over
    // imagery it only lays a grey haze). Borders keep a light wash so territories still read.
    'satellite': {
      palette: NIGHT_PAL, imagery: true, water: '#08131f', land: '#26331d', fillOpacity: 0.22,
      selected: '#f5c518', hover: '#e8cf94',
      shore: { color: '#f6efdc', width: 0.6, shadow: 'rgba(0,0,0,0)', shadowWidth: 0, dy: 0 },
      line: { color: '#ffd9a0', width: 0.9, blur: 0.2 },
      text: { color: '#241a10', halo: '#f2e9d4' }, voyage: '#ffce6b', paper: 0, vignette: 'rgba(0,0,0,0.4)',
    },
    // Earth — Satellite v2, and the same planet the lesson map draws (shared EARTH_COLOURS). The
    // photographed ground with the globe render over it: sea lit as water, the terminator, the haze
    // at the limb, the cloud deck. Until now this existed only in lessons, so choosing it here fell
    // through to Soft Atlas without a word — see the warning in applyMapStyle.
    'earth': {
      palette: NIGHT_PAL, imagery: true, globe: true,
      water: EARTH_COLOURS.water, land: EARTH_COLOURS.land, fillOpacity: 0.22,
      selected: EARTH_COLOURS.selected, hover: EARTH_COLOURS.hover,
      shore: { color: EARTH_COLOURS.coast, width: 0.6, shadow: 'rgba(0,0,0,0)', shadowWidth: 0, dy: 0 },
      line: { color: EARTH_COLOURS.line, width: 0.9, blur: 0.2 },
      text: { color: EARTH_COLOURS.text, halo: EARTH_COLOURS.halo },
      voyage: EARTH_COLOURS.voyage, paper: 0, vignette: 'rgba(0,0,0,0.4)',
    },
    'night': { palette: NIGHT_PAL, water: '#0f1420', shore: { color: '#8a99b8', width: 0.7, shadow: '#070b12', shadowWidth: 2.0, dy: 1.6 }, land: '#1b2230', fillOpacity: 0.6, selected: '#f5c518', hover: '#5a6b8c', line: { color: '#8a99b8', width: 0.6, blur: 0.2 }, text: { color: '#e6ecf7', halo: '#10151f' }, voyage: '#8fc3ef', paper: 0, vignette: 'rgba(0,0,0,0.45)' },
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
  // Wave animation: gently pulse the coast-echo rings with an outward-travelling phase so the
  // etched sea looks like rolling waves. Throttled to ~18fps; disabled for reduced-motion users.
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let waveRAF = null, coastCfg = null, lastWave = 0;
  const waveTick = () => {
    waveRAF = requestAnimationFrame(waveTick);
    const now = performance.now();
    if (now - lastWave < 55) return;
    lastWave = now;
    if (!coastCfg || !map.getLayer('coast-echo')) return;
    const t = now / 1000, base = coastCfg.opacity;
    const op = (echo) => {
      // Gentle outward fade — 0.7^echo keeps the outer ripple lines readable (was 0.45, which
      // killed rings 2–3). echo 0 = nearest/darkest.
      const fade = base * Math.pow(0.7, echo);
      return Math.max(0.05, fade * (0.74 + 0.26 * Math.sin(t * 0.9 - echo * 0.9)));
    };
    map.setPaintProperty('coast-echo', 'line-opacity', ['interpolate', ['linear'], ['to-number', ['get', 'echo']], 0, op(0), 1, op(1), 2, op(2), 3, op(3)]);
  };
  const startWaves = (coast) => { coastCfg = coast; if (!reduceMotion && !waveRAF) waveRAF = requestAnimationFrame(waveTick); };
  const stopWaves = () => { coastCfg = null; if (waveRAF) { cancelAnimationFrame(waveRAF); waveRAF = null; } };

  // The globe stack (sea, terminator, haze, sky, sun, moon, clouds) — held so the slider can move
  // the light and __tmSetLayer can dim any of them.
  let globe = null;
  /**
   * A year on the slider, as a moment the sun can be computed for.
   *
   * Midsummer noon UTC: a year alone does not say which day or hour, and any choice shows in the
   * terminator, so it is stated here once rather than being an accident of `new Date(year, 0)` —
   * which would put every era at midwinter midnight and light the wrong hemisphere.
   */
  const dateForYear = (year) => new Date(Date.UTC(Number.isFinite(year) ? year : 2000, 5, 21, 12));

  /**
   * How lit the place you are looking at is — 1 in full sun, 0 in full night.
   *
   * Uses planetSpacePosition and sunDirection rather than building a vector by hand: the axis order
   * on this globe is a known trap (MapLibre lists our axes z, y, x) and it cost 43° of arc earlier
   * tonight. Two functions that already agree with each other are safer than a third opinion.
   */
  const daylightAtCentre = () => {
    try {
      const { lng, lat } = map.getCenter();
      const n = planetSpacePosition(lng, lat, 0);
      const s = sunDirection(dateForYear(state.year));
      const dot = n[0] * s[0] + n[1] * s[1] + n[2] * s[2];
      // Smooth across the terminator rather than snapping at the exact line.
      return Math.max(0, Math.min(1, dot * 0.5 + 0.5));
    } catch (e) {
      return 1;
    }
  };

  /**
   * Dev-panel controls for the things this file decides: how territories are drawn, how far the map
   * lets you zoom, and where the camera starts.
   *
   * The camera pair is the shape of an entrance move — set a start, set an end, press Play and watch
   * it. The animation itself is not built; this is here so the numbers for it can be found by eye
   * instead of guessed, which is the whole point of the panel.
   */
  const registerTuning = () => {
    if (!window.__tune) return;

    const paint = (layer, prop, value) => {
      if (map.getLayer(layer)) { try { map.setPaintProperty(layer, prop, value); } catch (e) { /* style still parsing */ } }
    };
    /**
     * Territory names are drawn by TWO layers — the resting one and the one that appears under the
     * pointer — and they are the same words in the same place. Anything that changes how a name
     * looks has to change both, or hovering would swap the font out from under you.
     *
     * Glyph stacks are SDF-baked per font (scripts/build-glyphs.mjs), so this list is what is built,
     * not what is installed: naming a font with no .pbf makes the labels disappear entirely.
     */
    // Every layer that writes a place name. Kept as one list precisely because these drifted apart:
    // the manual markers were on a different font and case from the territories for as long as both
    // existed. Size is NOT driven from here — applyLabelSize owns boundaries-label alone, so the
    // region/polity size hierarchy stays.
    const LABEL_LAYERS = ['boundaries-label', 'markers-label'];
    const FONT_STACKS = [['Cinzel', 'Cinzel (map serif)'], ['Eagle Lake', 'Eagle Lake (calligraphy)'], ['inter', 'Inter (plain)']];
    const labelLayout = (prop, value) => {
      for (const layer of LABEL_LAYERS) {
        if (map.getLayer(layer)) {
          try { map.setLayoutProperty(layer, prop, value); } catch (e) { /* style still parsing */ }
        }
      }
    };
    const labelPaint = (prop, value) => { for (const layer of LABEL_LAYERS) paint(layer, prop, value); };

    // The border colour has to survive the highlight case, or clicking a territory stops showing it.
    const borderColour = (colour) => {
      const expr = ['case', ['boolean', ['feature-state', 'highlight'], false], PALETTE.highlight, colour];
      styleOverride['boundaries-line.line-color'] = expr;
      ownedPaint('boundaries-line', 'line-color', expr);
    };

    const camera = { startLng: 0, startLat: 20, startZoom: 0.4, endLng: 8.23, endLat: 46.8, endZoom: 4, seconds: 3.5 };

    // The fleet arrives in a lazy chunk after this runs, so a control touched too early — or on a
    // map whose ships failed to load — would silently do nothing. Say so instead.
    const fleet = () => {
      if (!voyageShips) console.warn('[tune] no fleet on this map yet');
      return voyageShips;
    };

    /**
     * The hover shine, which has no other way to be judged: it only exists under the pointer, so it
     * cannot be seen on a static screen or checked from a screenshot.
     *
     * Plain numbers, because the glow now has its own source that is EMPTY when nothing is hovered
     * — there is no off-state to express in the paint any more.
     */
    const glow = { colour: GLOW_COLOR, strength: 0.55, width: 1, blur: 6 };
    const applyGlow = () => {
      paint('boundaries-glow', 'line-color', glow.colour);
      paint('boundaries-glow', 'line-opacity', glow.strength);
      paint('boundaries-glow', 'line-width', ['*', GLOW_WIDTH, glow.width]);
      paint('boundaries-glow', 'line-blur', glow.blur);
    };

      // ── An army taking ground ────────────────────────────────────────────────────────────────
      //
      // The documentary effect: a front creeping across a country in rounded lobes, like ink
      // spreading through paper. See advance-field.js for why it is an arrival field and not an
      // animation — and why the front moves on √t rather than at a constant speed.
      //
      // Germany into Poland, September 1939, is the reference case: three axes (the western
      // border, East Prussia in the north, Slovakia in the south) converging, which is also the
      // case that shows off the merge — fronts that meet fuse into one shape instead of crossing.
      const ADVANCE_CASES = {
        'Poland 1939': {
          year: 1939,
          match: /^(second )?poland|polish republic/i,
          // Where the Wehrmacht crossed, roughly: Pomerania/Silesia in the west, East Prussia in
          // the north, and the Slovak border in the south.
          seeds: [[15.0, 52.3], [20.5, 54.0], [19.6, 49.4]],
        },
      };
      let advance = null;
      let advanceCase = 'Poland 1939';

      /** Every rendered piece of the target country, unioned by being drawn into the same mask. */
      const advanceTarget = (test) => {
        const parts = map.queryRenderedFeatures({ layers: ['boundaries-fill'] })
          .filter((f) => test.test(String(f.properties?.Name ?? '')));
        if (!parts.length) return null;
        const coordinates = parts.flatMap((f) => (
          f.geometry.type === 'Polygon' ? [f.geometry.coordinates]
            : f.geometry.type === 'MultiPolygon' ? f.geometry.coordinates : []
        ));
        return coordinates.length ? { type: 'MultiPolygon', coordinates } : null;
      };

      const advanceOptions = () => ({
        colour: advanceColour, rimColour: advanceRim, opacity: advanceOpacity,
        seconds: advanceSeconds, curve: advanceCurve, lobeScale: advanceLobeScale,
        lobeAmount: advanceLobeAmount, mergeKm: advanceMerge, rimKm: advanceRimKm,
      });

      /**
       * Jump to the year, find the country, build the field, play. Split out because the field is
       * built once and replayed — rebuilding it per play would also reshuffle the lobes, and a
       * shape that changes every replay cannot be judged against the one before it.
       */
      const runAdvance = () => {
        const preset = ADVANCE_CASES[advanceCase];
        if (!preset) return;
        window.__setTimemapYear(preset.year);
        // Tiles for the new year have to arrive before the country can be found.
        setTimeout(() => {
          const geometry = advanceTarget(preset.match);
          if (!geometry) {
            console.warn(`[tune] no territory matching ${preset.match} on screen at ${preset.year} — pan to it and try again`);
            return;
          }
          if (!advance) {
            advance = createAdvance(map, { beforeId: 'boundaries-label', ...advanceOptions() });
            window.__tmAdvance = advance; // dev tooling + Playwright
          } else advance.setOptions(advanceOptions());
          if (advance.prepare(geometry, preset.seeds)) advance.play();
        }, 700);
      };

      let advanceColour = '#b31217';
      let advanceRim = '#4a0505';
      let advanceOpacity = 0.85;
      let advanceSeconds = 4;
      let advanceCurve = 0.5;
      let advanceLobeScale = 6;
      let advanceLobeAmount = 45;
      let advanceMerge = 120;
      let advanceRimKm = 40;
      // Shape controls change the field itself, so they need a rebuild rather than a repaint.
      const reshape = () => { if (advance) { advance.setOptions(advanceOptions()); runAdvance(); } };
      const restyle = () => { if (advance) advance.setOptions(advanceOptions()); };


    tuneOff = [
      // A territory is resting, hovered, or opened by a click. Each state gets its own colour and
      // opacity, and each writes into the expression rather than over it — see fillOverride.
      // "From style" leaves that state exactly as the map style designed it.
      window.__tune.register('Territory · resting', [
        { key: 'usePalette', label: 'Per-country colours', type: 'boolean', value: true,
          apply: (on) => { usePalette = on; applyTerritoryFill(); } },
        { key: 'restColour', label: 'Colour', type: 'color', value: '#5b7fa8',
          apply: (v) => { fillColour = v; applyTerritoryFill(); } },
        { key: 'restOpacity', label: 'Opacity', min: 0, max: 1, step: 0.02, value: 0,
          // 0 hands the state back to the style, which is the default and is NOT "invisible" — the
          // styles set their own base between 0.3 and 0.7. Anything above is an explicit override.
          apply: (v) => { fillOverride.normal.opacity = v > 0 ? v : null; applyTerritoryFill(); } },
      ], { tab: 'Map' }),

      window.__tune.register('Territory · hover', [
        { key: 'hoverColour', label: 'Colour', type: 'color', value: theme.hover,
          apply: (v) => { fillOverride.hover.colour = v; applyTerritoryFill(); } },
        { key: 'hoverOpacity', label: 'Opacity', min: 0, max: 1, step: 0.02, value: 0,
          apply: (v) => { fillOverride.hover.opacity = v > 0 ? v : null; applyTerritoryFill(); } },
      ], { tab: 'Map' }),

      // The state that had no controls at all: what a territory looks like once it is clicked open.
      window.__tune.register('Territory · active', [
        { key: 'activeColour', label: 'Colour', type: 'color', value: theme.selected,
          apply: (v) => { fillOverride.active.colour = v; applyTerritoryFill(); } },
        { key: 'activeOpacity', label: 'Opacity', min: 0, max: 1, step: 0.02, value: 0,
          apply: (v) => { fillOverride.active.opacity = v > 0 ? v : null; applyTerritoryFill(); } },
      ], { tab: 'Map' }),

      window.__tune.register('Advance', [
        { key: 'play', label: 'Play advance', type: 'button', apply: runAdvance },
        { key: 'seconds', label: 'Seconds', min: 1, max: 15, step: 0.5, value: 4,
          apply: (v) => { advanceSeconds = v; restyle(); } },
        // 0.5 is √t, how liquid actually creeps. 1 is a constant speed — worth a look, because it
        // is what makes hand-animated versions of this read as mechanical.
        { key: 'curve', label: 'Slowdown (0.5 = liquid, 1 = linear)', min: 0.25, max: 1, step: 0.05, value: 0.5,
          apply: (v) => { advanceCurve = v; restyle(); } },
        { key: 'colour', label: 'Colour', type: 'color', value: '#b31217',
          apply: (v) => { advanceColour = v; restyle(); } },
        { key: 'rimColour', label: 'Leading edge', type: 'color', value: '#4a0505',
          apply: (v) => { advanceRim = v; restyle(); } },
        { key: 'rimKm', label: 'Leading edge depth (km)', min: 0, max: 200, step: 5, value: 40,
          apply: (v) => { advanceRimKm = v; restyle(); } },
        { key: 'opacity', label: 'Opacity', min: 0, max: 1, step: 0.05, value: 0.85,
          apply: (v) => { advanceOpacity = v; restyle(); } },
        { key: 'lobeAmount', label: 'Lobe depth (km)', min: 0, max: 150, step: 5, value: 45,
          apply: (v) => { advanceLobeAmount = v; reshape(); } },
        { key: 'lobeScale', label: 'Lobe count', min: 1, max: 20, step: 1, value: 6,
          apply: (v) => { advanceLobeScale = v; reshape(); } },
        { key: 'mergeKm', label: 'Front merge (km)', min: 0, max: 400, step: 10, value: 120,
          apply: (v) => { advanceMerge = v; reshape(); } },
        { key: 'clear', label: 'Clear', type: 'button', apply: () => advance?.clear() },
      ], { tab: 'Map' }),

      window.__tune.register('Territory labels', [
        { key: 'font', label: 'Font', type: 'select', options: FONT_STACKS, value: 'Cinzel',
          apply: (v) => labelLayout('text-font', [v]) },
        { key: 'size', label: 'Size', min: 6, max: 32, step: 0.5, value: 12,
          // Owned, not painted: the hover tween rebuilds text-size every frame from this.
          apply: (v) => { labelBaseSize = v; applyLabelSize(); } },
        { key: 'hoverScale', label: 'Hover scale', min: 1, max: 2.5, step: 0.05, value: 1.35,
          apply: (v) => { labelHoverScale = v; } },
        { key: 'hoverMs', label: 'Hover speed (ms)', min: 0, max: 800, step: 10, value: 180,
          apply: (v) => { labelHoverMs = v; } },
        { key: 'tracking', label: 'Letter spacing', min: 0, max: 0.4, step: 0.01, value: 0.06,
          apply: (v) => labelLayout('text-letter-spacing', v) },
        { key: 'caps', label: 'Small caps', type: 'boolean', value: true,
          apply: (on) => labelLayout('text-transform', on ? 'uppercase' : 'none') },
        // Over bright cloud a name can wash out completely — the halo is what keeps it readable,
        // and on Earth there is a lot of white to sit on.
        { key: 'haloWidth', label: 'Halo width', min: 0, max: 5, step: 0.25, value: 2,
          apply: (v) => labelPaint('text-halo-width', v) },
        { key: 'haloBlur', label: 'Halo softness', min: 0, max: 4, step: 0.25, value: 0.5,
          apply: (v) => labelPaint('text-halo-blur', v) },
      ], { tab: 'Map' }),

      window.__tune.register('Territory borders', [
        { key: 'lineColor', label: 'Border', type: 'color', value: '#8a99b8', apply: borderColour },
        { key: 'lineWidth', label: 'Border width', min: 0, max: 6, step: 0.1, value: 1,
          apply: (v) => { styleOverride['boundaries-line.line-width'] = v; ownedPaint('boundaries-line', 'line-width', v); } },
        { key: 'lineOpacity', label: 'Border opacity', min: 0, max: 1, step: 0.05, value: 1,
          // Owned, not painted: applyBoundaryOpacity rewrites line-opacity on every year tick.
          apply: (v) => { styleOverride['boundaries-line.line-opacity'] = v; lineOpacityValue = v; applyBoundaryOpacity(state.year); } },
        { key: 'lineBlur', label: 'Border softness', min: 0, max: 4, step: 0.1, value: 0,
          apply: (v) => { styleOverride['boundaries-line.line-blur'] = v; ownedPaint('boundaries-line', 'line-blur', v); } },
        { key: 'labelColor', label: 'Label', type: 'color', value: '#e6ecf7',
          apply: (v) => { styleOverride['boundaries-label.text-color'] = v; ownedPaint('boundaries-label', 'text-color', v); } },
        { key: 'labelHalo', label: 'Label halo', type: 'color', value: '#10151f',
          apply: (v) => { styleOverride['boundaries-label.text-halo-color'] = v; ownedPaint('boundaries-label', 'text-halo-color', v); } },
      ], { tab: 'Map' }),

      window.__tune.register('Hover glow', [
        { key: 'glowColour', label: 'Glow', type: 'color', value: GLOW_COLOR,
          apply: (v) => { glow.colour = v; applyGlow(); } },
        { key: 'glowStrength', label: 'Strength', min: 0, max: 1, step: 0.05, value: 0.55,
          apply: (v) => { glow.strength = v; applyGlow(); } },
        { key: 'glowWidth', label: 'Width', min: 0.2, max: 4, step: 0.1, value: 1,
          apply: (v) => { glow.width = v; applyGlow(); } },
        { key: 'glowBlur', label: 'Softness', min: 0, max: 20, step: 0.5, value: 6,
          apply: (v) => { glow.blur = v; applyGlow(); } },
      ], { tab: 'Map' }),

      window.__tune.register('Rounded corners', [
        // Samples per segment through the spline. 0 leaves the raw polygon border; anything above
        // swaps in the smoothed copy, so the two are never both drawn.
        { key: 'smoothing', label: 'Rounding', min: 0, max: 8, step: 1, value: 0, apply: applyRounding },
        { key: 'smoothWidth', label: 'Width', min: 0, max: 6, step: 0.1, value: theme.line.width,
          apply: (v) => paint('boundaries-smooth', 'line-width', v) },
        { key: 'smoothOpacity', label: 'Opacity', min: 0, max: 1, step: 0.05, value: theme.line.opacity ?? 1,
          apply: (v) => paint('boundaries-smooth', 'line-opacity', v) },
        { key: 'smoothColour', label: 'Colour', type: 'color', value: theme.line.color,
          apply: (v) => paint('boundaries-smooth', 'line-color', v) },
      ], { tab: 'Map' }),

      window.__tune.register('Ground', [
        // Real 3D relief. The Time-Map has the DEM source but has never switched terrain on, so this
        // is the first place to see how far the earth can actually be pulled up.
        { key: 'exaggeration', label: 'Heightmap', min: 0, max: 12, step: 0.25, value: 0,
          apply: (v) => { try { map.setTerrain(v > 0 ? { source: 'dem', exaggeration: v } : null); } catch (e) { /* style still parsing */ } } },
      ], { tab: 'Map' }),

      window.__tune.register('Ships', [
        // 45s a lap reads as a speedboat crossing an ocean. A voyage took months; it should feel
        // like something you notice rather than something you watch.
        { key: 'lapSeconds', label: 'Seconds per lap', min: 20, max: 900, step: 5, value: 45,
          apply: (v) => fleet()?.setLapSeconds(v) },
        { key: 'shipScale', label: 'Size', min: 0.2, max: 3, step: 0.05, value: 1,
          apply: (v) => fleet()?.setShipScale(v) },
        { key: 'anchored', label: 'Scale with zoom', type: 'boolean', value: true,
          apply: (on) => fleet()?.setShipScale(undefined, on) },
        // Dims the fleet on the night side. Scene-wide, not per ship — see setNightDim.
        { key: 'nightDim', label: 'Night dimming', min: 0, max: 1, step: 0.05, value: 0,
          apply: (v) => fleet()?.setNightDim(v, daylightAtCentre()) },
        { key: 'motion', label: 'Idle rock', min: 0, max: 1, step: 0.05, value: 1,
          apply: (v) => fleet()?.setMotion(v) },
      ], { tab: 'Camera' }),

      window.__tune.register('Framing', [
        /**
         * How much room to leave at the bottom, so the globe sits in what you can SEE.
         *
         * The time deck covers the bottom of the map, and MapLibre centres on the CONTAINER — so the
         * earth sat low with empty sky above it and the southern hemisphere behind the deck.
         *
         * This shrinks the container rather than padding the transform. Padding was tried first and
         * it broke the planet: the custom layers cut the far hemisphere using transform.cameraPosition,
         * which padding does not move, so the sea's horizon and the drawn globe disagreed and the
         * ocean showed as a bowl below the earth. Resizing the box moves nothing the shaders read.
         */
        { key: 'bottomInset', label: 'Bottom inset', type: 'number', min: 0, max: 400, step: 2, value: 0,
          apply: (v) => {
            // Tuning wins over the automatic measurement, or the two would fight on every resize.
            chromeInsetLocked = true;
            el.style.bottom = `${Math.max(0, Number(v) || 0)}px`;
            map.resize();
          } },
      ], { tab: 'Camera' }),

      /**
       * How far the camera may lean, which is how much curvature you get.
       *
       * Flat-on there is no horizon in the frame and the globe reads as a disc; leaning puts the
       * limb in shot. Pitch moves it live so it can be judged, and the two limits decide how far a
       * teacher can push it before the far hemisphere starts folding away.
       */
      window.__tune.register('Tilt and turn', [
        { key: 'pitch', label: 'Pitch', min: 0, max: 85, step: 1, value: 45,
          apply: (v) => map.easeTo({ pitch: Number(v), duration: 0 }) },
        { key: 'bearing', label: 'Bearing', min: -180, max: 180, step: 1, value: 0,
          apply: (v) => map.easeTo({ bearing: Number(v), duration: 0 }) },
        { key: 'minPitch', label: 'Min pitch', type: 'number', min: 0, max: 85, step: 1, value: 0,
          apply: (v) => map.setMinPitch(Number(v)) },
        { key: 'maxPitch', label: 'Max pitch', type: 'number', min: 0, max: 85, step: 1, value: 70,
          apply: (v) => map.setMaxPitch(Number(v)) },
      ], { tab: 'Camera' }),

      window.__tune.register('Zoom limits', [
        { key: 'minZoom', label: 'Min zoom', type: 'number', min: 0, max: 22, step: 0.1, value: map.getMinZoom(),
          apply: (v) => map.setMinZoom(Number(v)) },
        { key: 'maxZoom', label: 'Max zoom', type: 'number', min: 0, max: 22, step: 0.1, value: map.getMaxZoom(),
          apply: (v) => map.setMaxZoom(Number(v)) },
      ], { tab: 'Camera' }),

      window.__tune.register('Entrance', [
        { key: 'startLng', label: 'Start lng', type: 'number', step: 0.1, value: camera.startLng, apply: (v) => { camera.startLng = Number(v); } },
        { key: 'startLat', label: 'Start lat', type: 'number', step: 0.1, value: camera.startLat, apply: (v) => { camera.startLat = Number(v); } },
        { key: 'startZoom', label: 'Start zoom', min: 0, max: 8, step: 0.05, value: camera.startZoom, apply: (v) => { camera.startZoom = v; } },
        { key: 'endLng', label: 'End lng', type: 'number', step: 0.1, value: camera.endLng, apply: (v) => { camera.endLng = Number(v); } },
        { key: 'endLat', label: 'End lat', type: 'number', step: 0.1, value: camera.endLat, apply: (v) => { camera.endLat = Number(v); } },
        { key: 'endZoom', label: 'End zoom', min: 0, max: 10, step: 0.05, value: camera.endZoom, apply: (v) => { camera.endZoom = v; } },
        { key: 'seconds', label: 'Seconds', min: 0.5, max: 12, step: 0.25, value: camera.seconds, apply: (v) => { camera.seconds = v; } },
        { key: 'jumpStart', type: 'button', label: 'Go to start', apply: () => map.jumpTo({ center: [camera.startLng, camera.startLat], zoom: camera.startZoom }) },
        { key: 'play', type: 'button', label: 'Play entrance', apply: () => {
          map.jumpTo({ center: [camera.startLng, camera.startLat], zoom: camera.startZoom });
          setTimeout(() => map.flyTo({
            center: [camera.endLng, camera.endLat], zoom: camera.endZoom, duration: camera.seconds * 1000, essential: true,
          }), 120);
        } },
      ], { tab: 'Camera' }),
    ];
  };
  let tuneOff = [];
  // The cloud deck (custom layer) — held so its density can be changed live.
  let cloudLayer = null;
  /** Cloud density 0..1; 0 hides the deck (and stops its repaint loop). */
  window.__tmSetClouds = (v) => {
    const opacity = Math.max(0, Math.min(1, Number(v)));
    if (cloudLayer) cloudLayer.setOptions({ opacity });
    try { localStorage.setItem('tm-clouds', String(opacity)); } catch (e) { /* private mode */ }
  };

  let currentStyleName = 'soft-atlas';
  const applyMapStyle = (name) => {
    // Migrate the retired raster 'ink-art' style → the vector 'pen-ink' Tolkien style.
    if (name === 'ink-art' || name === 'inkart') name = 'pen-ink';
    // An unknown style falls back rather than throwing, which is right — but it used to do it in
    // total silence, so picking a style that was never defined here just quietly drew Soft Atlas.
    // "Earth" did exactly that for as long as it existed only on the lesson map: the map changed to
    // something, so it looked like a style, and there was nothing anywhere to say otherwise.
    if (!MAP_STYLES[name]) {
      console.warn(`[timemap] no map style "${name}" — falling back to soft-atlas. Defined: ${Object.keys(MAP_STYLES).join(', ')}`);
    }
    const key = MAP_STYLES[name] ? name : 'soft-atlas';
    const s = MAP_STYLES[key];
    currentStyleName = key;
    try { localStorage.setItem('tm-style', key); } catch (e) { /* private mode */ }
    // National colour when curated, else the per-style hash palette — and whatever the settings
    // panel has overridden, which is why this is built in one place rather than inline here.
    // Satellite: the photographed ground replaces the drawn one, so every layer that paints a
    // substitute for it (land fill, sea grid, coast drop-shadow, lakes) steps aside.
    const photo = !!s.imagery;
    const vis = (layer, on) => { if (map.getLayer(layer)) map.setLayoutProperty(layer, 'visibility', on ? 'visible' : 'none'); };
    // Both halves of the satellite ground: the base, and the close-range detail that fades in
    // over it. Toggling only the base leaves Sentinel-2 painting over a drawn atlas.
    vis('satellite', photo);
    vis('satellite-detail', photo);
    for (const drawn of ['land', 'coast-shadow', 'lakes', 'lake-line', 'lake-shadow']) vis(drawn, !photo);
    // The bold ink shore would fence off a real coastline; keep it as a faint guide line.
    if (map.getLayer('coast-bold')) map.setPaintProperty('coast-bold', 'line-opacity', photo ? 0.4 : 1);
    map.setPaintProperty('water', 'background-color', s.water);
    if (map.getLayer('lakes')) map.setPaintProperty('lakes', 'fill-color', s.water);
    // Lake edges track the coast tones: thin ink line all round + a deeper southern drop-shadow.
    {
      const sh = s.shore || { color: s.line.color, shadow: '#574631' };
      if (map.getLayer('lake-line')) map.setPaintProperty('lake-line', 'line-color', sh.color);
      if (map.getLayer('lake-shadow')) map.setPaintProperty('lake-shadow', 'line-color', sh.shadow);
    }
    map.setPaintProperty('land', 'fill-color', s.land);
    // Coast: a bold ink shore line + a southern drop-shadow (shifted down, under the land fill) for
    // relief. The shadow only shows on south-facing shores; the land fill hides it on north shores.
    const shore = s.shore || { color: s.line.color, width: 0.9, shadow: '#574631', shadowWidth: 2.4, dy: 2 };
    if (map.getLayer('coast-bold')) {
      map.setPaintProperty('coast-bold', 'line-color', shore.color);
      map.setPaintProperty('coast-bold', 'line-width', shore.width);
    }
    if (map.getLayer('coast-shadow')) {
      map.setPaintProperty('coast-shadow', 'line-color', shore.shadow);
      map.setPaintProperty('coast-shadow', 'line-width', shore.shadowWidth);
      map.setPaintProperty('coast-shadow', 'line-translate', [0, shore.dy ?? 2]);
    }
    // Etched coast echo: shown in ink styles; opacity fades from the nearest ring outward.
    if (map.getLayer('coast-echo')) {
      if (s.coast) {
        map.setLayoutProperty('coast-echo', 'visibility', 'visible');
        map.setPaintProperty('coast-echo', 'line-color', s.coast.color);
        map.setPaintProperty('coast-echo', 'line-width', s.coast.width ?? 0.6);
        startWaves(s.coast); // animates line-opacity each frame for the rolling-wave effect
      } else {
        map.setLayoutProperty('coast-echo', 'visibility', 'none');
        stopWaves();
      }
    }
    // Rivers: shown in ink styles, thicker for major rivers (low scalerank).
    if (map.getLayer('rivers')) {
      if (s.river) {
        map.setLayoutProperty('rivers', 'visibility', 'visible');
        map.setPaintProperty('rivers', 'line-color', s.river.color);
        map.setPaintProperty('rivers', 'line-opacity', s.river.opacity ?? 0.55);
        const w = s.river.width ?? 0.6;
        map.setPaintProperty('rivers', 'line-width', ['interpolate', ['linear'], ['to-number', ['get', 'scalerank']], 1, w + 0.5, 6, w * 0.5]);
      } else {
        map.setLayoutProperty('rivers', 'visibility', 'none');
      }
    }
    // Relief hillshade — atlas styles only (gives the terrain depth under the ink glyphs).
    if (map.getLayer('hillshade')) {
      map.setLayoutProperty('hillshade', 'visibility', s.hillshade ? 'visible' : 'none');
    }
    // Sketched sea grid (graticule) — atlas styles only; recolored to the style's grid tone.
    if (map.getLayer('graticule')) {
      if (s.grid) {
        map.setLayoutProperty('graticule', 'visibility', 'visible');
        map.setPaintProperty('graticule', 'line-color', s.grid.color);
        map.setPaintProperty('graticule', 'line-opacity', s.grid.opacity ?? 0.5);
        map.setPaintProperty('graticule', 'line-width', s.grid.width ?? 0.6);
      } else {
        map.setLayoutProperty('graticule', 'visibility', 'none');
      }
    }
    // Mountain glyphs along ridge lines — ink styles only.
    if (map.getLayer('mountains')) {
      map.setLayoutProperty('mountains', 'visibility', s.mountains ? 'visible' : 'none');
    }
    // Forests: tree-glyph field — ink styles only.
    if (map.getLayer('forests')) {
      map.setLayoutProperty('forests', 'visibility', s.forest ? 'visible' : 'none');
    }
    // Sparse single-tree texture across empty land — rides the same forest toggle.
    if (map.getLayer('land-scatter')) {
      map.setLayoutProperty('land-scatter', 'visibility', s.forest ? 'visible' : 'none');
    }
    // Famous volcanoes — shown with the mountains (ink/atlas styles).
    setVolcanoVisibility(map, !!s.mountains);
    if (map.getLayer('boundaries-fill')) {
      // Rebuilds colour AND opacity for all three states, honouring any panel override, so a style
      // switch restyles the map without silently discarding what has been tuned.
      lineOpacityValue = styleOverride['boundaries-line.line-opacity']
        ?? ((s.line && s.line.opacity != null) ? s.line.opacity : 1);
      applyTerritoryFill();
      ownedPaint('boundaries-line', 'line-color', s.line.color);
      ownedPaint('boundaries-line', 'line-width', s.line.width);
      ownedPaint('boundaries-line', 'line-blur', s.line.blur);
      map.setLayoutProperty('boundaries-line', 'line-join', 'round');
      // When a style supplies wobbled ink borders, hide the smooth vector line and draw from those.
      //
      // Rounding owns this layer as well, and it asked first. This line used to reset it to visible
      // on EVERY style switch, so the raw polygon border came back and drew straight over the
      // rounded copy — corners looked sharp again and the slider looked broken, when what had
      // actually happened was a style change. Whoever wants it hidden wins.
      const roundedBordersOn = smoothSamples >= 1;
      map.setLayoutProperty('boundaries-line', 'visibility', (s.borderSource || roundedBordersOn) ? 'none' : 'visible');
      ownedPaint('boundaries-label', 'text-color', s.text.color);
      ownedPaint('boundaries-label', 'text-halo-color', s.text.halo);
    }
    if (map.getLayer('markers-label')) {
      map.setPaintProperty('markers-label', 'text-color', s.text.color);
      map.setPaintProperty('markers-label', 'text-halo-color', s.text.halo);
    }
    applyVoyageStyle(map, { color: s.voyage, halo: s.text && s.text.halo });
    // Hand-drawn ink: several stacked strokes (per-feature varying width + bleed + broken dashes)
    // so borders read as uneven pen work rather than uniform vector lines.
    for (let i = 0; i < 6; i++) { if (map.getLayer(`ink-${i}`)) map.removeLayer(`ink-${i}`); }
    if (map.getLayer('boundaries-line')) {
      const bsrc = (s.borderSource && s.borderSource.source) || 'cliopatria';
      const bslayer = (s.borderSource && s.borderSource.sourceLayer) || 'boundaries';
      const bfilter = s.borderSource ? borderDateFilter(state.year) : polityFilter(state.year);
      (s.inkLayers || []).forEach((L, i) => {
        const layer = {
          id: `ink-${i}`, type: 'line', source: bsrc, 'source-layer': bslayer,
          filter: bfilter,
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
  // Dev tooling + Playwright: the two behaviours that only exist as a hover or a slider drag, and so
  // cannot otherwise be driven from a test.
  window.__tmShowGlow = showGlow;
  window.__tmSetRounding = applyRounding;

  // Read-aloud (ElevenLabs): gated by the Settings sound toggle (persisted). The panel calls
  // __timemapSpeak when a territory's summary is shown; audio is cached server-side per polity.
  window.__timemapSoundOn = (() => { try { return localStorage.getItem('tm-sound') === '1'; } catch (e) { return false; } })();
  let ttsAudio = null;

  // Broadcast read-aloud state to the floating audio control (Alpine, in the blade). `visible` is
  // forced false when there's no clip, so a late media event can never re-show a stopped bar.
  const emitAudioState = (visible) => {
    const a = ttsAudio;
    window.dispatchEvent(new CustomEvent('timemap-audio', { detail: {
      visible: !!(visible && a),
      playing: !!(a && !a.paused && !a.ended),
      currentTime: a ? (a.currentTime || 0) : 0,
      duration: a && isFinite(a.duration) ? a.duration : 0,
    } }));
  };

  window.__timemapStopSpeak = () => {
    if (ttsAudio) { try { ttsAudio.pause(); } catch (e) { /* noop */ } ttsAudio = null; }
    emitAudioState(false);
  };
  // Pause/resume the current clip without discarding it (floating control's play/pause button).
  window.__timemapToggleSpeak = () => {
    if (!ttsAudio) return;
    if (ttsAudio.paused) ttsAudio.play().catch(() => {});
    else ttsAudio.pause();
    emitAudioState(true);
  };
  // Seek to a fraction [0..1] of the clip (click-to-scrub on the progress bar).
  window.__timemapSeek = (frac) => {
    if (ttsAudio && isFinite(ttsAudio.duration)) {
      ttsAudio.currentTime = Math.max(0, Math.min(1, frac)) * ttsAudio.duration;
      emitAudioState(true);
    }
  };


  window.__timemapSpeak = (id, text) => {
    window.__timemapStopSpeak();
    if (!window.__timemapSoundOn || !id || !text) return;
    const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
    fetch('/teacher/timemap/speak', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ id, text }),
    }).then((r) => (r.ok ? r.json() : {})).then((d) => {
      if (!d || !d.url || !window.__timemapSoundOn) return;
      ttsAudio = new Audio(d.url);
      ['loadedmetadata', 'timeupdate', 'play', 'pause'].forEach((ev) =>
        ttsAudio.addEventListener(ev, () => emitAudioState(true)));
      ttsAudio.addEventListener('ended', () => window.__timemapStopSpeak());
      ttsAudio.play().catch(() => { /* autoplay blocked until a user gesture */ });
      emitAudioState(true);
    }).catch(() => {});
  };

  // A bare `new Audio()` plays independently of the DOM, so an SPA page swap (wire:navigate) leaves
  // the read-aloud playing over the next page. Stop it on navigate-away or unload. Guard so repeated
  // timemap mounts don't stack duplicate listeners.
  if (!window.__timemapAudioTeardownBound) {
    window.__timemapAudioTeardownBound = true;
    document.addEventListener('livewire:navigating', () => window.__timemapStopSpeak && window.__timemapStopSpeak());
    window.addEventListener('pagehide', () => window.__timemapStopSpeak && window.__timemapStopSpeak());
  }

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
      id: 'boundaries-smooth', type: 'line', source: SMOOTH_SRC,
      layout: { 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': theme.line.color, 'line-width': theme.line.width, 'line-opacity': theme.line.opacity },
    });
    map.addLayer({
      id: 'boundaries-glow', type: 'line', source: 'boundaries-glow-src',
      // The source carries the outline AND a point for the name; each layer takes its own kind.
      filter: ['==', ['geometry-type'], 'LineString'],
      layout: { 'line-cap': 'round', 'line-join': 'round' },
      paint: {
        'line-color': GLOW_COLOR,
        'line-width': GLOW_WIDTH,
        'line-blur': 6,
        // A plain number, not a feature-state case. The source is EMPTY when nothing is hovered, so
        // there is nothing to make invisible — which also removes the trap that bit this layer
        // first time round: MapLibre transitions only non data-driven properties, and pairing a
        // transition with a feature-state expression made it drop the expression and fall back to
        // fully opaque, drawing every border of every era at once.
        'line-opacity': 0.55,
        'line-opacity-transition': { duration: 140 },
      },
    });
    // NOTE: there was a second name layer here — the hovered territory's name, drawn from the glow
    // source so it could never be culled by collision. It is gone, because it anchored differently
    // from the real label and so hovering visibly shifted the name sideways.
    //
    // Its job survives without it: the hovered name takes symbol-sort-key 0 (see refreshLabels), so
    // it is placed FIRST and wins the collision it used to lose, and hoverLabel() grows it in place.
    map.addLayer({
      id: 'boundaries-line', type: 'line', source: 'cliopatria', 'source-layer': 'boundaries',
      paint: { 'line-color': theme.line.color, 'line-width': theme.line.width, 'line-opacity': theme.line.opacity },
    });
    // Rivers (Natural Earth, major only) — above borders, below labels; ink styles only.
    map.addLayer({
      id: 'rivers', type: 'line', source: 'rivers', 'source-layer': 'rivers',
      filter: ['<=', ['to-number', ['get', 'scalerank']], 5],
      layout: { visibility: 'none', 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': '#6a7c74', 'line-width': 0.6, 'line-opacity': 0.6 },
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
        // Major places in grand Cinzel small-caps — the Tolkien-map hierarchy.
        'text-font': ['Cinzel'], 'text-transform': 'uppercase', 'text-letter-spacing': 0.06,
        // Text sits clearly BELOW the flag (flag pushed up via icon-translate) so they never overlap.
        'text-size': 12, 'text-anchor': 'top', 'text-offset': [0, 0.55],
        'text-allow-overlap': false, 'text-optional': true,
        'text-padding': 6, // space labels out so dense regions de-clutter
        // Place the selected territory's label first (lowest key wins collision) so its name
        // always shows; everyone else keeps the default source-order placement.
        // Lowest key is placed first, so it never loses a collision. Both the OPEN territory and
        // the one under the pointer get 0 — the hovered name winning placement is what the removed
        // always-on-top hover layer used to guarantee.
        'symbol-sort-key': ['case', ['get', 'sel'], 0, ['get', 'hov'], 0, 1],
      },
      // Strong halo so names stay readable over borders/fills in every style.
      // icon-translate lifts the flag a few px above the name (independent of icon-size).
      // From theme.json, not repeated here. These were the same two hex values written out twice,
      // and when the hover-name layer (the other reader of theme.text) was removed, nothing read
      // the theme's ink at all — leaving a group in theme.json that looked live and was not.
      paint: {
        'text-color': theme.text.color, 'text-halo-color': theme.text.halo,
        'text-halo-width': 2, 'text-halo-blur': 0.5, 'icon-translate': [0, -9],
      },
    });

    // Mountains: hand-painted glyphs repeated along curated ridge lines, varied per range
    // (manifest-driven; shared with the lesson map). Added async — re-apply the style after so
    // the per-style visibility toggle is honoured.
    // Forests then mountains, both below the labels. Chained (not parallel) so the order is
    // deterministic: trees sit beneath the peaks, so a range rises above its forested foothills.
    const terrainLand = () => (MAP_STYLES[currentStyleName] || {}).land || theme.land;
    addScatterLayer(map, { beforeId: 'boundaries-label', visibility: 'none' })
      .then(() => addForestLayer(map, { beforeId: 'boundaries-label', visibility: 'none', landColor: terrainLand() }))
      .then(() => addMountainLayer(map, { beforeId: 'boundaries-label', visibility: 'none', landColor: terrainLand() }))
      .then(() => addVolcanoLayer(map, { beforeId: 'boundaries-label', visibility: 'none' }))
      .then(() => applyMapStyle(currentStyleName));

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
        'text-field': ['get', 'name'], 'text-size': 14,
        // The SAME hand as every other name on the map. These three (Gaul, Germania, Britannia) are
        // added by hand rather than coming from Cliopatria, and they used to be set in flowing Eagle
        // Lake title case while their neighbours were Cinzel small caps — so "Britannia" beside
        // "ROMAN EMPIRE" read as two maps stacked, which is exactly how Bart found it.
        //
        // The hierarchy survives in the one place it belongs: size. A region is 14 to a polity's 12.
        'text-font': ['Cinzel'], 'text-transform': 'uppercase',
        'text-offset': [0, 0.9], 'text-anchor': 'top',
        'text-allow-overlap': false, 'text-optional': true, 'text-letter-spacing': 0.06,
      },
      paint: { 'text-color': '#6b5a3e', 'text-halo-color': '#f3ead6', 'text-halo-width': 1.2 },
    });
    // Weather over the globe. Reduced-motion users get the deck without the drift — and without
    // the per-frame repaint that drives it.
    let cloudOpacity = 0.5;
    try {
      const saved = parseFloat(localStorage.getItem('tm-clouds'));
      if (Number.isFinite(saved)) cloudOpacity = Math.min(1, Math.max(0, saved));
    } catch (e) { /* private mode */ }
    // The real cloud field, not the procedural fallback. These assets have been sitting in
    // public/img/map/ unused: without a fieldUrl the layer silently runs on noise, which is banded
    // by latitude to imitate weather but has no actual weather in it. With them you get the real
    // ITCZ, real frontal bands and real cyclones, carried along a real GFS wind field.
    // The whole stack, not just the deck: the sea, the terminator, the haze band, the star sky, the
    // sun and the moon come on with it. Same insertion point the cloud layer alone used, so the
    // voyages and the ship below still anchor to CLOUD_LAYER_ID exactly as before.
    //
    // The date is the year on the slider, so the lit half of the planet belongs to the era being
    // looked at rather than to whenever the page happened to load. applyYear moves it.
    globe = addGlobeLayers(map, {
      date: dateForYear(state.year),
      reduceMotion,
      beforeId: 'boundaries-label',
    });
    cloudLayer = globe.layers.clouds;
    // Honour the density this browser last chose, which predates the shared panel and is still what
    // __tmSetClouds writes.
    cloudLayer?.setOptions({ opacity: cloudOpacity });
    registerTuning();
    // Explorer voyages / trade routes (voyages.json) — sea paths, era-filtered like polities;
    // clicking one opens the info panel via its Wikidata QID. Their sources/layers live in the
    // initial style; this lifts them above the boundary layers but keeps them BELOW the
    // tm-clouds 3D layer, whose globe-wide depth writes would otherwise cull them (see voyages.js).
    initVoyages(map, { year: state.year, beforeId: CLOUD_LAYER_ID });
    // The sailing tall ship (three.js custom layer) rides in its own lazy chunk — same
    // below-the-clouds placement, same era window as the routes.
    import('./voyage-ships.js')
      .then(({ addVoyageShips }) => {
        voyageShips = addVoyageShips(map, { beforeId: CLOUD_LAYER_ID });
        voyageShips.setYear(state.year);
        window.__tmShips = voyageShips; // dev tooling + Playwright
      })
      .catch(() => { /* ships are decoration — the map works without them */ });
    map.on('mouseenter', 'markers-dot', () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', 'markers-dot', () => { map.getCanvas().style.cursor = ''; });

    map.on('moveend', scheduleSettle);
    map.on('moveend', scheduleSmoothBorders);
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
      if (e.features[0].id === hoveredId) return;   // same territory, nothing to redraw
      hoveredId = e.features[0].id;
      const p = e.features[0].properties;
      // Same QID resolution the click path uses — Cliopatria reuses a QID across different polities,
      // so the name is part of the key.
      const hoverQid = p.Wikidata ? (QID_FOR_NAME.get(`${p.Wikidata}|${p.Name}`) || p.Wikidata) : null;
      showGlow(e.features[0].geometry, p.Name ?? null, hoverQid);
    });
    map.on('mouseleave', 'boundaries-fill', () => {
      map.getCanvas().style.cursor = '';
      hoveredId = null;
      showGlow(null);
    });

    state.ready = true;
    sync();
  });

  map.on('click', (e) => {
    // Supplemental markers sit on top and carry an explicit QID — check them first (small hit box).
    const box = [[e.point.x - 7, e.point.y - 7], [e.point.x + 7, e.point.y + 7]];
    const marker = map.queryRenderedFeatures(box, { layers: ['markers-dot'] })[0];
    if (marker) {
      if (selectedId !== null) { setSelected(selectedId, false); selectedId = null; refreshLabels(); }
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

    // Voyage routes next (wide invisible hit line): open the panel via the voyage's QID.
    const voyage = map.getLayer('voyage-hit') && map.queryRenderedFeatures(box, { layers: ['voyage-hit'] })[0];
    if (voyage) {
      if (selectedId !== null) { setSelected(selectedId, false); selectedId = null; refreshLabels(); }
      const p = voyage.properties;
      state.selectedRegion = p.qid;
      sync();
      window.dispatchEvent(new CustomEvent('polity-selected', { detail: { id: p.qid, name: `${p.name} (${p.years})`, qid: p.qid } }));
      return;
    }

    // Identify the clicked polity client-side from the rendered (era-filtered) features.
    const hit = map.queryRenderedFeatures(e.point, { layers: ['boundaries-fill'] })[0];

    if (selectedId !== null) setSelected(selectedId, false);
    selectedId = hit ? hit.id : null;
    if (selectedId !== null) setSelected(selectedId, true);
    refreshLabels(); // re-rank labels so the newly-selected territory's name wins collision

    const tileQid = hit ? hit.properties.Wikidata : null;
    const name = hit ? hit.properties.Name : null;
    const qid = tileQid ? QID_FOR_NAME.get(`${tileQid}|${name}`) || tileQid : null;
    state.selectedRegion = qid;
    sync();

    // Run the light around the border you just clicked — the voyage's landfall draw-on, borrowed.
    // The OUTLINE only: a territory announcing itself by tracing its own edge says where it is
    // without hiding what is inside it, which is the whole reason the fill no longer reacts.
    borderSweep?.cancel();
    borderSweep = hit
      ? sweepBorder(map, {
        id: 'polity-sweep',
        lines: outlineOf(hit.geometry),
        // No trail: this is a gesture, not a layer. It clears itself up when it finishes.
        durationMs: 2000,
        beforeId: map.getLayer('boundaries-label') ? 'boundaries-label' : undefined,
      })
      : null;

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

  // Toggled by the slider's play/pause: enables the fade-in/out ramp only during playback.
  el._setFadeMode = (on) => { fadeMode = !!on; applyBoundaryOpacity(state.year); };

  el._tmMap = map;
  window.__tmMap = map; // for debugging + Playwright assertions

  return map;
};

window.mountAtlasSlider = function (el, mapEl, initialYear) {
  // Throttle map updates (leading + trailing, ~100ms). _setYear is continuous/no-fetch, so this
  // keeps the map animating live while the timeline is played or dragged, while capping filter
  // churn, and the trailing call guarantees the map lands on the final year.
  const THROTTLE_MS = 100;
  let last = 0;
  let trailing = null;
  const pushYear = (year) => {
    if (!mapEl._setYear) return;
    const now = performance.now();
    const wait = THROTTLE_MS - (now - last);
    clearTimeout(trailing);
    if (wait <= 0) {
      last = now;
      mapEl._setYear(year);
    } else {
      trailing = setTimeout(() => { last = performance.now(); mapEl._setYear(year); }, wait);
    }
  };
  const slider = mountTimeSlider(el, {
    min: -4000, max: 2010, value: initialYear,
    onYear: (year) => pushYear(year),
    onPlay: (playing) => { if (mapEl._setFadeMode) mapEl._setFadeMode(playing); },
  });
  // Let other UI (e.g. the panel's era links) scrub the timeline + map to a given year.
  window.__setTimemapYear = (year) => {
    const y = Math.round(Number(year));
    if (Number.isNaN(y)) return;
    slider.setYear(y);                         // move the timeline UI
    if (mapEl._setYear) mapEl._setYear(y);     // update the map now
  };
};

// ── In-panel Wikipedia content (oldmapsonline pattern) ────────────────────────
// Wikipedia blocks iframing (X-Frame-Options: SAMEORIGIN), but its REST/action APIs send
// access-control-allow-origin: *, so the browser fetches the thumbnail + article text directly
// and we render it inside our own panel — users never leave the map.
function __wikiTitle (url) {
  const m = /\/wiki\/([^?#]+)/.exec(url || '');
  return m ? decodeURIComponent(m[1]) : null;
}

// Thumbnail + short extract (one REST summary call). Returns null for non-Wikipedia URLs.
window.__timemapWikiSummary = async (url) => {
  if (!url || !url.includes('wikipedia.org')) return null;
  const title = __wikiTitle(url);
  if (!title) return null;
  try {
    const r = await fetch('https://en.wikipedia.org/api/rest_v1/page/summary/' + encodeURIComponent(title));
    if (!r.ok) return null;
    const d = await r.json();
    return { thumbnail: d.thumbnail?.source || null, extract: d.extract || null };
  } catch { return null; }
};

// Fuller lead section (plaintext) for the "Read more" expansion, via the CORS action API.
window.__timemapWikiLead = async (url) => {
  if (!url || !url.includes('wikipedia.org')) return null;
  const title = __wikiTitle(url);
  if (!title) return null;
  try {
    const api = 'https://en.wikipedia.org/w/api.php?action=query&prop=extracts&exintro&explaintext'
      + '&redirects=1&format=json&origin=*&titles=' + encodeURIComponent(title);
    const r = await fetch(api);
    if (!r.ok) return null;
    const d = await r.json();
    const pages = (d.query && d.query.pages) || {};
    const first = Object.values(pages)[0];
    return (first && first.extract) || null;
  } catch { return null; }
};

// Panel mutators — take Alpine's reactive $data proxy and write to it directly. (Bare-identifier
// assignment inside an async .then in an inline Alpine expression doesn't reliably write back to
// scope; mutating the $data proxy does.)
window.__timemapHydratePanel = (scope, p) => {
  scope.thumb = null; scope.lead = null; scope.leadLoading = false; scope.leadFailed = false;
  if (p && p.wikipedia_url) {
    window.__timemapWikiSummary(p.wikipedia_url).then((s) => { scope.thumb = (s && s.thumbnail) || null; });
  }
};

window.__timemapPanelReadMore = (scope, p) => {
  if (!p || !p.wikipedia_url) return;
  scope.leadLoading = true; scope.leadFailed = false;
  window.__timemapWikiLead(p.wikipedia_url).then((t) => {
    if (t) scope.lead = t; else scope.leadFailed = true;
    scope.leadLoading = false;
  });
};

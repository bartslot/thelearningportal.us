import voyagesData from './voyages.json';

// Explorer voyages / trade routes as arrowed sea paths (curated in voyages.json). Clicking a route
// opens the same info panel as a territory: the click handler dispatches `polity-selected` with the
// voyage's Wikidata QID and the lazy polity endpoint enriches it like any other item.
//
// The sources AND layers are declared in the initial style (voyageStyleSources/voyageStyleLayers)
// rather than added at runtime: line layers on runtime-added GeoJSON sources do not render in this
// style pipeline (the style-time graticule line does), so voyages follow the proven path.
// initVoyages() then lifts them above the runtime-added boundary layers and applies the era filter.

// Catmull-Rom spline through the hand-curated waypoints so routes read as drawn curves, not
// dot-to-dot segments. `per` = interpolated points per waypoint pair.
/**
 * Samples emitted per waypoint segment. Waypoint i therefore lands on sample i * SAMPLES_PER_SEGMENT,
 * which is how the tour maps a track fraction back to a waypoint — import this rather than
 * hard-coding the number anywhere.
 */
export const SAMPLES_PER_SEGMENT = 18;

/**
 * Centripetal Catmull-Rom through the waypoints.
 *
 * The spline passes exactly through every waypoint, so a drag handle sitting on a waypoint sits on
 * the drawn line. Centripetal knot spacing (alpha = 0.5) rather than uniform is what keeps a sharp
 * turn — a route rounding a headland — from cusping or looping past itself; uniform Catmull-Rom
 * visibly kinks at tight corners, which is what made the coastal turns look angular.
 */
export const smooth = (points, per = SAMPLES_PER_SEGMENT) => {
  if (points.length < 3) return points;
  const pt = (i) => points[Math.max(0, Math.min(points.length - 1, i))];
  const ALPHA = 0.5;
  const knot = (t, a, b) => t + Math.pow(Math.hypot(b[0] - a[0], b[1] - a[1]) || 1e-6, ALPHA);

  const out = [points[0]];
  for (let i = 0; i < points.length - 1; i++) {
    const [p0, p1, p2, p3] = [pt(i - 1), pt(i), pt(i + 1), pt(i + 2)];
    const t0 = 0;
    const t1 = knot(t0, p0, p1);
    const t2 = knot(t1, p1, p2);
    const t3 = knot(t2, p2, p3);

    for (let s = 1; s <= per; s++) {
      const t = t1 + ((t2 - t1) * s) / per;
      // Barry–Goldman pyramid: three lerps down to one point on the segment p1→p2.
      const lerp = (a, b, ta, tb) => {
        const d = tb - ta || 1e-6;
        return [0, 1].map((k) => ((tb - t) / d) * a[k] + ((t - ta) / d) * b[k]);
      };
      const a1 = lerp(p0, p1, t0, t1);
      const a2 = lerp(p1, p2, t1, t2);
      const a3 = lerp(p2, p3, t2, t3);
      const b1 = lerp(a1, a2, t0, t2);
      const b2 = lerp(a2, a3, t1, t3);
      out.push(lerp(b1, b2, t1, t2));
    }
  }
  return out;
};

/** Smoothed route + era window + fleet/legs per voyage — consumed by voyage-ships.js and the
 *  voyage tour. `legs[].wp` are [start,end] indexes into the RAW waypoints; the tour smooths
 *  each slice itself so leg boundaries stay exactly on waypoints. */
export const voyageRoutes = () => voyagesData.voyages.map((v) => ({
  id: v.id, qid: v.qid, name: v.name,
  show_from: v.show_from, show_to: v.show_to,
  fleet: v.fleet, legs: v.legs, unknown: v.unknown,
  coords: smooth(v.waypoints),
  waypoints: v.waypoints,
}));

/** Smooth a raw-waypoint slice (used by the tour for per-leg tracks). */
export const smoothSlice = (waypoints, from, to) => smooth(waypoints.slice(from, to + 1));

const voyageFeatures = () => {
  const lines = [];
  const labels = [];
  for (const v of voyagesData.voyages) {
    const props = {
      qid: v.qid, name: v.name, years: v.years,
      showFrom: v.show_from, showTo: v.show_to,
    };
    lines.push({ type: 'Feature', properties: props, geometry: { type: 'LineString', coordinates: smooth(v.waypoints) } });
    labels.push({ type: 'Feature', properties: props, geometry: { type: 'Point', coordinates: v.waypoints[v.label_at ?? Math.floor(v.waypoints.length / 2)] } });
  }
  return { lines, labels };
};

const voyageFilter = (year) => ['all',
  ['<=', ['to-number', ['get', 'showFrom']], year],
  ['>=', ['to-number', ['get', 'showTo']], year],
];

const LAYERS = ['voyage-hit', 'voyage-line', 'voyage-label'];
const DEFAULT_COLOR = '#20618f';

/** GeoJSON sources for the initial style object. */
export function voyageStyleSources() {
  const { lines, labels } = voyageFeatures();
  return {
    voyages: { type: 'geojson', data: { type: 'FeatureCollection', features: lines } },
    'voyage-labels': { type: 'geojson', data: { type: 'FeatureCollection', features: labels } },
  };
}

/** Layer definitions for the initial style object (raised above runtime layers by initVoyages). */
export function voyageStyleLayers(fontStack = ['Cinzel']) {
  return [
    // Wide invisible twin of the line so a route is clickable without pixel-hunting.
    {
      id: 'voyage-hit', type: 'line', source: 'voyages',
      paint: { 'line-width': 16, 'line-color': '#000', 'line-opacity': 0.01 },
    },
    {
      id: 'voyage-line', type: 'line', source: 'voyages',
      layout: { 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': DEFAULT_COLOR, 'line-width': 1.7, 'line-opacity': 0.85 },
    },
    {
      id: 'voyage-label', type: 'symbol', source: 'voyage-labels',
      layout: {
        'text-field': ['concat', ['get', 'name'], '  ', ['get', 'years']],
        'text-font': fontStack, 'text-size': 11.5, 'text-anchor': 'top', 'text-offset': [0, 0.5],
        'text-max-width': 14,
      },
      paint: { 'text-color': DEFAULT_COLOR, 'text-halo-color': '#f3ead6', 'text-halo-width': 1.2 },
    },
  ];
}

/** Call in the map's load handler AFTER the tm-clouds custom layer exists: registers the arrow
 *  icon, lifts the voyage layers above the boundary layers but BELOW tm-clouds, and applies the
 *  era filter. The clouds layer (renderingMode:'3d') writes depth across the whole globe, so any
 *  depth-tested layer drawn after it — every line layer — silently fails the depth test and
 *  disappears; voyage lines must therefore render before it. */
export function initVoyages(map, { year = null, beforeId = undefined } = {}) {
  for (const id of LAYERS) {
    if (map.getLayer(id)) map.moveLayer(id, beforeId);
  }
  if (year !== null) applyVoyageYear(map, year);
}

export function applyVoyageYear(map, year) {
  if (!map.getLayer('voyage-line')) return;
  for (const id of LAYERS) map.setFilter(id, voyageFilter(year));
}

// Recolor routes to sit with the active map style (called from applyMapStyle).
export function applyVoyageStyle(map, { color, halo }) {
  if (!map.getLayer('voyage-line')) return;
  const c = color || DEFAULT_COLOR;
  map.setPaintProperty('voyage-line', 'line-color', c);
  map.setPaintProperty('voyage-label', 'text-color', c);
  if (halo) map.setPaintProperty('voyage-label', 'text-halo-color', halo);
}

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

// ── Where along a drawn line a traveller is ───────────────────────────────
//
// The route trail is revealed by cutting its line-gradient at a fraction of the line's length. That
// fraction used to be the traveller's progress along its own track — a different ruler (its own
// resampling, its own total length), so the two drifted and the line ended a visible distance behind
// the traveller. Small on a ship, glaring on a camel. Measuring from the traveller's POSITION
// instead makes the line stop exactly where it stands.

/**
 * Web-Mercator y for a latitude, in degrees.
 *
 * Everything below measures in THIS space, because the number it produces is fed to MapLibre's
 * `line-progress`, and line-progress is the fraction of the line's length in Mercator tile
 * coordinates. Measuring in ground distance instead (longitudes scaled by cos φ, which is what
 * this file used to do) is a different ruler: Mercator stretches with latitude, so a leg through
 * the Roaring Forties counts for far more of the line than the same ground distance near the
 * equator. On Tasman's route — Batavia at 6°S, New Zealand at 45°S — the two rulers disagreed by
 * thousands of kilometres, and the reveal stopped an ocean short of the ship.
 */
const mercY = (lat) => {
  const φ = Math.max(-89.9, Math.min(89.9, lat)) * Math.PI / 180;
  return Math.log(Math.tan(Math.PI / 4 + φ / 2)) * 180 / Math.PI;
};

/** Cumulative length along `coords` in Mercator space — the ruler line-progress uses. */
export const arcTable = (coords) => {
  const cum = new Array(coords.length).fill(0);
  let total = 0;
  for (let i = 1; i < coords.length; i++) {
    const [ax, ay] = coords[i - 1];
    const [bx, by] = coords[i];
    total += Math.hypot(bx - ax, mercY(by) - mercY(ay));
    cum[i] = total;
  }
  return { cum, total };
};

/** Where `pos` projects onto segment `i`, clamped to it: 0..1 along that segment. */
const segT = (coords, i, pos) => {
  const [ax, ay] = coords[i - 1];
  const [bx, by] = coords[i];
  const ay2 = mercY(ay), by2 = mercY(by), py = mercY(pos.lat);
  const dx = bx - ax, dy = by2 - ay2;
  const len2 = dx * dx + dy * dy;
  if (len2 <= 0) return 0;
  return Math.min(1, Math.max(0, ((pos.lng - ax) * dx + (py - ay2) * dy) / len2));
};

/** How far `pos` sits from segment `i`, in the same Mercator space. */
const segDistance = (coords, i, pos) => {
  const [ax, ay] = coords[i - 1];
  const [bx, by] = coords[i];
  const t = segT(coords, i, pos);
  const ay2 = mercY(ay), by2 = mercY(by);
  return Math.hypot(pos.lng - (ax + (bx - ax) * t), mercY(pos.lat) - (ay2 + (by2 - ay2) * t));
};

/**
 * Fraction along `coords` closest to `pos` — the line-progress to cut the reveal at.
 *
 * The whole line is searched rather than a window around `estimate`, because the two rulers can
 * disagree by more than any sensible window. Where a route doubles back on itself the traveller
 * genuinely sits on two passes at once, so among segments that come about equally near, the one
 * nearest `estimate` wins — that is the pass it is actually on. Two allocation-free passes, since
 * this runs every animation frame.
 *
 * @param {Array<[number, number]>} coords  the drawn line
 * @param {{cum: number[], total: number}} table  from arcTable(coords)
 * @param {{lng: number, lat: number}} pos  where the traveller is
 * @param {number} estimate  its progress along its own track — the tie-breaker, and the fallback
 */
export const cutAtPoint = (coords, table, pos, estimate) => {
  const n = coords.length;
  if (!table || !table.total || n < 2 || !pos) return estimate;
  let nearest = Infinity;
  for (let i = 1; i < n; i++) nearest = Math.min(nearest, segDistance(coords, i, pos));
  let bestCut = estimate, bestGap = Infinity;
  for (let i = 1; i < n; i++) {
    if (segDistance(coords, i, pos) > nearest * 1.5) continue;
    const cut = (table.cum[i - 1] + (table.cum[i] - table.cum[i - 1]) * segT(coords, i, pos)) / table.total;
    const gap = Math.abs(cut - estimate);
    if (gap < bestGap) { bestGap = gap; bestCut = cut; }
  }
  return bestCut;
};

// A marker's size comes back in metres; arcTable measures in Mercator degrees, which stretch with
// latitude — so a metre is worth 1/(111320·cos φ) of them, at the traveller's own latitude.
const METRES_PER_DEGREE = 111_320;
const mercatorDegreesPerMetre = (lat) => 1 / (METRES_PER_DEGREE * Math.max(0.05, Math.cos(lat * Math.PI / 180)));

/**
 * Where the drawn trail has to stop: at the traveller, never past it, set back by half its marker.
 *
 * cutAtPoint lands on the traveller's coordinate, and every marker is drawn CENTRED on it, so a
 * line cut there runs on under the hull and out past the bow. Half a marker puts the end of the
 * line beneath it. The bigger the marker (a tall ship at a teacher-raised ship_scale, zoomed in)
 * the further back it stops — hence a size per call rather than a constant.
 *
 * The projection itself is the ceiling, deliberately: it is where the traveller IS on the drawn
 * line, so it cannot be second-guessed with its track fraction. Clamping to that fraction instead
 * would bring back the older bug in the other direction, the line trailing behind a camel.
 *
 * @param {Array<[number, number]>} coords  the drawn line
 * @param {{cum: number[], total: number}} table  from arcTable(coords)
 * @param {{lng: number, lat: number}} pos  where the traveller is
 * @param {number} estimate  its progress along its own track — the tie-breaker, the ceiling, and
 *   the fallback when there is no line to measure against
 * @param {number} markerMetres  the marker's on-map length; 0 cuts at the bare coordinate
 */
export const trailCutAt = (coords, table, pos, estimate, markerMetres = 0) => {
  const cut = cutAtPoint(coords, table, pos, estimate);
  if (!table || !table.total || !(markerMetres > 0) || !pos) return cut;
  const setback = (markerMetres / 2) * mercatorDegreesPerMetre(pos.lat) / table.total;
  return Math.max(0, cut - setback);
};

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
export function initVoyages(map, { year = null, beforeId = undefined, labelBeforeId = undefined } = {}) {
  for (const id of LAYERS) {
    if (!map.getLayer(id)) continue;
    // THE LABEL IS NOT A LINE, and the depth argument above is about lines.
    //
    // Moving all three below tm-clouds put voyage-label inside the globe stack — under the clouds,
    // the haze and the glare — so a route's name came out washed and half-erased wherever the deck
    // crossed it. That reads as a font or a halo problem, which is exactly how the same fault was
    // reported for territory names before ("Constantinople" cut in two at the Bosphorus).
    //
    // Text goes above the globe with the rest of the writing; only the geometry needs to duck under
    // the cloud layer's depth write.
    map.moveLayer(id, id === 'voyage-label' ? labelBeforeId : beforeId);
  }
  if (year !== null) applyVoyageYear(map, year);
}

export function applyVoyageYear(map, year) {
  if (!map.getLayer('voyage-line')) return;
  for (const id of LAYERS) map.setFilter(id, voyageFilter(year));
}

// Recolor routes to sit with the active map style (called from applyMapStyle). `font` carries the
// style's display hand, so route names switch to the modern sans on Satellite and Night with
// every other label instead of staying in calligraphy on their own.
export function applyVoyageStyle(map, { color, halo, font }) {
  if (!map.getLayer('voyage-line')) return;
  const c = color || DEFAULT_COLOR;
  map.setPaintProperty('voyage-line', 'line-color', c);
  map.setPaintProperty('voyage-label', 'text-color', c);
  if (halo) map.setPaintProperty('voyage-label', 'text-halo-color', halo);
  if (font) map.setLayoutProperty('voyage-label', 'text-font', font);
}

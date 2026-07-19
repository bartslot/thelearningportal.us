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
const smooth = (points, per = 10) => {
  if (points.length < 3) return points;
  const pt = (i) => points[Math.max(0, Math.min(points.length - 1, i))];
  const out = [points[0]];
  for (let i = 0; i < points.length - 1; i++) {
    const [p0, p1, p2, p3] = [pt(i - 1), pt(i), pt(i + 1), pt(i + 2)];
    for (let s = 1; s <= per; s++) {
      const t = s / per;
      const t2 = t * t;
      const t3 = t2 * t;
      out.push([0, 1].map((axis) => 0.5 * (
        (2 * p1[axis])
        + (-p0[axis] + p2[axis]) * t
        + (2 * p0[axis] - 5 * p1[axis] + 4 * p2[axis] - p3[axis]) * t2
        + (-p0[axis] + 3 * p1[axis] - 3 * p2[axis] + p3[axis]) * t3
      )));
    }
  }
  return out;
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

// Right-pointing arrowhead drawn on a canvas — placed along the line by a symbol layer, so no
// glyph coverage is needed. Re-tinted per map style via map.updateImage.
const arrowImage = (color) => {
  const size = 22;
  const c = document.createElement('canvas');
  c.width = size;
  c.height = size;
  const ctx = c.getContext('2d');
  ctx.clearRect(0, 0, size, size);
  ctx.beginPath();
  ctx.moveTo(4, 5);
  ctx.lineTo(18, 11);
  ctx.lineTo(4, 17);
  ctx.closePath();
  ctx.fillStyle = color;
  ctx.fill();
  return ctx.getImageData(0, 0, size, size);
};

const voyageFilter = (year) => ['all',
  ['<=', ['to-number', ['get', 'showFrom']], year],
  ['>=', ['to-number', ['get', 'showTo']], year],
];

const LAYERS = ['voyage-hit', 'voyage-line', 'voyage-arrows', 'voyage-label'];
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
      id: 'voyage-arrows', type: 'symbol', source: 'voyages',
      layout: {
        'symbol-placement': 'line', 'symbol-spacing': 150,
        'icon-image': 'voyage-arrow', 'icon-size': 0.55,
        'icon-rotation-alignment': 'map', 'icon-allow-overlap': true, 'icon-ignore-placement': true,
      },
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
  if (!map.hasImage('voyage-arrow')) map.addImage('voyage-arrow', arrowImage(DEFAULT_COLOR));
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
  if (map.hasImage('voyage-arrow')) {
    map.updateImage('voyage-arrow', arrowImage(c));
  } else {
    map.addImage('voyage-arrow', arrowImage(c));
  }
  map.setPaintProperty('voyage-line', 'line-color', c);
  map.setPaintProperty('voyage-label', 'text-color', c);
  if (halo) map.setPaintProperty('voyage-label', 'text-halo-color', halo);
}

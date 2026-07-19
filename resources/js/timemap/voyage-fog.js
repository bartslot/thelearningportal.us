import { buffer as turfBuffer, difference as turfDifference, featureCollection, polygon as turfPolygon, rewind } from '@turf/turf';

// Fog of war for voyage tours — Van Braam engraving behavior: coasts the expedition has not
// yet reached are blank sea. A voyage declares its `unknown` region (rings in voyages.json);
// everything inside it is painted over in the water colour. Sailing reveals a corridor around
// the traversed route, so e.g. Tasman's New Zealand appears only as the west-coast strip he
// actually charted.
//
// Layering: the fog fill goes on top of land/terrain/labels (hiding undiscovered geography),
// a blurred same-colour line feathers the fog boundary (no hard land↔water seam where the
// cut crosses a continent), and the graticule is lifted above the fog so the blank region
// keeps its chart lines — unknown world is still paper, not a hole.

const FOG_SRC = 'voyage-fog';
const CORRIDOR_KM = 300;         // how far the crew "sees" — tuned so Tasman reveals a coastal strip
const WATER = '#d8e9f3';         // PALETTE.water in lesson-map.js

export function addVoyageFog(map, { unknown, samplePoint, beforeId }) {
  if (!unknown || unknown.length === 0) return null;

  // One feature PER unknown polygon, differenced separately: feeding martinez (turf's
  // boolean engine) a whole MultiPolygon at once yields overlapping output pieces, and
  // MapLibre's even-odd tessellation renders such overlaps as HOLES — undiscovered land
  // showing through its own fog. rewind() because hand-authored rings arrive in either
  // winding and martinez drops clockwise exteriors.
  const unknownPolys = unknown.map((ring) => rewind(turfPolygon([ring])));

  // Traversed track up to voyage-fraction f, sampled through the ships' own pointAt so the
  // corridor front sits EXACTLY at the fleet. (Fractions are arc-length based — slicing the
  // route's coordinate array by index instead overshoots far ahead on long ocean crossings.)
  const CORRIDOR_SAMPLES = 90;
  const corridorLine = (f) => {
    const n = Math.max(2, Math.ceil(CORRIDOR_SAMPLES * f));
    const pts = Array.from({ length: n + 1 }, (_, i) => {
      const p = samplePoint((i / n) * f);
      return [p.lng, p.lat];
    });
    return { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: pts } };
  };

  const fogAt = (f) => {
    let revealed = null;
    try {
      revealed = turfBuffer(corridorLine(f), CORRIDOR_KM, { units: 'kilometers' });
    } catch (_) { /* fall through — no reveal is safer than no fog */ }
    const features = unknownPolys
      .map((poly) => {
        if (!revealed) return poly;
        try {
          return turfDifference(featureCollection([poly, revealed]));
        } catch (_) {
          return poly;
        }
      })
      .filter(Boolean);
    return { type: 'FeatureCollection', features };
  };

  let current = fogAt(0);
  const before = beforeId && map.getLayer(beforeId) ? beforeId : undefined;
  map.addSource(FOG_SRC, { type: 'geojson', data: current });
  map.addLayer({
    id: 'voyage-fog', type: 'fill', source: FOG_SRC,
    paint: { 'fill-color': WATER, 'fill-opacity': 1 },
  }, before);
  // Feathered boundary: wide blurred water-colour line along the fog outline. Over open sea it
  // is invisible (water on water); where the cut crosses land it dissolves the hard seam.
  map.addLayer({
    id: 'voyage-fog-edge', type: 'line', source: FOG_SRC,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: { 'line-color': WATER, 'line-width': 26, 'line-blur': 18, 'line-opacity': 0.92 },
  }, before);
  // Chart lines continue across undiscovered paper, exactly like the engraving.
  if (map.getLayer('graticule')) map.moveLayer('graticule');

  let lastF = -1;
  let lastT = 0;
  return {
    /** Reveal the corridor sailed so far (voyage-global fraction 0..1). Throttled internally. */
    revealTo(f, { force = false } = {}) {
      const now = performance.now();
      if (!force && (f - lastF < 0.004 || now - lastT < 250)) return;
      lastF = f;
      lastT = now;
      current = fogAt(f);
      const src = map.getSource(FOG_SRC);
      if (src) src.setData(current);
    },
    /** Current fog FeatureCollection — the shore-etch overlay masks against it. */
    current: () => current,
    destroy() {
      try {
        if (map.getLayer('voyage-fog-edge')) map.removeLayer('voyage-fog-edge');
        if (map.getLayer('voyage-fog')) map.removeLayer('voyage-fog');
        if (map.getSource(FOG_SRC)) map.removeSource(FOG_SRC);
      } catch (_) { /* map may already be gone */ }
    },
  };
}

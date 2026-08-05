import { buffer as turfBuffer, difference as turfDifference, union as turfUnion, featureCollection, polygon as turfPolygon, rewind, simplify as turfSimplify } from '@turf/turf';

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
const CORRIDOR_KM = 450;         // how far the crew "sees" — wide enough that a visited landmass reveals
                                 // its nearby extent too (e.g. all of NZ, not just the South Island coast)
const WATER_FALLBACK = '#c7d4c6'; // soft-atlas water; real colour comes from the active style

// Feathered boundary. The fog fill is solid to its own outline; these blurred lines ride that
// outline and carry the colour outwards, so the mask fades from nothing to solid instead of
// ending at an edge. One line (the old behaviour) gave a ~40 px ramp — short enough to read as a
// drawn line, and unmissable where an auto mask cuts a straight bbox edge across open sea. Five
// stacked bands ramp over ~10× that distance, each too faint to show a step of its own; their
// alphas compound to ~0.94 by the time they meet the solid fill.
// Screen pixels on purpose: the feather should look the same at every zoom, and a world-distance
// buffer would have to be re-cut by turf on every reveal.
const FEATHER = [
  { id: 'voyage-fog-feather-4', width: 240, blur: 200, opacity: 0.16 },
  { id: 'voyage-fog-feather-3', width: 150, blur: 120, opacity: 0.24 },
  { id: 'voyage-fog-feather-2', width: 90, blur: 70, opacity: 0.34 },
  { id: 'voyage-fog-feather-1', width: 50, blur: 38, opacity: 0.5 },
  { id: 'voyage-fog-edge', width: 26, blur: 18, opacity: 0.7 },
];

// A [minLng,minLat,maxLng,maxLat] box → a closed polygon ring (kept in the route's unwrapped lng
// frame so Pacific/antimeridian voyages, e.g. Tonga ≈ 184.8°E, stay contiguous).
const boxRing = (b) => [[b[0], b[1]], [b[2], b[1]], [b[2], b[3]], [b[0], b[3]], [b[0], b[1]]];

/**
 * Bring a polygon back into the route's unwrapped longitude frame.
 *
 * Everything here lives in that frame (see boxRing) — but turf does not. turfBuffer projects
 * through a wrapped [-180,180] frame, so a corridor sailed past the antimeridian comes back on the
 * far side of the world from the route that made it: Tasman's leg to Tonga (184.8°E) buffers to
 * -175.2°, the difference then cuts its hole half a globe away, and the ship sits under intact fog
 * — a black void where the Pacific should be. Map-queried coastlines arrive wrapped for the same
 * reason.
 *
 * Each ring is walked in order, every point placed in the same 360° window as the one before it, so
 * a ring that crosses the seam mid-way stays contiguous instead of folding in half. The first point
 * is seeded next to `lng0` (the route's own centre). A no-op on geometry that is already in frame,
 * which is why it is safe to run over authored rings too.
 */
export const unwrapTo = (feature, lng0) => {
  if (!feature || !Number.isFinite(lng0)) return feature;
  const g = feature.geometry;
  if (!g || (g.type !== 'Polygon' && g.type !== 'MultiPolygon')) return feature;
  const unwrapRing = (ring) => {
    let prev = null;
    return ring.map((pos) => {
      const lng = pos[0] + 360 * Math.round(((prev === null ? lng0 : prev) - pos[0]) / 360);
      prev = lng;
      return [lng, pos[1]];
    });
  };
  const unwrapPoly = (poly) => poly.map(unwrapRing);
  const coordinates = g.type === 'MultiPolygon' ? g.coordinates.map(unwrapPoly) : unwrapPoly(g.coordinates);
  return { ...feature, geometry: { ...g, coordinates } };
};

/** Mean longitude of a set of [lng,lat] positions — the frame every reveal is seeded into. */
const meanLng = (positions) => (positions.length
  ? positions.reduce((sum, p) => sum + p[0], 0) / positions.length
  : 0);

export function addVoyageFog(map, { unknown, samplePoint, beforeId, waterColor, auto = false, knownBoxes = [], worldBox = null }) {
  // Always build the layer — even with zero regions — so a teacher can paint fog from scratch (or
  // after erasing everything) and setRegions() has a live source to update. Regions now come from
  // the lesson's editable voyage_fog, which may legitimately start empty.
  unknown = Array.isArray(unknown) ? unknown : [];
  // Undiscovered world must be the SAME colour as the sea (it's blank paper, not blue water) and
  // must follow the map style — so the fill/edge use the active style's water colour, not a constant.
  let water = waterColor || WATER_FALLBACK;

  // Close a hand-authored ring if the author didn't repeat the first point — turfPolygon
  // rejects an open ring ("First and last Position are not equivalent"), which would blow up
  // the whole tour. Defensive so new voyages can't crash on this easy-to-miss detail.
  const closeRing = (ring) => {
    if (ring.length < 3) return ring;
    const [a, b] = [ring[0], ring[ring.length - 1]];
    return (a[0] === b[0] && a[1] === b[1]) ? ring : [...ring, a];
  };

  // One feature PER unknown polygon, differenced separately: feeding martinez (turf's
  // boolean engine) a whole MultiPolygon at once yields overlapping output pieces, and
  // MapLibre's even-odd tessellation renders such overlaps as HOLES — undiscovered land
  // showing through its own fog. rewind() because hand-authored rings arrive in either
  // winding and martinez drops clockwise exteriors. `let` so teacher-painted regions can be
  // merged in live via setRegions().
  const toPolys = (rings) => (rings || []).map((ring) => rewind(turfPolygon([closeRing(ring)])));
  let unknownPolys = toPolys(unknown);

  // ── AUTO fog-of-war ──────────────────────────────────────────────────────────────────────────
  // In auto mode the base masked area is the WHOLE route bbox (one polygon), and the "known world"
  // boxes are permanently revealed (subtracted) — so all NEW land along the route reads as blank sea
  // until the sailed corridor reaches it, while Europe/Asia/Indonesia/home port stay charted.
  let autoMode = !!auto;
  let worldPoly = worldBox ? turfPolygon([boxRing(worldBox)]) : null;
  const buildKnownReveal = (boxes) => {
    const polys = (boxes || []).filter((b) => Array.isArray(b) && b.length === 4).map((b) => turfPolygon([boxRing(b)]));
    if (!polys.length) return null;
    return polys.reduce((acc, p) => {
      if (!acc) return p;
      try { return turfUnion(featureCollection([acc, p])) || acc; } catch (_) { return acc; }
    }, null);
  };
  let knownReveal = buildKnownReveal(knownBoxes);

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

  // Permanent reveals for landfalls the ship has REACHED — the whole island un-fogs when its coast is
  // drawn (the corridor alone only clears a 450 km swath, leaving a big island half-green). Unioned
  // into every reveal below, so it survives corridor updates.
  let landfallReveals = null;
  const addLandfall = (poly) => {
    if (!poly) return;
    try { landfallReveals = landfallReveals ? (turfUnion(featureCollection([landfallReveals, poly])) || landfallReveals) : poly; } catch (_) { /* keep prior */ }
  };

  // The frame every reveal is unwrapped into: the sailed route's own centre longitude. Seeded from
  // the full route (f = 1) so it does not drift as the corridor grows leg by leg.
  let routeLng0 = null;
  const routeFrame = () => {
    if (routeLng0 === null) routeLng0 = meanLng(corridorLine(1).geometry.coordinates);
    return routeLng0;
  };

  const fogAt = (f) => {
    let revealed = null;
    try {
      const line = corridorLine(f);
      // …then back into the route's frame: turfBuffer wraps at the antimeridian (see unwrapTo).
      revealed = unwrapTo(turfBuffer(line, CORRIDOR_KM, { units: 'kilometers' }), meanLng(line.geometry.coordinates));
    } catch (_) { /* fall through — no reveal is safer than no fog */ }
    if (landfallReveals) {
      try { revealed = revealed ? turfUnion(featureCollection([revealed, landfallReveals])) : landfallReveals; } catch (_) { /* keep corridor only */ }
    }

    // AUTO: whole route-bbox minus (sailed corridor ∪ known world). One clean difference.
    if (autoMode && worldPoly) {
      let reveal = revealed;
      if (knownReveal) {
        try { reveal = reveal ? turfUnion(featureCollection([reveal, knownReveal])) : knownReveal; } catch (_) { /* keep corridor only */ }
      }
      if (!reveal) return { type: 'FeatureCollection', features: [worldPoly] };
      try {
        const cut = turfDifference(featureCollection([worldPoly, reveal]));
        return { type: 'FeatureCollection', features: cut ? [cut] : [] };
      } catch (_) {
        return { type: 'FeatureCollection', features: [worldPoly] };
      }
    }

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
    paint: { 'fill-color': water, 'fill-opacity': 1 },
  }, before);
  // Widest band first so the tightest one lands on top and the ramp builds inwards.
  for (const f of FEATHER) {
    map.addLayer({
      id: f.id, type: 'line', source: FOG_SRC,
      layout: { 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': water, 'line-width': f.width, 'line-blur': f.blur, 'line-opacity': f.opacity },
    }, before);
  }
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
    /** Permanently un-fog a REACHED landfall — pass its coastline ring ([[lng,lat],…]); the island's
     *  interior (the land fill) is revealed so it no longer reads as sea. Idempotent + unioned. */
    revealRegion(ring) {
      if (!Array.isArray(ring) || ring.length < 4) return;
      try {
        const closed = ring.map((p) => [p[0], p[1]]);
        const a = closed[0], b = closed[closed.length - 1];
        if (a[0] !== b[0] || a[1] !== b[1]) closed.push([a[0], a[1]]);
        let poly = turfPolygon([closed]);
        try { poly = turfSimplify(poly, { tolerance: 0.03, highQuality: false }) || poly; } catch (_) { /* use full ring */ }
        try { poly = rewind(poly, { reverse: false }) || poly; } catch (_) { /* winding as-is */ }
        // A coastline queried from the map is wrapped to [-180,180]; the fog is not. Without this a
        // reached Pacific island un-fogs a patch of empty ocean on the other side of the world.
        poly = unwrapTo(poly, routeFrame());
        addLandfall(poly);
        current = fogAt(lastF < 0 ? 0 : lastF);
        const src = map.getSource(FOG_SRC);
        if (src) src.setData(current);
      } catch (_) { /* a malformed ring must not break the fog */ }
    },
    /**
     * Show or hide the whole mask without losing what has been revealed.
     *
     * The itinerary shows the WHOLE voyage — every leg, every numbered stop — so a mask whose job
     * is to hide what the crew had not reached yet would black out most of what the class came to
     * look at. Hidden there, restored the moment a leg is played again.
     */
    setVisible(on) {
      const vis = on ? 'visible' : 'none';
      try { if (map.getLayer('voyage-fog')) map.setLayoutProperty('voyage-fog', 'visibility', vis); } catch (_) { /* layer gone */ }
      for (const f of FEATHER) {
        try { if (map.getLayer(f.id)) map.setLayoutProperty(f.id, 'visibility', vis); } catch (_) { /* layer gone */ }
      }
    },
    /** Repaint the fog to a new colour (call on style change) — fill and every feather band. */
    setWaterColor(c) {
      if (!c) return;
      water = c;
      try { map.setPaintProperty('voyage-fog', 'fill-color', c); } catch (_) { /* layer gone */ }
      for (const f of FEATHER) {
        try { map.setPaintProperty(f.id, 'line-color', c); } catch (_) { /* layer gone */ }
      }
    },
    /** Replace the undiscovered regions (catalog + teacher-painted) and repaint. Ignored in auto mode
     *  (the whole-route mask owns the fog then). */
    setRegions(rings) {
      unknownPolys = toPolys(rings);
      if (autoMode) return;
      current = fogAt(lastF < 0 ? 0 : lastF);
      const src = map.getSource(FOG_SRC);
      if (src) src.setData(current);
    },
    /** Toggle auto fog-of-war live (whole-route mask ⇄ teacher-painted regions) — no re-mount. */
    setAuto(a, boxes, wbox) {
      autoMode = !!a;
      if (Array.isArray(boxes)) knownReveal = buildKnownReveal(boxes);
      if (Array.isArray(wbox) && wbox.length === 4) worldPoly = turfPolygon([boxRing(wbox)]);
      current = fogAt(lastF < 0 ? 0 : lastF);
      const src = map.getSource(FOG_SRC);
      if (src) src.setData(current);
    },
    /** Current fog FeatureCollection — the shore-etch overlay masks against it. */
    current: () => current,
    destroy() {
      try {
        for (const f of FEATHER) { if (map.getLayer(f.id)) map.removeLayer(f.id); }
        if (map.getLayer('voyage-fog')) map.removeLayer('voyage-fog');
        if (map.getSource(FOG_SRC)) map.removeSource(FOG_SRC);
      } catch (_) { /* map may already be gone */ }
    },
  };
}

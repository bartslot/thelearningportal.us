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

// A [minLng,minLat,maxLng,maxLat] box → a closed polygon ring (kept in the route's unwrapped lng
// frame so Pacific/antimeridian voyages, e.g. Tonga ≈ 184.8°E, stay contiguous).
const boxRing = (b) => [[b[0], b[1]], [b[2], b[1]], [b[2], b[3]], [b[0], b[3]], [b[0], b[1]]];

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

  const fogAt = (f) => {
    let revealed = null;
    try {
      revealed = turfBuffer(corridorLine(f), CORRIDOR_KM, { units: 'kilometers' });
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
  // Feathered boundary: wide blurred water-colour line along the fog outline. Over open sea it
  // is invisible (water on water); where the cut crosses land it dissolves the hard seam.
  map.addLayer({
    id: 'voyage-fog-edge', type: 'line', source: FOG_SRC,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: { 'line-color': water, 'line-width': 26, 'line-blur': 18, 'line-opacity': 0.92 },
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
        addLandfall(poly);
        current = fogAt(lastF < 0 ? 0 : lastF);
        const src = map.getSource(FOG_SRC);
        if (src) src.setData(current);
      } catch (_) { /* a malformed ring must not break the fog */ }
    },
    /** Repaint the fog to a new water colour (call on style change). */
    setWaterColor(c) {
      if (!c) return;
      water = c;
      try { map.setPaintProperty('voyage-fog', 'fill-color', c); } catch (_) { /* layer gone */ }
      try { map.setPaintProperty('voyage-fog-edge', 'line-color', c); } catch (_) { /* layer gone */ }
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
        if (map.getLayer('voyage-fog-edge')) map.removeLayer('voyage-fog-edge');
        if (map.getLayer('voyage-fog')) map.removeLayer('voyage-fog');
        if (map.getSource(FOG_SRC)) map.removeSource(FOG_SRC);
      } catch (_) { /* map may already be gone */ }
    },
  };
}

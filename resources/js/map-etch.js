/**
 * map-etch.js — engraved coastal "water lines", horizontal strokes only, after the
 * copperplate convention of the Van Braam Tasman chart.
 *
 * The strokes are REAL MAP GEOMETRY: horizontal segments computed in lng/lat and
 * rendered as a MapLibre line layer, so they sit in the paint pipeline and move
 * pixel-locked with the camera (a DOM canvas overlay always lags a throttle behind).
 * Recomputation happens only on map idle, when the camera is at rest and land tiles
 * for the view have arrived; between idles the existing geometry simply transforms.
 *
 * Engraver's logic, not AI confetti:
 *   - lines START at the shore: a hairline gap after the coast ink, then the densest row pack;
 *   - density and THICKNESS carry the fade — spacing doubles and width tapers with distance;
 *   - near-shore lines run long and unbroken; raggedness appears only seaward;
 *   - the outermost reaches dissolve through value noise (the "perlin fade" of the reference),
 *     never uniformly.
 *
 * Layering: inserted below the political borders and below the voyage fog, so
 * undiscovered coasts keep their blank paper until the fleet reveals them.
 */

const SRC = 'shore-etch';
const LAYER = 'shore-etch';
const INK = '#3f3020';                  // same ink as the bold coast line
// Distance bands, in screen px at compute time: [reach, row spacing, width, opacity, noise cut]
// Noise cut 0 = solid line; higher = more of the stroke dissolves (only seaward bands).
const BANDS = [
  { r: 4,  step: 1, w: 1.10, o: 0.65, cut: 0.00 },
  { r: 9,  step: 1, w: 0.85, o: 0.55, cut: 0.00 },
  { r: 15, step: 2, w: 0.65, o: 0.42, cut: 0.15 },
  { r: 22, step: 3, w: 0.50, o: 0.30, cut: 0.35 },
  { r: 30, step: 4, w: 0.40, o: 0.20, cut: 0.55 },
];
const ROW_PX = 2.6;                     // base row pitch at compute zoom (finest band)

// Deterministic value noise over lng/lat — smooth patchiness for the seaward dissolve.
const hash = (x, y) => {
  const s = Math.sin(x * 127.1 + y * 311.7) * 43758.5453;
  return s - Math.floor(s);
};
const noise2 = (x, y) => {
  const xi = Math.floor(x); const yi = Math.floor(y);
  const xf = x - xi; const yf = y - yi;
  const u = xf * xf * (3 - 2 * xf); const v = yf * yf * (3 - 2 * yf);
  return hash(xi, yi) * (1 - u) * (1 - v) + hash(xi + 1, yi) * u * (1 - v)
       + hash(xi, yi + 1) * (1 - u) * v + hash(xi + 1, yi + 1) * u * v;
};

export function attachShoreEtch(map) {
  const scratch = document.createElement('canvas');
  const sctx = scratch.getContext('2d', { willReadFrequently: true });
  const maskCanvas = document.createElement('canvas');
  const mctx = maskCanvas.getContext('2d');

  let destroyed = false;
  let lastKey = '';

  const ensureLayer = () => {
    if (map.getSource(SRC)) return;
    map.addSource(SRC, { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    const before = ['voyage-fog', 'boundaries-line', 'city-dots'].find((id) => map.getLayer(id));
    map.addLayer({
      id: LAYER, type: 'line', source: SRC,
      layout: { 'line-cap': 'butt' },
      paint: {
        'line-color': INK,
        'line-width': ['get', 'w'],
        'line-opacity': ['get', 'o'],
      },
    }, before);
  };

  const fillGeom = (c, geom) => {
    const polys = geom.type === 'Polygon' ? [geom.coordinates]
      : geom.type === 'MultiPolygon' ? geom.coordinates : [];
    for (const poly of polys) {
      c.beginPath();
      for (const ring of poly) {
        ring.forEach(([lng, lat], i) => {
          const p = map.project([lng, lat]);
          if (i === 0) c.moveTo(p.x, p.y); else c.lineTo(p.x, p.y);
        });
        c.closePath();
      }
      c.fill();
    }
  };

  // Alpha-only redraw of the land mask, used as the blur source for each band.
  const maskImage = (data, w, h) => {
    maskCanvas.width = w;
    maskCanvas.height = h;
    const img = mctx.createImageData(w, h);
    for (let i = 3; i < data.length; i += 4) img.data[i] = data[i];
    mctx.putImageData(img, 0, 0);
    return maskCanvas;
  };

  const compute = () => {
    if (destroyed) return;
    const w = map.getContainer().clientWidth;
    const h = map.getContainer().clientHeight;
    if (!w || !h) return;
    // Skip when the camera hasn't meaningfully moved since the last compute.
    const c = map.getCenter();
    const key = `${c.lng.toFixed(3)},${c.lat.toFixed(3)},${map.getZoom().toFixed(2)}`;
    if (key === lastKey) return;

    const feats = map.querySourceFeatures('land', { sourceLayer: 'land' });
    if (!feats.length) return;
    lastKey = key;

    scratch.width = w;
    scratch.height = h;
    sctx.clearRect(0, 0, w, h);
    sctx.fillStyle = '#000';
    for (const f of feats) fillGeom(sctx, f.geometry);
    const land = sctx.getImageData(0, 0, w, h).data;
    const landMask = maskImage(land, w, h);

    // One blurred silhouette per band reach — a pixel's band = the tightest blur that covers it.
    const bandData = BANDS.map(({ r }) => {
      sctx.clearRect(0, 0, w, h);
      sctx.filter = `blur(${r}px)`;
      sctx.drawImage(landMask, 0, 0);
      sctx.filter = 'none';
      return sctx.getImageData(0, 0, w, h).data;
    });

    // World-anchored rows: identical latitudes across recomputes at the same zoom, so
    // the etching never reflows mid-scene — the camera just carries it. Snapped to a
    // 1-2-5-ish series (NOT powers of two: that quantization is so coarse it swallows
    // any ROW_PX tuning whole).
    const latTop = map.unproject([0, 0]).lat;
    const latBot = map.unproject([0, h]).lat;
    const degPerPx = (latTop - latBot) / h;
    const raw = ROW_PX * degPerPx;
    const exp = Math.floor(Math.log10(raw));
    const mant = raw / 10 ** exp;
    const nice = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8].reduce((a, n) => (Math.abs(n - mant) < Math.abs(a - mant) ? n : a));
    const step = nice * 10 ** exp;

    const features = [];
    const emit = (x0, x1, y, band, rowSeed) => {
      if (x1 - x0 < 3) return;
      const a = map.unproject([x0, y]);
      const b = map.unproject([x1, y]);
      const spec = BANDS[band];
      const jit = 0.85 + 0.3 * hash(rowSeed, band * 7.3);
      features.push({
        type: 'Feature',
        properties: {
          w: +(spec.w * jit).toFixed(2),
          o: +(spec.o * (0.9 + 0.2 * hash(rowSeed * 1.7, x0))).toFixed(2),
        },
        geometry: { type: 'LineString', coordinates: [[a.lng, a.lat], [b.lng, b.lat]] },
      });
    };

    let row = 0;
    for (let lat = Math.floor(latBot / step) * step; lat <= latTop; lat += step, row++) {
      const y = Math.round(map.project([c.lng, lat]).y);
      if (y < 0 || y >= h) continue;
      const rowSeed = Math.abs(lat / step);
      let band = -1;
      let runStart = -1;
      for (let x = 0; x <= w; x++) {
        let b = -1;
        if (x < w) {
          const i = (y * w + x) * 4 + 3;
          if (land[i] < 8) { // water only — strokes never cross the shore ink
            for (let k = 0; k < BANDS.length; k++) {
              if (bandData[k][i] > 24) { b = k; break; }
            }
            // Coarser bands keep only every Nth row (density fade) — with a per-band
            // stagger so the dropouts of different bands never align into visible
            // registers of equal-length dashes.
            if (b >= 0) {
              const st = BANDS[b].step;
              if ((row + Math.floor(hash(b * 3.1, rowSeed) * st)) % st !== 0) b = -1;
            }
            // Seaward dissolve: value noise eats the outer bands, never the shore pack.
            if (b >= 0 && BANDS[b].cut > 0) {
              const ll = map.unproject([x, y]);
              if (noise2(ll.lng * 8, ll.lat * 8) < BANDS[b].cut) b = -1;
            }
          }
        }
        if (b !== band) {
          if (band >= 0 && runStart >= 0) emit(runStart, x, y, band, rowSeed);
          band = b;
          runStart = b >= 0 ? x : -1;
        }
      }
    }

    const src = map.getSource(SRC);
    if (src) src.setData({ type: 'FeatureCollection', features });
  };

  const onIdle = () => { ensureLayer(); compute(); };
  map.on('idle', onIdle);
  if (map.isStyleLoaded()) onIdle();

  return {
    refresh: () => { lastKey = ''; onIdle(); },
    destroy() {
      destroyed = true;
      map.off('idle', onIdle);
      try {
        if (map.getLayer(LAYER)) map.removeLayer(LAYER);
        if (map.getSource(SRC)) map.removeSource(SRC);
      } catch (_) { /* map may already be gone */ }
    },
  };
}

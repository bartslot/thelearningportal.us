import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const turf = require('@turf/turf');
const fs = require('fs');

// Wobbled border lines for the ink styles: take each polity ring, simplify, densify, then displace
// vertices with value-noise so the line wavers like a hand-drawn pen stroke. Keeps FromYear/ToYear.
const src = JSON.parse(fs.readFileSync('storage/app/cliopatria/cliopatria_polities_only.geojson', 'utf8'));
const AMP = 0.045; // degrees of wobble
function noise(x, y, seed) {
  const s = Math.sin(x * 12.9898 + y * 78.233 + seed * 37.719) * 43758.5453;
  return (s - Math.floor(s)) * 2 - 1;
}
function wobble(coords) {
  const step = 0.22, dense = [];
  for (let i = 0; i < coords.length - 1; i++) {
    const [x0, y0] = coords[i], [x1, y1] = coords[i + 1];
    dense.push([x0, y0]);
    const n = Math.floor(Math.hypot(x1 - x0, y1 - y0) / step);
    for (let k = 1; k < n; k++) { const t = k / n; dense.push([x0 + (x1 - x0) * t, y0 + (y1 - y0) * t]); }
  }
  dense.push(coords[coords.length - 1]);
  return dense.map(([x, y], i) => (i === 0 || i === dense.length - 1) ? [x, y]
    : [x + noise(x * 2.7, y * 2.7, 1) * AMP, y + noise(x * 2.7, y * 2.7, 2) * AMP]);
}
const out = [];
for (const f of src.features) {
  const p = f.properties;
  if (p.Type !== 'POLITY' || (p.Name || '').startsWith('(')) continue;
  const props = { FromYear: p.FromYear, ToYear: p.ToYear };
  const g = f.geometry;
  const polys = g.type === 'Polygon' ? [g.coordinates] : g.type === 'MultiPolygon' ? g.coordinates : [];
  for (const poly of polys) for (const ring of poly) {
    if (ring.length < 4) continue;
    let simp;
    try { simp = turf.simplify(turf.lineString(ring), { tolerance: 0.05, highQuality: false }).geometry.coordinates; } catch (e) { simp = ring; }
    if (simp.length < 3) continue;
    out.push(turf.lineString(wobble(simp), props));
  }
}
fs.writeFileSync('storage/app/cliopatria/ink-borders.geojson', JSON.stringify(turf.featureCollection(out)));
console.log('ink border lines:', out.length);

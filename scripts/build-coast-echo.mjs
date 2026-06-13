import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const turf = require('@turf/turf');
const fs = require('fs');

// Etched "coast echo": concentric lines paralleling the coast out to sea, fading outward.
// Built by buffering the land polygons at increasing distances and keeping the seaward outline.
const land = JSON.parse(fs.readFileSync('storage/app/naturalearth/ne_50m_land.geojson', 'utf8'));
const simp = turf.simplify(land, { tolerance: 0.06, highQuality: false, mutate: true });
const dists = [9, 22, 38, 58]; // km seaward; index 0 = closest/darkest
const out = [];
for (let i = 0; i < dists.length; i++) {
  let buf;
  try { buf = turf.buffer(simp, dists[i], { units: 'kilometers' }); } catch (e) { console.error('buffer', dists[i], e.message); continue; }
  if (!buf) continue;
  turf.flattenEach(buf, (poly) => {
    const line = turf.polygonToLine(poly);
    turf.flattenEach(line, (lf) => { out.push(turf.feature(lf.geometry, { echo: i })); });
  });
  console.error(`dist ${dists[i]}km -> ${out.length} cumulative lines`);
}
fs.writeFileSync('storage/app/naturalearth/coast-echo.geojson', JSON.stringify(turf.featureCollection(out)));
console.log('echo features:', out.length);

import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { VectorTile } = require('@mapbox/vector-tile');
const PbfMod = require('pbf');
const Protobuf = PbfMod.PbfReader || PbfMod.Pbf || PbfMod.default || PbfMod;

const UA = 'TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com) educational';
const BASE = 'https://vtiles.openhistoricalmap.org/maps/ohm_admin';

const lon2x = (lon, z) => Math.floor(((lon + 180) / 360) * 2 ** z);
const lat2y = (lat, z) => {
  const r = (lat * Math.PI) / 180;
  return Math.floor(((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2) * 2 ** z);
};

async function getTile(z, x, y) {
  const res = await fetch(`${BASE}/${z}/${x}/${y}.pbf`, { headers: { 'User-Agent': UA } });
  if (!res.ok) return { ok: false, size: 0 };
  const buf = new Uint8Array(await res.arrayBuffer());
  return { ok: true, size: buf.length, buf };
}

// --- Coverage + osm_id format from z4 Europe tiles ---
const eras = [-235, 1000, 1850];
const byEra = { '-235': new Set(), 1000: new Set(), 1850: new Set() };
let sampleProps = null;
const z = 4;
for (let x = lon2x(-25, z); x <= lon2x(50, z); x++) {
  for (let y = lat2y(72, z); y <= lat2y(30, z); y++) {
    const t = await getTile(z, x, y);
    if (!t.ok) continue;
    const layer = new VectorTile(new Protobuf(t.buf)).layers['boundaries'];
    if (!layer) continue;
    for (let i = 0; i < layer.length; i++) {
      const p = layer.feature(i).properties;
      if (!sampleProps) sampleProps = { osm_id: p.osm_id, admin_level: p.admin_level, name: p.name, start_decdate: p.start_decdate, end_decdate: p.end_decdate };
      const s = Number(p.start_decdate), e = Number(p.end_decdate);
      for (const yr of eras) {
        if ((isNaN(s) || s <= yr) && (isNaN(e) || e > yr) && p.name) {
          byEra[yr].add(`${p.name} (adm${p.admin_level} ${p.start_decdate}..${p.end_decdate})`);
        }
      }
    }
  }
}

console.log('=== sample feature props (note osm_id format) ===');
console.log(JSON.stringify(sampleProps));
for (const yr of eras) {
  const list = [...byEra[yr]].sort();
  console.log(`\n=== ${yr < 0 ? Math.abs(yr) + ' BCE' : yr + ' CE'}: ${list.length} features valid ===`);
  console.log(list.slice(0, 30).join('\n'));
}

// --- Tile size estimate z0-5 over Europe ---
let tiles = 0, bytes = 0;
for (let zz = 0; zz <= 5; zz++) {
  const x0 = zz <= 2 ? 0 : lon2x(-25, zz), x1 = zz <= 2 ? 2 ** zz - 1 : lon2x(50, zz);
  const y0 = zz <= 2 ? 0 : lat2y(72, zz), y1 = zz <= 2 ? 2 ** zz - 1 : lat2y(30, zz);
  for (let x = x0; x <= x1; x++) for (let y = y0; y <= y1; y++) {
    const t = await getTile(zz, x, y);
    if (t.ok) { tiles++; bytes += t.size; }
  }
}
console.log(`\n=== z0-5 Europe mirror: ${tiles} tiles, ${(bytes / 1048576).toFixed(1)} MB (raw, all name_xx fields) ===`);

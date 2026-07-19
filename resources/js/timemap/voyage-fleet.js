import * as THREE from 'three';

// Parametric low-poly tall ships — replaces the 2.2 MB traders_tallship.glb with a few KB of
// geometry. Flat colours only (no textures), 2/3/4-mast variants, and a named flag anchor per
// masthead so voyage-specific flags can be swapped in later. The Prinsenvlag is three stacked
// coloured quads, exactly like the reference photo, no texture needed.
//
// Convention: bow points toward -Z, waterline at y=0, overall hull length exactly 1.0 — the
// renderer (voyage-ships.js) scales by metres/modelLength and yaws by heading + PI/2.

export const PRINSENVLAG = ['#d9782d', '#f2ede2', '#3f5f9e']; // oranje-blanje-bleu
export const VOC_HULL = '#4a3626';

const lambert = (color) => new THREE.MeshLambertMaterial({ color, flatShading: true });

const box = (w, h, d, color, x = 0, y = 0, z = 0) => {
  const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), lambert(color));
  m.position.set(x, y, z);
  return m;
};

// Striped flag at a masthead: three stacked quads (top colour first). Group is named so specific
// flags (VOC monogram, provincial lions) can replace the children later without touching layout.
const buildFlag = (colors, mastIndex) => {
  const flag = new THREE.Group();
  flag.name = `flag-${mastIndex}`;
  const bandH = 0.022;
  colors.forEach((c, i) => {
    const band = box(0.005, bandH, 0.085, c, 0, -bandH * i, -0.0425 - 0.004);
    flag.add(band);
  });
  return flag;
};

const buildMast = ({ height, sailW, sailColor, flagColors, index }) => {
  const mast = new THREE.Group();
  const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.008, 0.011, height, 5), lambert('#3c2f22'));
  pole.position.y = height / 2;
  mast.add(pole);

  // Two square sails per mast — single planes, double-sided, slightly forward of the mast.
  const sailMat = new THREE.MeshLambertMaterial({ color: sailColor, side: THREE.DoubleSide, flatShading: true });
  const mkSail = (w, h, y) => {
    // Square rig: the sail's SURFACE spans across the hull (plane normal along the bow axis) —
    // the default PlaneGeometry orientation. Rotating it 90° made sails edge-on from above.
    const sail = new THREE.Mesh(new THREE.PlaneGeometry(w, h), sailMat);
    sail.position.set(0, y, -0.015);
    return sail;
  };
  mast.add(mkSail(sailW, height * 0.34, height * 0.42));
  mast.add(mkSail(sailW * 0.78, height * 0.26, height * 0.74));

  const flag = buildFlag(flagColors, index);
  flag.position.y = height + 0.012;
  mast.add(flag);
  return mast;
};

/**
 * @param {object} opts
 * @param {number} [opts.masts=3] 2..4
 * @param {boolean} [opts.flagship=false] flagship gets a taller mainmast + stern lantern
 * @param {string} [opts.hull]
 * @param {string} [opts.sails]
 * @param {string[]} [opts.flag] top-to-bottom stripe colours
 * @returns {THREE.Group} bow -Z, waterline y=0, length 1.0
 */
export function buildTallship({ masts = 3, flagship = false, hull = VOC_HULL, sails = '#e8e0cc', flag = PRINSENVLAG } = {}) {
  const ship = new THREE.Group();

  // Hull: main body + sterncastle + forecastle + bowsprit. Bow toward -Z, stern +Z.
  ship.add(box(0.24, 0.10, 0.86, hull, 0, 0.01, 0.02));
  ship.add(box(0.20, 0.07, 0.16, '#5d4732', 0, 0.095, 0.33));           // sterncastle
  ship.add(box(0.17, 0.045, 0.12, '#5d4732', 0, 0.075, -0.30));         // forecastle
  ship.add(box(0.26, 0.018, 0.80, '#7a6248', 0, 0.065, 0.02));          // deck rim
  const bowsprit = new THREE.Mesh(new THREE.CylinderGeometry(0.007, 0.01, 0.30, 5), lambert('#3c2f22'));
  bowsprit.rotation.x = -Math.PI / 3.2;
  bowsprit.position.set(0, 0.10, -0.52);
  ship.add(bowsprit);

  // Masts spaced along the hull; the middle (or aft-of-middle) mast is the tallest.
  const span = 0.62;
  const positions = { 2: [-0.16, 0.20], 3: [-0.24, 0.02, 0.28], 4: [-0.26, -0.05, 0.16, 0.33] }[Math.min(4, Math.max(2, masts))];
  positions.forEach((z, i) => {
    const isMain = i === (positions.length > 2 ? 1 : 0);
    const height = (isMain ? 0.62 : 0.48) * (flagship ? 1.12 : 1) * (1 - Math.abs(z) / (span * 3.2));
    const mast = buildMast({
      height, index: i,
      sailW: 0.20 * (isMain ? 1.15 : 0.95),
      sailColor: sails, flagColors: flag,
    });
    mast.position.z = z;
    ship.add(mast);
  });

  if (flagship) {
    const lantern = new THREE.Mesh(new THREE.SphereGeometry(0.016, 6, 5), lambert('#e6c46a'));
    lantern.position.set(0, 0.16, 0.42);
    ship.add(lantern);
  }
  return ship;
}

/** Build a voyage's fleet from its spec ([{masts, flagship}, ...]); defaults to one 3-master. */
export function buildFleet(fleetSpec) {
  const spec = Array.isArray(fleetSpec) && fleetSpec.length ? fleetSpec : [{ masts: 3, flagship: true }];
  return spec.map((s) => buildTallship(s));
}

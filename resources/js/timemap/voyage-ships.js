import * as THREE from 'three';
import { FBXLoader } from 'three/examples/jsm/loaders/FBXLoader.js';
import { voyageRoutes } from './voyages.js';
import { buildFleet } from './voyage-fleet.js';

// Low-poly tall-ship fleets sailing along each era-visible voyage route. Ships are parametric
// (voyage-fleet.js) — no GLB, no textures, flat colours, a couple of KB. Rendered as a MapLibre
// custom layer via three.js, placed with map.transform.getMatrixForModel so the globe projection
// is handled. This module is dynamically imported from index.js (own lazy chunk with three).
//
// Depth gotcha (see voyages.js): the layer must sit BELOW tm-clouds, whose renderingMode:'3d'
// pass writes depth across the whole globe — any depth-tested layer drawn after it is culled.

const SHIP_PX = 46;                 // approximate on-screen length of the flagship
const LAP_SECONDS = 45;             // ambient loop: one full route traversal
const EARTH_CIRCUMFERENCE = 40_075_000;
const FORMATION_GAP = 0.012;        // escort trail distance as a fraction of route length

// Constant-speed sampling: cumulative distances over the smoothed route (degree-space pacing).
const buildTrack = (coords) => {
  const cum = [0];
  for (let i = 1; i < coords.length; i++) {
    const dx = coords[i][0] - coords[i - 1][0];
    const dy = coords[i][1] - coords[i - 1][1];
    cum.push(cum[i - 1] + Math.hypot(dx, dy));
  }
  return { coords, cum, total: cum[cum.length - 1] };
};

const pointAt = (track, t) => {
  const target = Math.min(1, Math.max(0, t)) * track.total;
  let lo = 0;
  let hi = track.cum.length - 1;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (track.cum[mid] < target) lo = mid + 1; else hi = mid;
  }
  const i = Math.max(1, lo);
  const seg = track.cum[i] - track.cum[i - 1] || 1;
  const f = (target - track.cum[i - 1]) / seg;
  const a = track.coords[i - 1];
  const b = track.coords[i];
  return {
    lng: a[0] + (b[0] - a[0]) * f,
    lat: a[1] + (b[1] - a[1]) * f,
    heading: Math.atan2(b[1] - a[1], (b[0] - a[0]) * Math.cos((a[1] * Math.PI) / 180)),
  };
};

export function addVoyageShips(map, { beforeId = 'tm-clouds', only = null, ambient = true } = {}) {
  const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const voyages = voyageRoutes()
    .filter((v) => !only || v.id === only)
    .map((v, i) => ({
      ...v,
      track: buildTrack(v.coords),
      phase: (i * 0.37) % 1, // desync the fleet across voyages
    }));

  let year = null;
  const camera = new THREE.Camera();
  const scene = new THREE.Scene();
  scene.add(new THREE.AmbientLight(0xffffff, 2.2));
  const sun = new THREE.DirectionalLight(0xfff3d6, 2.4);
  sun.position.set(0.6, -0.4, 1);
  scene.add(sun);
  let renderer = null;

  // One fleet (1-2 ship groups) per voyage; only the ship being drawn is visible per pass.
  // A fleet entry with a `model` URL (e.g. Tasman's flagship FBX) upgrades in place once it
  // loads — the parametric tall ship renders instantly as the fallback.
  const allShips = () => voyages.flatMap((v) => v.ships);
  const upgradeShip = (v, i, url) => {
    new FBXLoader().load(url, (obj) => {
      // Normalise to the fleet convention: length 1, keel on the waterline, bow toward -Z.
      const box = new THREE.Box3().setFromObject(obj);
      const size = box.getSize(new THREE.Vector3());
      const len = Math.max(size.x, size.z) || 1;
      obj.scale.setScalar(1 / len);
      const box2 = new THREE.Box3().setFromObject(obj);
      const centre = box2.getCenter(new THREE.Vector3());
      obj.position.sub(centre);
      obj.position.y += box2.getSize(new THREE.Vector3()).y / 2;
      if (size.x > size.z) obj.rotation.y = Math.PI / 2; // hull built along X → align to -Z
      const group = new THREE.Group();
      group.add(obj);
      group.visible = false;
      scene.remove(v.ships[i]);
      scene.add(group);
      v.ships[i] = group;
      map.triggerRepaint();
    }, undefined, () => { /* keep the parametric fallback on load failure */ });
  };
  for (const v of voyages) {
    v.ships = buildFleet(v.fleet);
    v.ships.forEach((s) => {
      s.visible = false;
      scene.add(s);
    });
    (v.fleet || []).forEach((spec, i) => {
      if (spec && spec.model && v.ships[i]) upgradeShip(v, i, spec.model);
    });
  }

  const visibleVoyages = () =>
    (year === null ? [] : voyages.filter((v) => v.show_from <= year && year <= v.show_to));

  const layer = {
    id: 'voyage-ships',
    type: 'custom',
    renderingMode: '3d',
    onAdd(mapInstance, gl) {
      renderer = new THREE.WebGLRenderer({ canvas: mapInstance.getCanvas(), context: gl, antialias: true });
      renderer.autoClear = false;
    },
    render(gl, args) {
      if (!renderer) return;
      const active = visibleVoyages();
      if (!active.length) return;
      const getModelMatrix = map.transform && typeof map.transform.getMatrixForModel === 'function'
        ? (lngLat) => map.transform.getMatrixForModel(lngLat, 0)
        : null;
      if (!getModelMatrix) return;
      const mainMatrix = args && args.defaultProjectionData ? args.defaultProjectionData.mainMatrix : args;

      const zoom = map.getZoom();
      const metresPerPixel = EARTH_CIRCUMFERENCE / (512 * Math.pow(2, zoom));
      const shipMetres = SHIP_PX * metresPerPixel;
      const now = performance.now() / 1000;

      for (const v of active) {
        const baseT = v.tourT !== undefined
          ? v.tourT
          : (reducedMotion ? 0.35 : ((now / LAP_SECONDS) * (v.track.total > 200 ? 0.6 : 1) + v.phase) % 1);
        v.ships.forEach((ship, i) => {
          const p = pointAt(v.track, baseT - i * FORMATION_GAP);
          const lngLat = [((p.lng + 180) % 360 + 360) % 360 - 180, p.lat];
          const scale = shipMetres * (i === 0 ? 1 : 0.85); // escorts slightly smaller
          allShips().forEach((s) => { s.visible = false; });
          ship.visible = true;
          ship.rotation.set(0, p.heading + Math.PI / 2, 0);
          ship.scale.setScalar(scale);
          ship.updateMatrixWorld(true);
          camera.projectionMatrix = new THREE.Matrix4()
            .fromArray(mainMatrix)
            .multiply(new THREE.Matrix4().fromArray(getModelMatrix(lngLat)));
          renderer.resetState();
          renderer.render(scene, camera);
        });
      }
      allShips().forEach((s) => { s.visible = false; });
    },
  };

  map.addLayer(layer, map.getLayer(beforeId) ? beforeId : undefined);

  // Gentle sail loop: repaint while any fleet is on stage. Tour hosts drive their own frames.
  let raf = null;
  if (ambient) {
    const tick = () => {
      if (visibleVoyages().length && !reducedMotion) map.triggerRepaint();
      raf = window.setTimeout(() => window.requestAnimationFrame(tick), 66); // ~15fps is plenty
    };
    tick();
  }

  return {
    setYear(y) {
      year = y;
      map.triggerRepaint();
    },
    /** Tour mode: pin a voyage's fleet to an exact track fraction (null releases to ambient). */
    setTourProgress(voyageId, t) {
      const v = voyages.find((x) => x.id === voyageId);
      if (v) v.tourT = t === null ? undefined : t;
      map.triggerRepaint();
    },
    pointAt(voyageId, t) {
      const v = voyages.find((x) => x.id === voyageId);
      return v ? pointAt(v.track, t) : null;
    },
    /** Track fraction at a RAW waypoint index (smooth() emits waypoint i at sample i*10). */
    fractionAtWaypoint(voyageId, wpIndex) {
      const v = voyages.find((x) => x.id === voyageId);
      if (!v) return 0;
      const idx = Math.min(v.track.cum.length - 1, wpIndex * 10);
      return v.track.cum[idx] / (v.track.total || 1);
    },
    destroy() {
      if (raf) window.clearTimeout(raf);
      if (map.getLayer('voyage-ships')) map.removeLayer('voyage-ships');
    },
  };
}

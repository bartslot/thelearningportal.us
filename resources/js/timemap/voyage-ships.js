import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { voyageRoutes } from './voyages.js';

// A 3D tall ship (public/timemap/assets/traders_tallship.glb) sailing along each era-visible
// voyage route — replaces the old arrowheads. Rendered as a MapLibre custom layer via three.js,
// using getMatrixForModel so placement is correct on the globe projection. This module is
// dynamically imported from index.js, so three.js lives in its own lazy chunk.
//
// Same depth gotcha as the routes themselves: the layer must sit BELOW tm-clouds (whose 3D pass
// writes depth across the whole globe) or the ships are silently culled.

const SHIP_PX = 42;                 // approximate on-screen ship length
const LAP_SECONDS = 45;             // one full route traversal
const EARTH_CIRCUMFERENCE = 40_075_000;

// Constant-speed sampling: cumulative distances over the smoothed route (degree-space is fine
// for pacing — visual speed differences across latitudes are irrelevant at this scale).
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
  const target = t * track.total;
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
    // Heading in radians, 0 = east, counter-clockwise (screen-space-ish; fine at map scale).
    heading: Math.atan2(b[1] - a[1], (b[0] - a[0]) * Math.cos((a[1] * Math.PI) / 180)),
  };
};

export function addVoyageShips(map, { beforeId = 'tm-clouds' } = {}) {
  const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const tracks = voyageRoutes().map((v, i) => ({
    ...v,
    track: buildTrack(v.coords),
    phase: (i * 0.37) % 1, // desync the fleet
  }));

  let year = null;
  let model = null;
  let modelLength = 1;
  const camera = new THREE.Camera();
  const scene = new THREE.Scene();
  scene.add(new THREE.AmbientLight(0xffffff, 2.2));
  const sun = new THREE.DirectionalLight(0xfff3d6, 2.4);
  sun.position.set(0.6, -0.4, 1);
  scene.add(sun);
  let renderer = null;

  const visibleShips = () =>
    (year === null ? [] : tracks.filter((v) => v.show_from <= year && year <= v.show_to));

  const layer = {
    id: 'voyage-ships',
    type: 'custom',
    renderingMode: '3d',
    onAdd(mapInstance, gl) {
      renderer = new THREE.WebGLRenderer({ canvas: mapInstance.getCanvas(), context: gl, antialias: true });
      renderer.autoClear = false;
      new GLTFLoader().load('/timemap/assets/traders_tallship.glb', (gltf) => {
        model = gltf.scene;
        // Normalise: unknown export scale/origin — measure and centre so maths below can treat
        // the ship as a unit-length prop standing on the waterline.
        const box = new THREE.Box3().setFromObject(model);
        const size = box.getSize(new THREE.Vector3());
        modelLength = Math.max(size.x, size.z) || 1;
        const centre = box.getCenter(new THREE.Vector3());
        model.position.sub(centre);
        model.position.y += size.y / 2; // keel on the water
        scene.add(model);
        mapInstance.triggerRepaint();
      });
    },
    render(gl, args) {
      window.__shipDbg = {
        model: !!model, year, ships: visibleShips().length,
        api: !!(map.transform && typeof map.transform.getMatrixForModel === 'function'),
      };
      if (!model || !renderer) return;
      const ships = visibleShips();
      if (!ships.length) return;

      // v5 globe-aware placement: model matrix comes from map.transform.getMatrixForModel and the
      // projection from args.defaultProjectionData.mainMatrix (see MapLibre's globe 3D-model example).
      const getModelMatrix = map.transform && typeof map.transform.getMatrixForModel === 'function'
        ? (lngLat) => map.transform.getMatrixForModel(lngLat, 0)
        : null;
      const mainMatrix = args && args.defaultProjectionData ? args.defaultProjectionData.mainMatrix : args;
      const zoom = map.getZoom();
      // Metres per screen pixel at this zoom (equator approximation) → zoom-constant ship size.
      const metresPerPixel = EARTH_CIRCUMFERENCE / (512 * Math.pow(2, zoom));
      const shipMetres = SHIP_PX * metresPerPixel;
      const now = performance.now() / 1000;

      for (const ship of ships) {
        const t = reducedMotion ? 0.35 : ((now / LAP_SECONDS) * (ship.track.total > 200 ? 0.6 : 1) + ship.phase) % 1;
        const p = pointAt(ship.track, t);
        const lngLat = [((p.lng + 180) % 360 + 360) % 360 - 180, p.lat];

        if (!getModelMatrix) continue; // unknown API shape — skip rather than mis-place
        const modelMatrix = getModelMatrix(lngLat);

        const scale = shipMetres / modelLength;
        model.rotation.set(0, p.heading + Math.PI / 2, 0); // glTF is Y-up, bow along -Z
        model.scale.setScalar(scale);
        model.updateMatrixWorld(true);

        camera.projectionMatrix = new THREE.Matrix4()
          .fromArray(mainMatrix)
          .multiply(new THREE.Matrix4().fromArray(modelMatrix));
        renderer.resetState();
        renderer.render(scene, camera);
      }
    },
  };

  map.addLayer(layer, map.getLayer(beforeId) ? beforeId : undefined);

  // Gentle sail loop: repaint while any ship is on stage (waves already animate the map anyway).
  let raf = null;
  const tick = () => {
    if (visibleShips().length && !reducedMotion) map.triggerRepaint();
    raf = window.setTimeout(() => window.requestAnimationFrame(tick), 66); // ~15fps is plenty
  };
  tick();

  return {
    setYear(y) {
      year = y;
      map.triggerRepaint();
    },
    destroy() {
      if (raf) window.clearTimeout(raf);
      if (map.getLayer('voyage-ships')) map.removeLayer('voyage-ships');
    },
  };
}

import * as THREE from 'three';
import { FBXLoader } from 'three/examples/jsm/loaders/FBXLoader.js';
import { voyageRoutes, smooth } from './voyages.js';
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

// Idle "at sea" motion — the ship is always afloat, rocking gently as if on slight waves. All are
// fractions of the ship's length / radians, pivoted about the hull's centre-bottom (the group origin
// sits at the waterline centre), so the mast sways while the keel stays put.
const ROCK_ROLL = 0.10;             // side-to-side roll amplitude (rad, ~5.7°)
const ROCK_ROLL_SPEED = 1.05;
const ROCK_PITCH = 0.035;           // gentle bow-to-stern pitch (rad)
const ROCK_PITCH_SPEED = 0.8;
const ROCK_BOB = 0.02;              // vertical bob, fraction of ship length
const ROCK_BOB_SPEED = 0.9;
const SHIP_SINK = 0.06;             // sit a bit underwater — keel/lower hull below the waterline

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

const REF_ZOOM = 4;   // anchored ships hold a fixed WORLD size, referenced to this zoom
const REF_MPP = EARTH_CIRCUMFERENCE / (512 * Math.pow(2, REF_ZOOM));

export function addVoyageShips(map, { beforeId = 'tm-clouds', only = null, ambient = true, flagshipOnly = false, def = null, shipScale = 1, shipAnchored = false } = {}) {
  let sScale = Number(shipScale) > 0 ? Number(shipScale) : 1;   // on-screen size multiplier (live)
  let sAnchored = !!shipAnchored;                               // pin to the map (scale with zoom)?
  const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const voyages = voyageRoutes()
    .filter((v) => !only || v.id === only)
    .map((v, i) => {
      // Use the lesson's EDITED voyage_def geometry when supplied, so a dragged/added waypoint moves
      // the ship + route (the catalog v is only the fallback). Track is re-smoothed from its waypoints.
      const useDef = def && (def.id ? def.id === v.id : only === v.id) && Array.isArray(def.waypoints) && def.waypoints.length >= 2;
      const waypoints = useDef ? def.waypoints : v.waypoints;
      const legs = useDef ? (def.legs || v.legs) : v.legs;
      const coords = useDef ? smooth(waypoints) : v.coords;
      let fleet = (useDef && Array.isArray(def.fleet) && def.fleet.length) ? def.fleet : v.fleet;
      if (flagshipOnly && Array.isArray(fleet) && fleet.length) {
        fleet = [fleet.find((f) => f && f.flagship) || fleet[0]];
      }
      return {
        ...v,
        waypoints, legs, fleet,
        track: buildTrack(coords),
        phase: (i * 0.37) % 1, // desync the fleet across voyages
      };
    });

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
      // Fixed = constant on-screen px (divide zoom back out). Anchored = constant WORLD size (uses a
      // reference metres-per-pixel), so the ship visibly shrinks zooming out / grows zooming in.
      const shipMetres = SHIP_PX * sScale * (sAnchored ? REF_MPP : metresPerPixel);
      const now = performance.now() / 1000;

      for (const v of active) {
        const baseT = v.tourT !== undefined
          ? v.tourT
          : (reducedMotion ? 0.35 : ((now / LAP_SECONDS) * (v.track.total > 200 ? 0.6 : 1) + v.phase) % 1);
        v.ships.forEach((ship, i) => {
          const p = pointAt(v.track, baseT - i * FORMATION_GAP);
          // Place the ship in the SAME world copy as the camera. Wrapping to [-180,180] instead put
          // it 360° away from the camera on Pacific crossings (Tonga) → it vanished off-screen.
          const cLng = map.getCenter().lng;
          let lng = p.lng;
          while (lng - cLng > 180) lng -= 360;
          while (lng - cLng < -180) lng += 360;
          const lngLat = [lng, p.lat];
          const scale = shipMetres * (i === 0 ? 1 : 0.85); // escorts slightly smaller
          allShips().forEach((s) => { s.visible = false; });
          ship.visible = true;
          // Ease the heading (shortest-angle lerp) so the boat never snaps around at sharp waypoints.
          const targetYaw = p.heading + Math.PI / 2;
          if (ship._yaw === undefined) ship._yaw = targetYaw;
          let dYaw = targetYaw - ship._yaw;
          while (dYaw > Math.PI) dYaw -= 2 * Math.PI;
          while (dYaw < -Math.PI) dYaw += 2 * Math.PI;
          ship._yaw += dYaw * 0.08; // small factor = a lot of easing
          // Idle "at sea" motion, pivoted about the hull's centre-bottom (the group origin sits at the
          // waterline centre): roll side-to-side, a gentler bow/stern pitch, and a small vertical bob —
          // as if riding slight waves. 'YXZ' so the roll (Z) happens about the ship's own fore-aft axis
          // AFTER the heading yaw. Plus a constant sink so the lower hull rides under the waterline.
          if (ship._phase === undefined) ship._phase = i * 2.1;
          const ph = ship._phase;
          const roll = reducedMotion ? 0 : ROCK_ROLL * Math.sin(now * ROCK_ROLL_SPEED + ph);
          const pitch = reducedMotion ? 0 : ROCK_PITCH * Math.sin(now * ROCK_PITCH_SPEED + ph * 1.7);
          const bob = reducedMotion ? 0 : ROCK_BOB * Math.sin(now * ROCK_BOB_SPEED + ph);
          ship.rotation.order = 'YXZ';
          ship.rotation.set(pitch, ship._yaw, roll);
          ship.position.set(0, (bob - SHIP_SINK) * scale, 0);
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

  // Gentle repaint loop while any fleet is on stage — drives BOTH the ambient sail lap AND the idle
  // rock (so a ship parked at a landfall keeps rocking on the waves, not just while sailing). The ship
  // stays put when the tour pins it (v.tourT set); this loop only re-renders so the rock animates.
  let raf = null;
  const tick = () => {
    if (visibleVoyages().length && !reducedMotion) map.triggerRepaint();
    raf = window.setTimeout(() => window.requestAnimationFrame(tick), 66); // ~15fps is plenty
  };
  if (!reducedMotion) tick();

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
    /** Live-update the ship's on-screen size + whether it scales with zoom (whole-voyage setting). */
    setShipScale(scale, anchored) {
      if (Number(scale) > 0) sScale = Number(scale);
      if (anchored !== undefined) sAnchored = !!anchored;
      map.triggerRepaint();
    },
    /**
     * Reshape a voyage's route IN PLACE from an edited voyage_def (waypoints/legs) — no layer rebuild,
     * no re-mount. The ship + track just follow the new geometry on the next repaint. This is what lets
     * the editor drag/insert a waypoint without the whole map flashing back to its initial fit.
     */
    setDef(voyageId, newDef) {
      const v = voyages.find((x) => x.id === voyageId);
      if (!v || !newDef || !Array.isArray(newDef.waypoints) || newDef.waypoints.length < 2) return;
      v.waypoints = newDef.waypoints;
      if (Array.isArray(newDef.legs)) v.legs = newDef.legs;
      v.track = buildTrack(smooth(newDef.waypoints));
      map.triggerRepaint();
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

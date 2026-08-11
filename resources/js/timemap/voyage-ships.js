import * as THREE from 'three';
import { FBXLoader } from 'three/examples/jsm/loaders/FBXLoader.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { voyageRoutes, smooth, SAMPLES_PER_SEGMENT } from './voyages.js';
import transportCatalog from './transports.json';

// Tall-ship fleets sailing along each era-visible voyage route. Every vessel is the supplied
// tall-ship FBX — there is no procedural ship, and no other hull may be substituted. The model is
// fetched once per URL and cloned per ship, so a three-ship fleet costs one download. Rendered as a
// MapLibre custom layer via three.js, placed with map.transform.getMatrixForModel so the globe
// projection is handled. This module is dynamically imported from index.js (own lazy chunk).
//
// A ship is drawn only once its hull has loaded; nothing stands in for it in the meantime.
//
// Depth gotcha (see voyages.js): the layer must sit BELOW tm-clouds, whose renderingMode:'3d'
// pass writes depth across the whole globe — any depth-tested layer drawn after it is culled.

/** The one hull used by every vessel on every route. */
const SHIP_MODEL_URL = '/timemap/assets/ship.fbx';

const SHIP_PX = 46;                 // approximate on-screen length of the flagship
// Ambient loop: how long one full route traversal takes. Live-adjustable via setLapSeconds — the
// fleet reads `lapSeconds` per frame, so a change takes effect without a re-mount.
const LAP_SECONDS = 45;
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

// A marker held at a constant PIXEL size never reads as an object sitting in the world: from far
// out it looks enormous against an ocean, and it appears to SHRINK as you zoom in, because the map
// grows around it while it does not. Anchoring it to a real world size fixes that — zooming in
// magnifies the ship like everything else — but left alone it becomes an invisible speck from
// space. The usual answer is to anchor and then clamp: real-world size, bounded on screen.
// 1.0 would be true world-anchoring (double per zoom level, hits the cap almost at once).
// 0.25 grows the marker steadily across the whole usable range instead.
const GROWTH_EXPONENT = 0.25;
const MIN_ON_SCREEN_PX = 15;
const MAX_ON_SCREEN_PX = 110;

// Swapping transport at a coast is a moment worth seeing. The outgoing model is simply dropped and
// the incoming one scales up with a little overshoot, matching EASE.pop used elsewhere for things
// that appear (badges, pins, thumbnails).
const POP_MS = 380;
const POP_FROM = 0.35;
const popEase = (p) => {   // cubic-bezier(0.34, 1.56, 0.64, 1), approximated for a per-frame scalar
  const t = Math.min(1, Math.max(0, p));
  const c1 = 1.70158, c3 = c1 + 1;
  return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
};

// ── Land travel ───────────────────────────────────────────────────────────────
// Not every historic route was sailed end to end: Marco Polo rode and walked to China and only
// came home by sea. A leg may declare how it was travelled, and the marker swaps to a walking
// mount for land legs and back to the ship at the coast.
//
// Models are the optimised LODs from tools/optimize_glb.py (342 KB / 992 KB, walk cycle included).
// They are fetched ONLY when a route actually uses that transport, and — like the hulls — nothing
// is drawn in their place until they arrive.
// The catalogue in transports.json is the single source of truth, shared with the wizard's per-leg
// picker: a model added there appears in the picker AND is drivable here, with no second list to
// keep in step. Anything not travelling on land is at sea, so only the land entries need a mount.
export const TRANSPORTS = Array.isArray(transportCatalog?.transports) ? transportCatalog.transports : [];
const MOUNT_URLS = Object.fromEntries(
  TRANSPORTS.filter((t) => t && t.terrain === 'land' && t.model).map((t) => [t.id, t.model])
);
const MOUNT_PX = 26;          // a single rider reads smaller than a tall ship at the same zoom
const WALK_SPEED = 1.35;      // walk-cycle playback rate; the clips are a touch slow for map pacing

// ── A photograph or painting instead of a model ───────────────────────────────
// Not every journey has a model worth building — a leg travelled by sledge, on foot or by canoe is
// better served by a picture of the real thing. Drawn as a round portrait with a ring, matching the
// 64px avatar the picker shows, so it reads as a marker rather than a floating cut-out.
const MARKER_PX = 64;
const MARKER_TEX = 256;       // texture resolution behind that 64px marker (crisp on retina)
const MARKER_RING = 0.055;    // ring thickness as a fraction of the marker's diameter
const MARKER_RING_COLOR = '#f59e0b';

/**
 * Float waypoint index at a track fraction. smooth() emits each raw waypoint at
 * sample i * SAMPLES_PER_SEGMENT, so dividing maps back onto the leg's `wp` indices.
 */
export const waypointAt = (track, t) => {
  const target = Math.max(0, Math.min(1, t)) * track.total;
  let i = 1;
  while (i < track.cum.length && track.cum[i] < target) i++;
  const prev = track.cum[i - 1] ?? 0;
  const next = track.cum[i] ?? prev;
  const frac = next > prev ? (target - prev) / (next - prev) : 0;
  return (i - 1 + frac) / SAMPLES_PER_SEGMENT;
};

/**
 * World size (metres) for a marker whose nominal on-screen size is `basePx`.
 *
 * Anchored (the default) gives the marker a real-world size, so zooming in magnifies it like any
 * other feature on the map, then clamps what that becomes on screen so it neither vanishes from
 * space nor swamps an ocean. Passing anchored=false keeps the old constant-pixel behaviour, where
 * the marker looks enormous from far out and appears to shrink as you zoom in.
 */
export const unitWorldSize = (basePx, scale, metresPerPixel, anchored = true) => {
  if (!anchored) return basePx * scale * metresPerPixel;
  // Strict world-anchoring DOUBLES the marker every zoom level, so a ship hits the ceiling by
  // about zoom 6 and is pinned there for the rest of the range — constant pixels again, which is
  // the bug. Growing on a gentler curve keeps "zooming in zooms into the boat" true across the
  // whole range: ~32px from space, ~65px regional, reaching the cap around city zoom.
  const growth = Math.pow(REF_MPP / metresPerPixel, GROWTH_EXPONENT);
  const onScreen = basePx * scale * growth;
  // THE CLAMP SCALES TOO. The floor is there so a marker does not vanish from orbit, but applying
  // it after the scale made it overrule the request: from space every scale below about 0.75 gave
  // the identical 15px ship, so turning the size down did nothing and a vessel still spanned a
  // thousand kilometres of ocean. A default-size ship is still held above the floor; someone who
  // explicitly asks for smaller ships gets them.
  return Math.min(MAX_ON_SCREEN_PX * scale, Math.max(MIN_ON_SCREEN_PX * scale, onScreen)) * metresPerPixel;
};

/**
 * Motion dial: 1 = the rocking these constants were tuned for, 0 = dead still.
 *
 * Ships rolling 5.7° read as lively on an ocean and as seasickness on a classroom projector, so
 * how much the marker moves is the teacher's call rather than a constant.
 */
export const clampMotion = (m) => {
  // null/undefined/'' mean "not set" — and Number(null) is 0, which would silently freeze the ship.
  if (m === null || m === undefined || m === '') return 1;
  const n = Number(m);

  return Number.isFinite(n) ? Math.min(1, Math.max(0, n)) : 1;
};

/**
 * Which way the viewer is, expressed in the space a projection matrix maps FROM.
 *
 * MapLibre hands this renderer a matrix straight to clip space, so there is no camera whose
 * position could be read. Unprojecting the near and far centres of clip space recovers the view
 * ray, and its direction is all a billboard needs — convention-proof at any bearing, pitch or
 * globe rotation, and immune to whatever frame MapLibre's model matrix is in.
 */
export const viewerDirection = (matrix) => {
  const inv = matrix.clone().invert();

  return new THREE.Vector3(0, 0, -1).applyMatrix4(inv)
    .sub(new THREE.Vector3(0, 0, 1).applyMatrix4(inv))
    .normalize();
};

/** The leg being travelled at this point of the route, or null when the route has no legs. */
export const legAt = (v, t) => {
  if (!Array.isArray(v.legs) || !v.legs.length) return null;
  const wp = waypointAt(v.track, t);
  for (const leg of v.legs) {
    const a = Number(leg?.wp?.[0] ?? 0);
    const b = Number(leg?.wp?.[1] ?? a);
    if (wp >= Math.min(a, b) - 1e-3 && wp <= Math.max(a, b) + 1e-3) return leg;
  }
  return null;
};

/** How the traveller is moving at this point of the route. Unlabelled legs stay at sea. */
export const transportAt = (v, t) => {
  const leg = legAt(v, t);

  return leg && MOUNT_URLS[leg.transport] ? leg.transport : 'sea';
};

/**
 * A picture to draw INSTEAD of a model on this stretch, when the teacher chose one for the leg.
 * Takes precedence over `transport`: they picked a specific marker, so show it.
 */
export const markerImageAt = (v, t) => {
  const url = legAt(v, t)?.marker_image;

  return typeof url === 'string' && url !== '' ? url : null;
};

export function addVoyageShips(map, { beforeId = 'tm-clouds', only = null, ambient = true, flagshipOnly = false, def = null, shipScale = 1, shipAnchored = true, motion = 1 } = {}) {
  let sScale = Number(shipScale) > 0 ? Number(shipScale) : 1;   // on-screen size multiplier (live)
  let sAnchored = !!shipAnchored;                               // pin to the map (scale with zoom)?
  let sMotion = clampMotion(motion);                            // how far the idle rock swings (0–1)
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
  let lapSeconds = LAP_SECONDS;
  /** 0 = ships lit the same day or night; 1 = full darkening on the night side. */
  let nightDim = 0;
  const AMBIENT_BASE = 2.2;
  const SUN_BASE = 2.4;
  const camera = new THREE.Camera();
  const scene = new THREE.Scene();
  const ambientLight = new THREE.AmbientLight(0xffffff, AMBIENT_BASE);
  scene.add(ambientLight);
  const sun = new THREE.DirectionalLight(0xfff3d6, SUN_BASE);
  sun.position.set(0.6, -0.4, 1);
  scene.add(sun);
  let renderer = null;
  let lastNow = null;   // previous frame time, for advancing walk-cycle mixers

  // One fleet per voyage; only the ship being drawn is visible per pass. A fleet entry may name its
  // own `model` (e.g. Tasman's flagship FBX); everything else uses SHIP_MODEL_URL.
  // Entries are filled in as hulls resolve, so this can be sparse early on.
  const allShips = () => voyages.flatMap((v) => v.ships).filter(Boolean);
  /**
   * Normalise a loaded hull to the fleet convention: length 1, keel on the waterline, bow at -Z.
   * The renderer then scales by metres-per-model-length and yaws by heading + PI/2.
   */
  const normaliseHull = (obj) => {
    const box = new THREE.Box3().setFromObject(obj);
    const size = box.getSize(new THREE.Vector3());
    const len = Math.max(size.x, size.z) || 1;
    obj.scale.setScalar(1 / len);
    const box2 = new THREE.Box3().setFromObject(obj);
    const centre = box2.getCenter(new THREE.Vector3());
    obj.position.sub(centre);
    obj.position.y += box2.getSize(new THREE.Vector3()).y / 2;
    if (size.x > size.z) obj.rotation.y = Math.PI / 2;   // hull built along X → align to -Z
    return obj;
  };

  // One hull, loaded once per URL and cloned per ship. Cloning matters: a fleet of three would
  // otherwise fetch and parse the same FBX three times.
  const hullCache = new Map();   // url -> Promise<THREE.Object3D>
  const loadHull = (url) => {
    if (!hullCache.has(url)) {
      hullCache.set(url, new Promise((resolve, reject) => {
        new FBXLoader().load(url, (obj) => resolve(normaliseHull(obj)), undefined, reject);
      }));
    }
    return hullCache.get(url);
  };

  for (const v of voyages) {
    // Ships appear when their hull arrives. There is deliberately no stand-in: every vessel on
    // every route is the supplied FBX tall ship, so nothing else may be drawn in its place.
    v.ships = [];
    const specs = (Array.isArray(v.fleet) && v.fleet.length) ? v.fleet : [{ flagship: true }];
    specs.forEach((spec, i) => {
      loadHull((spec && spec.model) || SHIP_MODEL_URL).then((hull) => {
        const group = new THREE.Group();
        group.add(hull.clone(true));
        group.visible = false;
        scene.add(group);
        v.ships[i] = group;
        map.triggerRepaint();
      }).catch(() => {
        console.warn('[voyage-ships] hull failed to load', (spec && spec.model) || SHIP_MODEL_URL);
      });
    });
  }

  // Walking mounts, keyed by transport. Shared across voyages (two routes crossing the same desert
  // reuse one camel) and each loaded at most once, on the first frame a land leg is actually drawn.
  const mounts = new Map();     // 'horse' -> { group, mixer } | 'pending'
  const mixers = [];

  const ensureMount = (kind) => {
    const entry = mounts.get(kind);
    if (entry) return entry === 'pending' ? null : entry;
    const url = MOUNT_URLS[kind];
    if (!url) return null;
    mounts.set(kind, 'pending');

    new GLTFLoader().load(url, (gltf) => {
      const obj = gltf.scene;

      /**
       * Bounds of a rigged model, measured from its BONES.
       *
       * Box3.setFromObject multiplies each geometry's rest-pose box by its node matrix and ignores
       * skinning entirely. That is fine for a model authored around the origin (the horse) and
       * badly wrong for one that is not: the camel's mesh coordinates sit ~158 units up, with the
       * skin's inverse-bind matrices bringing it back, so setFromObject reported a box 158 units
       * off and the re-centring below flung it far outside the viewport. Bone world positions
       * reflect where the animal actually IS.
       */
      // Mounts arrive already normalised by tools/optimize_glb.py: centred in X/Z with their feet
      // on y=0. That is deliberate — measuring it back out here is unreliable for a rig, because
      // Box3.setFromObject ignores skinning, SkinnedMesh.computeBoundingBox reads bone matrices the
      // renderer has not filled in at load time, and raw bone positions include stray root/IK bones.
      // So the only job left is to scale the model to the fleet's convention: length 1, facing -Z.
      const box = new THREE.Box3().setFromObject(obj);
      const size = box.getSize(new THREE.Vector3());
      const len = Math.max(size.x, size.z) || 1;
      obj.scale.setScalar(1 / len);
      if (size.x > size.z) obj.rotation.y = Math.PI / 2;  // built along X → face -Z like the hulls

      // MapLibre hands us a projection matrix directly, so three.js derives a frustum that does not
      // describe the real view. A skinned mesh whose bounds are then recomputed from the rest pose
      // gets culled against that bogus frustum and simply never draws — the mount was loading,
      // animating and positioning correctly while rendering nothing. Plain hulls survive it; rigs
      // do not, so opt the whole subtree out.
      // MapLibre hands us a projection matrix directly, so three.js derives a frustum that does not
      // describe the real view. A rig whose bounds are recomputed from the rest pose gets culled
      // against that bogus frustum and never draws at all. Plain hulls survive it; skinned meshes
      // do not, so opt the whole subtree out.
      //
      // Leave bindMode alone. It must stay 'attached': the map rescales this group by a
      // metres-per-pixel figure (~60,000) every frame, and only an attached bind recomputes
      // bindMatrixInverse from the current world matrix, cancelling that scale out of the skinning
      // result. A detached bind freezes it at load, so the scale lands twice and the horse renders
      // ~10^9 times too big — drawn, but far past the near plane and effectively invisible.
      obj.traverse((n) => { n.frustumCulled = false; });

      const group = new THREE.Group();
      group.add(obj);
      group.visible = false;
      scene.add(group);

      // The walk cycle is what sells "travelling overland" — a static model just slides. A second
      // clip (where the model ships one) covers standing at a stop; the horse has no idle, so it
      // simply holds still instead.
      let mixer = null;
      let walk = null;
      let idle = null;
      if (gltf.animations && gltf.animations.length) {
        mixer = new THREE.AnimationMixer(obj);
        const pick = (re) => gltf.animations.find((c) => re.test(c.name || ''));
        const walkClip = pick(/walk/i) || gltf.animations[0];
        const idleClip = pick(/idle/i);

        walk = mixer.clipAction(walkClip);
        walk.setLoop(THREE.LoopRepeat, Infinity);
        walk.timeScale = WALK_SPEED;
        walk.play();

        if (idleClip && idleClip !== walkClip) {
          idle = mixer.clipAction(idleClip);
          idle.setLoop(THREE.LoopRepeat, Infinity);
          idle.play();
          idle.setEffectiveWeight(0);      // cross-faded in on arrival
        }
        mixers.push(mixer);
      }
      mounts.set(kind, { group, mixer, walk, idle, moving: true });
      map.triggerRepaint();
    }, undefined, () => {
      // Leave it 'pending' forever on failure: the ship keeps drawing, so the route still reads.
      console.warn('[voyage-ships] mount failed to load', kind);
    });

    return null;
  };

  const allMounts = () => [...mounts.values()].filter((m) => m && m !== 'pending').map((m) => m.group);

  // Picture markers, one plane per image URL (two legs sharing a photo share the plane). Like the
  // hulls and the mounts, nothing stands in while it loads — the ship keeps drawing.
  const markerSprites = new Map();   // url -> THREE.Mesh | 'pending'
  const markerGeometry = new THREE.PlaneGeometry(1, 1);

  /** Crop an image to a circle with a ring, on a transparent square — the 64px avatar, as a texture. */
  const roundTexture = (img) => {
    const canvas = document.createElement('canvas');
    canvas.width = MARKER_TEX;
    canvas.height = MARKER_TEX;
    const ctx = canvas.getContext('2d');
    const r = MARKER_TEX / 2;
    const ring = MARKER_TEX * MARKER_RING;

    ctx.save();
    ctx.beginPath();
    ctx.arc(r, r, r - ring, 0, Math.PI * 2);
    ctx.clip();
    // Cover, centred: a portrait squeezed into a circle is worse than one cropped to fit it.
    const side = Math.min(img.width, img.height) || 1;
    ctx.drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, MARKER_TEX, MARKER_TEX);
    ctx.restore();

    ctx.beginPath();
    ctx.arc(r, r, r - ring / 2, 0, Math.PI * 2);
    ctx.lineWidth = ring;
    ctx.strokeStyle = MARKER_RING_COLOR;
    ctx.stroke();

    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;

    return tex;
  };

  const ensureMarker = (url) => {
    const entry = markerSprites.get(url);
    if (entry) return entry === 'pending' ? null : entry;
    markerSprites.set(url, 'pending');

    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      // A plane rather than a THREE.Sprite: sprites billboard in VIEW space, and this renderer has
      // no view matrix (MapLibre hands us a projection straight to clip space), so a sprite would
      // stand in a fixed plane and shear as the camera tilts. The plane is aimed at the camera per
      // frame instead — see the render pass.
      const material = new THREE.MeshBasicMaterial({ map: roundTexture(img), transparent: true, depthTest: false });
      const mesh = new THREE.Mesh(markerGeometry, material);
      mesh.visible = false;
      scene.add(mesh);
      markerSprites.set(url, mesh);
      map.triggerRepaint();
    };
    // Leave it 'pending' on failure so a broken URL is retried never rather than every frame.
    img.onerror = () => console.warn('[voyage-ships] marker image failed to load', url);
    img.src = url;

    return null;
  };

  const allMarkers = () => [...markerSprites.values()].filter((s) => s && s !== 'pending');

  /**
   * Walk while the traveller is covering ground, settle when it has arrived.
   *
   * "Arrived" is inferred from the route position rather than from leg dates, so it is right in both
   * places it matters: the editor pins the marker at a landfall (tourT never changes) and the player
   * holds it at a stop between scenes. A walk cycle running on the spot at a stop reads as a glitch.
   */
  const MOVE_EPSILON = 1e-5;   // track fractions below this are a parked marker, not slow travel

  const setGait = (mount, moving) => {
    if (!mount.mixer || mount.moving === moving) return;
    mount.moving = moving;
    if (mount.idle) {
      // Drive the two weights directly rather than crossFadeTo. A cross-fade passes through a
      // moment where BOTH clips sit near zero weight, and a skinned mesh whose every bone matrix
      // is momentarily zeroed collapses to a point — the camel vanished instead of settling.
      // These two always sum to 1, so the skeleton is never unposed.
      mount.walk.enabled = true;
      mount.idle.enabled = true;
      mount.walk.setEffectiveWeight(moving ? 1 : 0);
      mount.idle.setEffectiveWeight(moving ? 0 : 1);
    } else {
      // No idle clip shipped (the horse): freeze the walk rather than march on the spot.
      mount.walk.paused = !moving;
    }
  };

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
        ? (lngLat, altitude) => map.transform.getMatrixForModel(lngLat, altitude || 0)
        : null;
      if (!getModelMatrix) return;
      // With 3D terrain on, sea level is no longer the ground: a camel placed at altitude 0 walks
      // INSIDE the mountain it is crossing. Every unit is lifted to the height under its own feet.
      // Queried with the wrapped longitude (the DEM lookup is not world-copy aware) while the model
      // matrix keeps the unwrapped one, so an antimeridian crossing still samples the right place.
      // Returns 0 with terrain off, which is exactly the old behaviour.
      // Never below sea level: the height map carries ocean depth too, and a ship following it would
      // sail along the sea FLOOR. Anything at or under the waterline rides at 0, as it did before.
      const groundAt = (lng, lat) => {
        if (!map.terrain) return 0;
        try { return Math.max(0, map.queryTerrainElevation([lng, lat]) || 0); } catch (e) { return 0; }
      };
      const mainMatrix = args && args.defaultProjectionData ? args.defaultProjectionData.mainMatrix : args;

      const zoom = map.getZoom();
      const metresPerPixel = EARTH_CIRCUMFERENCE / (512 * Math.pow(2, zoom));
      const unitMetres = (basePx) => unitWorldSize(basePx, sScale, metresPerPixel, sAnchored);

      const shipMetres = unitMetres(SHIP_PX);
      const now = performance.now() / 1000;

      // Advance walk cycles once per frame, not once per drawn unit.
      const dt = lastNow === null ? 0 : Math.min(0.1, now - lastNow);
      lastNow = now;
      if (dt > 0) mixers.forEach((m) => m.update(dt));

      const hideAll = () => {
        allShips().forEach((s) => { s.visible = false; });
        allMounts().forEach((m) => { m.visible = false; });
        allMarkers().forEach((s) => { s.visible = false; });
      };

      for (const v of active) {
        const baseT = v.tourT !== undefined
          ? v.tourT
          : (reducedMotion ? 0.35 : ((now / lapSeconds) * (v.track.total > 200 ? 0.6 : 1) + v.phase) % 1);

        // Overland stretch: one traveller on a mount replaces the fleet. While the model is still
        // downloading ensureMount() returns null and the ship keeps drawing, so the marker never
        // disappears mid-route.
        // A leg may name a picture to travel with instead of a model; it wins over `transport`,
        // because the teacher chose it for this stretch specifically.
        const pictureUrl = markerImageAt(v, baseT);
        const marker = pictureUrl ? ensureMarker(pictureUrl) : null;
        const mode = pictureUrl ? `img:${pictureUrl}` : transportAt(v, baseT);
        const mount = (pictureUrl || mode === 'sea') ? null : ensureMount(mode);

        // Note when the vehicle actually changes, so the newcomer can scale up rather than
        // materialise at full size the instant the route crosses a coast.
        if (v._unitKey !== mode) {
            v._unitKey = mode;
            v._poppedAt = now;
        }
        const pop = POP_FROM + (1 - POP_FROM) * popEase(((now - (v._poppedAt ?? now)) * 1000) / POP_MS);

        if (marker) {
          const p = pointAt(v.track, baseT);
          const cLng = map.getCenter().lng;
          let lng = p.lng;
          while (lng - cLng > 180) lng -= 360;
          while (lng - cLng < -180) lng += 360;

          const combined = new THREE.Matrix4()
            .fromArray(mainMatrix)
            .multiply(new THREE.Matrix4().fromArray(getModelMatrix([lng, p.lat], groundAt(p.lng, p.lat))));

          // Aim the disc at the camera without ever naming an axis.
          const toCamera = viewerDirection(combined);

          const scale = unitMetres(MARKER_PX) * pop;
          hideAll();
          marker.visible = true;
          marker.position.set(0, 0, 0);
          marker.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), toCamera);
          marker.scale.set(scale, scale, 1);
          marker.updateMatrixWorld(true);

          camera.projectionMatrix = combined;
          renderer.resetState();
          renderer.render(scene, camera);
          continue;
        }

        if (mount) {
          // Has the traveller moved since the last frame? Pinned at a landfall → stand still.
          setGait(mount, Math.abs(baseT - (v._lastLandT ?? baseT)) > MOVE_EPSILON);
          v._lastLandT = baseT;

          const p = pointAt(v.track, baseT);
          const cLng = map.getCenter().lng;
          let lng = p.lng;
          while (lng - cLng > 180) lng -= 360;
          while (lng - cLng < -180) lng += 360;

          const scale = unitMetres(MOUNT_PX) * pop;
          hideAll();
          const g = mount.group;
          g.visible = true;

          const targetYaw = p.heading + Math.PI / 2;
          if (g._yaw === undefined) g._yaw = targetYaw;
          let dYaw = targetYaw - g._yaw;
          while (dYaw > Math.PI) dYaw -= 2 * Math.PI;
          while (dYaw < -Math.PI) dYaw += 2 * Math.PI;
          g._yaw += dYaw * 0.08;

          // No roll/pitch/bob and no sink: hooves belong on the ground, and the walk cycle already
          // supplies the movement that the rocking supplies at sea.
          g.rotation.order = 'YXZ';
          g.rotation.set(0, g._yaw, 0);
          g.position.set(0, 0, 0);
          g.scale.setScalar(scale);
          g.updateMatrixWorld(true);

          camera.projectionMatrix = new THREE.Matrix4()
            .fromArray(mainMatrix)
            .multiply(new THREE.Matrix4().fromArray(getModelMatrix([lng, p.lat], groundAt(p.lng, p.lat))));
          renderer.resetState();
          renderer.render(scene, camera);
          continue;
        }

        v.ships.forEach((ship, i) => {
          const p = pointAt(v.track, baseT - i * FORMATION_GAP);
          // Place the ship in the SAME world copy as the camera. Wrapping to [-180,180] instead put
          // it 360° away from the camera on Pacific crossings (Tonga) → it vanished off-screen.
          const cLng = map.getCenter().lng;
          let lng = p.lng;
          while (lng - cLng > 180) lng -= 360;
          while (lng - cLng < -180) lng += 360;
          const lngLat = [lng, p.lat];
          const scale = shipMetres * (i === 0 ? 1 : 0.85) * pop; // escorts slightly smaller
          hideAll();
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
          // sMotion scales the swing (not the speed): at 0 the ship sits dead still on the water,
          // at 1 it rocks as tuned above. The sink is deliberately NOT scaled — that is where the
          // hull sits in the water, not motion.
          const swing = reducedMotion ? 0 : sMotion;
          const roll = ROCK_ROLL * swing * Math.sin(now * ROCK_ROLL_SPEED + ph);
          const pitch = ROCK_PITCH * swing * Math.sin(now * ROCK_PITCH_SPEED + ph * 1.7);
          const bob = ROCK_BOB * swing * Math.sin(now * ROCK_BOB_SPEED + ph);
          ship.rotation.order = 'YXZ';
          ship.rotation.set(pitch, ship._yaw, roll);
          ship.position.set(0, (bob - SHIP_SINK) * scale, 0);
          ship.scale.setScalar(scale);
          ship.updateMatrixWorld(true);
          camera.projectionMatrix = new THREE.Matrix4()
            .fromArray(mainMatrix)
            .multiply(new THREE.Matrix4().fromArray(getModelMatrix(lngLat, groundAt(p.lng, p.lat))));
          renderer.resetState();
          renderer.render(scene, camera);
        });
      }
      hideAll();
    },
  };

  map.addLayer(layer, map.getLayer(beforeId) ? beforeId : undefined);

  // Gentle repaint loop while any fleet is on stage — drives BOTH the ambient sail lap AND the idle
  // rock (so a ship parked at a landfall keeps rocking on the waves, not just while sailing). The ship
  // stays put when the tour pins it (v.tourT set); this loop only re-renders so the rock animates.
  let raf = null;
  const tick = () => {
    if (visibleVoyages().length && !reducedMotion) map.triggerRepaint();
    // ~15fps carries the idle rock fine, but a walk cycle reads as a stutter at that rate — step up
    // to ~30fps only while a mount is actually on stage.
    const interval = mixers.length ? 33 : 66;
    raf = window.setTimeout(() => window.requestAnimationFrame(tick), interval);
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
     * How long one lap of a route takes, in seconds. Bigger is slower.
     *
     * 45s was chosen to keep a ship visibly moving on a static map; against the real globe it reads
     * as a speedboat crossing an ocean, which is the opposite of what a voyage should feel like.
     */
    setLapSeconds(s) {
      const v = Number(s);
      if (Number.isFinite(v) && v > 0) lapSeconds = v;
      map.triggerRepaint();
    },
    /**
     * Darken the fleet on the night side.
     *
     * SCENE-WIDE, not per ship: three.js lights the whole scene, and the ships share materials, so
     * dimming one would dim them all anyway. The caller passes the daylight fraction where the
     * viewer is looking — good enough while a fleet is on screen together, and wrong if two fleets
     * on opposite sides of the terminator are visible at once. Say so rather than pretend.
     */
    setNightDim(amount, daylight = 1) {
      nightDim = Math.max(0, Math.min(1, Number(amount) || 0));
      const lit = 1 - nightDim * (1 - Math.max(0, Math.min(1, daylight)));
      ambientLight.intensity = AMBIENT_BASE * lit;
      sun.intensity = SUN_BASE * lit;
      map.triggerRepaint();
    },
    /** Live-update how far the idle rock swings (0 = still, 1 = full). */
    setMotion(m) {
      sMotion = clampMotion(m);
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
      const idx = Math.min(v.track.cum.length - 1, wpIndex * SAMPLES_PER_SEGMENT);
      return v.track.cum[idx] / (v.track.total || 1);
    },
    destroy() {
      if (raf) window.clearTimeout(raf);
      // Mixers keep references to the loaded rigs; drop them or a re-mounted map leaks a skeleton
      // and its clips per mount.
      mixers.forEach((m) => m.stopAllAction());
      mixers.length = 0;
      mounts.clear();
      // Each picture marker owns a canvas texture; the GPU keeps them until told otherwise.
      allMarkers().forEach((mesh) => { mesh.material.map?.dispose(); mesh.material.dispose(); });
      markerGeometry.dispose();
      markerSprites.clear();
      if (map.getLayer('voyage-ships')) map.removeLayer('voyage-ships');
    },
  };
}

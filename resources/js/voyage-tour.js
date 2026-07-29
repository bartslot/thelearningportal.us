import { renderLessonMap } from './lesson-map.js';
import { renderGallery } from './gallery-scene.js';
import { EASE, EASING } from './easing.js';
import { voyageRoutes, smooth, SAMPLES_PER_SEGMENT } from './timemap/voyages.js';
import { addVoyageShips } from './timemap/voyage-ships.js';
import { addVoyageFog } from './timemap/voyage-fog.js';
import { buffer as turfBuffer, lineString as turfLine, simplify as turfSimplify, destination as turfDestination, booleanPointInPolygon as turfPointInPolygon, polygon as turfPolygon, difference as turfDifference, featureCollection as turfFC } from '@turf/turf';
import { mapTextProjector } from './map-text-projector.js';

// Voyage tour — the whole voyage is ONE persistent map. Legs play via playLeg(): the map, ships,
// fog and route trail are built once, and moving between scenes eases the camera to the next leg
// instead of destroying + rebuilding the map (which caused the flash/re-fit chaos on scene change).
// The fleet sails each leg in real chronology (~1 month/2s), the camera follows, a date chip counts
// through, and arrival pins the thumbnails + a hotspot that opens the gallery modal.
// Content packs live next to the route data: resources/js/timemap/<voyage>-tour.json.

const TOUR_PACKS = import.meta.glob('./timemap/*-tour.json', { eager: true });
const MS_PER_MONTH = 2000;
const MONTH_MS = 30.44 * 24 * 3600 * 1000;
const VOYAGE_PITCH = 48;   // tilt the camera so the 3D ship is seen at an angle, not top-down
const LEG_EASE_MS = 1400;  // smooth glide from one leg's arrival to the next leg's departure
// The voyage's follow zoom is a GLOBAL slider (ocean_zoom 0–100), NOT an auto-fit of the leg span
// (which zoomed right out to the whole world on ocean crossings). 0% = tight on the ship + a small
// island; 100% = a continental view (never the whole globe).
const SAIL_TIGHT_ZOOM = 5.4;    // ocean_zoom = 0   → ship + a small island
const SAIL_WIDE_ZOOM = 2.9;     // ocean_zoom = 100 → continental
const OCEAN_ZOOM_DEFAULT = 30;
const ARRIVAL_ZOOM_DELTA = 1.1; // extra zoom IN as the ship makes landfall (Dolly on arrival)
const voyageFollowZoom = (pct) => {
  const p = Math.max(0, Math.min(100, Number.isFinite(+pct) ? +pct : OCEAN_ZOOM_DEFAULT)) / 100;
  return SAIL_TIGHT_ZOOM - (SAIL_TIGHT_ZOOM - SAIL_WIDE_ZOOM) * p;
};

// Animation strength is stored as a percentage (0–100) like the other voyage sliders; the renderer
// wants a 0–1 factor. An unset value means "as tuned", not "still".
const MOTION_DEFAULT = 100;
const motionAmount = (pct) => Math.max(0, Math.min(100, Number.isFinite(+pct) ? +pct : MOTION_DEFAULT)) / 100;

const nlDate = new Intl.DateTimeFormat('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
const smoothstep = (t) => t * t * (3 - 2 * t);

// AUTO fog-of-war: old-world coastlines already charted by 17th-c. European voyagers → never fogged.
// [minLng,minLat,maxLng,maxLat]. Curated by geography (per the teacher's choice); the voyage's home
// port is added at runtime. Only the boxes that overlap the route's bbox actually matter.
const KNOWN_WORLD_BOXES = [
  [-12, 34, 45, 72],   // Europe
  [-18, -36, 52, 38],  // Africa
  [40, 5, 150, 62],    // Asia (mainland)
  [94, -11, 142, 9],   // Maritime SE Asia / Indonesia
];

// Coastline geometry for the landfall draw-on. Fetched once (module-cached) and filtered by proximity
// — more reliable than querySourceFeatures, which returns globally-scattered, antimeridian-clipped bits.
let _coastGeoPromise = null;
const loadCoastGeo = () => {
  if (!_coastGeoPromise) {
    _coastGeoPromise = fetch(`${location.origin}/timemap/coastline.geojson`)
      .then((r) => r.json())
      .catch(() => ({ features: [] }));
  }
  return _coastGeoPromise;
};

// The route trail fades behind the ship: bright at the ship's current position, dimming to a low
// (still visible) floor over the earliest sailed path — so students see where the ship has been.
const TRAIL_FADE_FLOOR = 0.32;   // opacity of the oldest sailed segment (0 = invisible, 1 = full)
const hexToRgba = (hex, a) => {
  const h = String(hex || '#000000').replace('#', '');
  const r = parseInt(h.slice(0, 2), 16) || 0;
  const g = parseInt(h.slice(2, 4), 16) || 0;
  const b = parseInt(h.slice(4, 6), 16) || 0;
  return `rgba(${r},${g},${b},${a})`;
};

const ROUTE_LINE_DEFAULTS = { enabled: true, color: '#7c2d12', opacity: 0.9, thickness: 3, wobble: 0.3, curve: 'bezier' };

export function renderVoyageTour(el, { voyage, def = null, view = 'flat', routeLine = null, onArrived = null, preview = false, editable = false, onGalleryEdit = null, onHotspotMove = null, onEndpointMove = null, onWaypointInsert = null, openGalleryOnArrive = false, mapOptions = null, legLabels = null, paintedFog = null } = {}) {
  // Prefer the lesson's editable copy (game_config.voyage_def); fall back to the shared catalog.
  const route = def || voyageRoutes().find((v) => v.id === voyage);
  const pack = TOUR_PACKS[`./timemap/${voyage}-tour.json`]?.default
    || TOUR_PACKS[`./timemap/${voyage}-tour.json`] || {};
  if (!route || !route.legs || !route.legs.length) {
    el.innerHTML = '<div class="flex h-full items-center justify-center text-base-content/60">Reis niet gevonden.</div>';
    return { playLeg() {}, destroy() {} };
  }

  const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const skipAnim = reducedMotion || preview;   // preview/reduced-motion jumps straight to arrival
  const RL = Object.assign({}, ROUTE_LINE_DEFAULTS, routeLine || {});

  // ── Hosts: map + HUD overlay (built once) ─────────────────────────────
  const mapHost = document.createElement('div');
  mapHost.style.cssText = 'position:absolute;inset:0;';
  const hud = document.createElement('div');
  hud.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:20;';
  el.style.position = 'relative';
  el.appendChild(mapHost);
  el.appendChild(hud);

  const firstYear = new Date(Date.parse(route.legs[0].depart)).getUTCFullYear();

  // Map detail options (lesson-wide): hide anachronistic cities/borders, and pin period place labels
  // resolved from the landfalls (legLabels: [{text, leg}] → the leg's arrival waypoint coordinate).
  // Mutable so a map-setting change applies LIVE (setMapOptions) instead of rebuilding the whole tour.
  let MO = Object.assign({ cities: true, borders: true, labels: true }, mapOptions || {});
  let LL = Array.isArray(legLabels) ? legLabels : [];
  let PAINTED = Array.isArray(paintedFog) ? paintedFog : [];   // teacher-painted "undiscovered" rings
  const resolveLabels = () => {
    if (!MO.labels) return [];
    return LL.map((l) => {
      const leg = route.legs[Number(l.leg)];
      const wp = leg && route.waypoints[leg.wp[1]];
      return (wp && l.text) ? { text: l.text, lng: wp[0], lat: wp[1] } : null;
    }).filter(Boolean);
  };

  // terrain:false — the voyage map is about the sea route, not hill/forest/volcano glyphs.
  // interactive only in the EDITOR: the teacher needs to pan/zoom to place a leg's destination dot.
  // The player stays non-interactive (a cinematic sail).
  const inst = renderLessonMap(mapHost, {
    qid: null, year: firstYear, interactive: editable, terrain: false,
    projection: view === 'flat' ? 'mercator' : 'globe',
    showCities: MO.cities, showBorders: MO.borders, labels: resolveLabels(),
  });
  const map = inst.map;
  // Project a lng into the SAME world copy the camera is showing (like the ship in voyage-ships.js),
  // NOT a fixed [-180,180] wrap. Antimeridian crossings (Tonga ≈ 184.8°E) leave the camera centred on
  // an unwrapped lng; a plain wrap would land the HUD 360° off-screen. Used for all overlay placement.
  const sameCopyLng = (lng) => {
    let l = lng;
    const c = map.getCenter().lng;
    while (l - c > 180) l -= 360;
    while (l - c < -180) l += 360;
    return l;
  };
  // Pan + zoom, but no rotate/pitch drag — keep the map steady for placing points.
  if (editable) {
    try { map.dragRotate.disable(); } catch (_) { /* noop */ }
    try { map.touchZoomRotate.disableRotation(); } catch (_) { /* noop */ }
    try { map.keyboard.disableRotation && map.keyboard.disableRotation(); } catch (_) { /* noop */ }
  }

  let ships = null;
  let fog = null;
  // Auto fog-of-war geometry (computed once the route is known): the whole-route bbox that gets masked
  // and the "known world" boxes (old-world coasts + home port) that stay charted. Kept in the route's
  // unwrapped lng frame so Pacific voyages line up with the corridor reveal.
  const routeBbox = () => {
    let a = Infinity, b = Infinity, c = -Infinity, d = -Infinity;
    for (const w of (route.waypoints || [])) {
      if (!Array.isArray(w)) continue;
      a = Math.min(a, w[0]); c = Math.max(c, w[0]); b = Math.min(b, w[1]); d = Math.max(d, w[1]);
    }
    if (!Number.isFinite(a)) return [-180, -85, 180, 85];
    return [a - 20, Math.max(-85, b - 15), c + 20, Math.min(85, d + 15)];
  };
  const fogWorldBox = routeBbox();
  const fogHome = route.waypoints && route.waypoints[0];
  const fogKnownBoxes = [...KNOWN_WORLD_BOXES];
  if (Array.isArray(fogHome)) fogKnownBoxes.push([fogHome[0] - 7, fogHome[1] - 7, fogHome[0] + 7, fogHome[1] + 7]);
  let onMapStyle = null;
  let ready = false;         // map + ships + fog + trail built
  let pendingLeg = null;     // a playLeg() that arrived before the map was ready
  let pendingOverview = null;   // showOverview() arrived before the style finished loading
  let legToken = 0;          // bumped on every leg → cancels the previous leg's sail/timers
  let raf = null;
  let galleryClose = null;
  let placeMarkers = null;   // the current leg's 'move' handler (removed on the next leg)
  let coastDrawOn = null;    // { svg, onMove } — the landfall coastline draw-on (fog-of-war only)
  let arrivalPins = [];      // current landfall polaroid pins (tracked so a live refresh can replace them)
  let arrivalHotspot = null; // current gallery-opener button
  let arrivalHandles = [];   // editor-only draggable endpoint / bend handles + the hover ghost
  let legHoverMove = null;   // editor-only map 'mousemove' handler that reveals the hover-bend ghost
  let legHoverHide = null;   // editor-only map 'zoom' handler that hides the ghost while zooming
  // The teacher-painted regions as a drawable FeatureCollection (each ring → a polygon).
  const paintFC = () => ({
    type: 'FeatureCollection',
    features: PAINTED.filter((r) => Array.isArray(r) && r.length >= 4)
      .map((ring) => ({ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [ring] } })),
  });
  // Editor-only amber wash refresh. Always looks the source up live (never a captured no-op), so a
  // reset/undo/erase repaints it even if it was created on a different code path — this was the bug
  // where the fog cleared but the amber overlay lingered until a full page refresh.
  const updatePaintPreview = () => { try { const s = map.getSource('voyage-paint'); if (s) s.setData(paintFC()); } catch (_) { /* no source yet */ } };

  // Per-leg state, replaced by runLeg().
  let L = { def: null, stop: null, departMs: 0, arriveMs: 0, f0: 0, f1: 1, start: null, end: null, followZoom: 3.8, stopImages: [], gallery: null };
  let sailing = false;   // true only while a leg's sail animation is running (drives the live zoom)

  // ── HUD chip (persistent) ─────────────────────────────────────────────
  const chip = document.createElement('div');
  chip.className = 'absolute left-1/2 top-5 -translate-x-1/2 rounded-full bg-base-100/90 px-5 py-2 text-base font-semibold shadow-lg';
  chip.style.pointerEvents = 'none';
  hud.appendChild(chip);
  const place = document.createElement('div');
  place.className = 'absolute left-1/2 top-16 -translate-x-1/2 rounded-full bg-base-100/75 px-4 py-1 text-sm shadow';
  place.style.pointerEvents = 'none';
  hud.appendChild(place);
  const setChip = (ms, placeText) => {
    chip.textContent = nlDate.format(new Date(ms));
    place.textContent = placeText || '';
    place.style.display = placeText ? 'block' : 'none';
  };

  // ── Route trail ───────────────────────────────────────────────────────────
  // The WHOLE path is built once and then revealed as the ship advances, rather than regrown each
  // frame. Regrowing is what made the line visibly crawl: the wobble is keyed to a sample's index
  // along the path, so every time the path got longer each sample landed somewhere new and the
  // whole line wriggled — most obvious at higher wobble settings. Fixed geometry + a moving
  // gradient stop gives a line that sits still and simply appears under the ship.
  const trailFeature = (fEnd = 1) => {
    const pts = [];
    if (RL.curve === 'straight') {
      const startWp = route.legs[0].wp[0];
      for (let w = startWp; w <= L.def.wp[1]; w++) {
        const fw = ships.fractionAtWaypoint(voyage, w);
        if (fw <= fEnd + 1e-6) { const q = ships.pointAt(voyage, fw); pts.push([q.lng, q.lat]); } else break;
      }
      const c = ships.pointAt(voyage, fEnd);
      pts.push([c.lng, c.lat]);
    } else {
      const N = 120;
      for (let k = 0; k <= N; k++) {
        const q = ships.pointAt(voyage, fEnd * (k / N));
        pts.push([q.lng, q.lat]);
      }
    }
    const w = Number(RL.wobble) || 0;
    if (w > 0 && pts.length > 2) {
      const amp = w * 0.25;
      const out = pts.map((p) => p.slice());
      for (let i = 1; i < pts.length - 1; i++) {
        let dx = pts[i + 1][0] - pts[i - 1][0];
        let dy = pts[i + 1][1] - pts[i - 1][1];
        const len = Math.hypot(dx, dy) || 1; dx /= len; dy /= len;
        // Envelope must return to zero AT every waypoint, not just at the two ends of the whole
        // path. It used to span the entire route, so each intermediate waypoint was pushed up to
        // ~8km sideways while its drag handle stayed on the true waypoint — the handle ended up
        // visibly off the line it was supposed to bend.
        const seg = SAMPLES_PER_SEGMENT;
        const off = Math.sin(i * 0.7) * amp * Math.sin(((i % seg) / seg) * Math.PI);
        out[i][0] += -dy * off;
        out[i][1] += dx * off;
      }
      return { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: out } };
    }
    return { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: pts } };
  };
  /**
   * Reveal the trail up to `f`: sailed path fades in behind the ship, nothing drawn ahead of it.
   *
   * This is the half that used to be done by regrowing the geometry. Doing it in the gradient
   * instead means the drawn line never moves — only where it stops being transparent does.
   * Stops must strictly ascend, so everything is clamped away from 0 and 1.
   */
  const CLEAR_RGBA = 'rgba(0,0,0,0)';
  const trailGradient = (f = 1) => {
    const cut = Math.min(0.998, Math.max(0.004, Number(f) || 0));
    const mid = cut * 0.7;
    return ['interpolate', ['linear'], ['line-progress'],
      0, hexToRgba(RL.color, TRAIL_FADE_FLOOR),
      mid, hexToRgba(RL.color, TRAIL_FADE_FLOOR + (1 - TRAIL_FADE_FLOOR) * 0.5),
      cut, hexToRgba(RL.color, 1),
      Math.min(0.999, cut + 0.001), CLEAR_RGBA,
      1, CLEAR_RGBA];
  };
  let lastTrailF = 0;   // remembered so setRouteLine() can restyle the trail live
  const updateTrail = (f) => {
    lastTrailF = f;
    if (!RL.enabled) return;
    // Geometry is static; only the reveal point moves.
    try { map.setPaintProperty('voyage-trail', 'line-gradient', trailGradient(f)); } catch (_) { /* layer not up yet */ }
  };
  /** Lay down (or re-lay) the full path. Only needed when the ROUTE or its wobble/curve changes. */
  const redrawTrailGeometry = () => {
    const src = map.getSource('voyage-trail');
    if (src) src.setData(trailFeature(1));
  };

  // ── Gallery modal (opened by the landfall hotspot / a thumbnail) ───────
  const galleryData = () => {
    if (L.gallery && (L.gallery.story || (L.gallery.images && L.gallery.images.length) || L.gallery.title)) return L.gallery;
    if (!L.stop) return null;
    return {
      title: L.stop.title || L.stop.place,
      date_label: L.stop.date_label || '',
      story: L.stop.story || '',
      images: (L.stopImages || []).map((u) => ({ url: u })),
    };
  };
  const openGallery = (gal, startUrl = null) => {
    if (!gal || galleryClose) return;
    let g = gal;
    let startIndex = 0;
    if (startUrl) {
      const list = (gal.images || []).slice();
      const idx = list.findIndex((im) => (im && im.url ? im.url : im) === startUrl);
      if (idx >= 0) { startIndex = idx; } else { list.unshift({ url: startUrl }); g = Object.assign({}, gal, { images: list }); }
    }
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:absolute;inset:0;z-index:40;background:rgba(3,8,20,0.9);pointer-events:auto;';
    const box = document.createElement('div');
    box.style.cssText = 'position:absolute;inset:4%;border-radius:1rem;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.65);';
    overlay.appendChild(box);
    const gInst = renderGallery(box, g, { startIndex, editable, onEdit: onGalleryEdit });
    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Sluiten');
    close.textContent = '✕';
    close.style.cssText = 'position:absolute;top:calc(4% + 10px);right:calc(4% + 14px);z-index:50;width:36px;height:36px;border-radius:9999px;border:none;background:rgba(15,23,42,.92);color:#e2e8f0;font-size:18px;line-height:1;cursor:pointer;';
    overlay.appendChild(close);
    window.__voyageGalleryOpen = true;
    // Esc closes the modal (matches DaisyUI's native <dialog>); backdrop click closes it too.
    const onKey = (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); galleryClose && galleryClose(); } };
    galleryClose = () => { try { gInst.destroy(); } catch (_) { /* gone */ } overlay.remove(); document.removeEventListener('keydown', onKey); galleryClose = null; window.__voyageGalleryOpen = false; };
    close.onclick = galleryClose;
    overlay.addEventListener('click', (e) => { if (e.target === overlay) galleryClose(); });
    document.addEventListener('keydown', onKey);
    el.appendChild(overlay);
  };

  // Remove the previous leg's transient HUD (pins/hotspot/gallery), keep the persistent chip.
  const clearCoastDrawOn = () => {
    if (!coastDrawOn) return;
    try { coastDrawOn.cancel && coastDrawOn.cancel(); } catch (_) { /* noop */ }
    try { if (map.getLayer(coastDrawOn.srcId)) map.removeLayer(coastDrawOn.srcId); if (map.getSource(coastDrawOn.srcId)) map.removeSource(coastDrawOn.srcId); } catch (_) { /* map gone */ }
    coastDrawOn = null;
  };

  const clearLegHud = () => {
    if (galleryClose) { try { galleryClose(); } catch (_) { /* noop */ } }
    clearCoastDrawOn();
    if (placeMarkers) { map.off('move', placeMarkers); placeMarkers = null; }
    if (legHoverMove) { map.off('mousemove', legHoverMove); legHoverMove = null; }
    if (legHoverHide) { map.off('zoom', legHoverHide); map.off('movestart', legHoverHide); legHoverHide = null; }
    Array.from(hud.children).forEach((c) => { if (c !== chip && c !== place) c.remove(); });
    arrivalPins = []; arrivalHotspot = null; arrivalHandles = [];
  };

  // (Re)build the landfall pins + hotspot for the CURRENT leg at the CURRENT camera. No camera move,
  // and any OPEN gallery modal is left untouched — so editing the gallery (adding an image, changing
  // text) updates the map's pins LIVE without rebuilding the whole tour.
  const buildArrivalHud = () => {
    if (placeMarkers) { map.off('move', placeMarkers); placeMarkers = null; }
    if (legHoverMove) { map.off('mousemove', legHoverMove); legHoverMove = null; }
    if (legHoverHide) { map.off('zoom', legHoverHide); map.off('movestart', legHoverHide); legHoverHide = null; }
    arrivalPins.forEach((p) => { try { p.remove(); } catch (_) { /* gone */ } });
    if (arrivalHotspot) { try { arrivalHotspot.remove(); } catch (_) { /* gone */ } arrivalHotspot = null; }
    arrivalHandles.forEach((h) => { try { h.remove(); } catch (_) { /* gone */ } }); arrivalHandles = [];

    const gal = galleryData();
    // Map pins ARE the gallery's own images (first few) — so an image added to the gallery shows on
    // the map immediately. Falls back to the legacy stop_images only when the gallery has none.
    const galImgs = ((gal && gal.images) || []).map((im) => (typeof im === 'string' ? im : (im && im.url))).filter(Boolean);
    const imgs = (galImgs.length ? galImgs : (L.stopImages || [])).slice(0, 3);
    const pins = [];
    imgs.forEach((src, i) => {
      const pin = document.createElement('img');
      pin.src = src;
      pin.alt = L.stop ? L.stop.place : '';
      pin.className = 'absolute rounded shadow-lg';
      const rot = (i - 1) * 6 + (Math.random() * 16 - 8);
      pin._rot = rot;
      pin._dx = 40 + i * 22 + (Math.random() * 26 - 13);
      pin._dy = -108 + i * 40 + (Math.random() * 30 - 15);
      pin.style.cssText += `width:96px;border:4px solid #fff;transform:rotate(${rot}deg);`
        + 'transition:transform .18s ease, box-shadow .18s ease;pointer-events:auto;will-change:transform;'
        + (gal ? 'cursor:pointer;' : '');
      pin.onerror = () => { pin.style.display = 'none'; };
      pin.addEventListener('mouseenter', () => { pin.style.transform = 'rotate(0deg) scale(1.14)'; pin.style.zIndex = '30'; pin.style.boxShadow = '0 14px 30px rgba(0,0,0,.45)'; });
      pin.addEventListener('mouseleave', () => { pin.style.transform = `rotate(${pin._rot}deg)`; pin.style.zIndex = ''; pin.style.boxShadow = ''; });
      if (gal) pin.onclick = () => openGallery(gal, src);
      hud.appendChild(pin);
      pins.push(pin);
      // Pop each pin in (scale + fade with a little overshoot), staggered — so an added image
      // visibly lands on the map. fill:'backwards' hides it during its stagger delay.
      try {
        pin.animate([
          { transform: `rotate(${rot}deg) scale(0.3)`, opacity: 0 },
          { transform: `rotate(${rot}deg) scale(1.14)`, opacity: 1, offset: 0.65 },
          { transform: `rotate(${rot}deg) scale(1)`, opacity: 1 },
        ], { duration: 460, delay: i * 90, easing: EASE.pop, fill: 'backwards' });
      } catch (_) { /* WAAPI unavailable — pin just appears */ }
    });
    arrivalPins = pins;
    let hotspot = null;
    // A custom hotspot position (geo, so it survives zoom/pan) — set by the teacher dragging it.
    let hotspotPos = (L.hotspot && Number.isFinite(Number(L.hotspot.lng)) && Number.isFinite(Number(L.hotspot.lat)))
      ? { lng: Number(L.hotspot.lng), lat: Number(L.hotspot.lat) } : null;
    if (gal) {
      hotspot = document.createElement('button');
      hotspot.type = 'button';
      hotspot.className = 'absolute rounded-full bg-base-100/95 px-4 py-2 text-sm font-semibold shadow-xl';
      hotspot.style.cssText += 'pointer-events:auto;cursor:pointer;border:2px solid rgba(251,191,36,.9);touch-action:none;';
      // Label the landfall hotspot with the GALLERY title the teacher typed (it's the gallery this
      // button opens). Fall back to the catalog stop name, then a neutral label. Built via text nodes
      // so a teacher-typed title can never inject markup.
      const hotspotLabel = (gal && typeof gal.title === 'string' && gal.title.trim())
        || (L.stop && (L.stop.title || L.stop.place)) || 'Beelden';
      const lblSpan = document.createElement('span');
      lblSpan.textContent = hotspotLabel;
      const arrowSpan = document.createElement('span');
      arrowSpan.style.opacity = '.6';
      arrowSpan.textContent = ' ▸';
      hotspot.append(lblSpan, arrowSpan);
      hud.appendChild(hotspot);
      arrivalHotspot = hotspot;
      // Pop the hotspot in just after the pins.
      try {
        hotspot.animate([
          { transform: 'scale(0.6)', opacity: 0 },
          { transform: 'scale(1.06)', opacity: 1, offset: 0.7 },
          { transform: 'scale(1)', opacity: 1 },
        ], { duration: 380, delay: 140, easing: EASE.pop, fill: 'backwards' });
      } catch (_) { /* WAAPI unavailable */ }

      // Click opens the gallery. When onHotspotMove is provided (the editor) the teacher can also DRAG
      // the button onto any part of the map; a small move threshold distinguishes a drag from a click.
      let down = null; let dragged = false;
      hotspot.addEventListener('pointerdown', (e) => {
        const r = hotspot.getBoundingClientRect();
        down = { x: e.clientX, y: e.clientY, gx: e.clientX - r.left, gy: e.clientY - r.top };
        dragged = false;
        if (onHotspotMove) { try { hotspot.setPointerCapture(e.pointerId); } catch (_) {} e.preventDefault(); }
      });
      hotspot.addEventListener('pointermove', (e) => {
        if (!down || !onHotspotMove) return;
        if (!dragged && Math.hypot(e.clientX - down.x, e.clientY - down.y) > 4) { dragged = true; hotspot.style.cursor = 'grabbing'; }
        if (!dragged) return;
        const cr = map.getContainer().getBoundingClientRect();
        const c = map.unproject([e.clientX - down.gx - cr.left, e.clientY - down.gy - cr.top]);
        hotspotPos = { lng: c.lng, lat: c.lat };
        placeMarkers();
      });
      const endDrag = () => {
        if (!down) return;
        const wasDrag = dragged; down = null; dragged = false; hotspot.style.cursor = 'pointer';
        if (wasDrag && onHotspotMove && hotspotPos) { try { onHotspotMove(hotspotPos.lng, hotspotPos.lat); } catch (_) {} }
        else { openGallery(gal); }   // a plain click (no drag) opens the gallery
      };
      hotspot.addEventListener('pointerup', endDrag);
      hotspot.addEventListener('pointercancel', () => { down = null; dragged = false; hotspot.style.cursor = 'pointer'; });
    }

    // Editor: reshape THIS leg's route by hand. The DESTINATION (amber X) is always shown — drag it to
    // set where the leg ends. To route the ship AROUND land (e.g. through Gibraltar) the teacher hovers
    // the route line: a rounded "bend" handle appears under the cursor, and dragging it bends the line
    // in real time and drops a waypoint there — or, if the cursor grabbed an existing bend, moves it.
    // A leg always sails FROM the previous landfall, so its start point isn't editable here.
    if (onEndpointMove && L.def && Array.isArray(L.def.wp) && Array.isArray(route.waypoints)) {
      const startIdx = Number(L.def.wp[0]) || 0;
      const endIdx = Number(L.def.wp[1]) || 0;

      // ── Shared live-preview line: while a point is dragged, hide the fixed trail and draw the leg
      //    (re-smoothed from its raw waypoints, with the drag applied) so the route bends live. ──
      const editSrc = () => map.getSource('voyage-edit-line');
      const f0 = ships.fractionAtWaypoint(voyage, startIdx);
      const f1 = ships.fractionAtWaypoint(voyage, endIdx);
      const legRaw = () => route.waypoints.slice(startIdx, endIdx + 1).map((p) => p.slice());
      const prefixCoords = () => {   // voyage start → this leg's start, from the fixed track
        const out = []; const M = 40;
        for (let k = 0; k <= M; k++) { const g = ships.pointAt(voyage, f0 * (k / M)); if (g) out.push([g.lng, g.lat]); }
        return out;
      };
      let previewing = false;
      const beginPreview = () => {
        previewing = true;
        try { map.setLayoutProperty('voyage-trail', 'visibility', 'none'); } catch (_) {}
        try { map.setLayoutProperty('voyage-edit-line', 'visibility', 'visible'); } catch (_) {}
      };
      const updatePreview = (desc, geo) => {
        const raw = legRaw();
        if (desc.mode === 'move') raw[desc.wpIndex - startIdx] = [geo.lng, geo.lat];
        else raw.splice(desc.afterWpIndex - startIdx + 1, 0, [geo.lng, geo.lat]);
        const coords = prefixCoords().concat(smooth(raw));
        const src = editSrc();
        if (src) src.setData({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } });
      };
      const endPreview = () => {
        previewing = false;
        try { map.setLayoutProperty('voyage-edit-line', 'visibility', 'none'); } catch (_) {}
        try { const s = editSrc(); if (s) s.setData({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [] } }); } catch (_) {}
        try { if (RL.enabled) map.setLayoutProperty('voyage-trail', 'visibility', 'visible'); } catch (_) {}
      };

      // ── The always-on amber Destination X (the leg's landfall) — dragging previews + persists. ──
      const destWp = route.waypoints[endIdx];
      if (Array.isArray(destWp)) {
        const h = document.createElement('button');
        h.type = 'button';
        h.dataset.voyageEndpoint = 'to';
        h.dataset.wpIndex = String(endIdx);
        h.title = "Drag to set this leg's destination";
        h.style.cssText = 'position:absolute;transform:translate(-50%,-50%);background:none;border:none;padding:0;cursor:grab;pointer-events:auto;touch-action:none;z-index:23;line-height:0;';
        h.innerHTML = '<svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" style="display:block;filter:drop-shadow(0 1px 2px rgba(0,0,0,.65))">'
          + '<path d="M5 5 19 19M19 5 5 19" fill="none" stroke="#fff" stroke-width="5.5" stroke-linecap="round"/>'
          + '<path d="M5 5 19 19M19 5 5 19" fill="none" stroke="#f59e0b" stroke-width="3" stroke-linecap="round"/></svg>'
          + '<span style="position:absolute;top:100%;left:50%;transform:translateX(-50%);margin-top:3px;background:rgba(15,23,42,.92);'
          + 'color:#fbbf24;font-size:10px;font-weight:600;letter-spacing:.02em;padding:1px 7px;border-radius:9999px;white-space:nowrap;'
          + 'box-shadow:0 1px 4px rgba(0,0,0,.5);line-height:1.5;">Destination</span>';
        h._geo = { lng: destWp[0], lat: destWp[1] };
        const desc = { mode: 'move', wpIndex: endIdx };
        let down = null; let moved = false;
        h.addEventListener('pointerdown', (e) => { down = { x: e.clientX, y: e.clientY }; moved = false; try { h.setPointerCapture(e.pointerId); } catch (_) {} h.style.cursor = 'grabbing'; e.preventDefault(); e.stopPropagation(); });
        h.addEventListener('pointermove', (e) => {
          if (!down) return;
          if (!moved && Math.hypot(e.clientX - down.x, e.clientY - down.y) > 3) { moved = true; beginPreview(); }
          if (!moved) return;
          const cr = map.getContainer().getBoundingClientRect();
          const c = map.unproject([e.clientX - cr.left, e.clientY - cr.top]);
          h._geo = { lng: c.lng, lat: c.lat };
          h.style.left = `${e.clientX - cr.left}px`; h.style.top = `${e.clientY - cr.top}px`;
          updatePreview(desc, h._geo);
        });
        const end = () => { if (!down) return; const wasMove = moved; down = null; moved = false; h.style.cursor = 'grab'; if (wasMove) { endPreview(); try { onEndpointMove(endIdx, h._geo.lng, h._geo.lat); } catch (_) {} } };
        h.addEventListener('pointerup', end);
        h.addEventListener('pointercancel', () => { if (down) { endPreview(); } down = null; moved = false; h.style.cursor = 'grab'; });
        hud.appendChild(h);
        arrivalHandles.push(h);
      }

      // ── Hover-to-bend: one ghost handle that snaps to the route line under the cursor. ──
      const GRAB_PX = 12;     // cursor must be this close to the drawn line for the ghost to appear
      const VERTEX_PX = 12;   // …and this close to an existing bend to grab it (move) vs. insert new
      const ghost = document.createElement('button');
      ghost.type = 'button';
      ghost.dataset.voyageEndpoint = 'bend';
      ghost.title = 'Drag to bend the route around land';
      ghost.style.cssText = 'position:absolute;width:15px;height:15px;transform:translate(-50%,-50%);border-radius:9999px;border:3px solid #fff;'
        + 'background:rgba(59,130,246,.95);box-shadow:0 1px 6px rgba(0,0,0,.55);cursor:grab;pointer-events:auto;touch-action:none;z-index:22;display:none;';
      ghost._geo = null;
      hud.appendChild(ghost);
      arrivalHandles.push(ghost);

      // Nearest point on the drawn leg line (sampled from the same track geometry the trail uses), or
      // null when the cursor is too far. Also resolves the raw segment → the waypoint to insert after.
      const SAMPLES = 72;
      const nearestOnLeg = (px, py) => {
        let best = null;
        for (let k = 0; k <= SAMPLES; k++) {
          const f = f0 + (f1 - f0) * (k / SAMPLES);
          const g = ships.pointAt(voyage, f);
          if (!g) continue;
          const q = map.project([sameCopyLng(g.lng), g.lat]);
          const d = Math.hypot(q.x - px, q.y - py);
          if (!best || d < best.d) best = { d, x: q.x, y: q.y, lng: g.lng, lat: g.lat, f };
        }
        if (!best || best.d > GRAB_PX) return null;
        let afterWpIndex = startIdx;
        for (let w = startIdx; w < endIdx; w++) {
          if (ships.fractionAtWaypoint(voyage, w) <= best.f + 1e-9) afterWpIndex = w; else break;
        }
        return { x: best.x, y: best.y, lng: best.lng, lat: best.lat, afterWpIndex };
      };
      // Existing bend under the cursor (strictly between the leg's start and its destination)?
      const nearBend = (px, py) => {
        for (let w = startIdx + 1; w < endIdx; w++) {
          const wp = route.waypoints[w];
          if (!Array.isArray(wp)) continue;
          const q = map.project([sameCopyLng(wp[0]), wp[1]]);
          if (Math.hypot(q.x - px, q.y - py) <= VERTEX_PX) return { wpIndex: w, lng: wp[0], lat: wp[1] };
        }
        return null;
      };

      let ghostDesc = null;   // {mode:'move',wpIndex} | {mode:'insert',afterWpIndex} — set on hover
      legHoverMove = (e) => {
        if (previewing) return;
        // Never reveal the ghost while a button is held — the teacher is panning the map or dragging
        // another handle (e.g. the info hotspot); popping a bend handle under the cursor would hijack it.
        if (e.originalEvent && e.originalEvent.buttons) { ghost.style.display = 'none'; ghostDesc = null; return; }
        const px = e.point.x; const py = e.point.y;
        const bend = nearBend(px, py);
        const hit = bend ? { x: null, y: null, lng: bend.lng, lat: bend.lat } : nearestOnLeg(px, py);
        if (!hit) { ghost.style.display = 'none'; ghostDesc = null; return; }
        const q = map.project([sameCopyLng(hit.lng), hit.lat]);
        ghost.style.left = `${q.x}px`; ghost.style.top = `${q.y}px`; ghost.style.display = '';
        ghost._geo = { lng: hit.lng, lat: hit.lat };
        ghostDesc = bend ? { mode: 'move', wpIndex: bend.wpIndex } : { mode: 'insert', afterWpIndex: hit.afterWpIndex };
      };
      legHoverHide = () => { if (!previewing) { ghost.style.display = 'none'; ghostDesc = null; } };
      map.on('mousemove', legHoverMove);
      map.on('zoom', legHoverHide);
      map.on('movestart', legHoverHide);

      let gDown = null; let gMoved = false; let gDesc = null;
      ghost.addEventListener('pointerdown', (e) => {
        if (!ghostDesc) return;
        gDown = { x: e.clientX, y: e.clientY }; gMoved = false; gDesc = ghostDesc;
        try { ghost.setPointerCapture(e.pointerId); } catch (_) {}
        ghost.style.cursor = 'grabbing'; e.preventDefault(); e.stopPropagation();
      });
      ghost.addEventListener('pointermove', (e) => {
        if (!gDown) return;
        if (!gMoved && Math.hypot(e.clientX - gDown.x, e.clientY - gDown.y) > 3) { gMoved = true; beginPreview(); }
        if (!gMoved) return;
        const cr = map.getContainer().getBoundingClientRect();
        const c = map.unproject([e.clientX - cr.left, e.clientY - cr.top]);
        ghost._geo = { lng: c.lng, lat: c.lat };
        ghost.style.left = `${e.clientX - cr.left}px`; ghost.style.top = `${e.clientY - cr.top}px`;
        updatePreview(gDesc, ghost._geo);
      });
      const endGhost = () => {
        if (!gDown) return;
        const wasMove = gMoved; const desc = gDesc; const geo = ghost._geo;
        gDown = null; gMoved = false; gDesc = null; ghost.style.cursor = 'grab'; ghost.style.display = 'none';
        if (!wasMove) return;
        endPreview();
        if (!geo) return;
        if (desc.mode === 'move') { try { onEndpointMove(desc.wpIndex, geo.lng, geo.lat); } catch (_) {} }
        else if (onWaypointInsert) { try { onWaypointInsert(desc.afterWpIndex, geo.lng, geo.lat); } catch (_) {} }
      };
      ghost.addEventListener('pointerup', endGhost);
      ghost.addEventListener('pointercancel', endGhost);
    }

    placeMarkers = () => {
      const p = map.project([sameCopyLng(L.end.lng), L.end.lat]);
      pins.forEach((pin) => { pin.style.left = `${p.x + pin._dx}px`; pin.style.top = `${p.y + pin._dy}px`; });
      if (hotspot) {
        if (hotspotPos) {
          const hp = map.project([sameCopyLng(hotspotPos.lng), hotspotPos.lat]);
          hotspot.style.left = `${hp.x}px`; hotspot.style.top = `${hp.y}px`;
        } else {
          hotspot.style.left = `${p.x + 46}px`; hotspot.style.top = `${p.y + 12}px`;
        }
      }
      arrivalHandles.forEach((h) => { if (!h._geo) return; const q = map.project([sameCopyLng(h._geo.lng), h._geo.lat]); h.style.left = `${q.x}px`; h.style.top = `${q.y}px`; });
    };
    placeMarkers();
    map.on('move', placeMarkers);
  };

  // Landfall: build the HUD, then the arrival extras — the date chip, the faded-in place name, and
  // (only on a deep-link) the reopened gallery modal.
  // Landfall coastline draw-on (AUTO fog-of-war only): the reached region's coast was blank sea until
  // now, so sweep it on with an SVG stroke-dashoffset (offset-path) + fade, ≤4s scaled to its length.
  const buildCoastDrawOn = (endLng, endLat) => {
    clearCoastDrawOn();
    if (!MO.fog_auto) return;
    const token = legToken;   // a leg switch mid-fetch cancels this draw-on
    loadCoastGeo().then((geo) => {
      if (token !== legToken || !MO.fog_auto || !geo || !geo.features) return;
      drawCoastFrom(geo.features, endLng, endLat);
    }).catch(() => { /* coastline unavailable */ });
  };

  // Stitch unordered coastline segments into ordered polylines by shared endpoints (Natural-Earth
  // splits the coast at feature/tile edges, but the endpoints match exactly). → the island's ring.
  const stitchCoast = (segments) => {
    const K = (p) => `${Math.round(p[0] * 1000)}_${Math.round(p[1] * 1000)}`;
    const byEnd = new Map();
    const add = (k, seg) => { const a = byEnd.get(k); if (a) a.push(seg); else byEnd.set(k, [seg]); };
    segments.forEach((seg) => { if (seg.length >= 2) { add(K(seg[0]), seg); add(K(seg[seg.length - 1]), seg); } });
    const used = new Set();
    const out = [];
    segments.forEach((seed) => {
      if (used.has(seed) || seed.length < 2) return;
      used.add(seed);
      let chain = seed.slice();
      // grow forward from the tail, then backward from the head, following matching endpoints
      for (const dir of [1, -1]) {
        let grow = true;
        while (grow) {
          grow = false;
          const anchor = dir === 1 ? chain[chain.length - 1] : chain[0];
          for (const seg of (byEnd.get(K(anchor)) || [])) {
            if (used.has(seg)) continue;
            const atStart = K(seg[0]) === K(anchor);
            const piece = atStart ? seg.slice(1) : seg.slice(0, -1).reverse();
            chain = dir === 1 ? chain.concat(piece) : piece.concat(chain);
            used.add(seg); grow = true; break;
          }
        }
      }
      out.push(chain);
    });
    return out;
  };

  const drawCoastFrom = (feats, endLng, endLat) => {
    if (!feats.length) return;
    // Collect coast segments within ~5° of the landfall. Antimeridian-safe: distances compared in the
    // wrapped [-180,180] frame (Tonga ≈ 184.8°E lives at -175.2 in the coastline data).
    const wrap = (l) => ((l + 180) % 360 + 360) % 360 - 180;
    const cLng = wrap(endLng), R = 5;
    const near = (lng, lat) => { let dl = Math.abs(wrap(lng) - cLng); if (dl > 180) dl = 360 - dl; return dl <= R && Math.abs(lat - endLat) <= R; };
    const segs = [];
    for (const f of feats) {
      const g = f.geometry; if (!g) continue;
      const lines = g.type === 'MultiLineString' ? g.coordinates : (g.type === 'LineString' ? [g.coordinates] : []);
      for (const line of lines) if (Array.isArray(line) && line.some((c) => near(c[0], c[1]))) segs.push(line);
      if (segs.length > 500) break;
    }
    if (!segs.length) return;

    // Stitch → ordered rings, then find the vertex nearest the landing point (where the ship arrived).
    const rings = stitchCoast(segs);
    let best = null;
    for (const ring of rings) {
      for (let i = 0; i < ring.length; i++) {
        let dl = Math.abs(wrap(ring[i][0]) - cLng); if (dl > 180) dl = 360 - dl;
        const d = dl * dl + (ring[i][1] - endLat) ** 2;
        if (!best || d < best.d) best = { ring, i, d };
      }
    }
    if (!best) return;
    const ring = best.ring; const s = best.i;
    const closed = ring.length > 3 && Math.abs(ring[0][0] - ring[ring.length - 1][0]) < 1e-6 && Math.abs(ring[0][1] - ring[ring.length - 1][1]) < 1e-6;
    // If the reached land is an ISLAND (a closed loop, or endpoints nearly meeting), un-fog its whole
    // interior so the land fill shows under the drawn coast — not just the 450 km sailed corridor.
    const nearlyClosed = closed || (ring.length > 8 && Math.hypot(ring[0][0] - ring[ring.length - 1][0], ring[0][1] - ring[ring.length - 1][1]) < 1.5);
    if (nearlyClosed && fog && typeof fog.revealRegion === 'function') { try { fog.revealRegion(ring); } catch (_) { /* fog not ready */ } }
    const N = ring.length;
    const segLen = (a, b) => { let dl = Math.abs(wrap(a[0]) - wrap(b[0])); if (dl > 180) dl = 360 - dl; return Math.hypot(dl, b[1] - a[1]); };

    // Two arcs sweeping OUTWARD from the landing point — one each way around the coast. Capped so a
    // continental landfall draws only a local stretch (an island draws its whole ring).
    const MAX_DEG = 16;
    const buildArc = (dir) => {
      const pts = [ring[s]]; let acc = 0; let i = s;
      for (let step = 0; step < N; step++) {
        let ni = i + dir;
        if (closed) ni = ((ni % N) + N) % N;
        else if (ni < 0 || ni >= N) break;
        if (ni === s) break;                       // came all the way round
        acc += segLen(ring[i], ring[ni]);
        pts.push(ring[ni]);
        if (acc > MAX_DEG) break;
        i = ni;
      }
      return pts;
    };
    const arcsPts = [buildArc(1), buildArc(-1)].filter((p) => p.length >= 2);
    if (!arcsPts.length) return;

    // Render the reveal as a MAP line layer (geo coordinates) at the SHORE level — below the trail,
    // ship, labels and the whole DOM HUD — so it composites with the map exactly like the real coast
    // and can never overlay the pins / hotspot / place names. An amber front sweeps out from the
    // landing point along each arc, leaving the dark coast behind; ease-IN (slow build → speeds up),
    // and the tinier the island the longer the amber lingers.
    const AMBER = '#f59e0b';
    const CLEAR = 'rgba(0,0,0,0)';
    const DARK = (() => { try { return map.getPaintProperty('coast-bold', 'line-color'); } catch (_) { return '#211809'; } })() || '#211809';
    const SRC = 'voyage-coast-draw';
    try { if (map.getLayer(SRC)) map.removeLayer(SRC); if (map.getSource(SRC)) map.removeSource(SRC); } catch (_) { /* noop */ }
    const features = arcsPts.map((pts) => ({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: pts.map((c) => [c[0], c[1]]) } }));
    try {
      map.addSource(SRC, { type: 'geojson', lineMetrics: true, data: { type: 'FeatureCollection', features } });
      const before = ['voyage-trail', 'voyage-ships', 'lesson-labels'].find((l) => map.getLayer(l));
      map.addLayer({
        id: SRC, type: 'line', source: SRC,
        layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-width': 2.6, 'line-gradient': ['interpolate', ['linear'], ['line-progress'], 0, CLEAR, 1, CLEAR] },
      }, before);
    } catch (_) { coastDrawOn = null; return; }

    // Amber comet at the draw-front `p` (0..1 of each arc's length): dark behind, amber head, clear ahead.
    const grad = (p) => {
      const pc = Math.max(0.002, Math.min(0.998, p));
      const back = Math.max(0.001, pc - 0.14);
      return ['interpolate', ['linear'], ['line-progress'], 0, DARK, back, DARK, pc, AMBER, Math.min(0.999, pc + 0.003), CLEAR, 1, CLEAR];
    };
    const solid = ['interpolate', ['linear'], ['line-progress'], 0, DARK, 1, DARK];
    // Duration inverse to arc size: a tiny island's amber front lingers; a big coast draws briskly.
    let maxDeg = 0;
    for (const pts of arcsPts) { let a = 0; for (let k = 1; k < pts.length; k++) a += segLen(pts[k - 1], pts[k]); if (a > maxDeg) maxDeg = a; }
    const dur = Math.round(Math.max(1400, Math.min(4200, 4200 - Math.min(15, maxDeg) * 190)));
    let raf = null; const t0 = performance.now();
    const tick = (now) => {
      const t = Math.min(1, (now - t0) / dur);
      const p = EASING.easeInCubic(t);
      try { map.setPaintProperty(SRC, 'line-gradient', t < 1 ? grad(p) : solid); } catch (_) {}
      if (t < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    coastDrawOn = { srcId: SRC, cancel: () => { if (raf) cancelAnimationFrame(raf); } };
  };

  const showArrival = (token) => {
    if (token !== legToken) return;
    setChip(L.arriveMs, L.stop ? L.stop.place : '');
    buildArrivalHud();
    // Fade the landfall's place name in now that the ship has arrived here (nearest label to the
    // leg's endpoint). Cumulative — earlier landfalls stay revealed as the voyage continues.
    try { inst.revealLabelNear(L.end.lng, L.end.lat); } catch (_) { /* labels not ready */ }
    // Keep the waypoint titles on top every time a landfall reveals one (the leg-play flow can re-lift
    // the historical-city layers above them).
    try { if (map.getLayer('lesson-labels')) map.moveLayer('lesson-labels'); } catch (_) { /* noop */ }
    // Auto fog-of-war: draw the just-reached coastline on (offset-path sweep + fade).
    try { buildCoastDrawOn(L.end.lng, L.end.lat); } catch (_) { /* coastline not ready */ }
    // The gallery is opened on demand (hotspot / thumbnail) — never auto-opened, EXCEPT once when a
    // preview "Edit scene" deep-link arrived with its modal open (openGalleryOnArrive), so the editor
    // restores exactly what the teacher was looking at.
    const gal = galleryData();
    if (openGalleryOnArrive && gal) { openGalleryOnArrive = false; try { openGallery(gal); } catch (_) { /* gallery not ready */ } }
    if (onArrived) onArrived();
  };

  const sail = (token) => {
    const durationMs = Math.min(26_000, Math.max(6_000, ((L.arriveMs - L.departMs) / MONTH_MS) * MS_PER_MONTH));
    const t0 = performance.now();
    sailing = true;
    const step = (now) => {
      if (token !== legToken) { sailing = false; return; }
      const p = Math.min(1, (now - t0) / durationMs);
      const eased = smoothstep(p);
      const f = L.f0 + eased * (L.f1 - L.f0);
      ships.setTourProgress(voyage, f);
      updateTrail(f);
      if (fog) fog.revealTo(f);
      const pos = ships.pointAt(voyage, f);
      // Follow the ship at the slider-controlled zoom (L.followZoom). "Dolly in on arrival" adds a
      // little extra zoom over the last 40% of the crossing as the ship makes landfall.
      const arrivalIn = MO.cam_dolly_arrival ? ARRIVAL_ZOOM_DELTA * smoothstep(Math.max(0, (eased - 0.6) / 0.4)) : 0;
      map.jumpTo({ center: [pos.lng, pos.lat], zoom: L.followZoom + arrivalIn, pitch: VOYAGE_PITCH });
      setChip(L.departMs + eased * (L.arriveMs - L.departMs), null);
      if (p < 1) { raf = requestAnimationFrame(step); } else { sailing = false; showArrival(token); }
    };
    raf = requestAnimationFrame(step);
  };

  const begin = (token) => {
    if (token !== legToken) return;
    ships.setTourProgress(voyage, L.f0);
    setChip(L.departMs, 'Vertrek');
    if (skipAnim) {
      ships.setTourProgress(voyage, L.f1);
      updateTrail(L.f1);
      // Reveal the fog corridor for the WHOLE sailed leg too — otherwise the route trail past the
      // leg's start stays buried under intact fog (the editor preview jumps to arrival without
      // sailing, so nothing else advances the reveal). Matches what the animated sail() does.
      if (fog) fog.revealTo(L.f1, { force: true });
      map.jumpTo({ center: [L.end.lng, L.end.lat], zoom: L.followZoom, pitch: VOYAGE_PITCH });
      showArrival(token);
      return;
    }
    sail(token);
  };

  // Play one leg on the EXISTING map — no rebuild, just ease the camera over and sail.
  const runLeg = (leg, { intro = false, stopImages = [], gallery = null, hotspot = null } = {}) => {
    const token = ++legToken;      // cancels any in-flight sail/timer from the previous leg
    if (raf) { cancelAnimationFrame(raf); raf = null; }
    clearLegHud();

    const legDef = route.legs[leg];
    if (!legDef) return;
    ships.setYear(new Date(Date.parse(legDef.depart)).getUTCFullYear());
    const f0 = ships.fractionAtWaypoint(voyage, legDef.wp[0]);
    const f1 = ships.fractionAtWaypoint(voyage, legDef.wp[1]);
    const start = ships.pointAt(voyage, f0);
    const end = ships.pointAt(voyage, f1);
    // The stop's landfall name: the teacher-editable voyage_def leg title wins over the catalog pack,
    // so the date chip / label reflect what they typed. (Gallery title stays separate.)
    const packStop = pack.legs?.[leg]?.stop || null;
    const legTitle = (route.legs?.[leg] && typeof route.legs[leg].title === 'string') ? route.legs[leg].title.trim() : '';
    const legStop = legTitle ? Object.assign({}, packStop, { place: legTitle, title: legTitle }) : packStop;
    L = {
      def: legDef, stop: legStop,
      departMs: Date.parse(legDef.depart), arriveMs: Date.parse(legDef.arrive),
      f0, f1, start, end, stopImages: stopImages || [], gallery: gallery || null, hotspot: hotspot || null,
      followZoom: voyageFollowZoom(MO.ocean_zoom),   // GLOBAL slider, not an auto-fit of the leg span
    };

    ships.setTourProgress(voyage, f0);
    if (fog) fog.revealTo(f0, { force: true });   // cumulative: reveals everything sailed up to here
    updateTrail(f0);

    if (intro && !skipAnim) {
      // Only the very first leg gets the "from space" dive-in.
      map.jumpTo({ center: [start.lng, start.lat], zoom: 1.05, pitch: 0 });
      setChip(L.departMs, pack.title || '');
      setTimeout(() => {
        if (token !== legToken) return;
        map.easeTo({ center: [start.lng, start.lat], zoom: L.followZoom, pitch: VOYAGE_PITCH, duration: 3200, essential: true });
        map.once('moveend', () => { if (token === legToken) begin(token); });
      }, 900);
    } else if (skipAnim) {
      begin(token);
    } else {
      // Smooth glide to the new leg's departure, THEN sail — no jump/re-fit between scenes.
      map.easeTo({ center: [start.lng, start.lat], zoom: L.followZoom, pitch: VOYAGE_PITCH, duration: LEG_EASE_MS, essential: true });
      map.once('moveend', () => { if (token === legToken) begin(token); });
    }
  };

  let lastPlayed = null;   // so hideOverview() can return the camera to the leg it left
  const playLeg = (leg, opts = {}) => {
    const n = Number(leg) || 0;
    lastPlayed = { leg: n, opts };
    if (!ready) { pendingLeg = { leg: n, opts }; return; }
    runLeg(n, opts);
  };

  const whenReady = (fn) => {
    if (map.isStyleLoaded() || map.loaded()) { fn(); return; }
    map.once('load', fn);
  };
  whenReady(() => {
    ships = addVoyageShips(map, { beforeId: undefined, only: voyage, ambient: false, flagshipOnly: true, def: route, shipScale: Number(MO.ship_scale) || 1, shipAnchored: MO.ship_anchored !== false, motion: motionAmount(MO.motion) });
    // The ship is a 3D custom layer added last, so it would otherwise sit ON TOP of the place names.
    // Lift every text label above it so a landfall name (e.g. "La Gomera") reads over the ship.
    ['lesson-labels', 'city-labels', 'hcity-label'].forEach((id) => { if (map.getLayer(id)) { try { map.moveLayer(id); } catch (_) { /* noop */ } } });
    const readWater = () => { try { return map.getPaintProperty('bg', 'background-color'); } catch (_) { return null; } };
    // Fog is driven ENTIRELY by the lesson's editable painted regions (game_config.voyage_fog).
    // The catalog's `unknown` is only a SEED — copied into voyage_fog at lesson-build time — so a
    // teacher can reshape or delete every masked area instead of fighting a baked-in polygon.
    fog = addVoyageFog(map, {
      unknown: [...PAINTED], beforeId: 'voyage-ships', samplePoint: (t) => ships.pointAt(voyage, t), waterColor: readWater(),
      auto: !!MO.fog_auto, knownBoxes: fogKnownBoxes, worldBox: fogWorldBox,
    });
    onMapStyle = () => { if (fog) setTimeout(() => fog.setWaterColor(readWater()), 60); };
    window.addEventListener('lessonmap:style', onMapStyle);
    // Route trail line — sits just above the land/coast but BELOW every text label, so place names
    // (and city names) read cleanly on top of the sailed route instead of being struck through.
    // Anchor before the first label layer that exists; fall back to below the fleet.
    // Always build the trail layer (visibility follows RL.enabled) so the route line can be toggled
    // and restyled LIVE via setRouteLine() — no re-mount when the teacher tweaks colour/width/style.
    if (!map.getSource('voyage-trail')) {
      // Anchor the trail BELOW the 3D ship so the sailed line runs under the hull (not over the deck).
      // The label layers are moved to the very top earlier, so anchoring before them would place the
      // trail ABOVE the ship — anchor before voyage-ships explicitly, falling back to the labels.
      const trailBefore = map.getLayer('voyage-ships')
        ? 'voyage-ships'
        : ['city-labels', 'lesson-labels', 'hcity-label'].find((l) => map.getLayer(l));
      // lineMetrics:true so the trail can fade along its length (line-gradient / line-progress).
      map.addSource('voyage-trail', { type: 'geojson', lineMetrics: true, data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [] } } });
      map.addLayer({
        id: 'voyage-trail', type: 'line', source: 'voyage-trail',
        layout: { 'line-cap': 'round', 'line-join': 'round', visibility: RL.enabled ? 'visible' : 'none' },
        // line-gradient fades the sailed path from a low floor (oldest) to full at the ship (newest).
        // line-color is ignored while a gradient is set; line-opacity still scales the whole line.
        paint: { 'line-gradient': trailGradient(0), 'line-opacity': Number(RL.opacity), 'line-width': Number(RL.thickness) },
      }, trailBefore);
      redrawTrailGeometry();   // draw the whole route once; updateTrail() only reveals it
    }
    // Editor-only: a transient preview of the CURRENT leg while the teacher drags a bend on it. The
    // real trail is fixed until the drop persists + re-mounts, so during the drag we hide it and draw
    // this line following the cursor — the route visibly bends in real time. Empty (hidden) at rest.
    if (editable && !map.getSource('voyage-edit-line')) {
      const editBefore = map.getLayer('voyage-ships')
        ? 'voyage-ships'
        : ['city-labels', 'lesson-labels', 'hcity-label'].find((l) => map.getLayer(l));
      map.addSource('voyage-edit-line', { type: 'geojson', data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [] } } });
      map.addLayer({
        id: 'voyage-edit-line', type: 'line', source: 'voyage-edit-line',
        layout: { 'line-cap': 'round', 'line-join': 'round', visibility: 'none' },
        paint: { 'line-color': RL.color, 'line-opacity': 1, 'line-width': Number(RL.thickness) },
      }, editBefore);
    }
    // Editor-only: a visible amber wash + dashed outline over the teacher-painted regions. The real
    // fog is water-coloured (invisible over sea), so without this the teacher gets no feedback on
    // what they masked — and nothing to aim the eraser at. Rendered ABOVE the fog/ships.
    if (editable && !map.getSource('voyage-paint')) {
      map.addSource('voyage-paint', { type: 'geojson', data: paintFC() });
      map.addLayer({
        id: 'voyage-paint-fill', type: 'fill', source: 'voyage-paint',
        paint: { 'fill-color': '#f59e0b', 'fill-opacity': 0.22 },
      });
      map.addLayer({
        id: 'voyage-paint-line', type: 'line', source: 'voyage-paint',
        layout: { 'line-join': 'round' },
        paint: { 'line-color': '#f59e0b', 'line-opacity': 0.85, 'line-width': 1.5, 'line-dasharray': [2, 2] },
      });
    }
    // Apply the lesson's per-element label/city/border style overrides once the base layers exist.
    try { inst.setDetailStyle(MO); } catch (_) { /* map style not ready */ }
    // Waypoint titles (landfall place names) sit ABOVE everything — fog, ship, trail, city names,
    // graticule, paint wash and the historical-city dots/labels. Re-lift lesson-labels to the very top
    // as the LAST step (after setDetailStyle, which itself re-lifts the hcity layers). Never covered.
    try { if (map.getLayer('lesson-labels')) map.moveLayer('lesson-labels'); } catch (_) { /* noop */ }
    ready = true;
    if (pendingLeg) { runLeg(pendingLeg.leg, pendingLeg.opts); pendingLeg = null; }
    if (pendingOverview) { renderOverview(pendingOverview); pendingOverview = null; }
  });

  // ── Overview: the whole voyage on one screen ──────────────────────────────
  // Everything here is DERIVED from the route, never authored, so adding a leg or dragging a
  // waypoint updates the itinerary automatically.
  let overviewPins = [];
  let overviewMove = null;

  /** Every stop in sailing order: the departure point, then each leg's arrival waypoint. */
  const overviewStops = () => {
    const nameFor = (legIdx) => {
      const hit = LL.find((x) => Number(x.leg) === legIdx);
      return (hit && hit.text) ? String(hit.text) : '';
    };
    const stops = [];
    const first = route.waypoints[route.legs[0].wp[0]];
    if (first) stops.push({ lng: first[0], lat: first[1], name: nameFor(-1), date: route.legs[0].depart, index: 0 });
    route.legs.forEach((leg, i) => {
      const wp = route.waypoints[leg.wp[1]];
      if (wp) stops.push({ lng: wp[0], lat: wp[1], name: nameFor(i), date: leg.arrive, leg: i, index: stops.length });
    });
    return stops;
  };

  const clearOverview = () => {
    if (overviewMove) { map.off('move', overviewMove); overviewMove = null; }
    overviewPins.forEach((p) => { try { p.remove(); } catch (_) { /* gone */ } });
    overviewPins = [];
  };

  const renderOverview = ({ animate = true } = {}) => {
    clearOverview();
    const stops = overviewStops();
    if (!stops.length) return;

    // Whole route drawn, nothing hidden ahead of a ship.
    updateTrail(1);

    stops.forEach((s, i) => {
      const pin = document.createElement('div');
      pin.className = 'absolute -translate-x-1/2 -translate-y-full';
      pin.style.cssText = 'pointer-events:none;z-index:30;';
      const last = i === stops.length - 1;
      // Numbered dot + name, so the class can read the itinerary in order.
      pin.innerHTML = `
        <div style="display:flex;flex-direction:column;align-items:center;gap:2px">
          ${s.name ? `<span style="white-space:nowrap;font-size:11px;font-weight:600;color:#0f172a;
              background:rgba(255,255,255,0.88);border-radius:9999px;padding:1px 7px;
              box-shadow:0 1px 3px rgba(0,0,0,0.25)"></span>` : ''}
          <span style="display:flex;align-items:center;justify-content:center;width:18px;height:18px;
              border-radius:9999px;font-size:10px;font-weight:700;color:#fff;
              background:${last ? '#b45309' : '#7c2d12'};box-shadow:0 1px 4px rgba(0,0,0,0.4);
              border:2px solid rgba(255,255,255,0.9)">${i + 1}</span>
        </div>`;
      // Names are teacher-authored: set as text, never interpolated into the markup above.
      const label = pin.querySelector('span');
      if (s.name && label) label.textContent = s.name;
      hud.appendChild(pin);
      overviewPins.push(pin);
    });

    overviewMove = () => {
      stops.forEach((s, i) => {
        const el = overviewPins[i];
        if (!el) return;
        const pt = map.project([s.lng, s.lat]);
        el.style.left = `${pt.x}px`;
        el.style.top = `${pt.y}px`;
      });
    };
    map.on('move', overviewMove);
    overviewMove();

    // Fit the CAMERA to the whole trip: flat on, no pitch — this is an itinerary, not a sail.
    const lngs = stops.map((s) => s.lng);
    const lats = stops.map((s) => s.lat);
    const bounds = [[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]];
    try {
      map.fitBounds(bounds, {
        padding: { top: 70, bottom: 70, left: 60, right: 60 },
        pitch: 0,
        duration: animate && !skipAnim ? 1200 : 0,
        essential: true,
      });
    } catch (_) { /* degenerate bounds (single stop) */ }
  };

  return {
    playLeg,
    map,   // the live MapLibre instance (used by the text projector; handy for debugging)
    /**
     * Frame the WHOLE voyage: full route drawn, every landfall named, camera fitted to the lot.
     *
     * Derived entirely from the route, never authored, so adding or dragging a leg updates the
     * itinerary with it. Used for the opening overview scene and for the zoom-out toggle a teacher
     * can hit from any leg mid-lesson.
     *
     * @param {{ animate?: boolean }} opts
     */
    showOverview(opts = {}) {
      if (!ready) { pendingOverview = opts; return; }
      renderOverview(opts);
    },
    /** Drop the overview furniture and hand the map back to the leg it came from. */
    hideOverview() {
      clearOverview();
      // Replaying the leg restores its camera, pins and the partly-revealed trail in one step.
      if (lastPlayed) runLeg(lastPlayed.leg, lastPlayed.opts);
    },
    /** Names + coordinates of every stop, in sailing order — for an itinerary list beside the map. */
    itinerary() {
      return overviewStops();
    },
    /**
     * Projector for map-pinned text labels — the same contract the standalone map scene exposes.
     *
     * Without this a voyage scene had a map on screen but no way to turn a screen position into a
     * place, so "pin to map" was silently unavailable on exactly the scenes most likely to want it
     * (labelling Venice on a route). The editor wires whichever renderer is on stage.
     */
    textProjector: (hostEl) => mapTextProjector(map, hostEl),
    /**
     * Apply map-detail settings LIVE — hide/show cities/borders + refresh place labels — without
     * rebuilding the tour. Lets the editor toggle a map setting while staying on the map (no flash,
     * no re-opened gallery).
     */
    setMapOptions(newMapOptions, newLegLabels) {
      const prevZoomPct = MO.ocean_zoom;
      if (newMapOptions) MO = Object.assign(MO, newMapOptions);
      if (Array.isArray(newLegLabels)) LL = newLegLabels;
      // The follow zoom is a global slider. When it changes, re-zoom the parked landfall live so the
      // teacher sees the new level (during a sail the frame loop already drives the zoom, so skip then).
      if (MO.ocean_zoom !== prevZoomPct) {
        L.followZoom = voyageFollowZoom(MO.ocean_zoom);
        if (!sailing) { try { map.easeTo({ center: map.getCenter(), zoom: L.followZoom, duration: 400, essential: true }); } catch (_) {} }
      }
      try { inst.setLayerToggles({ cities: MO.cities, borders: MO.borders }); } catch (_) { /* map not ready */ }
      try { inst.setLabels(resolveLabels()); } catch (_) { /* idem */ }
      try { inst.setDetailStyle(MO); } catch (_) { /* idem — MO carries the *_color/_size/_width keys */ }
      try { if (map.getLayer('lesson-labels')) map.moveLayer('lesson-labels'); } catch (_) { /* keep waypoint titles on top */ }
      try { ships && ships.setShipScale(Number(MO.ship_scale) || 1, !!MO.ship_anchored); } catch (_) { /* ships not ready */ }
      try { ships && ships.setMotion(motionAmount(MO.motion)); } catch (_) { /* idem */ }
      try { fog && fog.setAuto(!!MO.fog_auto, fogKnownBoxes, fogWorldBox); } catch (_) { /* fog not ready */ }
    },
    /**
     * Reshape the voyage's ROUTE geometry LIVE from an edited voyage_def (a dragged/inserted/moved
     * waypoint) — WITHOUT re-mounting the tour. The ship track, route trail, fog corridor and the
     * current leg's landfall HUD all update in place at the SAME camera (no jump, no flash, no
     * re-opened gallery). This is the fix for "the map refreshes every time I edit the route".
     * `leg` is the leg the editor is parked on; its endpoint/handles are recomputed from the new track.
     */
    setVoyageDef(newDef, leg) {
      if (!newDef || !Array.isArray(newDef.waypoints) || newDef.waypoints.length < 2) return;
      route.waypoints = newDef.waypoints;
      if (Array.isArray(newDef.legs)) route.legs = newDef.legs;
      if (newDef.id) route.id = newDef.id;
      try { ships.setDef(voyage, newDef); } catch (_) { /* ships not ready */ }
      const n = Number.isFinite(Number(leg)) ? Number(leg) : 0;
      const legDef = route.legs[n];
      if (!legDef) return;
      // Recompute the parked leg from the NEW track — no camera move (unlike runLeg → begin's jumpTo).
      const f0 = ships.fractionAtWaypoint(voyage, legDef.wp[0]);
      const f1 = ships.fractionAtWaypoint(voyage, legDef.wp[1]);
      // Refresh the stop's landfall name too (the editable leg title wins), so the date chip updates.
      const packStop = pack.legs?.[n]?.stop || null;
      const legTitle = (legDef && typeof legDef.title === 'string') ? legDef.title.trim() : '';
      const legStop = legTitle ? Object.assign({}, packStop, { place: legTitle, title: legTitle }) : (L.stop || packStop);
      L = Object.assign({}, L, { def: legDef, stop: legStop, f0, f1, start: ships.pointAt(voyage, f0), end: ships.pointAt(voyage, f1) });
      try { ships.setTourProgress(voyage, f1); } catch (_) {}
      updateTrail(f1);
      if (fog) { try { fog.revealTo(f1, { force: true }); } catch (_) {} }
      try { setChip(L.arriveMs, L.stop ? L.stop.place : ''); } catch (_) { /* chip not ready */ }
      try { buildArrivalHud(); } catch (_) { /* HUD not ready */ }
    },
    /** Replace the teacher-painted "undiscovered" regions and repaint the fog + editor wash live. */
    setPaintedFog(rings) {
      PAINTED = Array.isArray(rings) ? rings : [];
      try { fog?.setRegions([...PAINTED]); } catch (_) { /* fog not ready */ }
      updatePaintPreview();
    },
    /**
     * Restyle the route line LIVE — colour / opacity / width via paint props, enabled via layer
     * visibility, and curve / wobble by recomputing the trail geometry at the current fraction. No
     * re-mount, so editing the route line never glitches the whole world.
     */
    setRouteLine(newRl) {
      if (!newRl || typeof newRl !== 'object') return;
      Object.assign(RL, newRl);
      try {
        if (map.getLayer('voyage-trail')) {
          map.setLayoutProperty('voyage-trail', 'visibility', RL.enabled ? 'visible' : 'none');
          map.setPaintProperty('voyage-trail', 'line-opacity', Number(RL.opacity));
          map.setPaintProperty('voyage-trail', 'line-width', Number(RL.thickness));
        }
        // Curve/wobble change the drawn path itself, so re-lay the geometry, then re-reveal it to
        // wherever the ship currently is (this also re-applies the new colour).
        redrawTrailGeometry();
        updateTrail(lastTrailF);
      } catch (_) { /* map not ready */ }
    },
    /** Switch the map projection (flat ↔ globe) LIVE, without tearing down the tour. */
    setView(newView) {
      try { map.setProjection({ type: newView === 'globe' ? 'globe' : 'mercator' }); } catch (_) { /* older maplibre */ }
    },
    /** The current painted regions — the single source the paint overlay reads (never its own copy). */
    getPaintedFog() { return PAINTED.slice(); },
    /**
     * Erase brush: subtract the brush footprint (a ring) from every painted region via turf.difference,
     * so the eraser reveals a brush-sized bite of fog (symmetric with the paint brush) instead of
     * deleting a whole blob. Returns the new ring list; holes (erasing a blob's middle) fall back to
     * leaving the outer ring — edge-erasing to reveal coastline is the common case.
     */
    eraseFog(ring) {
      if (!Array.isArray(ring) || ring.length < 3) return PAINTED.slice();
      const closed = (r) => { const c = r.map((p) => [p[0], p[1]]); const a = c[0], b = c[c.length - 1]; if (a[0] !== b[0] || a[1] !== b[1]) c.push([a[0], a[1]]); return c; };
      let brush; try { brush = turfPolygon([closed(ring)]); } catch (_) { return PAINTED.slice(); }
      const out = [];
      for (const r of PAINTED) {
        if (!Array.isArray(r) || r.length < 3) continue;
        let region; try { region = turfPolygon([closed(r)]); } catch (_) { out.push(r); continue; }
        let diff; try { diff = turfDifference(turfFC([region, brush])); } catch (_) { diff = region; }
        if (!diff || !diff.geometry) continue;   // fully erased
        const g = diff.geometry;
        const polys = g.type === 'MultiPolygon' ? g.coordinates : (g.type === 'Polygon' ? [g.coordinates] : []);
        for (const poly of polys) { const outer = poly[0]; if (outer && outer.length >= 4) out.push(outer.map((c) => [c[0], c[1]])); }
      }
      PAINTED = out;
      try { fog?.setRegions([...PAINTED]); } catch (_) { /* fog not ready */ }
      updatePaintPreview();
      return out.slice();
    },
    /**
     * Update the CURRENT leg's gallery/thumbnails/hotspot and rebuild the map pins LIVE — no tour
     * rebuild, no camera move, and an open gallery modal is left alone. Lets an image added to the
     * gallery pin on the map immediately.
     */
    refreshArrivalContent(opts) {
      if (opts && typeof opts === 'object') {
        if ('gallery' in opts) L.gallery = opts.gallery || null;
        if ('stopImages' in opts) L.stopImages = opts.stopImages || [];
        if ('hotspot' in opts) L.hotspot = opts.hotspot || null;
      }
      try { buildArrivalHud(); } catch (_) { /* HUD not ready (e.g. still sailing) */ }
    },
    /**
     * Index of the top-most painted region under a screen point (for the eraser), or -1. Searches
     * back-to-front so the most recently painted blob wins when regions overlap.
     */
    paintedRegionAt(pt) {
      try {
        const c = map.unproject([pt.x, pt.y]);
        for (let i = PAINTED.length - 1; i >= 0; i--) {
          const ring = PAINTED[i];
          if (Array.isArray(ring) && ring.length >= 4 && turfPointInPolygon([c.lng, c.lat], turfPolygon([ring]))) return i;
        }
      } catch (_) { /* map/geometry not ready */ }
      return -1;
    },
    /**
     * Turn a freehand brush stroke (screen pixels, relative to the map container) into a geo ring:
     * unproject each point, then buffer the path by the brush radius so a drag paints a band of
     * "undiscovered" land. Returns a [[lng,lat],…] ring, or null if the stroke is too short.
     */
    /** Screen-pixel radius of a brushKm circle at a given screen point (for the live brush cursor). */
    brushRadiusPx(pt, brushKm = 250) {
      try {
        const c = map.unproject([pt.x, pt.y]);
        const dest = turfDestination([c.lng, c.lat], Math.max(30, brushKm), 90, { units: 'kilometers' });
        const a = map.project([c.lng, c.lat]);
        const b = map.project(dest.geometry.coordinates);
        return Math.hypot(b.x - a.x, b.y - a.y);
      } catch (_) { return 20; }
    },
    strokeToRing(points, brushKm = 250) {
      if (!Array.isArray(points) || points.length < 2) return null;
      try {
        const lngLat = points.map((p) => { const c = map.unproject([p.x, p.y]); return [c.lng, c.lat]; });
        const line = turfLine(lngLat);
        const buffered = turfBuffer(line, Math.max(30, brushKm), { units: 'kilometers' });
        if (!buffered) return null;
        const simple = turfSimplify(buffered, { tolerance: 0.15, highQuality: false });
        const geom = simple.geometry;
        const ring = geom.type === 'MultiPolygon' ? geom.coordinates[0][0] : geom.coordinates[0];
        return ring && ring.length >= 4 ? ring : null;
      } catch (_) { return null; }
    },
    destroy() {
      clearOverview();
      legToken++;   // invalidate any in-flight leg
      if (raf) cancelAnimationFrame(raf);
      if (onMapStyle) { try { window.removeEventListener('lessonmap:style', onMapStyle); } catch (_) { /* noop */ } onMapStyle = null; }
      try { galleryClose && galleryClose(); } catch (_) { /* clears the gallery interval */ }
      try { if (map.getLayer('voyage-trail')) map.removeLayer('voyage-trail'); if (map.getSource('voyage-trail')) map.removeSource('voyage-trail'); } catch (_) { /* map gone */ }
      try { if (map.getLayer('voyage-edit-line')) map.removeLayer('voyage-edit-line'); if (map.getSource('voyage-edit-line')) map.removeSource('voyage-edit-line'); } catch (_) { /* map gone */ }
      try {
        if (map.getLayer('voyage-paint-line')) map.removeLayer('voyage-paint-line');
        if (map.getLayer('voyage-paint-fill')) map.removeLayer('voyage-paint-fill');
        if (map.getSource('voyage-paint')) map.removeSource('voyage-paint');
      } catch (_) { /* map gone */ }
      try { fog?.destroy(); } catch (_) { /* idem */ }
      try { ships?.destroy(); } catch (_) { /* idem */ }
      try { inst.destroy(); } catch (_) { /* idem */ }
      el.innerHTML = '';
    },
  };
}

window.renderVoyageTour = renderVoyageTour;

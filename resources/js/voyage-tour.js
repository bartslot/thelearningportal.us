import { renderLessonMap } from './lesson-map.js';
import { voyageRoutes } from './timemap/voyages.js';
import { addVoyageShips } from './timemap/voyage-ships.js';

// Voyage tour — plays ONE dated leg of a voyage inside the lesson player (scene kind 'voyage').
// The fleet sails the leg in real chronology (~1 month of history per 2 s), the camera follows,
// a date chip counts through the journey, and arrival shows the stop card + image thumbs pinned
// near the coast. Content packs live next to the route data: resources/js/timemap/<voyage>-tour.json.
//
// Framework, not a one-off: any voyage with `legs` in voyages.json + a content pack gets a tour.

const TOUR_PACKS = import.meta.glob('./timemap/*-tour.json', { eager: true });
const MS_PER_MONTH = 2000;
const MONTH_MS = 30.44 * 24 * 3600 * 1000;

const nlDate = new Intl.DateTimeFormat('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
const smoothstep = (t) => t * t * (3 - 2 * t);
const wrapLng = (lng) => ((lng + 180) % 360 + 360) % 360 - 180;

export function renderVoyageTour(el, { voyage, leg = 0, view = 'globe', intro = false, stopImages = [], onArrived = null } = {}) {
  const route = voyageRoutes().find((v) => v.id === voyage);
  const pack = TOUR_PACKS[`./timemap/${voyage}-tour.json`]?.default
    || TOUR_PACKS[`./timemap/${voyage}-tour.json`] || {};
  if (!route || !route.legs || !route.legs[leg]) {
    el.innerHTML = '<div class="flex h-full items-center justify-center text-base-content/60">Reis niet gevonden.</div>';
    return { destroy() {} };
  }
  const legDef = route.legs[leg];
  const stop = pack.legs?.[leg]?.stop || null;
  const departMs = Date.parse(legDef.depart);
  const arriveMs = Date.parse(legDef.arrive);
  const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── Hosts: map + HUD overlay ──────────────────────────────────────────
  const mapHost = document.createElement('div');
  mapHost.style.cssText = 'position:absolute;inset:0;';
  const hud = document.createElement('div');
  hud.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:20;';
  el.style.position = 'relative';
  el.appendChild(mapHost);
  el.appendChild(hud);

  const year = new Date(departMs).getUTCFullYear();
  const inst = renderLessonMap(mapHost, { qid: null, year, interactive: false, projection: view === 'flat' ? 'mercator' : 'globe' });
  const map = inst.map;

  // ── Ships + leg geometry — assigned in the ready block: addVoyageShips adds layers, and
  // maplibre throws "Style is not done loading" for addLayer before the style settles. ────────
  let ships = null;
  let f0 = 0;
  let f1 = 1;
  let start = null;
  let end = null;
  let followZoom = 3.8;

  // ── HUD elements ──────────────────────────────────────────────────────
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

  // Stop card + coastal thumbs, shown on arrival.
  const showArrival = () => {
    setChip(arriveMs, stop ? stop.place : '');
    if (stop) {
      const card = document.createElement('div');
      card.className = 'absolute bottom-6 left-1/2 w-[34rem] max-w-[92%] -translate-x-1/2 rounded-box bg-base-100/95 p-5 shadow-xl';
      card.style.pointerEvents = 'auto';
      card.innerHTML = `
        <p class="text-xs uppercase tracking-wide opacity-60">${stop.date_label || ''}</p>
        <h3 class="mt-0.5 text-xl font-bold">${stop.title || stop.place}</h3>
        <p class="mt-2 text-sm leading-relaxed">${stop.story || ''}</p>
        <p class="mt-3 text-xs opacity-60">Druk op <span class="kbd kbd-xs">→</span> voor de beelden</p>`;
      hud.appendChild(card);

      // Small "polaroids" pinned near the landfall (URLs come from the scene config).
      const imgs = (stopImages || []).slice(0, 3);
      const pins = [];
      imgs.forEach((src, i) => {
        const pin = document.createElement('img');
        pin.src = src;
        pin.alt = stop.place;
        pin.className = 'absolute rounded shadow-lg';
        pin.style.cssText += `width:96px;border:4px solid #fff;transform:rotate(${(i - 1) * 7}deg);pointer-events:none;`;
        hud.appendChild(pin);
        pins.push(pin);
      });
      const placePins = () => {
        const p = map.project([wrapLng(end.lng), end.lat]);
        pins.forEach((pin, i) => {
          pin.style.left = `${p.x + 46 + i * 30}px`;
          pin.style.top = `${p.y - 120 + i * 44}px`;
        });
      };
      placePins();
      map.on('move', placePins);
    }
    if (onArrived) onArrived();
  };

  // ── Playback ──────────────────────────────────────────────────────────
  let raf = null;
  let cancelled = false;
  const durationMs = Math.min(26_000, Math.max(6_000, ((arriveMs - departMs) / MONTH_MS) * MS_PER_MONTH));

  const sail = () => {
    const t0 = performance.now();
    const step = (now) => {
      if (cancelled) return;
      const p = Math.min(1, (now - t0) / durationMs);
      const eased = smoothstep(p);
      const f = f0 + eased * (f1 - f0);
      ships.setTourProgress(voyage, f);
      const pos = ships.pointAt(voyage, f);
      map.jumpTo({ center: [pos.lng, pos.lat], zoom: followZoom });
      setChip(departMs + eased * (arriveMs - departMs), null);
      if (p < 1) {
        raf = requestAnimationFrame(step);
      } else {
        showArrival();
      }
    };
    raf = requestAnimationFrame(step);
  };

  const begin = () => {
    ships.setTourProgress(voyage, f0);
    setChip(departMs, leg === 0 ? (pack.subtitle || 'Vertrek') : 'Vertrek');
    if (reducedMotion) {
      ships.setTourProgress(voyage, f1);
      map.jumpTo({ center: [end.lng, end.lat], zoom: followZoom });
      showArrival();
      return;
    }
    sail();
  };

  // Space intro on the first leg: start far out ("from space"), then dive to the departure port.
  // The style may already be loaded by the time we attach (renderLessonMap shares cached tiles),
  // so run immediately in that case — a once('load') alone silently never fires.
  const whenReady = (fn) => {
    if (map.isStyleLoaded() || map.loaded()) { fn(); return; }
    map.once('load', fn);
  };
  whenReady(() => {
    ships = addVoyageShips(map, { beforeId: undefined, only: voyage, ambient: false });
    ships.setYear(year);
    f0 = ships.fractionAtWaypoint(voyage, legDef.wp[0]);
    f1 = ships.fractionAtWaypoint(voyage, legDef.wp[1]);
    start = ships.pointAt(voyage, f0);
    end = ships.pointAt(voyage, f1);
    // Follow zoom: whole leg readable — shorter legs get a closer camera.
    const spanDeg = Math.max(Math.abs(end.lng - start.lng), Math.abs(end.lat - start.lat), 2);
    followZoom = Math.min(5.2, Math.max(3.0, 7.2 - Math.log2(spanDeg) - 1.2));
    if (intro && !reducedMotion) {
      map.jumpTo({ center: [start.lng, start.lat], zoom: 1.05, pitch: 0 });
      setChip(departMs, pack.title || '');
      setTimeout(() => {
        if (cancelled) return;
        map.easeTo({ center: [start.lng, start.lat], zoom: followZoom, duration: 3200, essential: true });
        map.once('moveend', () => { if (!cancelled) begin(); });
      }, 900);
    } else {
      map.jumpTo({ center: [start.lng, start.lat], zoom: followZoom });
      begin();
    }
  });

  return {
    destroy() {
      cancelled = true;
      if (raf) cancelAnimationFrame(raf);
      try { ships.destroy(); } catch (_) { /* map may already be gone */ }
      try { inst.destroy(); } catch (_) { /* idem */ }
      el.innerHTML = '';
    },
  };
}

window.renderVoyageTour = renderVoyageTour;

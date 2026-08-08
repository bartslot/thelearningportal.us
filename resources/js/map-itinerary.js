/**
 * map-itinerary.js — a numbered list of places, drawn on a map and visited in order.
 *
 * This is the voyage overview's furniture, lifted out so a plain map block can use it too.
 *
 * A voyage already showed a class an itinerary: numbered dots, one per landfall, in sailing order,
 * with the route drawn between them. A map block showed nothing of the kind — a scene whose script
 * says "on the left, Venice … then Constantinople … then Samarkand … and on the far right,
 * Chang'an" put four identical pins on a continent-wide view and left the camera parked, so the
 * class had to hunt for each city while the narrator talked about it.
 *
 * The two are the same idea with one difference: a voyage's stops are joined by a line because a
 * ship sailed between them, and a map block's focus cities are NOT — Marco Polo did not sail from
 * Venice to Chang'an in a straight line, and drawing one would teach that he did. So the line
 * belongs to the voyage and the itinerary belongs to both.
 *
 *   renderItineraryPins(map, hostEl, stops, opts)  // numbered dots + name pills that track the map
 *   itinerarySchedule(count, totalMs, opts)        // when the camera leaves for each stop
 *   itineraryTour(map, stops, opts)                // the camera walk itself
 *
 * `stops` is always `[{ lng, lat, name, number }]`. `number` is the caller's business: a voyage
 * leaves its departure port unnumbered (stop 1 is the first place it ARRIVES at), a map block
 * numbers every city from 1. Pass `number: null` for a pin that shows a name but no dot.
 */
import { PLACE_LABEL } from './map-place-label.js'
import { EASE } from './easing.js'
import { boxView } from './map-view.js'
import { PITCH } from './map-imagery.js'

/** Ink for the numbered dot. The last stop is lighter, so a class can see where the list ends. */
const DOT_INK = '#7c2d12'
const DOT_INK_LAST = '#b45309'

/** How long the opening overview holds before the camera sets off, and the default per-stop beats. */
export const ITINERARY_TIMING = {
  overviewMs: 2600,
  flyMs: 2200,
  holdMs: 1800,
  /** Never fly for less than this, however many stops are crammed into a short narration. */
  minFlyMs: 900,
  minHoldMs: 700,
}

/**
 * Bounding box of every stop, in the shape boxView() wants.
 *
 * @param {Array<{lng:number, lat:number}>} stops
 * @returns {{minX:number, minY:number, maxX:number, maxY:number}|null}
 */
export function stopsBox (stops) {
  const pts = (Array.isArray(stops) ? stops : [])
    .map((s) => [Number(s.lng), Number(s.lat)])
    .filter(([x, y]) => Number.isFinite(x) && Number.isFinite(y))
  if (!pts.length) return null
  return {
    minX: Math.min(...pts.map((p) => p[0])),
    minY: Math.min(...pts.map((p) => p[1])),
    maxX: Math.max(...pts.map((p) => p[0])),
    maxY: Math.max(...pts.map((p) => p[1])),
  }
}

/**
 * When the camera leaves for each stop, and when it gets there.
 *
 * The tour is paced by the NARRATION, not by a fixed cadence: the script names the cities in order,
 * so the camera should arrive at roughly the moment the narrator says the name. With no narration
 * to pace against (the editor's preview, a scene whose audio has not been generated yet) the
 * default beats apply and `totalMs` is whatever they add up to.
 *
 * Stops are spread evenly over the time left after the opening overview. When there is not enough
 * time to fly and hold at the full beats — six cities in a forty-second script — both shrink
 * proportionally rather than the last stops being dropped, down to the floors in ITINERARY_TIMING.
 *
 * @param {number} count how many stops
 * @param {number|null} totalMs the narration's duration, or null for the natural length
 * @param {object} [opts] overrides for overviewMs / flyMs / holdMs
 * @returns {Array<{index:number, departAt:number, arriveAt:number}>} milliseconds from tour start
 */
export function itinerarySchedule (count, totalMs = null, opts = {}) {
  const n = Math.max(0, Math.floor(count))
  if (!n) return []

  const t = { ...ITINERARY_TIMING, ...opts }
  const natural = t.overviewMs + n * (t.flyMs + t.holdMs)
  const budget = Number.isFinite(totalMs) && totalMs > 0 ? totalMs : natural

  // How much of the ideal we can afford. Above 1 we do NOT stretch the flights — a camera drifting
  // for eight seconds between two cities reads as broken, not leisurely. The slack becomes a longer
  // hold on each stop instead, which is time the class spends looking at the place being described.
  const perStop = Math.max(0, budget - t.overviewMs) / n
  const ideal = t.flyMs + t.holdMs
  const squeeze = Math.min(1, perStop / ideal)

  const flyMs = Math.max(t.minFlyMs, t.flyMs * squeeze)
  const holdMs = Math.max(t.minHoldMs, perStop - flyMs)

  const out = []
  let cursor = t.overviewMs
  for (let i = 0; i < n; i++) {
    out.push({ index: i, departAt: Math.round(cursor), arriveAt: Math.round(cursor + flyMs) })
    cursor += flyMs + holdMs
  }
  return out
}

/**
 * Numbered dots and name pills for every stop, positioned over the map and kept in place as it
 * moves.
 *
 * Pins are DOM, not a MapLibre symbol layer, for the same reason the voyage overview's are: a
 * numbered badge with a name pill beside it is two shapes and a border, which the symbol layer
 * cannot draw, and there are never more than a dozen of them.
 *
 * @param {object} map MapLibre map
 * @param {HTMLElement} hostEl absolutely-positioned host covering the map
 * @param {Array<{lng:number, lat:number, name?:string, number?:number|null}>} stops
 * @param {{ hidden?: boolean }} [opts] hidden: start invisible, for a tour that reveals as it goes
 * @returns {{ reveal(i:number):void, revealAll():void, highlight(i:number|null):void, destroy():void }}
 */
export function renderItineraryPins (map, hostEl, stops, { hidden = false } = {}) {
  const list = Array.isArray(stops) ? stops : []
  const pins = []

  list.forEach((s, i) => {
    const last = i === list.length - 1
    const pin = document.createElement('div')
    pin.className = 'absolute -translate-x-1/2 -translate-y-full'
    pin.style.cssText = 'pointer-events:none;z-index:30;transition:opacity .3s ease'
    pin.innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;gap:2px">
        ${s.name ? `<span data-name style="white-space:nowrap;font-size:11px;font-weight:${PLACE_LABEL.weight};color:${PLACE_LABEL.ink};
            background:${PLACE_LABEL.fill};border-radius:9999px;padding:${PLACE_LABEL.padY}px ${PLACE_LABEL.padX}px;
            box-shadow:0 1px 3px rgba(0,0,0,0.25)"></span>` : ''}
        ${s.number == null ? '' : `<span data-number style="display:flex;align-items:center;justify-content:center;width:18px;height:18px;
            border-radius:9999px;font-size:10px;font-weight:700;color:#fff;
            background:${last ? DOT_INK_LAST : DOT_INK};box-shadow:0 1px 4px rgba(0,0,0,0.4);
            border:2px solid rgba(255,255,255,0.9);transition:transform .3s ${EASE.pop}">${Number(s.number)}</span>`}
      </div>`
    // Place names are teacher-authored. Set as text so a name can never become markup.
    const label = pin.querySelector('[data-name]')
    if (s.name && label) label.textContent = String(s.name)
    if (hidden) pin.style.opacity = '0'
    hostEl.appendChild(pin)
    pins.push(pin)
  })

  const position = () => {
    list.forEach((s, i) => {
      const el = pins[i]
      if (!el) return
      const pt = map.project([Number(s.lng), Number(s.lat)])
      el.style.left = `${pt.x}px`
      el.style.top = `${pt.y}px`
    })
  }
  map.on('move', position)
  position()

  return {
    reveal (i) {
      const pin = pins[i]
      if (!pin || pin.style.opacity === '1') return
      pin.style.opacity = '1'
      pin.animate(
        [{ transform: 'scale(0.4)', opacity: 0 }, { transform: 'scale(1)', opacity: 1 }],
        { duration: 320, easing: EASE.pop, fill: 'backwards' },
      )
    },
    revealAll () { pins.forEach((p) => { p.style.opacity = '1' }) },
    /** Swell the stop the camera is sitting on, so the class knows which name is being spoken. */
    highlight (i) {
      pins.forEach((p, j) => {
        const dot = p.querySelector('[data-number]')
        if (dot) dot.style.transform = j === i ? 'scale(1.35)' : 'scale(1)'
      })
    },
    destroy () {
      try { map.off('move', position) } catch (_) { /* map already gone */ }
      pins.forEach((p) => { try { p.remove() } catch (_) { /* already detached */ } })
    },
  }
}

/**
 * Visit each stop in order: open on the whole set, then fly from one to the next.
 *
 * This is the missing half of a map block. `boxView` already knew how to frame a set of points —
 * it is what a map block opens on — so the overview here is that same framing, and each stop is
 * the same maths applied to one point.
 *
 * The tour is pausable because the player is: a student who hits space mid-scene should not come
 * back to a camera three cities further on than the narrator.
 *
 * @param {object} map MapLibre map
 * @param {Array<{lng:number, lat:number, name?:string, number?:number|null}>} stops
 * @param {object} opts
 * @param {HTMLElement} opts.hostEl host for the numbered pins
 * @param {number|null} [opts.totalMs] narration duration to pace against
 * @param {number} [opts.stopZoom] how close the camera gets to a single city
 * @param {(i:number, stop:object)=>void} [opts.onArrive]
 * @returns {{ start():void, pause():void, resume():void, goTo(i:number):void, destroy():void }}
 */
export function itineraryTour (map, stops, {
  hostEl, totalMs = null, stopZoom = 4.6, onArrive = null, timing = {},
} = {}) {
  const list = (Array.isArray(stops) ? stops : []).filter(
    (s) => Number.isFinite(Number(s.lng)) && Number.isFinite(Number(s.lat)),
  )
  const pins = hostEl ? renderItineraryPins(map, hostEl, list, { hidden: true }) : null
  const schedule = itinerarySchedule(list.length, totalMs, timing)

  let timers = []
  let paused = false
  let elapsed = 0
  let startedAt = 0
  let next = 0        // the next stop that has not been visited
  let dead = false

  const clear = () => { timers.forEach(clearTimeout); timers = [] }

  const arrive = (i) => {
    if (dead) return
    const s = list[i]
    if (!s) return
    next = i + 1
    const view = boxView({ minX: s.lng, minY: s.lat, maxX: s.lng, maxY: s.lat }, { maxZoom: stopZoom })
    try {
      map.easeTo({
        center: view.center,
        zoom: stopZoom,
        // The house tilt, not flat. This used to force pitch 0, written when the ground under it
        // was a drawn atlas and there was nothing to lean over; now it is photographed terrain
        // with the height raised, and flattening the camera at every stop threw that away — a map
        // block in the player sat dead flat no matter what, which is what made the Alps invisible.
        pitch: PITCH,
        duration: Math.max(300, (schedule[i].arriveAt - schedule[i].departAt)),
        essential: true,
      })
    } catch (_) { /* map torn down mid-flight */ }
    pins?.reveal(i)
    pins?.highlight(i)
    if (typeof onArrive === 'function') onArrive(i, s)
  }

  /** Queue every stop from `from` onwards, offset so a resume picks up where the pause left off. */
  const queue = (from, offsetMs) => {
    clear()
    for (let i = from; i < schedule.length; i++) {
      const delay = Math.max(0, schedule[i].departAt - offsetMs)
      timers.push(setTimeout(() => arrive(i), delay))
    }
  }

  return {
    start () {
      if (dead || !list.length) return
      // Open on all of them: this is the "measure the space between them with your eye" shot the
      // script asks for, and it is the only moment the class sees the whole span at once.
      const box = stopsBox(list)
      if (box) {
        const v = boxView(box, { pad: 1.5, maxZoom: 5 })
        try { map.easeTo({ ...v, pitch: PITCH, duration: 1200, essential: true }) } catch (_) { /* torn down */ }
      }
      startedAt = performance.now()
      elapsed = 0
      next = 0
      queue(0, 0)
    },
    pause () {
      if (paused || dead) return
      paused = true
      elapsed += performance.now() - startedAt
      clear()
      // Stop the camera where it stands rather than letting it coast to a city nobody is talking
      // about yet.
      try { map.stop() } catch (_) { /* torn down */ }
    },
    resume () {
      if (!paused || dead) return
      paused = false
      startedAt = performance.now()
      queue(next, elapsed)
    },
    /** Jump straight to a stop — the teacher clicking an entry in the list. */
    goTo (i) {
      clear()
      arrive(i)
    },
    destroy () {
      dead = true
      clear()
      pins?.destroy()
    },
  }
}

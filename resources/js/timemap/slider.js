// The time scrubber: one 47px bar at the foot of the map.
//
//   • a ruler of decade ticks that scrolls/drags under a fixed playhead
//   • the play control and the year, on a scrim at the left end
//   • the EraService "≈ X years ago · ~N generations" readout, on the year's tooltip
//
// Drawn to Figma node 1471:2238 (`timeline-scrubber`); the values it decides are tokens in
// resources/css/brand-kit.css and this file reads them from there rather than repeating them.
//
// TWO THINGS THE DESIGN SETTLES, because both were once the other way round here:
//   The ruler emphasises by WEIGHT, not by length. Every tick is the same height and hangs from the
//   top edge; a century tick is brighter and half a pixel wider, and carries the year. Three tick
//   lengths read as three kinds of thing — one length reads as one ruler, which is what it is.
//   The playhead is a plain light line. No accent colour, no arrowhead: it marks a position, and a
//   position needs neither to be understood.
//
// The timeline uses a centre-aligned scroll model: the year under the fixed playhead is the
// selected year. With the strip padded by half the viewport on each side,
//   scrollLeft === (year - min) * pxPerYear
// so reading/seeking a year is a single multiply/divide. onYear(year) fires (immediately for
// input edits, throttled while scrubbing) so the caller can reload the map.
import { formatReadout } from './era.js';
import { EASE, EASING } from '../easing.js';

/** Read a unitless number token off :root, so the stylesheet stays the single source. */
const token = (name, fallback) => {
  const raw = getComputedStyle(document.documentElement).getPropertyValue(name);
  const n = parseFloat(raw);
  return Number.isFinite(n) ? n : fallback;
};

/**
 * Read a LENGTH token as pixels. Must resolve the unit: these are authored in rem, so a bare
 * parseFloat of "2.9375rem" is 2.9375 — a slider that opens 16× below the number the bar is
 * actually drawn at, with nothing to say so.
 */
const lengthPx = (name, fallback) => {
  const raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const n = parseFloat(raw);
  if (!Number.isFinite(n)) return fallback;
  return raw.endsWith('rem') ? n * parseFloat(getComputedStyle(document.documentElement).fontSize) : n;
};

// Century labels are bare and BCE ones are marked — the convention every atlas uses, and what the
// design shows. Spelling "CE" on two thirds of a ruler that runs from 4000 BCE is noise.
const fmtTick = (y) => (y < 0 ? `${Math.abs(y)} BCE` : String(y));
const fmtEra = (y) => (y < 0 ? `${Math.abs(y)} BCE` : `${y} CE`);
const fmtSuffix = (y) => (y < 0 ? 'BCE' : 'CE');

// Heroicons outline, 24×24, fill none, stroke 1.5 — the house icon set.
//
// vector-effect="non-scaling-stroke" so 1.5 means 1.5 SCREEN pixels, not 1.5 of a 24-unit box shrunk
// to a 17px control. The design's own play glyph is 12px tall with a 1.5px stroke — twice the weight
// a scaled Heroicon lands on — and at this size the difference is a crisp control against a wispy
// one. It also keeps play and pause at matching weight for free.
//
// 17px, not a round 16 or 18, because the stroke no longer scales with the box: Heroicons' triangle
// is 14.67 of 24 units tall, so the drawn glyph is 14.67·(17/24) + 1.5 = 11.9px — the design's 12.
const ICON_PLAY = '<path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 0 1 0 1.971l-11.54 6.347a1.125 1.125 0 0 1-1.667-.985V5.653Z"/>';
const ICON_PAUSE = '<path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5"/>';

// How close a century label may come to the playhead before it steps aside. Half a four-digit label
// at 10px: any nearer and the line runs through the digits.
const LABEL_CLEARANCE_PX = 24;

/** The resting tick height, as written on the element — the magnifier must restore exactly this. */
const REST_HEIGHT = 'var(--scrubber-tick-height)';

/**
 * The ruler shows single years while you are scrubbing slowly enough to be aiming at one.
 *
 * Speed is in pixels of ruler per millisecond of scrub. Two thresholds rather than one, because a
 * single threshold sits exactly where a hand naturally hovers and the year ticks strobe on and off
 * against it. Entering is the slow number, leaving is the fast one, and the gap between them is
 * where whatever it was last is kept.
 */
const FINE_ENTER_SPEED = 0.12;  // ≈ 30 years a second at 4px/year
const FINE_LEAVE_SPEED = 0.30;
const FINE_IDLE_MS = 400;       // no scrub for this long and the years go again
const SPEED_SMOOTHING = 0.45;   // weight kept from the previous window

/**
 * Speed is pixels moved per millisecond over a FIXED WINDOW, never per event.
 *
 * Dividing one event's distance by the gap since the last one measures the input device, not the
 * hand: pointer moves arrive in bursts, and a 4px step landing 3ms after the previous sample reads
 * as a fling however slowly the hand is actually going. Measured this way the years came in and
 * went straight back out again, once per burst.
 */
const SPEED_WINDOW_MS = 90;

/**
 * `labels` comes in from Blade, already translated. The play control is icon-only, so the app-wide
 * tooltip gives it a name — which makes these strings VISIBLE rather than screen-reader-only, and
 * visible text in this product is five languages. English is only the fallback for a caller that
 * has not passed them.
 */
const DEFAULT_LABELS = {
  play: 'Play timeline',
  pause: 'Pause timeline',
  track: 'Timeline year',
  year: 'Year (negative for BCE)',
};

export function mountTimeSlider(el, { min, max, value, onYear, onPlay, labels }) {
  const t = { ...DEFAULT_LABELS, ...(labels ?? {}) };
  const clamp = (y) => Math.min(max, Math.max(min, y));
  let current = clamp(Math.round(value));
  let pxPerYear = token('--scrubber-px-per-year', 4);

  el.classList.add('select-none');
  el.innerHTML = `
    <div class="tm-track absolute inset-0 overflow-hidden rounded-card"
         role="slider" tabindex="0"
         aria-label="${t.track}" aria-valuemin="${min}" aria-valuemax="${max}" aria-valuenow="${current}">
      <!-- A lighter band gives the playhead somewhere to sit; it is behind everything. -->
      <div class="pointer-events-none absolute inset-y-0 left-1/2 -translate-x-1/2 bg-scrubber-band"
           style="width: var(--scrubber-band-width)"></div>
      <!-- Every single year — see buildStrip. Pinned to the track rather than living on the strip:
           the strip is 25,000px wide, and Chrome will not rasterise a 1px line every 4px across a
           layer that size. It comes back as a soft wash instead of a ruler. So it is one
           track-width element and the pattern's PHASE is what scrolls. -->
      <div class="tm-years pointer-events-none absolute inset-x-0 top-0" style="opacity:0"></div>
      <div class="tm-scroll absolute inset-0 cursor-grab overflow-x-scroll overflow-y-hidden no-scrollbar">
        <div class="tm-strip relative h-full"></div>
      </div>
    </div>

    <!-- The playhead overshoots the top edge, so it reads as a mark ON the ruler, not a seam in it. -->
    <div class="pointer-events-none absolute left-1/2 z-20 w-[1.5px] -translate-x-1/2 rounded-[2px] bg-scrubber-playhead"
         style="top: -3px; height: calc(var(--scrubber-height) + 3px)"></div>

    <!-- Play + year: an overlay ON the ruler, with no surface of its own.
         The mock backs this group with 90% of the bar's own colour — invisible as a shape, but it
         wipes out every tick behind it, and live that turns the left end into a solid slab the
         ruler appears to start after. Bart: "the play button and year input is an overlay that
         should be an overlay of the scrub bar." So the ruler runs unbroken underneath, and the
         year stays readable because the badge carries its own fill rather than the group doing it. -->
    <div class="absolute inset-y-0 left-0 z-30 flex items-center gap-1 pl-1 pr-3">
      <button type="button" aria-label="${t.play}" aria-pressed="false" data-tooltip="${t.play}"
              class="tm-play grid h-6 w-6 place-items-center rounded-full text-scrubber-control">
        <svg class="tm-ic-play h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg">${ICON_PLAY}</svg>
        <svg class="tm-ic-pause h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg" style="display:none">${ICON_PAUSE}</svg>
      </button>
      <label class="tm-year-badge flex h-[30px] cursor-text items-center gap-0.5 rounded-md border border-scrubber-badge-edge bg-scrubber-badge px-2">
        <input type="number" inputmode="numeric"
               class="tm-year-input w-[5ch] bg-transparent text-right font-mono text-scrubber-num tracking-[0.8px] tabular-nums text-scrubber-year outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
               min="${min}" max="${max}" step="1" value="${current}" aria-label="${t.year}">
        <span class="tm-era-suffix lp-card-label lp-card-label-sm">${fmtSuffix(current)}</span>
      </label>
    </div>`;

  const input = el.querySelector('.tm-year-input');
  const suffix = el.querySelector('.tm-era-suffix');
  const badge = el.querySelector('.tm-year-badge');
  const track = el.querySelector('.tm-track');
  const scroll = el.querySelector('.tm-scroll');
  const strip = el.querySelector('.tm-strip');

  // Hide the native scrollbar without a global stylesheet.
  scroll.style.scrollbarWidth = 'none';

  // Build ticks: one per decade, hanging from the top edge, all the same height. Every century is
  // brighter, half a pixel wider, and labelled. Strip is half-viewport padded so the endpoints can
  // reach the centre.
  const centuryLabels = new Map(); // year → its label element, for the playhead clearance below
  const ticks = [];                // every decade tick, ascending — the magnifier walks this
  const firstTickYear = Math.ceil(min / 10) * 10;
  const yearTicks = el.querySelector('.tm-years');
  let yearPhase = 0;               // where year `min` sits on the strip, i.e. the left padding

  /**
   * Slide the year hatch to match the ruler.
   *
   * A line sits at strip x = pad + k·pxPerYear, and the strip is scrolled by scrollLeft, so on
   * screen they land at (pad − scrollLeft) + k·pxPerYear — the pattern is the same, only its phase
   * moves. Which is why this can be one small element and still be a ruler and not a texture.
   */
  const positionYears = () => {
    const phase = (((yearPhase - scroll.scrollLeft) % pxPerYear) + pxPerYear) % pxPerYear;
    yearTicks.style.backgroundPositionX = `${phase}px`;
  };

  const buildStrip = () => {
    const pad = track.clientWidth / 2;
    strip.style.width = `${(max - min) * pxPerYear + pad * 2}px`;
    strip.style.paddingLeft = `${pad}px`;
    strip.innerHTML = '';
    centuryLabels.clear();
    ticks.length = 0;
    steppedAside = null; // the element it pointed at has just been thrown away

    /**
     * Every single year, as ONE repeating gradient rather than 6,010 divs. The ruler spans six
     * millennia, so a tick per year is a line every four pixels — as DOM that is an element count
     * with nothing to show for it, and as a background it costs nothing and fades as one thing.
     */
    yearTicks.style.height = 'var(--scrubber-year-tick-height)';
    yearTicks.style.backgroundImage = 'repeating-linear-gradient(90deg,'
      + ' var(--color-scrubber-tick) 0 var(--scrubber-year-tick-width),'
      + ` transparent var(--scrubber-year-tick-width) ${pxPerYear}px)`;
    yearPhase = pad;
    positionYears();
    paintFine();
    readMagnifyMetrics();

    const start = Math.ceil(min / 10) * 10;
    for (let y = start; y <= max; y += 10) {
      const isCentury = y % 100 === 0;
      const x = pad + (y - min) * pxPerYear;
      const tick = document.createElement('div');
      tick.className = isCentury
        ? 'absolute top-0 w-[1.5px] rounded-[3px] bg-scrubber-tick-major'
        : 'absolute top-0 w-px rounded-[3px] bg-scrubber-tick';
      tick.style.left = `${x}px`;
      tick.style.height = REST_HEIGHT;
      strip.appendChild(tick);
      ticks.push(tick); // ascending, one per decade — the magnifier indexes straight into this
      if (!isCentury) continue;
      const label = document.createElement('span');
      label.className = 'absolute -translate-x-1/2 whitespace-nowrap text-scrubber-num font-semibold tabular-nums text-scrubber-tick-major';
      label.style.left = `${x}px`;
      label.style.top = 'var(--scrubber-label-top)';
      // The line box is exactly the room left below the ticks. Inter's own line-height at 10px is
      // 15px, which ran 4px past the bottom of a 47px bar and shaved the digits — visible only as
      // labels that look slightly wrong, never as an error.
      label.style.lineHeight = 'calc(var(--scrubber-height) - var(--scrubber-label-top))';
      label.textContent = fmtTick(y);
      strip.appendChild(label);
      centuryLabels.set(y, label);
    }
  };

  /**
   * Keep the playhead off the century labels.
   *
   * The mock never had one under the line, but a live ruler puts one there every time the year is a
   * round century — which, at the very least, is where it starts. A 1.5px white line through "1600"
   * reads as a strike-through, so the label the playhead is standing on steps aside. It loses
   * nothing: that year is the one the badge is already showing.
   */
  /**
   * Fine mode — the single years, and the snap that comes with them.
   *
   * The ruler adapts to how you are moving it. Thrown across a millennium it stays a ruler of
   * decades; eased along a few years it puts the years in, because that is the only moment they
   * mean anything. Speed is measured in ruler pixels moved per millisecond, from the scroll
   * position itself rather than from the pointer, so a trackpad flick counts the same as a drag.
   *
   * Holding still is the slowest scrub there is, and it fires no events at all — hence the rAF
   * below, which keeps feeding zeroes while the pointer is down so a press-and-hold brings the
   * years in rather than timing out and losing them.
   */
  let fineEnter = FINE_ENTER_SPEED;  // both are live, so the dev panel can find the right feel
  let fineLeave = FINE_LEAVE_SPEED;
  let scrubSpeed = fineLeave;        // starts coarse: a gesture has to earn the years
  let fine = false;
  let scrubRaf = null;
  let scrubUntil = 0;                // activity deadline; the sampler stops once it passes
  let fineIdle = null;               // and a timer says so too — see noteScrub
  let sampledLeft = 0;
  let movedPx = 0;
  let windowStart = 0;

  const paintFine = () => {
    yearTicks.style.transition = `opacity ${fine ? 140 : 220}ms ${fine ? EASE.enter : EASE.exit}`;
    yearTicks.style.opacity = fine ? '1' : '0';
  };
  const setFine = (on) => { if (on !== fine) { fine = on; paintFine(); } };

  /**
   * ONE sampler, reading the scroll position — not two handlers posting deltas to each other.
   *
   * The first version had the drag loop and the scroll handler both contributing, and they
   * interleave: a frame could close a timing window a moment before the frame's movement had been
   * added to it, measure a fling as motionless, and put the years up mid-throw. Reading
   * `scrollLeft` here means the distance and the time always come from the same instant.
   */
  const scrubTick = (now) => {
    const left = scroll.scrollLeft;
    movedPx += Math.abs(left - sampledLeft);
    sampledLeft = left;
    const dt = now - windowStart;
    if (dt >= SPEED_WINDOW_MS) {
      scrubSpeed = scrubSpeed * SPEED_SMOOTHING + (movedPx / dt) * (1 - SPEED_SMOOTHING);
      movedPx = 0;
      windowStart = now;
      setFine(scrubSpeed < fineEnter ? true : scrubSpeed > fineLeave ? false : fine);
    }
    // Holding the ruler still is the slowest scrub there is and fires no events at all, so the
    // sampler keeps running while the pointer is down — a press-and-hold brings the years in
    // rather than timing out and losing them at the moment you were being careful.
    if (!dragging && now > scrubUntil) {
      scrubRaf = null;
      scrubSpeed = fineLeave;
      setFine(false);
      return;
    }
    scrubRaf = requestAnimationFrame(scrubTick);
  };

  /**
   * Any scrub activity: start the sampler, or push its deadline out.
   *
   * The deadline is ALSO a timer, and not only the sampler's own exit test. rAF is the right clock
   * for measuring a hand's speed and the wrong one for a deadline: it stops in a background tab and
   * pauses whenever the compositor has nothing to draw, and a loop that is the only thing able to
   * switch the years off leaves them on for as long as it is asleep. A timer still fires.
   */
  const idle = () => {
    // A hand still on the ruler has not stopped scrubbing — it is holding, which is the slowest and
    // most deliberate scrub there is. Only a released pointer ends the gesture. Without this the
    // years faded out from under anyone who paused mid-drag to look at what they had.
    if (dragging) { fineIdle = setTimeout(idle, FINE_IDLE_MS); return; }
    scrubSpeed = fineLeave;
    setFine(false);
  };

  const noteScrub = (now) => {
    scrubUntil = now + FINE_IDLE_MS;
    clearTimeout(fineIdle);
    fineIdle = setTimeout(idle, FINE_IDLE_MS);
    if (scrubRaf !== null) return;
    movedPx = 0;
    windowStart = now;
    sampledLeft = scroll.scrollLeft;
    scrubSpeed = fineLeave; // a fresh gesture starts coarse, so a pause is never read as a crawl
    scrubRaf = requestAnimationFrame(scrubTick);
  };

  /** Snap the ruler to a whole year, so the playhead is never between two of them. */
  const snap = (left) => Math.round(left / pxPerYear) * pxPerYear;

  /**
   * Magnification, the way the Dock does it.
   *
   * The tick under the pointer stands up to nearly the full height of the bar and its neighbours
   * follow, less and less, out to a radius — so the ruler swells under your hand and tells you what
   * you are about to grab. Smoothstep rather than a linear falloff, because a linear one has a
   * corner at the edge of the radius and the eye finds it: the bulge stops rather than settles.
   *
   * Only the ticks actually on screen are touched. There are 601 of them and about 22 fit in the
   * bar, and they are evenly spaced — so the visible slice is arithmetic, not a search.
   */
  const MAG_IN_MS = 140;
  const MAG_OUT_MS = 220;
  let magX = -1;          // pointer position along the track, or -1 for "not over it"
  let magStrength = 0;    // 0..1
  let magFrom = 0;
  let magTo = 0;
  let magStart = 0;
  let magRaf = null;
  let magApplied = false;
  // Read once and refreshed when the strip rebuilds or the dev panel moves them, rather than three
  // getComputedStyle calls every frame for numbers that almost never change.
  let magRest = 30;
  let magPeak = 44;
  let magRadius = 112;
  const readMagnifyMetrics = () => {
    magRest = lengthPx('--scrubber-tick-height', 30);
    magPeak = lengthPx('--scrubber-magnify-height', 44);
    magRadius = lengthPx('--scrubber-magnify-radius', 112);
  };

  const smoothstep = (t) => t * t * (3 - 2 * t);

  const drawMagnify = () => {
    const rest = magRest;
    const peak = magPeak;
    const radius = magRadius;
    const pad = track.clientWidth / 2;
    const originX = pad - scroll.scrollLeft; // where year `min` sits on screen

    // Which ticks are on screen: solve for the decade indices whose x lands inside the track.
    const perTick = 10 * pxPerYear;
    const lo = Math.max(0, Math.floor((-originX - (firstTickYear - min) * pxPerYear) / perTick));
    const hi = Math.min(ticks.length - 1,
      Math.ceil((track.clientWidth - originX - (firstTickYear - min) * pxPerYear) / perTick));

    for (let i = lo; i <= hi; i++) {
      const tick = ticks[i];
      if (!tick) continue;
      const x = originX + (firstTickYear + i * 10 - min) * pxPerYear;
      const d = Math.abs(x - magX);
      const t = magX >= 0 && d < radius ? smoothstep(1 - d / radius) : 0;
      // Back to the TOKEN, not to '' — the resting height is an inline var(), so blanking it
      // collapses the tick to nothing instead of returning it to 30px.
      const h = t > 0 ? `${(rest + (peak - rest) * t * magStrength).toFixed(1)}px` : REST_HEIGHT;
      if (tick.style.height !== h) tick.style.height = h;
    }
    magApplied = true;
  };

  /** Reset every tick to the resting height — cheaper than tracking which ones were touched. */
  const clearMagnify = () => {
    for (const tick of ticks) if (tick.style.height !== REST_HEIGHT) tick.style.height = REST_HEIGHT;
    magApplied = false;
  };

  // The POSITION follows the pointer with no easing whatever — easing that would read as lag. Only
  // the arrival and the departure ease, by intent: a reveal decelerates in, a dismissal accelerates
  // away. Time-based, so it takes the same 140ms on a 30Hz display as on a 120Hz one.
  const magTick = (now) => {
    const p = MAG_IN_MS ? Math.min(1, (now - magStart) / (magTo > magFrom ? MAG_IN_MS : MAG_OUT_MS)) : 1;
    const eased = magTo > magFrom ? EASING.easeOutCubic(p) : EASING.easeInCubic(p);
    magStrength = magFrom + (magTo - magFrom) * eased;
    if (magStrength > 0) drawMagnify();
    else if (magApplied) clearMagnify();
    if (p >= 1 && magStrength === 0) { magRaf = null; return; }
    magRaf = requestAnimationFrame(magTick);
  };

  const magnifyAt = (clientX) => {
    magX = clientX < 0 ? -1 : clientX - track.getBoundingClientRect().left;
    const target = magX >= 0 ? 1 : 0;
    if (target !== magTo) { magFrom = magStrength; magTo = target; magStart = performance.now(); }
    if (magRaf === null) magRaf = requestAnimationFrame(magTick);
  };

  scroll.addEventListener('pointermove', (e) => magnifyAt(e.clientX));
  scroll.addEventListener('pointerleave', () => magnifyAt(-1));
  scroll.addEventListener('pointercancel', () => magnifyAt(-1));

  /** The year, big and brief, over the map — see year-flash.js. */
  const flashYear = () => window.dispatchEvent(new CustomEvent('timemap-scrub', {
    detail: { year: current, label: fmtTick(current) },
  }));

  let steppedAside = null;
  const clearPlayhead = () => {
    const century = Math.round(current / 100) * 100;
    const label = centuryLabels.get(century);
    const collides = !!label && Math.abs((century - current) * pxPerYear) < LABEL_CLEARANCE_PX;
    const next = collides ? label : null;
    if (steppedAside && steppedAside !== next) steppedAside.style.visibility = '';
    if (next) next.style.visibility = 'hidden';
    steppedAside = next;
  };

  // Guards against the scroll⇄input feedback loop.
  let seeking = false;

  const yearFromScroll = () => clamp(min + Math.round(scroll.scrollLeft / pxPerYear));
  const scrollForYear = (y) => (y - min) * pxPerYear;

  const renderReadout = () => {
    input.value = String(current);
    suffix.textContent = fmtSuffix(current);
    // The "≈ 87 years ago · ~3 generations" line has no room on a 47px bar, and it is something a
    // teacher wants occasionally rather than always — so it hangs off the year as a tooltip.
    badge.setAttribute('data-tooltip', formatReadout(current));
    track.setAttribute('aria-valuenow', String(current));
    track.setAttribute('aria-valuetext', fmtEra(current));
    clearPlayhead();
  };

  // Move the timeline (and UI) to `y`. fireYear=false suppresses the onYear callback (used when
  // syncing from an external setYear so we don't re-trigger a map reload).
  const seek = (y, { fireYear = true } = {}) => {
    current = clamp(Math.round(y));
    renderReadout();
    seeking = true;
    scroll.scrollLeft = scrollForYear(current);
    positionYears();
    sampledLeft = scroll.scrollLeft; // or the sampler reads this jump as a fling
    requestAnimationFrame(() => { seeking = false; });
    if (fireYear) onYear(current);
  };

  // Play/pause: auto-advance the year forward so the map animates through time. Driven by a
  // time-delta rAF loop (not setInterval) so the rate stays constant even when map reloads hog the
  // main thread — dropped frames just produce larger year jumps. Reuses seek() so the map reloads
  // (throttled by the caller). Stops at max; restarts from min if pressed at the end.
  const playBtn = el.querySelector('.tm-play');
  const icPlay = el.querySelector('.tm-ic-play');
  const icPause = el.querySelector('.tm-ic-pause');
  const PLAY_YEARS_PER_SEC = 100;
  let playOn = false;
  let playRaf = null;
  let playYear = 0; // float accumulator so rounding to whole years doesn't drift the rate
  let playLast = 0;
  const renderPlay = () => {
    icPlay.style.display = playOn ? 'none' : '';
    icPause.style.display = playOn ? '' : 'none';
    playBtn.setAttribute('aria-pressed', playOn ? 'true' : 'false');
    playBtn.setAttribute('aria-label', playOn ? t.pause : t.play);
    playBtn.setAttribute('data-tooltip', playOn ? t.pause : t.play);
  };
  const playStep = (now) => {
    if (!playOn) return;
    // Clamp the frame delta so a starved/backgrounded tab (where rAF pauses) resumes by advancing
    // slowly rather than leaping years on the first frame back.
    if (playLast) playYear += (PLAY_YEARS_PER_SEC * Math.min(now - playLast, 100)) / 1000;
    playLast = now;
    if (playYear >= max) { seek(max); stopPlay(); return; }
    seek(playYear);
    playRaf = requestAnimationFrame(playStep);
  };
  const stopPlay = () => {
    if (!playOn) return;
    playOn = false;
    if (playRaf !== null) cancelAnimationFrame(playRaf);
    playRaf = null;
    playLast = 0;
    renderPlay();
    if (onPlay) onPlay(false);
  };
  const startPlay = () => {
    if (playOn) return;
    playOn = true;
    if (current >= max) seek(min); // wrap to the start when pressed at the end
    playYear = current;
    playLast = 0;
    renderPlay();
    if (onPlay) onPlay(true);
    playRaf = requestAnimationFrame(playStep);
  };
  playBtn.addEventListener('click', () => (playOn ? stopPlay() : startPlay()));

  // Scrubbing the timeline: derive the year from scroll position, update UI immediately, and
  // throttle the onYear callback to one per frame.
  let rafPending = false;
  scroll.addEventListener('scroll', () => {
    if (seeking) return;
    // Outside the throttle: a wheel or trackpad scrub has no pointer to start the sampler, and the
    // ruler can move a long way inside one year — which is exactly the movement that decides
    // whether the years should be showing.
    noteScrub(performance.now());
    if (rafPending) return;
    rafPending = true;
    requestAnimationFrame(() => {
      rafPending = false;
      positionYears(); // the hatch is pinned to the track, so its phase is what follows the ruler
      const y = yearFromScroll();
      if (y === current) return;
      current = y;
      renderReadout();
      flashYear();
      onYear(current);
    });
  });

  // A trackpad flick keeps gliding after the fingers lift and stops wherever momentum ran out —
  // half a year off, with the playhead sitting between two of them. Land it on the year.
  scroll.addEventListener('scrollend', () => {
    if (dragging || seeking) return;
    const left = snap(scroll.scrollLeft);
    if (left !== scroll.scrollLeft) seek(min + left / pxPerYear, { fireYear: false });
  });

  // Pointer drag-to-scroll (desktop): native overflow scroll only reacts to wheel/trackpad, so
  // translate a horizontal drag into scrollLeft.
  let dragStartX = 0;
  let dragStartLeft = 0;
  let dragging = false;
  scroll.addEventListener('pointerdown', (e) => {
    stopPlay();
    dragging = true;
    dragStartX = e.clientX;
    dragStartLeft = scroll.scrollLeft;
    scroll.setPointerCapture(e.pointerId);
    scroll.classList.replace('cursor-grab', 'cursor-grabbing');
    noteScrub(performance.now());
  });
  scroll.addEventListener('pointermove', (e) => {
    if (!dragging) return;
    // Snapped, always. One year is four pixels, so at speed the quantising is invisible and at a
    // crawl it is the detent that lets you count years off with the pointer.
    scroll.scrollLeft = snap(dragStartLeft - (e.clientX - dragStartX));
  });
  const endDrag = () => {
    if (!dragging) return;
    dragging = false;
    scroll.classList.replace('cursor-grabbing', 'cursor-grab');
    // Snap the resting position to the nearest exact year.
    seek(yearFromScroll());
  };
  scroll.addEventListener('pointerup', endDrag);
  scroll.addEventListener('pointercancel', endDrag);

  // Number input: typing a year scrubs the map. ↑/↓ step it by one — which is why the design has no
  // stepper buttons and this no longer draws any.
  input.addEventListener('input', () => {
    stopPlay();
    const raw = parseInt(input.value, 10);
    if (Number.isNaN(raw)) return;
    seek(raw);
  });
  // Re-normalise the displayed value on blur (clamp / strip stray characters).
  input.addEventListener('change', () => seek(parseInt(input.value, 10) || current));

  // Keyboard on the track (arrow keys nudge by a year, Page keys by a decade).
  track.addEventListener('keydown', (e) => {
    const step = { ArrowLeft: -1, ArrowRight: 1, PageDown: -10, PageUp: 10 }[e.key];
    if (step === undefined) return;
    e.preventDefault();
    stopPlay();
    seek(current + step);
    flashYear(); // arrowing along the ruler is scrubbing, and reads better with the year called out
  });

  buildStrip();
  renderReadout();
  // Position the strip at the initial year once the track has measurable width.
  requestAnimationFrame(() => { seek(current, { fireYear: false }); });

  // Keep layout correct if the container is resized (half-viewport padding depends on width).
  const ro = new ResizeObserver(() => { buildStrip(); seek(current, { fireYear: false }); });
  ro.observe(track);

  // Dev-panel controls for the geometry this file draws. They write the same custom properties the
  // stylesheet declares, so the panel and the bar can never disagree about what a number means.
  const css = (name, v, unit = 'px') => document.documentElement.style.setProperty(name, `${v}${unit}`);
  const relayout = () => { buildStrip(); seek(current, { fireYear: false }); };
  const tuneOff = window.__tune?.register('Time scrubber', [
    { key: 'width', label: 'Bar width', min: 480, max: 1400, step: 5, value: lengthPx('--scrubber-width', 875),
      apply: (v) => css('--scrubber-width', v) },
    { key: 'height', label: 'Bar height', min: 32, max: 88, step: 1, value: lengthPx('--scrubber-height', 47),
      apply: (v) => css('--scrubber-height', v) },
    { key: 'tickHeight', label: 'Tick height', min: 8, max: 64, step: 1, value: lengthPx('--scrubber-tick-height', 30),
      apply: (v) => { css('--scrubber-tick-height', v); readMagnifyMetrics(); } },
    { key: 'labelTop', label: 'Label baseline', min: 8, max: 72, step: 1, value: lengthPx('--scrubber-label-top', 36),
      apply: (v) => css('--scrubber-label-top', v) },
    { key: 'bandWidth', label: 'Centre band', min: 0, max: 640, step: 4, value: lengthPx('--scrubber-band-width', 268),
      apply: (v) => css('--scrubber-band-width', v) },
    { key: 'yearTickHeight', label: 'Year ticks', min: 0, max: 30, step: 1, value: lengthPx('--scrubber-year-tick-height', 8),
      apply: (v) => css('--scrubber-year-tick-height', v) },
    // Below 1 it is a half-pixel line: one device pixel on a retina screen, and a soft one
    // everywhere else. That trade is worth seeing rather than arguing about.
    { key: 'yearTickWidth', label: 'Year tick width', min: 0.5, max: 2, step: 0.25, value: lengthPx('--scrubber-year-tick-width', 1),
      apply: (v) => css('--scrubber-year-tick-width', v) },
    { key: 'magnifyHeight', label: 'Magnify to', min: 30, max: 47, step: 1, value: lengthPx('--scrubber-magnify-height', 44),
      apply: (v) => { css('--scrubber-magnify-height', v); readMagnifyMetrics(); } },
    { key: 'magnifyRadius', label: 'Magnify reach', min: 20, max: 300, step: 5, value: lengthPx('--scrubber-magnify-radius', 112),
      apply: (v) => { css('--scrubber-magnify-radius', v); readMagnifyMetrics(); } },
    // How slow a scrub has to be before the years come in. Shown in years a second, which is what
    // the hand is actually doing; px/ms is what the code measures and means nothing to an eye.
    { key: 'fineAt', label: 'Years in below', min: 5, max: 200, step: 5,
      value: Math.round((fineEnter / pxPerYear) * 1000),
      apply: (v) => { fineEnter = (v * pxPerYear) / 1000; fineLeave = fineEnter * 2.5; } },
    { key: 'showYears', type: 'button', label: 'Flash the years',
      apply: () => { setFine(true); setTimeout(() => setFine(false), 1500); } },
    // Sets how much ruler a century occupies, so it changes both tick density and how far a drag
    // travels. Rebuilds the strip and re-seeks, because both depend on it.
    { key: 'pxPerYear', label: 'Pixels per year', min: 1, max: 10, step: 0.5, value: pxPerYear,
      apply: (v) => { pxPerYear = v; document.documentElement.style.setProperty('--scrubber-px-per-year', String(v)); relayout(); } },
  ], { tab: 'Map' });

  return {
    setYear: (y) => { stopPlay(); seek(y, { fireYear: false }); },
    destroy: () => {
      ro.disconnect();
      stopPlay();
      clearTimeout(fineIdle);
      if (scrubRaf !== null) cancelAnimationFrame(scrubRaf);
      if (magRaf !== null) cancelAnimationFrame(magRaf);
      if (tuneOff) tuneOff();
    },
  };
}

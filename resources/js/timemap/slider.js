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
  const buildStrip = () => {
    const pad = track.clientWidth / 2;
    strip.style.width = `${(max - min) * pxPerYear + pad * 2}px`;
    strip.style.paddingLeft = `${pad}px`;
    strip.innerHTML = '';
    centuryLabels.clear();
    steppedAside = null; // the element it pointed at has just been thrown away
    const start = Math.ceil(min / 10) * 10;
    for (let y = start; y <= max; y += 10) {
      const isCentury = y % 100 === 0;
      const x = pad + (y - min) * pxPerYear;
      const tick = document.createElement('div');
      tick.className = isCentury
        ? 'absolute top-0 w-[1.5px] rounded-[3px] bg-scrubber-tick-major'
        : 'absolute top-0 w-px rounded-[3px] bg-scrubber-tick';
      tick.style.left = `${x}px`;
      tick.style.height = 'var(--scrubber-tick-height)';
      strip.appendChild(tick);
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
    if (rafPending) return;
    rafPending = true;
    requestAnimationFrame(() => {
      rafPending = false;
      const y = yearFromScroll();
      if (y === current) return;
      current = y;
      renderReadout();
      onYear(current);
    });
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
  });
  scroll.addEventListener('pointermove', (e) => {
    if (!dragging) return;
    scroll.scrollLeft = dragStartLeft - (e.clientX - dragStartX);
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
      apply: (v) => css('--scrubber-tick-height', v) },
    { key: 'labelTop', label: 'Label baseline', min: 8, max: 72, step: 1, value: lengthPx('--scrubber-label-top', 36),
      apply: (v) => css('--scrubber-label-top', v) },
    { key: 'bandWidth', label: 'Centre band', min: 0, max: 640, step: 4, value: lengthPx('--scrubber-band-width', 268),
      apply: (v) => css('--scrubber-band-width', v) },
    // Sets how much ruler a century occupies, so it changes both tick density and how far a drag
    // travels. Rebuilds the strip and re-seeks, because both depend on it.
    { key: 'pxPerYear', label: 'Pixels per year', min: 1, max: 10, step: 0.5, value: pxPerYear,
      apply: (v) => { pxPerYear = v; document.documentElement.style.setProperty('--scrubber-px-per-year', String(v)); relayout(); } },
  ], { tab: 'Map' });

  return {
    setYear: (y) => { stopPlay(); seek(y, { fireYear: false }); },
    destroy: () => { ro.disconnect(); stopPlay(); if (tuneOff) tuneOff(); },
  };
}

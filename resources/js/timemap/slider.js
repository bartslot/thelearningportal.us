// An oldmapsonline.org-style time control:
//   • a year number input with up/down steppers (two-way bound to the map year)
//   • a horizontal, scrubbable tick timeline (minor ticks per decade, medium per
//     half-century, bold labelled ticks per century) that scrolls/drags under a fixed
//     centre mark, with gradient fades at both edges
//   • the EraService "≈ X years ago · ~N generations" readout
//
// The timeline uses a centre-aligned scroll model: the year under the fixed centre mark is
// the selected year. With the strip padded by half the viewport on each side,
//   scrollLeft === (year - min) * PX_PER_YEAR
// so reading/seeking a year is a single multiply/divide. onYear(year) fires (immediately for
// stepper/input edits, throttled while scrubbing) so the caller can reload the map.
import { formatReadout } from './era.js';

const PX_PER_YEAR = 2; // century = 200px, decade = 20px

const fmtEra = (y) => (y < 0 ? `${Math.abs(y)} BCE` : `${y} CE`);
const fmtSuffix = (y) => (y < 0 ? 'BCE' : 'CE');

export function mountTimeSlider(el, { min, max, value, onYear }) {
  const clamp = (y) => Math.min(max, Math.max(min, y));
  let current = clamp(Math.round(value));

  el.classList.add('select-none');
  el.innerHTML = `
    <!-- Play/pause: cog-sized circular button at the bottom edge of the scrubber, before the year -->
    <button type="button" aria-label="Play timeline" aria-pressed="false"
            class="tm-play btn btn-circle border-none bg-warning text-black shadow-lg hover:bg-warning absolute bottom-3 left-3 z-30">
      <svg class="tm-ic-play h-6 w-6" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M8 5v14l11-7z"/></svg>
      <svg class="tm-ic-pause h-6 w-6" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" style="display:none"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
    </button>

    <div class="tm-input-row flex items-center justify-center gap-2">
      <button type="button" class="tm-step btn btn-circle btn-ghost btn-sm" data-step="-1" aria-label="Previous year">
        <svg viewBox="0 0 16 16" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 3 5 8l5 5"/></svg>
      </button>
      <div class="flex items-baseline gap-1">
        <input type="number" inputmode="numeric" class="tm-year-input input input-sm input-bordered w-24 text-center text-lg font-bold tabular-nums"
               min="${min}" max="${max}" step="1" value="${current}" aria-label="Year (negative for BCE)">
        <span class="tm-era-suffix text-sm font-semibold opacity-70">${fmtSuffix(current)}</span>
      </div>
      <button type="button" class="tm-step btn btn-circle btn-ghost btn-sm" data-step="1" aria-label="Next year">
        <svg viewBox="0 0 16 16" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 3 5 5-5 5"/></svg>
      </button>
    </div>

    <div class="tm-readout mt-0.5 text-center text-xs opacity-70"></div>

    <div class="tm-track relative mt-2 h-12 overflow-hidden"
         role="slider" tabindex="0"
         aria-label="Timeline year" aria-valuemin="${min}" aria-valuemax="${max}" aria-valuenow="${current}">
      <!-- fixed centre indicator -->
      <div class="pointer-events-none absolute left-1/2 top-0 z-20 h-full -translate-x-1/2">
        <div class="mx-auto h-full w-px bg-primary"></div>
        <div class="absolute -top-px left-1/2 -translate-x-1/2 border-x-4 border-t-[6px] border-x-transparent border-t-primary"></div>
      </div>
      <!-- edge fades -->
      <div class="pointer-events-none absolute inset-y-0 left-0 z-10 w-12 bg-gradient-to-r from-base-100 to-transparent"></div>
      <div class="pointer-events-none absolute inset-y-0 right-0 z-10 w-12 bg-gradient-to-l from-base-100 to-transparent"></div>
      <!-- scrollable tick strip -->
      <div class="tm-scroll absolute inset-0 cursor-grab overflow-x-scroll overflow-y-hidden no-scrollbar">
        <div class="tm-strip relative h-full"></div>
      </div>
    </div>`;

  const input = el.querySelector('.tm-year-input');
  const suffix = el.querySelector('.tm-era-suffix');
  const readout = el.querySelector('.tm-readout');
  const track = el.querySelector('.tm-track');
  const scroll = el.querySelector('.tm-scroll');
  const strip = el.querySelector('.tm-strip');

  // Hide the native scrollbar without a global stylesheet.
  scroll.style.scrollbarWidth = 'none';

  // Build ticks: every decade a minor tick, every half-century a medium tick, every century a
  // bold tick with a label. Strip is half-viewport padded so endpoints can reach the centre.
  const stripWidth = (max - min) * PX_PER_YEAR;
  const buildStrip = () => {
    const pad = track.clientWidth / 2;
    strip.style.width = `${stripWidth + pad * 2}px`;
    strip.style.paddingLeft = `${pad}px`;
    strip.innerHTML = '';
    const start = Math.ceil(min / 10) * 10;
    for (let y = start; y <= max; y += 10) {
      const isCentury = y % 100 === 0;
      const isHalf = y % 50 === 0;
      const tick = document.createElement('div');
      const h = isCentury ? 'h-6' : isHalf ? 'h-4' : 'h-2.5';
      const shade = isCentury ? 'bg-base-content/60' : 'bg-base-content/25';
      tick.className = `absolute bottom-4 w-px ${h} ${shade}`;
      tick.style.left = `${pad + (y - min) * PX_PER_YEAR}px`;
      if (isCentury) {
        const label = document.createElement('span');
        label.className = 'absolute bottom-0 -translate-x-1/2 whitespace-nowrap text-[10px] font-semibold tabular-nums text-base-content/70';
        label.style.left = `${pad + (y - min) * PX_PER_YEAR}px`;
        label.textContent = fmtEra(y);
        strip.appendChild(label);
      }
      strip.appendChild(tick);
    }
  };

  // Guards against the scroll⇄input feedback loop.
  let seeking = false;

  const yearFromScroll = () => clamp(min + Math.round(scroll.scrollLeft / PX_PER_YEAR));
  const scrollForYear = (y) => (y - min) * PX_PER_YEAR;

  const renderReadout = () => {
    input.value = String(current);
    suffix.textContent = fmtSuffix(current);
    readout.textContent = formatReadout(current);
    track.setAttribute('aria-valuenow', String(current));
    track.setAttribute('aria-valuetext', fmtEra(current));
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
  const playing = () => playOn;
  const renderPlay = () => {
    icPlay.style.display = playOn ? 'none' : '';
    icPause.style.display = playOn ? '' : 'none';
    playBtn.setAttribute('aria-pressed', playOn ? 'true' : 'false');
    playBtn.setAttribute('aria-label', playOn ? 'Pause timeline' : 'Play timeline');
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
  };
  const startPlay = () => {
    if (playOn) return;
    playOn = true;
    if (current >= max) seek(min); // wrap to the start when pressed at the end
    playYear = current;
    playLast = 0;
    renderPlay();
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

  // Number input: typing a year scrubs the map.
  input.addEventListener('input', () => {
    stopPlay();
    const raw = parseInt(input.value, 10);
    if (Number.isNaN(raw)) return;
    seek(raw);
  });
  // Re-normalise the displayed value on blur (clamp / strip stray characters).
  input.addEventListener('change', () => seek(parseInt(input.value, 10) || current));

  // Steppers.
  el.querySelectorAll('.tm-step').forEach((btn) => {
    btn.addEventListener('click', () => { stopPlay(); seek(current + Number(btn.dataset.step)); });
  });

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

  return { setYear: (y) => { stopPlay(); seek(y, { fireYear: false }); } };
}

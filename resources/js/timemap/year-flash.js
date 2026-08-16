/**
 * year-flash.js — the year, big and brief, over the map while you scrub.
 *
 * The scrubber's own year badge is 10px in a 47px bar at the bottom of the screen, which is right
 * for a control and wrong for the thing you are actually watching: while you drag, your eyes are on
 * the globe, not on the bar your hand is on. So the year is called out where you are already
 * looking, and then gets out of the way.
 *
 * It is the one piece of text on this screen that is not a control, which is why it is the one set
 * in History rather than Inter — the brand's voice belongs on the content, not on the chrome.
 *
 * Driven by the `timemap-scrub` event that slider.js fires on a scrub — a drag, a trackpad flick or
 * the arrow keys. NOT on playback: the year changes for the whole of a play, and a readout that
 * never leaves is a readout, not a flash.
 */
import { EASE } from '../easing.js';

const HOLD_MS = 1000;     // how long the year stays after the last change
const IN_MS = 120;
const OUT_MS = 420;       // longer than it came, so it withdraws rather than blinks out

/**
 * @param {HTMLElement|null} el the flash element; absent on pages that do not have one
 * @returns {() => void} teardown
 */
export function mountYearFlash(el) {
  if (!el) return () => {};

  // Fetch History now rather than on the first scrub. A browser only loads a face when something
  // needs a glyph from it, and this element starts empty — so the first year a teacher ever
  // scrubbed to appeared in the fallback serif and swapped a moment later. Nothing else on this
  // page uses the face: the map's own labels are SDF glyphs inside WebGL, not CSS text.
  document.fonts?.load('600 1em History').catch(() => {});

  let hold = null;
  let holdMs = HOLD_MS;

  const hide = () => {
    el.style.transition = `opacity ${OUT_MS}ms ${EASE.exit}`;
    el.style.opacity = '0';
  };

  const show = (label) => {
    el.textContent = label;
    el.style.transition = `opacity ${IN_MS}ms ${EASE.enter}`;
    el.style.opacity = '1';
    clearTimeout(hold);
    hold = setTimeout(hide, holdMs);
  };

  const onScrub = (e) => show(e.detail?.label ?? '');
  window.addEventListener('timemap-scrub', onScrub);

  const tuneOff = window.__tune?.register('Year flash', [
    { key: 'size', label: 'Size', min: 16, max: 160, step: 2,
      value: parseFloat(getComputedStyle(el).fontSize) || 56,
      apply: (v) => document.documentElement.style.setProperty('--scrubber-flash-size', `${v}px`) },
    { key: 'hold', label: 'Hold (ms)', min: 200, max: 4000, step: 100, value: HOLD_MS,
      apply: (v) => { holdMs = v; } },
    { key: 'preview', type: 'button', label: 'Show it', apply: () => show(el.textContent || '1600') },
  ], { tab: 'Map' });

  return () => {
    window.removeEventListener('timemap-scrub', onScrub);
    clearTimeout(hold);
    if (tuneOff) tuneOff();
  };
}

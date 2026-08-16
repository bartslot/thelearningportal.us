/**
 * scrub-tick.js — the ruler clicks under your hand.
 *
 * A scrubber that moves in whole years but makes no sound is a slider; one that clicks is a detented
 * knob, and the difference is entirely in the ear. So one click per year, quietly.
 *
 * The sound is public/sound/sfx/scrubber.mp3 — five recorded clicks of a radial knob, laid out 50ms
 * apart, quietest first and loudest last. That ladder is used rather than flattened: an ordinary
 * year is one of the quiet ones, a decade is the middle, and a century is the loudest. A real
 * detented knob is not uniform either, and it means the ruler you are looking at is also the ruler
 * you are hearing.
 *
 * THE OFFSETS ARE MEASURED, NOT WRITTEN DOWN. They could be five constants — 0.00, 0.05, 0.10,
 * 0.15, 0.20 — but MP3 decoders disagree by a frame or two about where a file starts depending on
 * whether they honour the gapless header, and a re-export with different spacing would go on
 * playing the gap between two clicks with nothing to say it had. Finding the transients in the
 * buffer we actually decoded costs thirty lines and cannot drift.
 */
const SRC = '/sound/sfx/scrubber.mp3';
const SLICE_S = 0.04;      // each click is 10–16ms; 40ms carries the whole tail
const MIN_GAP_MS = 28;     // a fling crosses years faster than an ear can separate them
const DEFAULT_VOLUME = 0.45;

/**
 * Find the click transients: an RMS envelope in 0.5ms windows, a rise past a tenth of the peak, and
 * a refractory reset once it falls back to near nothing.
 * @returns {{at: number, level: number}[]} start time in seconds and peak level, in file order
 */
function findClicks(buffer) {
  const data = buffer.getChannelData(0);
  const win = Math.max(1, Math.round(buffer.sampleRate * 0.0005));
  const env = [];
  for (let i = 0; i + win <= data.length; i += win) {
    let sum = 0;
    for (let k = 0; k < win; k++) sum += data[i + k] * data[i + k];
    env.push(Math.sqrt(sum / win));
  }
  const peak = Math.max(...env, 1e-9);

  const clicks = [];
  let armed = true;
  for (let i = 0; i < env.length; i++) {
    if (armed && env[i] > peak * 0.1) {
      // Back off to just before the attack, or the click starts a fraction late and loses its edge.
      const start = Math.max(0, i - 2);
      clicks.push({ at: (start * win) / buffer.sampleRate, level: env[i], from: i });
      armed = false;
    } else if (!armed && env[i] < peak * 0.02) {
      armed = true;
    }
  }
  // Each click's own peak, so they can be ranked by loudness rather than by position.
  const span = Math.round((SLICE_S * buffer.sampleRate) / win);
  for (const c of clicks) c.level = Math.max(...env.slice(c.from, c.from + span));
  return clicks.map(({ at, level }) => ({ at, level }));
}

/**
 * @param {object} [opts]
 * @param {number} [opts.volume]
 * @returns {{ destroy: () => void }}
 */
export function mountScrubTick({ volume = DEFAULT_VOLUME } = {}) {
  const Ctx = window.AudioContext || window.webkitAudioContext;
  if (!Ctx) return { destroy: () => {} };

  let ctx = null;
  let buffer = null;
  let clicks = [];
  let quiet = [];   // ordinary years
  let mid = null;   // decades
  let loud = null;  // centuries
  let turn = 0;
  let lastAt = 0;
  let gain = volume;

  const load = async () => {
    try {
      const res = await fetch(SRC);
      if (!res.ok) return; // the click is a nicety; a missing file must not break scrubbing
      ctx = new Ctx();
      buffer = await ctx.decodeAudioData(await res.arrayBuffer());
      clicks = findClicks(buffer);
      if (!clicks.length) { buffer = null; return; }
      // Rank by loudness and split by POSITION in that ranking, not by any ratio between levels:
      // the file's top two clicks are within half a percent of each other, and a threshold that
      // fine lands on whichever side the decoder rounds to. Rank cannot wobble.
      // Quietest half → an ordinary year. Median → a decade. Loudest → a century.
      const byLevel = [...clicks].sort((a, b) => a.level - b.level);
      loud = byLevel[byLevel.length - 1];
      mid = byLevel[Math.floor((byLevel.length - 1) / 2)];
      quiet = byLevel.slice(0, Math.max(1, Math.floor(byLevel.length / 2)));
    } catch (e) {
      buffer = null;
    }
  };
  const ready = load();

  const play = (click) => {
    const src = ctx.createBufferSource();
    src.buffer = buffer;
    const g = ctx.createGain();
    g.gain.value = gain;
    src.connect(g).connect(ctx.destination);
    src.start(0, click.at, SLICE_S);
  };

  const onScrub = (e) => {
    if (!buffer || gain <= 0) return;
    const now = performance.now();
    if (now - lastAt < MIN_GAP_MS) return;
    lastAt = now;
    // Created before any gesture, so it starts suspended; a scrub IS the gesture that frees it.
    if (ctx.state === 'suspended') ctx.resume();
    const year = e.detail?.year ?? 0;
    play(year % 100 === 0 ? loud : year % 10 === 0 ? mid : quiet[turn++ % quiet.length]);
  };
  window.addEventListener('timemap-scrub', onScrub);

  const tuneOff = window.__tune?.register('Scrub tick', [
    { key: 'volume', label: 'Volume', min: 0, max: 1, step: 0.05, value: gain,
      apply: (v) => { gain = v; } },
    { key: 'try', type: 'button', label: 'Year · decade · century',
      apply: async () => {
        await ready;
        if (!buffer) return;
        if (ctx.state === 'suspended') ctx.resume();
        [quiet[0], mid, loud].forEach((c, i) => setTimeout(() => c && play(c), i * 220));
      } },
  ], { tab: 'Map' });

  return {
    destroy: () => {
      window.removeEventListener('timemap-scrub', onScrub);
      if (tuneOff) tuneOff();
      if (ctx) ctx.close().catch(() => {});
    },
  };
}

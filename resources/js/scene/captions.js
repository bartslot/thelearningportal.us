// Narration subtitles (pure logic, unit-tested).
//
// The narration is a paragraph of text and an audio file; captions need it cut into short,
// readable lines with a start and an end. Two clocks are possible:
//
//   - the TTS character alignment, when the provider returned one (ElevenLabs does)
//   - the character count, spread across the real audio duration (Azure returns no timings)
//
// The second is an estimate, and it is a good one: TTS speaks at an even pace, so a line's share
// of the characters is very close to its share of the time. Both paths land in the same shape.

/** Roughly one comfortable line of subtitle; two of these fit the bar without wrapping oddly. */
const MAX_CUE_CHARS = 84;
/** Never flash a line — a cue below this is merged into its neighbour. */
const MIN_CUE_SECONDS = 0.9;

/** Strip the player's inline direction tags — [position:left], [game] — they are never spoken. */
export const spokenText = (script) => String(script ?? '').replace(/\[[^\]]*\]/g, '').replace(/\s+/g, ' ').trim();

/**
 * Cut text into caption-sized pieces: sentence by sentence, and long sentences split further at a
 * word boundary. Returns pieces in order; joined, they are the spoken text.
 */
export const splitIntoCues = (text) => {
  const clean = spokenText(text);
  if (clean === '') return [];

  const sentences = clean.match(/[^.!?…]+(?:[.!?…]+["'”’)]*|$)/g) ?? [clean];
  const cues = [];

  for (const sentence of sentences) {
    const trimmed = sentence.trim();
    if (trimmed === '') continue;
    if (trimmed.length <= MAX_CUE_CHARS) { cues.push(trimmed); continue; }

    let line = '';
    for (const word of trimmed.split(' ')) {
      // Keep the break at a word boundary; an over-long single word gets its own line rather
      // than being cut in half.
      if (line !== '' && (line.length + 1 + word.length) > MAX_CUE_CHARS) {
        cues.push(line);
        line = word;
      } else {
        line = line === '' ? word : `${line} ${word}`;
      }
    }
    if (line !== '') cues.push(line);
  }

  return cues;
};

/**
 * Timed cues for a scene's narration.
 *
 * @param {string} script          the scene's narration, tags and all
 * @param {number} duration        the audio's real length in seconds
 * @param {Array<{character?: string, start_time?: number}>} [alignment] TTS character timings
 * @returns {Array<{start: number, end: number, text: string}>}
 */
export const buildCues = (script, duration, alignment = null) => {
  const pieces = splitIntoCues(script);
  const total = Number(duration);
  if (!pieces.length || !Number.isFinite(total) || total <= 0) return [];

  const starts = charStarts(pieces, alignment, total);
  const cues = pieces.map((text, i) => ({
    text,
    start: Math.min(starts[i], total),
    end: Math.min(i + 1 < pieces.length ? starts[i + 1] : total, total),
  }));

  // A line that flashes past is worse than one that lingers: fold anything too short to read into
  // the line before it (or, for the very first, into the line after).
  const merged = [];
  for (const cue of cues) {
    const previous = merged[merged.length - 1];
    if (previous && cue.end - cue.start < MIN_CUE_SECONDS) {
      previous.text = `${previous.text} ${cue.text}`;
      previous.end = cue.end;
      continue;
    }
    merged.push({ ...cue });
  }
  if (merged.length > 1 && merged[0].end - merged[0].start < MIN_CUE_SECONDS) {
    const [first, second, ...rest] = merged;

    return [{ text: `${first.text} ${second.text}`, start: first.start, end: second.end }, ...rest];
  }

  return merged;
};

/** Start time per cue: from the alignment when there is one, else spread over the characters. */
const charStarts = (pieces, alignment, total) => {
  const chars = pieces.reduce((sum, p) => sum + p.length, 0) || 1;
  const timed = Array.isArray(alignment) && alignment.length > 0;

  const starts = [];
  let offset = 0;
  for (const piece of pieces) {
    starts.push(timed ? alignedTime(alignment, offset, total) : (offset / chars) * total);
    // +1 for the space that joined this piece to the next; the alignment counts it too.
    offset += piece.length + 1;
  }

  return starts;
};

/**
 * The alignment is indexed by character of the SPOKEN text, which is what splitIntoCues works on
 * too — so a running character offset indexes straight into it. Out-of-range offsets (a script
 * edited after the audio was made) fall back to the end rather than throwing.
 */
const alignedTime = (alignment, offset, total) => {
  const entry = alignment[Math.min(offset, alignment.length - 1)];
  const at = Number(entry?.start_time ?? entry?.startTime);

  return Number.isFinite(at) ? Math.min(at, total) : total;
};

/** The cue showing at time t, or null between cues. Cheap enough to call on every timeupdate. */
export const cueAt = (cues, t) => {
  if (!Array.isArray(cues) || !cues.length) return null;
  const time = Number(t) || 0;
  for (const cue of cues) {
    if (time >= cue.start && time < cue.end) return cue;
  }

  return null;
};

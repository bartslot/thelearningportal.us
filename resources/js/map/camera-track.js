/**
 * camera-track.js — keyframed camera moves over a globe, in the spirit of Google Earth Studio.
 *
 * A POSE is a place plus a way of looking at it, exactly as Earth Studio states it: the camera sits
 * at (lng, lat, altitude) and looks along a heading, tilted down from the horizontal. Not a map
 * centre and a zoom — those are a projection detail, and they make a dive from space impossible to
 * author. The renderer converts at the last moment (see camera-director.js).
 *
 * A TRACK is poses pinned to times, with an easing per segment. Sampling it is pure: the same time
 * always yields the same pose, which is what makes a move scrubbable, resumable and testable.
 *
 * The three interpolations that matter, and why none of them is a plain lerp:
 *
 *  - LONGITUDE wraps. A move from 170°E to 170°W is 20° east across the date line, not 340° back
 *    across Asia. Keyframes are unwrapped into one continuous frame before sampling.
 *  - HEADING wraps the same way: 350° → 10° swings 20° through north.
 *  - ALTITUDE is logarithmic. Halfway between 100 m and 10,000 m should look halfway, which is
 *    1,000 m (the geometric mean), not 5,050. Linear altitude is why a naive fly-to hangs in space
 *    for most of the move and then falls the last stretch.
 */

import { EASING } from '../easing.js'

/** Where the camera is and how it looks — Earth Studio's model. Altitude is metres above sea level. */
export const DEFAULT_POSE = Object.freeze({
  lng: 0, lat: 0, altitude: 12e6, heading: 0, tilt: 0,
})

const POSE_KEYS = ['lng', 'lat', 'altitude', 'heading', 'tilt']
const DEFAULT_EASING = 'easeInOutCubic'   // EASE.move — the camera convention in easing.js

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))
const lerp = (a, b, t) => a + (b - a) * t

/** Shift `angle` into the same 360° turn as `near`, so interpolation takes the short way round. */
export const unwrapAngle = (angle, near) => angle + 360 * Math.round((near - angle) / 360)

/** Altitude interpolates through its geometric mean; 0 and negatives fall back to linear. */
export const lerpAltitude = (a, b, t) => (a > 0 && b > 0
  ? Math.exp(lerp(Math.log(a), Math.log(b), t))
  : lerp(a, b, t))

const easingFn = (name) => EASING[name] || EASING[DEFAULT_EASING]

/** Keyframes in time order, with longitude and heading unwrapped into one continuous frame. */
const prepare = (track) => {
  const keys = [...(track?.keyframes ?? [])]
    .filter((k) => k && Number.isFinite(k.time))
    .sort((a, b) => a.time - b.time)
    .map((k) => ({ ...DEFAULT_POSE, ...k }))

  for (let i = 1; i < keys.length; i++) {
    keys[i] = {
      ...keys[i],
      lng: unwrapAngle(keys[i].lng, keys[i - 1].lng),
      heading: unwrapAngle(keys[i].heading, keys[i - 1].heading),
    }
  }
  return keys
}

/** Total run time of a track, in the same unit its keyframe times use (seconds by convention). */
export const trackDuration = (track) => {
  const keys = prepare(track)
  return keys.length ? keys[keys.length - 1].time : 0
}

// Catmull-Rom through four values, for `smooth` tracks. Per-segment easing alone makes a multi-stop
// move stutter: it eases to a halt at every keyframe it passes through, like a bus at every stop.
const catmullRom = (p0, p1, p2, p3, t) => {
  const t2 = t * t
  const t3 = t2 * t
  return 0.5 * ((2 * p1) + (-p0 + p2) * t + (2 * p0 - 5 * p1 + 4 * p2 - p3) * t2 + (-p0 + 3 * p1 - 3 * p2 + p3) * t3)
}

/**
 * The pose at `time`. Before the first keyframe and after the last, the track holds that pose —
 * a camera is somewhere at every moment, so this never returns null.
 *
 * @param {{keyframes: Array, smooth?: boolean}} track
 * @param {number} time
 */
export const samplePose = (track, time) => {
  const keys = prepare(track)
  if (!keys.length) return { ...DEFAULT_POSE }
  if (keys.length === 1 || time <= keys[0].time) return pickPose(keys[0])
  if (time >= keys[keys.length - 1].time) return pickPose(keys[keys.length - 1])

  let i = 0
  while (i < keys.length - 2 && time > keys[i + 1].time) i++
  const a = keys[i]
  const b = keys[i + 1]
  const span = b.time - a.time
  const raw = span > 0 ? clamp((time - a.time) / span, 0, 1) : 0
  // The easing belongs to the segment being left, so a keyframe can hold a hard cut or a slow start.
  const t = easingFn(a.easing ?? track.easing ?? DEFAULT_EASING)(raw)

  if (!track.smooth || keys.length < 3) {
    return {
      lng: lerp(a.lng, b.lng, t),
      lat: lerp(a.lat, b.lat, t),
      altitude: lerpAltitude(a.altitude, b.altitude, t),
      heading: lerp(a.heading, b.heading, t),
      tilt: lerp(a.tilt, b.tilt, t),
    }
  }

  const p0 = keys[Math.max(0, i - 1)]
  const p3 = keys[Math.min(keys.length - 1, i + 2)]
  return {
    lng: catmullRom(p0.lng, a.lng, b.lng, p3.lng, t),
    lat: catmullRom(p0.lat, a.lat, b.lat, p3.lat, t),
    // Splined in log space for the same reason it is lerped there.
    altitude: Math.exp(catmullRom(...[p0, a, b, p3].map((k) => Math.log(Math.max(1, k.altitude))), t)),
    heading: catmullRom(p0.heading, a.heading, b.heading, p3.heading, t),
    tilt: catmullRom(p0.tilt, a.tilt, b.tilt, p3.tilt, t),
  }
}

const pickPose = (k) => POSE_KEYS.reduce((pose, key) => ({ ...pose, [key]: k[key] }), {})

// ── Editing. Every one of these returns a NEW track; nothing here mutates what it is handed. ──

/** Insert a keyframe, keeping the track in time order. Replaces any keyframe at the same time. */
export const addKeyframe = (track, keyframe) => ({
  ...track,
  keyframes: [...(track?.keyframes ?? []).filter((k) => k.time !== keyframe.time), { ...keyframe }]
    .sort((a, b) => a.time - b.time),
})

export const removeKeyframe = (track, index) => ({
  ...track,
  keyframes: (track?.keyframes ?? []).filter((_, i) => i !== index),
})

export const updateKeyframe = (track, index, patch) => ({
  ...track,
  keyframes: (track?.keyframes ?? []).map((k, i) => (i === index ? { ...k, ...patch } : k)),
})

/** Rescale every keyframe time so the whole move runs for `seconds`. */
export const setDuration = (track, seconds) => {
  const current = trackDuration(track)
  if (!(current > 0) || !(seconds > 0)) return { ...track }
  const scale = seconds / current
  return { ...track, keyframes: (track.keyframes ?? []).map((k) => ({ ...k, time: k.time * scale })) }
}

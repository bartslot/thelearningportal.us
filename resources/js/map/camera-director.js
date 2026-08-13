/**
 * camera-director.js — plays a camera track over a MapLibre map.
 *
 * The track (camera-track.js) knows nothing about MapLibre: it yields a pose, a camera standing at
 * (lng, lat, altitude) looking along a heading at a tilt. This is the piece that lands that pose on
 * a map, and it is the only place that knows a map has a centre and a zoom at all.
 *
 * The conversion is MapLibre's own — `transform.calculateCenterFromCameraLngLatAlt` — so a pose
 * frames the same ground here as it would in any other camera-first tool. Doing the arithmetic by
 * hand (zoom from altitude and pitch) drifts as soon as the projection or the field of view moves.
 *
 * Playback runs on a pausable clock, so a paused lesson does not silently skip the middle of a
 * flight, and `seekTo` is exact while paused — which is what makes a timeline scrub possible.
 */

import { createPausableClock } from '../pausable-clock.js'
import { samplePose, trackDuration } from './camera-track.js'

/** Put a camera pose on the map. Falls back to centre+zoom framing on transforms too old to convert. */
export const applyPose = (map, pose) => {
  const transform = map.transform
  if (transform && typeof transform.calculateCenterFromCameraLngLatAlt === 'function') {
    const { center, zoom } = transform.calculateCenterFromCameraLngLatAlt(
      [pose.lng, pose.lat], pose.altitude, pose.heading, pose.tilt,
    )
    map.jumpTo({ center, zoom, bearing: pose.heading, pitch: pose.tilt })
    return
  }
  map.jumpTo({ center: [pose.lng, pose.lat], bearing: pose.heading, pitch: pose.tilt })
}

/** Read the map's current camera back OUT as a pose, for "add a keyframe here". */
export const poseFromMap = (map) => {
  const transform = map.transform
  const lngLat = typeof transform?.getCameraLngLat === 'function' ? transform.getCameraLngLat() : map.getCenter()
  const altitude = typeof transform?.getCameraAltitude === 'function' ? transform.getCameraAltitude() : 0
  return {
    lng: +lngLat.lng.toFixed(6),
    lat: +lngLat.lat.toFixed(6),
    altitude: Math.round(altitude),
    heading: +map.getBearing().toFixed(2),
    tilt: +map.getPitch().toFixed(2),
  }
}

/**
 * @param {object} map        MapLibre map
 * @param {object} opts
 * @param {object} [opts.track]     initial track ({ keyframes, smooth })
 * @param {Function} [opts.onTick]  called with (timeSeconds, pose) each frame — drives a timeline UI
 * @param {Function} [opts.onEnd]   called once when playback reaches the end (not on loop)
 * @param {boolean} [opts.loop]
 */
export const createCameraDirector = (map, { track = { keyframes: [] }, onTick = null, onEnd = null, loop = false } = {}) => {
  const clock = createPausableClock()
  let current = track
  let raf = null
  let playing = false
  let offset = 0        // seconds already played before the clock was last reset (seek support)

  const duration = () => trackDuration(current)
  const timeNow = () => offset + clock.elapsed() / 1000

  const show = (time) => {
    const pose = samplePose(current, time)
    applyPose(map, pose)
    onTick?.(time, pose)
    return pose
  }

  const frame = () => {
    if (!playing) return
    const time = timeNow()
    const end = duration()
    if (time >= end) {
      if (loop) {
        offset = 0
        clock.reset()
        raf = requestAnimationFrame(frame)
        show(0)
        return
      }
      show(end)
      playing = false
      raf = null
      onEnd?.()
      return
    }
    show(time)
    raf = requestAnimationFrame(frame)
  }

  return {
    get track () { return current },
    setTrack (next) {
      current = next ?? { keyframes: [] }
      if (!playing) show(Math.min(timeNow(), duration()))
    },
    get duration () { return duration() },
    get time () { return Math.min(timeNow(), duration()) },
    get isPlaying () { return playing },

    play () {
      if (playing || duration() <= 0) return
      if (timeNow() >= duration()) offset = 0     // replay from the top rather than sitting at the end
      clock.reset()
      playing = true
      raf = requestAnimationFrame(frame)
    },

    pause () {
      if (!playing) return
      offset = timeNow()
      clock.pause()
      playing = false
      if (raf) { cancelAnimationFrame(raf); raf = null }
    },

    /** Jump to a time and show it. Works while playing or paused — this is the timeline scrub. */
    seekTo (time) {
      const t = Math.max(0, Math.min(time, duration()))
      offset = t
      clock.reset()
      if (!playing) clock.pause()
      return show(t)
    },

    /** Show one pose without touching playback — used by the editor when a keyframe is selected. */
    preview (pose) { applyPose(map, pose) },

    destroy () {
      playing = false
      if (raf) { cancelAnimationFrame(raf); raf = null }
    },
  }
}

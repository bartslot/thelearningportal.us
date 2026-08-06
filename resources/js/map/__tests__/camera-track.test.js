import { describe, it, expect } from 'vitest'
import {
  samplePose, trackDuration, addKeyframe, removeKeyframe, updateKeyframe, setDuration,
  unwrapAngle, lerpAltitude, DEFAULT_POSE,
} from '../camera-track.js'

/**
 * Keyframed camera moves.
 *
 * The three things that are not plain lerps are the ones worth pinning down: longitude and heading
 * wrap, and altitude is logarithmic. Get any of them wrong and the failure is visible but hard to
 * name — a fly-to that crosses the Pacific the long way round, or one that hangs in space and then
 * drops at the end.
 */

const track = (keyframes, extra = {}) => ({ keyframes, ...extra })
const at = (time, pose) => ({ time, ...pose })

describe('unwrapAngle', () => {
  it('takes the short way across the date line', () => {
    expect(unwrapAngle(-170, 170)).toBe(190)     // 20° east, not 340° west
    expect(unwrapAngle(170, -170)).toBe(-190)
  })

  it('leaves an angle already in frame alone', () => {
    expect(unwrapAngle(20, 10)).toBe(20)
    expect(unwrapAngle(-45, 0)).toBe(-45)
  })
})

describe('lerpAltitude', () => {
  it('passes through the geometric mean, so a descent reads as even', () => {
    expect(lerpAltitude(100, 10000, 0.5)).toBeCloseTo(1000, 6)
  })

  it('falls back to linear when an altitude is not positive', () => {
    expect(lerpAltitude(0, 100, 0.5)).toBe(50)
  })
})

describe('samplePose', () => {
  it('returns a default pose for an empty track rather than null', () => {
    expect(samplePose(track([]), 5)).toEqual({ ...DEFAULT_POSE })
  })

  it('hits each keyframe exactly at its own time', () => {
    const t = track([
      at(0, { lng: 4.9, lat: 52.4, altitude: 8000000, heading: 0, tilt: 0 }),
      at(6, { lng: 4.9, lat: 52.4, altitude: 1500, heading: 45, tilt: 65 }),
    ])
    expect(samplePose(t, 0).altitude).toBeCloseTo(8000000, 6)
    const end = samplePose(t, 6)
    expect(end.altitude).toBeCloseTo(1500, 6)
    expect(end.tilt).toBeCloseTo(65, 6)
  })

  it('holds the first and last pose outside the track, so the camera is never nowhere', () => {
    const t = track([at(2, { lng: 10, altitude: 1000 }), at(4, { lng: 20, altitude: 2000 })])
    expect(samplePose(t, 0).lng).toBeCloseTo(10, 6)
    expect(samplePose(t, 99).lng).toBeCloseTo(20, 6)
  })

  it('crosses the date line the short way', () => {
    const t = track([at(0, { lng: 170 }), at(2, { lng: -170 })], { easing: 'linear' })
    // Midway is 180, not 0 — the wrong frame would sail back over Asia.
    expect(Math.abs(samplePose(t, 1).lng)).toBeCloseTo(180, 4)
  })

  it('swings a heading through north instead of all the way round', () => {
    const t = track([at(0, { heading: 350 }), at(2, { heading: 10 })], { easing: 'linear' })
    expect(samplePose(t, 1).heading).toBeCloseTo(360, 4)   // i.e. north
  })

  it('descends through the geometric mean of altitude', () => {
    const t = track([at(0, { altitude: 10000000 }), at(2, { altitude: 1000 })], { easing: 'linear' })
    expect(samplePose(t, 1).altitude).toBeCloseTo(100000, 0)
  })

  it('eases per segment, and the segment being LEFT owns the curve', () => {
    const eased = track([at(0, { lng: 0, easing: 'easeInCubic' }), at(2, { lng: 100 })])
    const linear = track([at(0, { lng: 0, easing: 'linear' }), at(2, { lng: 100 })])
    expect(samplePose(eased, 1).lng).toBeLessThan(samplePose(linear, 1).lng)  // slow start
  })

  it('tolerates keyframes handed over out of order', () => {
    const t = track([at(4, { lng: 40 }), at(0, { lng: 0 }), at(2, { lng: 20 })], { easing: 'linear' })
    expect(samplePose(t, 2).lng).toBeCloseTo(20, 6)
  })

  it('still passes through every keyframe when smoothed', () => {
    const t = track([
      at(0, { lng: 0, lat: 0, altitude: 1e6 }),
      at(2, { lng: 30, lat: 10, altitude: 5e5 }),
      at(4, { lng: 60, lat: 0, altitude: 1e5 }),
    ], { smooth: true })
    const mid = samplePose(t, 2)
    expect(mid.lng).toBeCloseTo(30, 3)
    expect(mid.lat).toBeCloseTo(10, 3)
    expect(mid.altitude).toBeCloseTo(5e5, -2)
  })

  it('smoothing changes the path between keyframes, not the keyframes', () => {
    const keys = [at(0, { lat: 0 }), at(2, { lat: 10 }), at(4, { lat: 0 })]
    const straight = samplePose(track(keys, { easing: 'linear' }), 3).lat
    const smooth = samplePose(track(keys, { smooth: true, easing: 'linear' }), 3).lat
    expect(smooth).not.toBeCloseTo(straight, 3)
  })

  it('survives two keyframes sharing a time without dividing by zero', () => {
    const t = track([at(0, { lng: 0 }), at(0, { lng: 50 }), at(2, { lng: 100 })])
    expect(Number.isFinite(samplePose(t, 0).lng)).toBe(true)
    expect(Number.isFinite(samplePose(t, 1).lng)).toBe(true)
  })
})

describe('trackDuration', () => {
  it('is the last keyframe time', () => {
    expect(trackDuration(track([at(0, {}), at(7.5, {})]))).toBe(7.5)
  })

  it('is zero for an empty track', () => {
    expect(trackDuration(track([]))).toBe(0)
    expect(trackDuration(undefined)).toBe(0)
  })
})

describe('editing', () => {
  it('never mutates the track it is given', () => {
    const original = track([at(0, { lng: 0 })])
    const snapshot = JSON.stringify(original)
    addKeyframe(original, at(2, { lng: 20 }))
    removeKeyframe(original, 0)
    updateKeyframe(original, 0, { lng: 99 })
    setDuration(original, 10)
    expect(JSON.stringify(original)).toBe(snapshot)
  })

  it('keeps keyframes in time order when inserting', () => {
    const t = addKeyframe(track([at(0, {}), at(4, {})]), at(2, { lng: 5 }))
    expect(t.keyframes.map((k) => k.time)).toEqual([0, 2, 4])
  })

  it('replaces a keyframe landing on an existing time instead of stacking one', () => {
    const t = addKeyframe(track([at(0, { lng: 1 }), at(2, { lng: 2 })]), at(2, { lng: 99 }))
    expect(t.keyframes).toHaveLength(2)
    expect(t.keyframes[1].lng).toBe(99)
  })

  it('rescales the whole move to a new duration, keeping relative spacing', () => {
    const t = setDuration(track([at(0, {}), at(2, {}), at(8, {})]), 4)
    expect(t.keyframes.map((k) => k.time)).toEqual([0, 1, 4])
  })

  it('refuses to rescale a track with no length', () => {
    const t = setDuration(track([at(0, {})]), 5)
    expect(t.keyframes.map((k) => k.time)).toEqual([0])
  })
})

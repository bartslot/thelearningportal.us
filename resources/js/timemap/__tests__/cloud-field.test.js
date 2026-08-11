import { describe, it, expect } from 'vitest'
import {
  CLOUD_ALTITUDE_M, CLOUD_FIELD_GLSL, CLOUD_FIELD_UNIFORMS, setCloudFieldUniforms,
} from '../cloud-field.js'

/**
 * Sharing the GLSL only stops two layers sampling the weather differently if they are also handed
 * the same NUMBERS. The deck and the ground both go through this function for exactly that reason,
 * so what it does with a frozen clock, a missing texture or a half-loaded pair is the contract.
 */

const recorder = () => {
  const set = {}
  const bound = []
  const gl = {
    TEXTURE0: 33984, TEXTURE_2D: 3553,
    uniform1f: (name, value) => { set[name] = value },
    uniform1i: (name, value) => { set[name] = value },
    activeTexture: (unit) => bound.push({ unit: unit - 33984 }),
    bindTexture: (_target, texture) => { bound[bound.length - 1].texture = texture },
  }
  return { gl, set, bound }
}

// getUniformLocation in these stubs returns the uniform's name, so locations key by name.
const locations = () => Object.fromEntries(CLOUD_FIELD_UNIFORMS.map((n) => [n, `u_${n}`]))

const state = {
  animate: true, driftRate: 0.0004, windAmount: 1, windScale: 0.06, windRate: 0.05,
}

describe('the cloud field both layers read', () => {
  it('exposes every uniform its GLSL declares, so neither layer can miss one', () => {
    for (const name of CLOUD_FIELD_UNIFORMS) {
      expect(CLOUD_FIELD_GLSL).toContain(`u_${name}`)
    }
  })

  it('binds the field and the wind to the units it was given', () => {
    const { gl, set, bound } = recorder()
    const field = { ready: true, texture: 'field-texture' }
    const wind = { ready: true, texture: 'wind-texture' }
    setCloudFieldUniforms(gl, locations(), state, { seconds: 10, field, wind }, 0, 1)
    expect(bound).toEqual([
      { unit: 0, texture: 'field-texture' },
      { unit: 1, texture: 'wind-texture' },
    ])
    expect(set.u_field).toBe(0)
    expect(set.u_wind).toBe(1)
    expect(set.u_fieldAmount).toBe(1)
    expect(set.u_windAmount).toBe(1)
  })

  it('reports no field and no wind until their textures arrive', () => {
    // The deck falls back to procedural weather and the ground casts no shadow. Both are correct
    // and neither is an error — a missing asset costs the effect, never the map.
    const { gl, set, bound } = recorder()
    setCloudFieldUniforms(gl, locations(), state, { seconds: 10 }, 0, 1)
    expect(set.u_fieldAmount).toBe(0)
    expect(set.u_windAmount).toBe(0)
    expect(bound).toEqual([])
  })

  it('stops the wind when the field has loaded but the wind has not', () => {
    const { gl, set, bound } = recorder()
    setCloudFieldUniforms(
      gl, locations(), state, { seconds: 10, field: { ready: true, texture: 'f' } }, 0, 1,
    )
    expect(set.u_fieldAmount).toBe(1)
    expect(set.u_windAmount).toBe(0)
    expect(bound).toHaveLength(1)
  })

  it('freezes the clock, the drift and the wind together for a reduced-motion caller', () => {
    // Half-freezing is the trap: a still field under a running advection clock still churns, and
    // the per-frame repaint that drives it never stops either.
    const { gl, set } = recorder()
    setCloudFieldUniforms(
      gl, locations(), { ...state, animate: false },
      { seconds: 1234, field: { ready: true, texture: 'f' }, wind: { ready: true, texture: 'w' } },
      0, 1,
    )
    expect(set.u_time).toBe(0)
    expect(set.u_drift).toBe(0)
    expect(set.u_windRate).toBe(0)
  })

  it('drifts the field west to east in proportion to the clock', () => {
    const { gl, set } = recorder()
    setCloudFieldUniforms(gl, locations(), state, { seconds: 500 }, 0, 1)
    expect(set.u_time).toBe(500)
    expect(set.u_drift).toBeCloseTo(500 * state.driftRate, 9)
  })

  it('keeps the deck at the exaggerated height its shadow is thrown from', () => {
    // Real cloud tops out near 12 km, which is invisible on a 6371 km globe. The number is a
    // choice, and the shadow has to be cast from the same one or it separates from the cloud.
    expect(CLOUD_ALTITUDE_M).toBe(90000)
  })
})

import { describe, it, expect } from 'vitest'
import {
  LAYER_CONTROLS,
  REFERENCE_CONTROL,
  readStoredLayers,
  writeStoredLayers,
  optionsFor,
  applyLayerState,
  registerLayerControls,
} from '../layer-controls.js'

/** A localStorage that can be told to fail, the way a browser in private mode does. */
const storageStub = (initial = null, { throws = false } = {}) => {
  let held = initial
  return {
    getItem: () => { if (throws) throw new Error('blocked'); return held },
    setItem: (_k, v) => { if (throws) throw new Error('blocked'); held = v },
    get raw() { return held },
  }
}

describe('the option each layer actually wants', () => {
  it('sends each layer its own name for strength, not a shared one', () => {
    // The whole reason this module exists. Eight branches, five different names; a panel that
    // assumed one of them would silently do nothing to the other seven.
    const byKey = Object.fromEntries(LAYER_CONTROLS.map((c) => [c.key, c.option]))
    expect(byKey.atmosphere).toBe('strength')
    expect(byKey.daylight).toBe('nightDarkness')
    expect(byKey.clouds).toBe('opacity')
    expect(byKey.ocean).toBe('opacity')
    expect(byKey.starfield).toBe('brightness')
    expect(byKey.moon).toBe('brightness')
    expect(byKey.sun).toBe('brightness')
  })

  it('sends zero when hidden, so hiding costs nothing', () => {
    const control = { key: 'clouds', option: 'opacity' }
    expect(optionsFor(control, { visible: false, value: 0.8 })).toEqual({ opacity: 0 })
  })

  it('sends the slider value when visible', () => {
    const control = { key: 'clouds', option: 'opacity' }
    expect(optionsFor(control, { visible: true, value: 0.8 })).toEqual({ opacity: 0.8 })
  })

  it('never sends NaN, which would render as nothing with no error at all', () => {
    const control = { key: 'clouds', option: 'opacity' }
    expect(optionsFor(control, { visible: true, value: 'banana' })).toEqual({ opacity: 0 })
    expect(optionsFor(control, undefined)).toEqual({ opacity: 0 })
  })
})

describe('remembering what was set', () => {
  it('falls back to defaults when nothing is stored', () => {
    const state = readStoredLayers(storageStub(null))
    expect(state.atmosphere).toEqual({ visible: true, value: 1 })
    // Relief ships off: at 8192x4096 it is 171 MiB resident, over the surface budget. See
    // asset-budget.json, where it is declared optional for exactly this reason.
    expect(state.relief).toEqual({ visible: false, value: 0 })
    expect(state.reference).toEqual({ visible: false, value: 0 })
  })

  it('survives a browser that refuses storage rather than taking the panel down with it', () => {
    expect(() => readStoredLayers(storageStub(null, { throws: true }))).not.toThrow()
    expect(() => writeStoredLayers({}, storageStub(null, { throws: true }))).not.toThrow()
    expect(readStoredLayers(storageStub(null, { throws: true })).atmosphere.visible).toBe(true)
  })

  it('ignores a stored value that is not a number, and keeps the default', () => {
    const stored = storageStub(JSON.stringify({ clouds: { visible: true, value: null } }))
    expect(readStoredLayers(stored).clouds.value).toBe(1)
  })

  it('ignores keys for layers that do not exist', () => {
    const stored = storageStub(JSON.stringify({ nonsense: { visible: true, value: 1 } }))
    expect(readStoredLayers(stored).nonsense).toBeUndefined()
  })

  it('round-trips through storage', () => {
    const store = storageStub(null)
    writeStoredLayers({ clouds: { visible: false, value: 0.25 } }, store)
    expect(readStoredLayers(store).clouds).toEqual({ visible: false, value: 0.25 })
  })
})

describe('pushing the state onto whatever has merged', () => {
  const recorder = () => {
    const calls = []
    return { layer: { setOptions: (o) => calls.push(o) }, calls }
  }

  it('skips a layer whose branch has not merged instead of throwing', () => {
    // A panel row only appears for a layer that landed, but the state map outlives any one branch.
    const clouds = recorder()
    const applied = applyLayerState({ clouds: clouds.layer }, readStoredLayers(storageStub(null)))
    expect(applied).toEqual(['clouds'])
    expect(clouds.calls).toEqual([{ opacity: 1 }])
  })

  it('drives relief through the daylight layer, because it is a term in that shader', () => {
    const daylight = recorder()
    applyLayerState({ daylight: daylight.layer }, {
      daylight: { visible: true, value: 0.85 },
      relief: { visible: true, value: 1.5 },
    })
    expect(daylight.calls).toEqual([{ nightDarkness: 0.85 }, { reliefPower: 1.5 }])
  })

  it('gives the panel exactly one way in, whatever the layer calls its strength', () => {
    // The panel sends a key and a number. Everything about which option that is stays on this side,
    // which is the point: two routes to the same effect is how a control ends up driving one thing
    // while the map listens to another.
    const clouds = recorder()
    const atmosphere = recorder()
    const target = {}
    registerLayerControls({ clouds: clouds.layer, atmosphere: atmosphere.layer }, { target })

    target.__tmSetLayer('clouds', 0.4)
    target.__tmSetLayer('atmosphere', 0.9)
    expect(clouds.calls).toEqual([{ opacity: 0.4 }])
    expect(atmosphere.calls).toEqual([{ strength: 0.9 }])
  })

  it('routes the reference overlay to its own handler, not to a layer', () => {
    const seen = []
    const target = {}
    registerLayerControls({}, { target, onReference: (v) => seen.push(v) })
    target.__tmSetLayer('reference', 0.6)
    expect(seen).toEqual([0.6])
  })

  it('shrugs off a key for a layer that has not merged', () => {
    const target = {}
    registerLayerControls({}, { target })
    expect(() => target.__tmSetLayer('ocean', 1)).not.toThrow()
    expect(() => target.__tmSetLayer('nonsense', 1)).not.toThrow()
  })

  it('tears the setter down, so a replaced map leaves nothing pointing at a dead context', () => {
    const target = {}
    const teardown = registerLayerControls({}, { target })
    expect(typeof target.__tmSetLayer).toBe('function')
    teardown()
    expect(target.__tmSetLayer).toBeUndefined()
  })

  it('leaves the reference overlay alone — it is not a globe layer', () => {
    // It is an image over the canvas. If it ever reached a setOptions call, something has gone
    // wrong in a way that would be very hard to see.
    expect(LAYER_CONTROLS.some((c) => c.key === REFERENCE_CONTROL.key)).toBe(false)
    const applied = applyLayerState({ reference: recorder().layer }, readStoredLayers(storageStub(null)))
    expect(applied).not.toContain('reference')
  })
})

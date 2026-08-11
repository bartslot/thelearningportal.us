/**
 * layer-controls.js — one place that knows what the globe's layers are called and how to dim them.
 *
 * Eight sessions built these layers on eight branches, and each one names its own strength
 * differently: the haze band calls it `strength`, the night shell `nightDarkness`, the sky, the moon
 * and the sun all say `brightness`, and the clouds and the sea say `opacity`. That is not worth
 * renaming across eight files — the names are right in their own context — but it does mean a
 * panel with eight sliders on it would otherwise carry eight special cases.
 *
 * So the mapping lives here, once, and both the panel and any other caller drive layers through it.
 *
 * ONE SCALAR PER LAYER, doing double duty. Every one of these layers already treats zero as "draw
 * nothing and cost nothing" — that contract is asserted for all of them in globe-layers.test.js
 * ("draws nothing when switched off, so hiding it is free"). So a visibility toggle is not a
 * separate concept needing separate plumbing: hidden means send 0, visible means send the slider's
 * value. The slider's own value is kept while hidden, so unhiding restores what you had rather than
 * jumping to a default.
 */

/**
 * The layers a panel may offer, in the order they should be listed: sky first, then light, then
 * the things that sit on the planet. A layer whose module has not merged simply has no entry.
 *
 * `option` is the key that layer's `setOptions` expects. `max` is the top of its useful range,
 * which is not 1 for all of them — the relief term is a power and reads well past 1.
 */
export const LAYER_CONTROLS = [
  { key: 'starfield', option: 'brightness', label: 'Stars', default: 0.5, max: 1 },
  { key: 'sun', option: 'brightness', label: 'Sun', default: 1, max: 1 },
  { key: 'moon', option: 'brightness', label: 'Moon', default: 1, max: 1 },
  { key: 'daylight', option: 'nightDarkness', label: 'Day and night', default: 0.85, max: 1 },
  { key: 'atmosphere', option: 'strength', label: 'Atmosphere', default: 1, max: 1 },
  { key: 'clouds', option: 'opacity', label: 'Clouds', default: 1, max: 1 },
  { key: 'ocean', option: 'opacity', label: 'Ocean', default: 1, max: 1 },
  { key: 'relief', option: 'reliefPower', label: 'Relief', default: 0, max: 2, on: 'daylight' },
]

/**
 * The reference photograph, as a foreground overlay.
 *
 * Not a globe layer at all — it is an image over the top of everything, so the render can be
 * dissolved against the thing it is trying to match. It is the acceptance tool for the whole
 * programme: "the night side is too bright" is an argument, and a slider that cross-fades to the
 * NASA frame is not.
 */
export const REFERENCE_CONTROL = { key: 'reference', label: 'NASA reference', default: 0, max: 1 }

const STORAGE_KEY = 'tm-layers'

/** Every control that has a stored value, defaults filled in. Never throws — private mode blocks storage. */
export const readStoredLayers = (storage = globalThis.localStorage) => {
  const defaults = {}
  for (const control of LAYER_CONTROLS) {
    defaults[control.key] = { visible: control.default > 0, value: control.default }
  }
  defaults[REFERENCE_CONTROL.key] = { visible: false, value: REFERENCE_CONTROL.default }

  try {
    const saved = JSON.parse(storage?.getItem(STORAGE_KEY) || '{}')
    for (const [key, entry] of Object.entries(saved)) {
      if (!defaults[key] || !entry || typeof entry !== 'object') continue
      // A stored file is untrusted input like any other: a hand-edited or half-written value must
      // not be able to push a uniform to NaN, which renders as nothing at all with no error.
      //
      // The type check has to come BEFORE the coercion, not instead of it. `Number(null)` is 0 and
      // `Number('')` is 0 — both finite, both perfectly happy — so a null written by a half-failed
      // save would have come back as a silently switched-off layer rather than as the default.
      const stored = entry.value
      defaults[key] = {
        visible: entry.visible === true,
        value: typeof stored === 'number' && Number.isFinite(stored) ? stored : defaults[key].value,
      }
    }
  } catch (_) { /* unreadable or blocked — the defaults stand */ }
  return defaults
}

/** Persist, quietly. A browser that refuses storage must not break the panel. */
export const writeStoredLayers = (layers, storage = globalThis.localStorage) => {
  try { storage?.setItem(STORAGE_KEY, JSON.stringify(layers)) } catch (_) { /* private mode */ }
}

/**
 * What to send a layer's `setOptions` for a given panel state.
 *
 * Hidden sends 0 rather than skipping the call: a layer left at its old value would keep drawing,
 * and "hidden" that still draws is the bug this indirection exists to make impossible.
 */
export const optionsFor = (control, state) => {
  const value = state?.visible ? Number(state.value) : 0
  return { [control.option]: Number.isFinite(value) ? value : 0 }
}

/**
 * Push every control's state onto the layers that are actually mounted.
 *
 * `layers` is a plain map of key to layer instance; a key with no layer is skipped rather than
 * throwing, because which layers exist depends on which branches have merged. `relief` rides on the
 * daylight layer (see `on`), since it is a term in that shader rather than a layer of its own.
 */
export const applyLayerState = (layers, state) => {
  const applied = []
  for (const control of LAYER_CONTROLS) {
    const layer = layers?.[control.on || control.key]
    if (!layer || typeof layer.setOptions !== 'function') continue
    layer.setOptions(optionsFor(control, state?.[control.key]))
    applied.push(control.key)
  }
  return applied
}

/**
 * Install the one function the layers panel calls: `window.__tmSetLayer(key, value)`.
 *
 * ONE PATH, deliberately. The icons work already learned this the expensive way — several routes
 * to the same effect drift apart and the panel ends up driving one of them while the map listens to
 * another. So the panel knows a key and a number and nothing else, and everything about which
 * layer that is and what its option is called stays on this side.
 *
 * `onReference` handles the NASA overlay, which is an image over the canvas rather than a layer;
 * it is passed in because only the page that owns the canvas knows where to put it.
 *
 * Returns a teardown, so a map that is replaced on an SPA navigation does not leave a setter behind
 * pointing at a dead GL context.
 */
export const registerLayerControls = (layers, { onReference = null, target = globalThis } = {}) => {
  const byKey = new Map(LAYER_CONTROLS.map((control) => [control.key, control]))

  target.__tmSetLayer = (key, value) => {
    const amount = Number.isFinite(Number(value)) ? Number(value) : 0
    if (key === REFERENCE_CONTROL.key) return onReference?.(amount)
    const control = byKey.get(key)
    if (!control) return undefined
    const layer = layers?.[control.on || control.key]
    if (!layer || typeof layer.setOptions !== 'function') return undefined
    return layer.setOptions({ [control.option]: amount })
  }

  return () => { if (target.__tmSetLayer) delete target.__tmSetLayer }
}

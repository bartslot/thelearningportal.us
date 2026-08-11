/**
 * map-globe-layers.js — the globe layers, attached to a lesson's map.
 *
 * Eight branches built these: the sea, the haze band, the terminator, the star sky, the sun, the
 * moon, the cloud deck. Every one of them is a MapLibre custom layer, every one is merged, and
 * until this file existed **not one of them was imported by anything.** A reachability walk from
 * all eight Vite entry points found 25 of the 36 modules under timemap/ unreachable, and the built
 * bundles agreed: `tm-clouds` appeared in two assets and `tm-ocean`, `tm-atmosphere`, `tm-daylight`
 * and `tm-starfield` in none. The atmosphere you could see on the Time-Map was MapLibre's own.
 *
 * So this is the wire, not a rewrite. The factories were always designed to attach to any map.
 *
 * It lives beside lesson-map.js rather than under timemap/ because the lesson map is now the
 * consumer; the Time-Map can import the same function when someone wires it there too. ONE place
 * that knows the assembly, for the reason layer-controls.js gives about the panel: several routes
 * to the same effect drift apart, and then the panel drives one and the map listens to another.
 */
import { createStarfieldLayer, STARFIELD_LAYER_ID } from './timemap/starfield.js'
import { createSunLayer, SUN_LAYER_ID } from './timemap/sun-disc.js'
import { createMoonLayer, MOON_LAYER_ID } from './timemap/moon.js'
import { createDaylightLayer, DAYLIGHT_LAYER_ID } from './timemap/daylight.js'
import { createAtmosphereLayer, ATMOSPHERE_LAYER_ID } from './timemap/atmosphere.js'
import { createCloudLayer, CLOUD_LAYER_ID } from './timemap/clouds.js'
import { createOceanWaterLayer, OCEAN_LAYER_ID } from './timemap/ocean-water.js'
import { sunDirection } from './timemap/sun.js'
import { registerLayerControls, readStoredLayers, applyLayerState } from './timemap/layer-controls.js'

/** The cloud field and its wind, already sitting in public/img/map/. Without them the deck runs on
 *  noise banded by latitude — weather-shaped, with no actual weather in it. */
const CLOUD_FIELD_URL = '/img/map/clouds-field.webp'
const WIND_FIELD_URL = '/img/map/wind-field.png'

/** '#1d5c8f' → [0.11, 0.36, 0.56]. The shaders take colours as 0..1 triples; the picker gives hex. */
const hexToRgb = (hex) => {
  const n = parseInt(String(hex).replace('#', ''), 16)
  return [(n >> 16 & 255) / 255, (n >> 8 & 255) / 255, (n & 255) / 255]
}

/**
 * Draw order, back to front.
 *
 * The sky bodies go down first so the planet's own opaque pixels occlude them — moon.js relies on
 * exactly that ("a moon behind the planet projects behind the planet's own opaque pixels"). Then
 * the sea, which is ground. Then DAYLIGHT, which shades the ground — including the shadows the
 * clouds throw down onto it. Then the CLOUDS, whose tops are lit and belong above their own
 * shadows. Then the haze, last, because it is the limb seen through everything else.
 *
 * CLOUDS AFTER DAYLIGHT, and that pair is load-bearing. It was the other way round first, so the
 * daylight shell — a darkening pass — was painted over the deck, and turning cloud shadows up
 * dimmed the clouds themselves instead of the ground beneath them. Reported as "it shouldn't darken
 * the clouds, just what's underneath — if the top deck is lit up it should be white", which is both
 * correct and exactly what the wrong order does. The shader was never at fault: it walks from a
 * patch of ground toward the sun and samples the deck above it, so it is a GROUND term, and a
 * ground term drawn on top of the sky is a contradiction.
 *
 * The rest of the order is still reasoned from each layer's own notes rather than inherited —
 * nothing had ever composited these seven together — so it stays the first thing to suspect.
 */
const STACK = [
  ['starfield', STARFIELD_LAYER_ID, (o) => createStarfieldLayer(o)],
  ['sun', SUN_LAYER_ID, (o) => createSunLayer(o)],
  ['moon', MOON_LAYER_ID, (o) => createMoonLayer(o)],
  ['ocean', OCEAN_LAYER_ID, (o) => createOceanWaterLayer(o)],
  ['daylight', DAYLIGHT_LAYER_ID, (o) => createDaylightLayer(o)],
  ['clouds', CLOUD_LAYER_ID, (o) => createCloudLayer(o)],
  ['atmosphere', ATMOSPHERE_LAYER_ID, (o) => createAtmosphereLayer(o)],
]

/**
 * Add every globe layer to `map` and hand the panel a way to drive them.
 *
 * @param {object}  map                   a live MapLibre map
 * @param {object}  [opts]
 * @param {Date}    [opts.date]           when the scene is set — decides where the sun is, so it
 *                                        decides which half of the planet is in daylight
 * @param {boolean} [opts.reduceMotion]   drop the per-frame drift (and the repaint that drives it)
 * @param {string}  [opts.beforeId]       insert beneath this layer, e.g. to keep labels on top
 * @returns {{layers: object, remove: function}} teardown included: a style change replaces the map,
 *          and a setter left pointing at a dead GL context is how the panel starts throwing.
 */
export const addGlobeLayers = (map, { date = new Date(), reduceMotion = false, beforeId } = {}) => {
  const sun = sunDirection(date)
  const animate = !reduceMotion

  // Shared by the clouds and the shadows they cast. daylight.js is explicit that both layers must be
  // given the SAME field, or the shadows drift away from the clouds casting them.
  // windAmount 0.2, not the module's 1: full advection drags the field into visible spirals once
  // you are close enough to see individual cells, which reads as a swirl filter rather than as
  // weather. Enough to carry the deck along real circulation, not enough to draw with it. The 0.2
  // is off the tuner — I had guessed 0.35 and it was still too busy.
  const field = { fieldUrl: CLOUD_FIELD_URL, windUrl: WIND_FIELD_URL, windAmount: 0.2, animate }

  const options = {
    starfield: { date, animate },
    sun: { date },
    moon: { date, sun },
    /**
     * A ROUGH sea, not a polished one.
     *
     * The module's own defaults (roughness 0.55, strength 0.9) put a tight white highlight on the
     * water — a single mirror rather than an ocean, which reads as glass or plastic. Real sun
     * glitter spreads over several degrees, because the sea is millions of wave facets tilted
     * every which way and only a scattered few point the sun at your eye at any moment. So the
     * slope distribution goes wide and the peak comes down: the same light, spread out.
     *
     * Set here rather than in ocean-water.js because this is the house look, not a correction to
     * its physics — another consumer may want a glassier sea, and the module still offers one.
     *
     * These numbers came off the tuner rather than out of a guess: Bart dragged them and sent the
     * values back (roughness 0.95, glint 0.48, patchiness 0.6, shore 19.5 km). The shore falloff in
     * particular is far softer than the 2.45 km floor the data can justify — that floor stops the
     * SDF's texels showing as a staircase, and this is a deliberate look on top of it.
     */
    ocean: { sun, roughness: 0.95, strength: 0.48, windPatch: 0.6, shoreSoftnessKm: 19.5 },
    clouds: { ...field, sun },
    daylight: { ...field, sun, date },
    // Haze at 1.25 rather than the module's 1 — off the tuner, same session as the sea values.
    atmosphere: { sun, strength: 1.25 },
  }

  const layers = {}
  for (const [key, id, create] of STACK) {
    if (map.getLayer(id)) map.removeLayer(id)
    const layer = create(options[key])
    // beforeId only applies where it exists; MapLibre throws on an unknown id rather than ignoring it.
    map.addLayer(layer, beforeId && map.getLayer(beforeId) ? beforeId : undefined)
    layers[key] = layer
  }

  // Restore the teacher's own slider positions before the panel has said anything, so a reload does
  // not silently reset the map to defaults while the panel still shows what they chose.
  applyLayerState(layers, readStoredLayers())

  const teardown = registerLayerControls(layers)

  /**
   * The knobs that are NOT on the Globe layers panel.
   *
   * That panel is a teacher-facing control for whether a layer shows and how strongly. These are
   * the constants underneath it — the ones tuned by rebuilding and squinting, which is exactly the
   * loop the tuner exists to end. Registered only while these layers are mounted, so they appear on
   * a map and nowhere else. Option names are the layers' own; nothing is invented here.
   */
  const set = (layer, patch) => layers[layer]?.setOptions?.(patch)
  const untune = [
    window.__tune?.register('Ocean', [
      { key: 'shoreSoftnessKm', label: 'Shore falloff', min: 0, max: 25, step: 0.25, value: 19.5,
        apply: (v) => set('ocean', { shoreSoftnessKm: v }) },
      { key: 'roughness', label: 'Roughness', min: 0, max: 1, step: 0.01, value: 0.95,
        apply: (v) => set('ocean', { roughness: v }) },
      { key: 'strength', label: 'Sun glint', min: 0, max: 1, step: 0.01, value: 0.48,
        apply: (v) => set('ocean', { strength: v }) },
      { key: 'windPatch', label: 'Patchiness', min: 0, max: 1, step: 0.01, value: 0.6,
        apply: (v) => set('ocean', { windPatch: v }) },
      // Cells of weather per unit sphere — the size of the wave-slope patches the glitter rides on.
      { key: 'windScale', label: 'Wave scale', min: 1, max: 60, step: 1, value: 14,
        apply: (v) => set('ocean', { windScale: v }) },
      { key: 'water', label: 'Tint depth', min: 0, max: 2, step: 0.05, value: 1,
        apply: (v) => set('ocean', { water: v }) },
      { key: 'scatter', label: 'Tint colour', type: 'color', value: '#1d5c8f',
        apply: (v) => set('ocean', { scatter: hexToRgb(v) }) },
      { key: 'opacity', label: 'Opacity', min: 0, max: 1, step: 0.01, value: 1,
        apply: (v) => set('ocean', { opacity: v }) },
    ], { tab: 'Earth' }),

    window.__tune?.register('Clouds', [
      { key: 'opacity', label: 'Density', min: 0, max: 1, step: 0.01, value: 1,
        apply: (v) => set('clouds', { opacity: v }) },
      { key: 'windAmount', label: 'Drift', min: 0, max: 1, step: 0.05, value: 0.2,
        apply: (v) => set('clouds', { windAmount: v }) },
      { key: 'windScale', label: 'Wind scale', min: 0.01, max: 0.5, step: 0.01, value: 0.06,
        apply: (v) => set('clouds', { windScale: v }) },
      { key: 'windRate', label: 'Wind speed', min: 0, max: 0.3, step: 0.005, value: 0.05,
        apply: (v) => set('clouds', { windRate: v }) },
      { key: 'cloudShadow', label: 'Shadows', min: 0, max: 1, step: 0.05, value: 0.5,
        apply: (v) => set('daylight', { cloudShadow: v }) },
    ], { tab: 'Earth' }),

    window.__tune?.register('Sky and light', [
      { key: 'nightDarkness', label: 'Night', min: 0, max: 1, step: 0.005, value: 0.965,
        apply: (v) => set('daylight', { nightDarkness: v }) },
      // Twilight is two colours on opposite sides of the terminator — warm sunward, blue nightward
      // (the blue hour). Both are here because judging one without the other is what produced the
      // orange stripe: a warm edge with no cool margin beside it reads as a decal.
      { key: 'twilightColour', label: 'Twilight warm', type: 'color', value: '#9e5229',
        apply: (v) => set('daylight', { twilightColour: hexToRgb(v) }) },
      { key: 'twilightCool', label: 'Twilight blue', type: 'color', value: '#1a2647',
        apply: (v) => set('daylight', { twilightCool: hexToRgb(v) }) },
      { key: 'twilightStrength', label: 'Twilight strength', min: 0, max: 1, step: 0.05, value: 0.55,
        apply: (v) => set('daylight', { twilightStrength: v }) },
      { key: 'atmosphere', label: 'Haze', min: 0, max: 2, step: 0.05, value: 1.25,
        apply: (v) => set('atmosphere', { strength: v }) },
      { key: 'starfield', label: 'Stars', min: 0, max: 1, step: 0.05, value: 0.5,
        apply: (v) => set('starfield', { brightness: v }) },
      { key: 'sun', label: 'Sun', min: 0, max: 2, step: 0.05, value: 1,
        apply: (v) => set('sun', { brightness: v }) },
      { key: 'moon', label: 'Moon', min: 0, max: 2, step: 0.05, value: 1,
        apply: (v) => set('moon', { brightness: v }) },
    ], { tab: 'Earth' }),
  ]

  return {
    layers,

    /**
     * Move the light. The Time-Map's slider runs across centuries, so the sun has to follow it or
     * the terminator sits where it was when the page loaded and quietly contradicts the date on
     * screen. Every layer merges what it is given and repaints, so this is one pass over the stack.
     */
    setDate(next) {
      const at = next instanceof Date ? next : new Date(next)
      if (Number.isNaN(at.getTime())) return
      const lit = sunDirection(at)
      for (const key of Object.keys(layers)) {
        layers[key]?.setOptions?.({ date: at, sun: lit })
      }
    },

    remove() {
      teardown?.()
      // Unregister the knobs with the layers: a slider still listed after its map has gone would
      // apply to a dead GL context, which is the same class of bug as a setter left behind.
      for (const off of untune) off?.()
      for (const [, id] of STACK) if (map.getLayer(id)) map.removeLayer(id)
    },
  }
}

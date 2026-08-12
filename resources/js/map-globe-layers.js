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
import { parseColour } from './dev/tuner.js'

/** The cloud field and its wind, already sitting in public/img/map/. Without them the deck runs on
 *  noise banded by latitude — weather-shaped, with no actual weather in it. */
const CLOUD_FIELD_URL = '/img/map/clouds-field.webp'
const WIND_FIELD_URL = '/img/map/wind-field.png'

/**
 * The deck's real source: six patches of MODIS true-colour cloud over open ocean, reduced to
 * coverage and packed into one atlas. 611 m per texel against the whole-planet field's 19543, and
 * laid down stochastically so no repeat shows. Built by scripts/build-cloud-patches.mjs and
 * scripts/build-cloud-atlas.mjs; credited in docs/credits.md, as GIBS asks.
 *
 * Both are passed. The atlas is the source wherever it has loaded, and the old field is what the
 * deck falls back to if it has not — the same degradation the field itself has to procedural noise.
 */
const CLOUD_PATCH_URL = '/img/map/cloud-patches.webp'

/**
 * '#1d5c8f' → [0.11, 0.36, 0.56]. The shaders take colours as 0..1 triples.
 *
 * Goes through the tuner's parser rather than doing its own hex arithmetic, because the colour
 * field emits `rgba(...)` once its alpha drops below 1 and a bare parseInt would read that as NaN
 * — a silently black layer. Alpha is dropped here on purpose: these are vec3 uniforms, and each
 * layer already has its own strength or opacity control that means something more specific.
 */
const hexToRgb = (value) => parseColour(value).rgb.map((c) => c / 255)

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

  /**
   * Shared by the clouds and the shadows they cast. daylight.js is explicit that both layers must
   * be given the SAME field, or the shadows drift away from the clouds casting them.
   *
   * windAmount 0.2 — BART'S VALUE, kept, after this file argued itself out of 0.1.
   *
   * The argument for cutting it was that advection costs sharpness, measured in pixel-scale
   * structure because brightness is blind to smearing. At the time that was true and the price was
   * steep: 0.2 spent 6.9% of the deck's fine structure, against a source eight times finer than the
   * one the number was chosen for.
   *
   * Then the wind stopped carrying the detail. Advection now warps only the smooth 19.5 km field —
   * it had to, because warping the tiled atlas stretched its lattice into streaks — and the cost it
   * was being cut for went with it:
   *
   *     windAmount   0      0.1    0.2    0.35   0.6    1.0
   *     detail       9.119  9.103  9.015  8.947  8.658  8.283
   *
   * 1.1% at 0.2, where it used to be 6.9%. So 0.1 would now be a value compensating for a cause that
   * no longer exists — which is precisely the fault this whole piece of work was sent to correct in
   * four other numbers, arrived at from the other direction. Bart tuned 0.2 and 0.2 is fine.
   *
   * Worth keeping about the knob itself: the cost no longer peaks in the middle, because the double
   * image it used to produce was the tiled detail being cross-faded against itself.
   */
  const field = {
    fieldUrl: CLOUD_FIELD_URL,
    patchUrl: CLOUD_PATCH_URL,
    windUrl: WIND_FIELD_URL,
    windAmount: 0.2,
    animate,
  }

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
      // "Drift" is the wrong word for it and always was: this DEFORMS the field. The real drift is
      // driftRate, which rotates it. Renamed rather than left to mislead the next person to drag it.
      { key: 'windAmount', label: 'Curl', min: 0, max: 1, step: 0.05, value: 0.2,
        apply: (v) => set('clouds', { windAmount: v }) },
      { key: 'windScale', label: 'Wind scale', min: 0.01, max: 0.5, step: 0.01, value: 0.06,
        apply: (v) => set('clouds', { windScale: v }) },
      { key: 'windRate', label: 'Wind speed', min: 0, max: 0.3, step: 0.005, value: 0.05,
        apply: (v) => set('clouds', { windRate: v }) },
      { key: 'cloudShadow', label: 'Shadows', min: 0, max: 1, step: 0.01, value: 0.5,
        apply: (v) => set('daylight', { cloudShadow: v }) },
      // Both readers, always. A tiling knob moved on the deck alone would leave the ground casting
      // the shadow of a sky nobody is drawing, and the drift between them looks like a bug in the
      // shadows rather than like two settings that disagree.
      { key: 'patchTiles', label: 'Cloud cells', min: 96, max: 320, step: 8, value: 144,
        apply: (v) => { set('clouds', { patchTiles: v }); set('daylight', { patchTiles: v }) } },
      // The atlas's own mean coverage, which the variance-preserving blend centres on. Wrong here
      // and the lattice reappears as a faint brightness pattern — which is the one thing worth
      // being able to check by eye, hence a control rather than a constant alone.
      { key: 'patchMean', label: 'Atlas mean', min: 0.2, max: 0.8, step: 0.005, value: 0.4359,
        apply: (v) => { set('clouds', { patchMean: v }); set('daylight', { patchMean: v }) } },
      // How much patch variation rides on the global distribution. At 0 the deck is the old 19.5 km
      // field again — which is the A/B for whether the detail is worth having at all.
      { key: 'patchDetail', label: 'Detail amount', min: 0, max: 1.5, step: 0.05, value: 0.75,
        apply: (v) => { set('clouds', { patchDetail: v }); set('daylight', { patchDetail: v }) } },
      // Off returns the deck to the 19.5 km whole-planet field, which is the A/B this change exists
      // to win. Kept because "is the new source actually better" should be one click, not a rebuild.
      { key: 'patchUrl', label: 'Tiled source', type: 'boolean', value: true,
        apply: (v) => {
          const url = v ? CLOUD_PATCH_URL : null
          set('clouds', { patchUrl: url })
          set('daylight', { patchUrl: url })
        } },
    ], { tab: 'Earth' }),

    /**
     * The four lighting terms, in the order they have to be built — each one alone looks wrong.
     * The normal by itself is grey terrain; the normal with Beer-Powder is cloud; the forward lobe
     * on top of that is what makes it look photographed.
     *
     * All four are zero-is-off, so any of them can be taken out on the panel and the deck returns
     * to exactly the flat white shell it was before. That is what makes an A/B here worth anything.
     */
    window.__tune?.register('Cloud light', [
      // Cloud coverage is a heightfield, and this is how tall the fully covered sky stands. A real
      // cumulus tops out around 8 km, which is where it sits.
      // Applied to the 19.5 km field rather than to the atlas, so it is a height on a SMOOTHED
      // heightfield and runs larger than a real cloud. See clouds.js for why the atlas cannot be
      // used here.
      { key: 'cloudRelief', label: 'Cloud height km', min: 0, max: 120, step: 2, value: 90,
        apply: (v) => set('clouds', { cloudRelief: v }) },
      { key: 'cloudDepth', label: 'Optical depth', min: 0, max: 12, step: 0.25, value: 4,
        apply: (v) => set('clouds', { cloudDepth: v }) },
      // Thin edges darker than the plain exponential says. Take it to 0 and the rims fade out
      // linearly, which is what makes a cloud read as painted-on fog.
      { key: 'powder', label: 'Powder', min: 0, max: 1, step: 0.05, value: 1,
        apply: (v) => set('clouds', { powder: v }) },
      { key: 'forward', label: 'Silver lining', min: 0, max: 2, step: 0.05, value: 0.5,
        apply: (v) => set('clouds', { forward: v }) },
      { key: 'forwardG', label: 'Lobe sharpness', min: 0, max: 0.95, step: 0.05, value: 0.7,
        apply: (v) => set('clouds', { forwardG: v }) },
      { key: 'selfShadow', label: 'Self shadow', min: 0, max: 1, step: 0.02, value: 0.18,
        apply: (v) => set('clouds', { selfShadow: v }) },
      // How far the shadow read walks sunward. Too far and it stops being a shadow and becomes an
      // even dimmer — measured at 25 km, where it took a quarter of the deck's brightness flat.
      { key: 'selfShadowStep', label: 'Shadow reach', min: 0.0002, max: 0.006, step: 0.0002,
        value: 0.0015,
        apply: (v) => set('clouds', { selfShadowStep: v }) },
    ], { tab: 'Earth' }),

    window.__tune?.register('Sky and light', [
      /**
       * LEFT AT 0.965, and left deliberately rather than settled.
       *
       * It belongs with the three values resettled in this change: it was chosen while the draw
       * order had the clouds shadowing themselves, which is fixed, so the picture it was judged
       * against no longer exists. The other three moved on measurements — the shadow dial on 40.91
       * against 53.86, the curl on detail per setting, the noise ceiling on a source-derived
       * crossover. There is no equivalent measurement for how black a night side should be. It is a
       * look, and it is Bart's to set.
       *
       * Moving it on taste and calling that a resettlement would put a number in the code with no
       * reason attached, which is the thing every comment in this file is trying to prevent. The
       * control is here and the reason to revisit it is written down; the drag is his.
       */
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

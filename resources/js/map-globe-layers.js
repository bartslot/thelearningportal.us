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

/**
 * Draw order, back to front.
 *
 * The sky bodies go down first so the planet's own opaque pixels occlude them — moon.js relies on
 * exactly that ("a moon behind the planet projects behind the planet's own opaque pixels"). Then
 * the sea, which is ground; then the clouds above it; then the terminator, which has to darken
 * everything already drawn; and the haze last, because it is the limb seen through all of it.
 *
 * NOT INHERITED FROM A WORKING SETUP — nothing ever composited these seven together, so this order
 * is reasoned from each layer's own notes rather than copied from something known good. It is the
 * first thing to suspect if the render looks wrong.
 */
const STACK = [
  ['starfield', STARFIELD_LAYER_ID, (o) => createStarfieldLayer(o)],
  ['sun', SUN_LAYER_ID, (o) => createSunLayer(o)],
  ['moon', MOON_LAYER_ID, (o) => createMoonLayer(o)],
  ['ocean', OCEAN_LAYER_ID, (o) => createOceanWaterLayer(o)],
  ['clouds', CLOUD_LAYER_ID, (o) => createCloudLayer(o)],
  ['daylight', DAYLIGHT_LAYER_ID, (o) => createDaylightLayer(o)],
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
  // windAmount 0.35, not the module's 1: full advection drags the field into visible spirals once
  // you are close enough to see individual cells, which reads as a swirl filter rather than as
  // weather. Enough to carry the deck along real circulation, not enough to draw with it.
  const field = { fieldUrl: CLOUD_FIELD_URL, windUrl: WIND_FIELD_URL, windAmount: 0.35, animate }

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
     */
    ocean: { sun, roughness: 0.9, strength: 0.45, windPatch: 0.6 },
    clouds: { ...field, sun },
    daylight: { ...field, sun, date },
    atmosphere: { sun },
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
      for (const [, id] of STACK) if (map.getLayer(id)) map.removeLayer(id)
    },
  }
}

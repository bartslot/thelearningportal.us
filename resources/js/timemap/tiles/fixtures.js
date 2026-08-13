/**
 * fixtures.js — terrain with a known answer, for measurement rigs that must not touch the network.
 *
 * A rig that measures shading against live AWS tiles measures the network too: a slow tile makes a
 * frame darker, a 503 makes it flat, and the number moves for reasons that have nothing to do with
 * the code under test. A flaky measurement suite is worse than none here, because measurements are
 * the only thing standing between this programme and plausible-looking wrong answers.
 *
 * So these plug into `createTilePyramid`'s `fetch` and `decode` hooks and generate tiles from their
 * own z/x/y. Nothing is fetched, nothing is cached between runs, and the same camera gives the same
 * pixels on every machine.
 *
 * THE SECOND REASON THEY EXIST IS ABSOLUTE ANCHORS. Real terrain can only be checked against itself
 * — is this ridge sharper than that one — and a whole relief term that is uniformly too strong, or
 * lit from a mirrored direction, passes every comparison of that kind. These surfaces have a normal
 * in closed form, so a rig can assert what a pixel SHOULD be rather than that it changed.
 */

import { MERCATOR_CIRCUMFERENCE_M, tileMercatorBounds } from './scheme.js'
import { heightTextureFromHeights } from './sources.js'
import { latFromMercatorY } from '../planet-mesh.js'

/**
 * Turn a height function into the `fetch` and `decode` a pyramid needs.
 *
 * `heightAt(mercatorX, mercatorY)` returns metres. It is sampled per texel, so the same ground
 * gives the same height at every level and tiles agree across their edges by construction — which
 * is what makes these usable for seam tests as well as for shading ones.
 *
 * @param {Function} heightAt
 * @param {object} [opts]
 * @param {number} [opts.tileSize]
 * @returns {{fetch: Function, decode: Function}} pass straight to createTilePyramid
 */
export const syntheticTerrain = (heightAt, { tileSize = 256 } = {}) => ({
  // Never called for its bytes; the decoder ignores them. It exists so nothing reaches the network
  // even if a source descriptor still carries a real URL template.
  fetch: async () => new ArrayBuffer(0),

  decode: async (_buffer, tile) => {
    const { x0, y0, size } = tileMercatorBounds(tile)
    const heights = new Float32Array(tileSize * tileSize)
    for (let row = 0; row < tileSize; row++) {
      const my = y0 + ((row + 0.5) / tileSize) * size
      for (let column = 0; column < tileSize; column++) {
        const mx = x0 + ((column + 0.5) / tileSize) * size
        heights[row * tileSize + column] = heightAt(mx, my)
      }
    }
    const data = heightTextureFromHeights(heights, tileSize)
    return { data, bytes: data.byteLength }
  },
})

/** A source descriptor that reaches nothing. Pair with `syntheticTerrain`. */
export const syntheticSource = ({ id = 'synthetic', maxzoom = 12, tileSize = 256 } = {}) => ({
  id,
  url: 'synthetic://{z}/{x}/{y}',
  minzoom: 0,
  maxzoom,
  tileSize,
  channels: 'rg',
})

/** Metres of ground per unit of mercator x at a latitude. The scale everything below converts through. */
const metresPerMercatorUnit = (lat) => MERCATOR_CIRCUMFERENCE_M * Math.cos((lat * Math.PI) / 180)

/**
 * Perfectly flat ground at a fixed height.
 *
 * The strongest assertion in any relief rig: flat ground must shade to EXACTLY nothing. A relief
 * term that tilts flat ground is the constant-bias failure — a decode centred half a step out, a
 * stencil that reads its own texel — and it is invisible against real terrain because everything
 * moves together. Its normal is (0, 0, 1) everywhere, at every latitude and every zoom.
 */
export const flatTerrain = (metres = 500) => {
  const heightAt = () => metres
  heightAt.normalAt = () => [0, 0, 1]
  return heightAt
}

/** Flat sea floor. For asserting that water is left alone rather than shaded. */
export const oceanTerrain = (depth = -3000) => {
  const heightAt = () => depth
  heightAt.normalAt = () => [0, 0, 1]
  return heightAt
}

/**
 * A sinusoidal ridge running north-south, with a normal in closed form.
 *
 * Bounded by its own amplitude, so it stays inside the elevation range at every zoom — a tilted
 * plane would be the obvious analytic surface and it runs to hundreds of kilometres of rise across
 * a z0 tile, which the encoder then clamps into a staircase.
 *
 *   h(mx) = amplitude · sin(2π · cycles · mx)
 *   dh/dmx = amplitude · 2π · cycles · cos(2π · cycles · mx)
 *   dh/dEast = dh/dmx / metresPerMercatorUnit(lat)
 *
 * and the normal of a height field is the gradient, negated, over a unit rise — in the frame
 * terrain-normals.js defines: +R east, +G SOUTH, +B up.
 *
 * `normalAt(mercatorX, mercatorY)` gives that vector, so a rig can assert an absolute shading value
 * rather than a relative one.
 */
export const ridgeTerrain = ({ amplitudeM = 1200, cycles = 64 } = {}) => {
  const angular = 2 * Math.PI * cycles
  const heightAt = (mx) => amplitudeM * Math.sin(angular * mx)

  heightAt.normalAt = (mx, my) => {
    const lat = latFromMercatorY(my)
    const eastward = (amplitudeM * angular * Math.cos(angular * mx)) / metresPerMercatorUnit(lat)
    const length = Math.hypot(eastward, 1)
    return [-eastward / length, 0, 1 / length]
  }
  return heightAt
}

/**
 * Land east of a meridian, sea west of it, with a vertical cliff between.
 *
 * For the ocean layer rather than for relief: it puts a shoreline at an exactly known longitude, so
 * a depth ramp can be checked for where it crosses zero instead of for whether it looks like a
 * coast. `heightAt` is discontinuous on purpose — the point is the crossing.
 */
export const coastTerrain = ({ shoreLng = 0, landM = 800, seaM = -2000 } = {}) => {
  const shoreX = (shoreLng + 180) / 360
  const heightAt = (mx) => (mx >= shoreX ? landM : seaM)
  heightAt.shoreMercatorX = shoreX
  return heightAt
}

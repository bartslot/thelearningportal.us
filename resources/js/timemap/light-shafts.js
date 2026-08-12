/**
 * light-shafts.js — the light doing something to the air it passes through.
 *
 * A sun with a bloom around it is still a sticker on the sky. What makes it read as a LIGHT is
 * seeing its beams interrupted: rays fanning past the edge of the planet at the terminator, and
 * through gaps in the cloud deck. Crepuscular rays, godrays, sunbeams — the same thing.
 *
 * WHY THIS IS CHEAP AND NOT A LIE. The honest version integrates scattering along every view ray
 * through a participating medium, which is a raymarch per pixel and costs more than the rest of
 * this map put together. But the effect has a screen-space identity that is exactly right for a
 * single distant source: a shaft is the bright source SMEARED ALONG THE DIRECTION AWAY FROM IT, cut
 * off wherever something opaque stands in the way. So blur the bright parts of the frame radially
 * about the sun's screen position, with an exponential decay per step, and the occluders carve the
 * beams for free — because they are already black in the mask.
 *
 * That is the Kenny Mitchell post-process from GPU Gems 3, and its one real limitation is worth
 * stating: an occluder that is off screen cannot cast a shaft, because it is not in the mask. Pan
 * the sun to the very edge of the frame and the rays weaken. Every game that ships this has the
 * same behaviour, and against a raymarch costing fifty times more it is a good trade.
 *
 * WHERE THE SUN IS. Not re-derived here. `sun-disc.js` did the projection, so it publishes the
 * answer through `screenPosition` and this layer is handed a function to read it. A second copy of
 * the placement rule would be free to drift from the first, and the failure — shafts radiating from
 * a point near but not on the sun — looks like a tuning problem rather than a bug.
 */

import { buildProgram } from './planet-mesh.js'
import {
  createRenderTarget, destroyRenderTarget, createScreenQuad, SCREEN_QUAD_VERTEX_GLSL,
  captureGlState, restoreGlState, beginPass, drawScreenQuad, bindTextureUnit,
} from './render-target.js'

const LAYER_ID = 'tm-light-shafts'

/** How far down the chain the mask is built. A quarter is plenty: shafts are a low-frequency thing. */
const MASK_DIVISOR = 4

/**
 * Taps along each shaft. Thirty-two is the point where the banding stops being visible against a
 * dark sky at a density of one; below about twenty-four the beams show as concentric arcs.
 */
const SAMPLES = 32

/**
 * The occluder mask: the light, and nothing else.
 *
 * Same threshold idea as the bloom's bright pass, and deliberately not shared with it — the two
 * want different resolutions and the bloom's chain would have to be re-run at this one. What
 * matters is that everything solid comes out BLACK, because the shafts are carved by the gaps.
 */
const maskFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_scene;
uniform float u_threshold;

void main() {
  vec3 colour = texture2D(u_scene, v_uv).rgb;
  float brightness = max(colour.r, max(colour.g, colour.b));
  float kept = smoothstep(u_threshold, min(u_threshold + 0.25, 1.0), brightness);
  gl_FragColor = vec4(colour * kept, 1.0);
}`

const shaftFragmentSource = (samples) => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_mask;
uniform vec2 u_sun;          // the sun's position in the same UV space
uniform float u_density;     // how far along the ray the samples reach
uniform float u_decay;       // per-step falloff: what makes a beam a beam and not a streak
uniform float u_weight;
uniform float u_exposure;
uniform float u_aspect;

void main() {
  // Step BACK toward the sun, accumulating. Correcting for aspect keeps the rays radial rather
  // than elliptical on a wide canvas, which is the sort of error that reads as artistic licence.
  vec2 offset = v_uv - u_sun;
  offset.x *= u_aspect;
  float distance = length(offset);
  offset /= max(distance, 1e-5);
  offset.x /= u_aspect;

  vec2 step = offset * (distance * u_density / float(${samples}));
  vec2 uv = v_uv;
  float illumination = u_exposure;
  vec3 sum = vec3(0.0);

  for (int i = 0; i < ${samples}; i++) {
    uv -= step;
    // Outside the frame there is no mask, and sampling the clamped edge instead smears the border
    // pixel down the whole beam — a bright bar along the edge of the screen that appears the moment
    // the sun approaches it.
    if (uv.x < 0.0 || uv.x > 1.0 || uv.y < 0.0 || uv.y > 1.0) break;
    sum += texture2D(u_mask, uv).rgb * illumination * u_weight;
    illumination *= u_decay;
  }

  gl_FragColor = vec4(sum, 0.0);
}`

/**
 * Fade the whole effect out as the sun leaves the frame.
 *
 * Without it the shafts do not stop, they rotate: every pixel keeps stepping toward a point beyond
 * the edge, so the screen fills with parallel streaks coming from nowhere. It looks like a bug in
 * the blur rather than the sun having set.
 */
export const shaftFalloff = ({ u, v, onScreen, visible }) => {
  if (!onScreen) return 0
  // Distance outside the unit square, in UV. Zero anywhere on screen.
  const outside = Math.max(0, Math.abs(u - 0.5) - 0.5, Math.abs(v - 0.5) - 0.5)
  const edge = Math.max(0, 1 - outside / SHAFT_EDGE_MARGIN)
  return Math.max(0, Math.min(1, visible)) * edge * edge
}

/**
 * How far past the edge of the frame the sun keeps casting, in UV.
 *
 * A third of a screen: shafts from a source just off the edge are real and worth keeping, and
 * cutting them the instant the disc crosses the border is a visible pop on a slowly turning globe.
 * Past this the effect is exactly zero, not merely small.
 */
export const SHAFT_EDGE_MARGIN = 0.35

/**
 * @param {object} opts
 * @param {Function} opts.source     returns the sun's `screenPosition` — usually `() => sunLayer.screenPosition`
 * @param {number} [opts.strength]   0 switches the pass off entirely, and it then costs nothing
 * @param {number} [opts.threshold]  brightness an occluder has to fall below to carve a shaft
 * @param {number} [opts.density]    how far along each ray the taps reach, as a fraction of the gap
 * @param {number} [opts.decay]      per-step falloff; lower makes short stubby beams
 * @param {number[]} [opts.tint]     linear RGB the shafts are multiplied by
 */
export const createLightShaftLayer = ({
  source = () => ({ u: 0.5, v: 0.5, onScreen: false, visible: 0 }),
  strength = 0.7,
  threshold = 0.55,
  density = 0.72,
  decay = 0.962,
  weight = 0.42,
  exposure = 0.28,
  tint = [1, 0.86, 0.62],
} = {}) => {
  let map = null
  let gl = null
  let quad = null
  let programs = null
  let scene = null
  let mask = null
  let shafts = null
  let allocatedFor = null
  let state = { strength, threshold, density, decay, weight, exposure, tint }

  const buildPrograms = () => {
    const compile = (fragment, label, names) => {
      const program = buildProgram(gl, SCREEN_QUAD_VERTEX_GLSL, fragment, label)
      const uniforms = {}
      names.forEach((name) => { uniforms[name] = gl.getUniformLocation(program, `u_${name}`) })
      return { program, corner: gl.getAttribLocation(program, 'a_corner'), ...uniforms }
    }
    return {
      mask: compile(maskFragmentSource(), 'tm-shafts-mask', ['scene', 'threshold']),
      shaft: compile(shaftFragmentSource(SAMPLES), 'tm-shafts-radial',
        ['mask', 'sun', 'density', 'decay', 'weight', 'exposure', 'aspect']),
      composite: compile(compositeFragmentSource(), 'tm-shafts-composite', ['shafts', 'strength', 'tint']),
    }
  }

  const releaseTargets = () => {
    destroyRenderTarget(gl, scene)
    destroyRenderTarget(gl, mask)
    destroyRenderTarget(gl, shafts)
    scene = mask = shafts = null
    allocatedFor = null
  }

  const ensureTargets = (width, height) => {
    if (allocatedFor && allocatedFor.width === width && allocatedFor.height === height) return
    releaseTargets()
    scene = createRenderTarget(gl, width, height, false)   // filled by copyTexImage2D, no framebuffer
    mask = createRenderTarget(gl, width / MASK_DIVISOR, height / MASK_DIVISOR)
    shafts = createRenderTarget(gl, width / MASK_DIVISOR, height / MASK_DIVISOR)
    allocatedFor = { width, height }
  }

  return {
    id: LAYER_ID,
    type: 'custom',
    renderingMode: '2d',

    onAdd(mapInstance, glContext) {
      map = mapInstance
      gl = glContext
      quad = createScreenQuad(gl)
    },

    onRemove() {
      if (!gl) return
      if (programs) {
        Object.values(programs).forEach(({ program }) => gl.deleteProgram(program))
        programs = null
      }
      releaseTargets()
      if (quad) { gl.deleteBuffer(quad); quad = null }
      map = null
      gl = null
    },

    render() {
      if (!quad || state.strength <= 0) return
      const sun = source() || { onScreen: false }
      const falloff = shaftFalloff(sun)
      // Nothing to smear. Bail BEFORE allocating, so a scene where the sun never rises is free.
      if (falloff <= 0) return

      const canvas = map.getCanvas()
      const width = Math.max(1, canvas.width)
      const height = Math.max(1, canvas.height)

      const saved = captureGlState(gl)
      programs = programs || buildPrograms()
      ensureTargets(width, height)

      gl.bindTexture(gl.TEXTURE_2D, scene.texture)
      gl.copyTexImage2D(gl.TEXTURE_2D, 0, gl.RGBA, 0, 0, width, height, 0)

      gl.disable(gl.DEPTH_TEST)
      gl.disable(gl.BLEND)
      gl.depthMask(false)

      beginPass(gl, mask, programs.mask.program)
      bindTextureUnit(gl, 0, scene.texture, programs.mask.scene)
      if (programs.mask.threshold) gl.uniform1f(programs.mask.threshold, state.threshold)
      drawScreenQuad(gl, quad, programs.mask.corner)

      beginPass(gl, shafts, programs.shaft.program)
      bindTextureUnit(gl, 0, mask.texture, programs.shaft.mask)
      if (programs.shaft.sun) gl.uniform2f(programs.shaft.sun, sun.u, sun.v)
      if (programs.shaft.density) gl.uniform1f(programs.shaft.density, state.density)
      if (programs.shaft.decay) gl.uniform1f(programs.shaft.decay, state.decay)
      if (programs.shaft.weight) gl.uniform1f(programs.shaft.weight, state.weight)
      if (programs.shaft.exposure) gl.uniform1f(programs.shaft.exposure, state.exposure)
      if (programs.shaft.aspect) gl.uniform1f(programs.shaft.aspect, width / height)
      drawScreenQuad(gl, quad, programs.shaft.corner)

      gl.bindFramebuffer(gl.FRAMEBUFFER, saved.framebuffer)
      gl.viewport(saved.viewport[0], saved.viewport[1], saved.viewport[2], saved.viewport[3])
      gl.enable(gl.BLEND)
      gl.blendFunc(gl.ONE, gl.ONE)
      gl.useProgram(programs.composite.program)
      bindTextureUnit(gl, 0, shafts.texture, programs.composite.shafts)
      if (programs.composite.strength) gl.uniform1f(programs.composite.strength, state.strength * falloff)
      if (programs.composite.tint) gl.uniform3f(programs.composite.tint, ...state.tint)
      drawScreenQuad(gl, quad, programs.composite.corner)

      restoreGlState(gl, saved)
    },

    setOptions(next = {}) {
      state = { ...state, ...next }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
  }
}

/** Alpha ZERO: additive light, and the map canvas is composited over the page. */
const compositeFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_shafts;
uniform float u_strength;
uniform vec3 u_tint;

void main() {
  gl_FragColor = vec4(texture2D(u_shafts, v_uv).rgb * u_tint * u_strength, 0.0);
}`

export const LIGHT_SHAFT_LAYER_ID = LAYER_ID
export const LIGHT_SHAFT_SAMPLES = SAMPLES

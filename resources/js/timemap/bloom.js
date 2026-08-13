/**
 * bloom.js — a real bloom pass, in a map that has nowhere to put one.
 *
 * Take what the map has drawn, keep the bright parts, spread them across five scales, add them
 * back. The sun is what prompted it, but the pass is not about the sun: the specular glint off the
 * ocean, the sunlit crescent along the limb and city lights on the night side all bloom too, and
 * none of them is a point source that could have its halo written out by hand.
 *
 * WHERE IT HOOKS IN. The reference method is three.js selective bloom — a second EffectComposer, an
 * UnrealBloomPass, every non-blooming object swapped to a black material. There is no composer
 * here, no scene graph and no post chain: MapLibre hands a custom layer a raw WebGL context and its
 * own bound framebuffer. The way in is `copyTexImage2D`, which copies whatever is currently bound
 * into a texture of ours. Added LAST in the style, this layer therefore sees the finished frame.
 *
 * That makes it whole-scene rather than selective, which is the better trade here: the three.js
 * method needs a list of what is allowed to bloom, and on this map the answer is "whatever happens
 * to be bright", which a threshold already knows.
 *
 * A MIP CHAIN, NOT A BLUR. One gaussian has one scale, and one scale reads as fog. Real glare has
 * a bright core, a broad aureole and a very wide faint skirt, spanning two orders of magnitude of
 * radius, and no single kernel covers that without either a huge tap count or visible ringing.
 * So: downsample five times with a 13-tap filter, then walk back UP the chain adding each level
 * into the one above through a 9-tap tent. Every level contributes its own scale, the sum is smooth
 * because each was band-limited before it was added, and the whole thing costs ten passes on
 * textures that are between a quarter and a thousandth of the screen.
 *
 * CHROMATIC FALLOFF. Real optics do not scatter evenly across wavelengths, and the aureole around
 * a bright source is measurably redder further out than it is close in. Each upsample carries a
 * per-level tint, so the wide levels lean warm and the tight ones stay neutral. It costs one
 * uniform and no extra taps, and it is the difference between "a glow" and "a light".
 *
 * THE STATE HAS TO GO BACK. This is the only layer here that binds a framebuffer, and leaving one
 * bound sends every MapLibre layer drawn afterwards into a texture nobody looks at: the map stops
 * updating with a perfectly good last frame on screen and no error anywhere. `render-target.js`
 * owns that, and it is tested there.
 */

import { buildProgram } from './planet-mesh.js'
import {
  createRenderTarget, createMipChain, destroyRenderTarget, createScreenQuad,
  SCREEN_QUAD_VERTEX_GLSL, captureGlState, restoreGlState, beginPass, drawScreenQuad, bindTextureUnit,
} from './render-target.js'

const LAYER_ID = 'tm-bloom'

/**
 * How many halvings. Five puts the coarsest level at a thirty-second of the screen, so its taps
 * reach about a fifth of the way across it — which is the width the faint outer skirt needs and
 * roughly where a real aureole stops being distinguishable from sky.
 */
const LEVELS = 5

/**
 * One tint per upsample step — there are `LEVELS - 1` of those — coarsest first. Warm where the
 * halo is wide, neutral where it is tight. Multipliers on linear RGB, kept near 1 in the mean so
 * the chain does not also change the bloom's overall brightness.
 */
const CHROMATIC_TINTS = [
  [1.18, 0.92, 0.68],   // the widest scale: the outer aureole, distinctly warm
  [1.12, 0.95, 0.78],
  [1.06, 0.98, 0.89],
  [1.02, 1.0, 0.97],    // the tightest: nearly the source's own colour
]
const NEUTRAL_TINT = [1, 1, 1]

/**
 * Keep what is bright, throw away what is not.
 *
 * The remap matters as much as the threshold. `max(colour - threshold, 0)` alone leaves a
 * near-white pixel contributing only what it had above the cut, so with a high threshold nothing
 * visibly blooms and with a low one the whole picture does. Dividing by the headroom restores a
 * saturated pixel to full strength, which is what an HDR buffer would have given for free — and
 * there is no HDR buffer here, because half-float render targets need an extension that is not
 * universal on classroom hardware.
 *
 * The soft knee stops the bloom acquiring an outline of its own along the terminator, where a lot
 * of the frame sits within a few percent of the threshold and a hard cut would flicker as the
 * globe turns.
 */
const brightFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_scene;
uniform float u_threshold;
uniform float u_knee;

void main() {
  vec3 colour = texture2D(u_scene, v_uv).rgb;
  float brightness = max(colour.r, max(colour.g, colour.b));
  float soft = smoothstep(u_threshold - u_knee, u_threshold + u_knee, brightness);
  float headroom = max(1.0 - u_threshold, 0.02);
  vec3 kept = max(colour - vec3(u_threshold), vec3(0.0)) / headroom;
  gl_FragColor = vec4(kept * soft, 1.0);
}`

/**
 * Thirteen taps arranged as four overlapping quads plus a centre cross — the downsample filter from
 * Jimenez's Call of Duty bloom.
 *
 * A plain box or bilinear halving is what causes the classic bloom fireflies: a single very bright
 * pixel survives one level, disappears at the next, and flickers in and out as the camera moves a
 * fraction of a pixel. The overlapping arrangement gives every source pixel a share in several
 * output pixels, which is stable.
 */
const downsampleFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_source;
uniform vec2 u_texel;          // one texel of the SOURCE

void main() {
  vec3 a = texture2D(u_source, v_uv + u_texel * vec2(-2.0,  2.0)).rgb;
  vec3 b = texture2D(u_source, v_uv + u_texel * vec2( 0.0,  2.0)).rgb;
  vec3 c = texture2D(u_source, v_uv + u_texel * vec2( 2.0,  2.0)).rgb;
  vec3 d = texture2D(u_source, v_uv + u_texel * vec2(-2.0,  0.0)).rgb;
  vec3 e = texture2D(u_source, v_uv).rgb;
  vec3 f = texture2D(u_source, v_uv + u_texel * vec2( 2.0,  0.0)).rgb;
  vec3 g = texture2D(u_source, v_uv + u_texel * vec2(-2.0, -2.0)).rgb;
  vec3 h = texture2D(u_source, v_uv + u_texel * vec2( 0.0, -2.0)).rgb;
  vec3 i = texture2D(u_source, v_uv + u_texel * vec2( 2.0, -2.0)).rgb;
  vec3 j = texture2D(u_source, v_uv + u_texel * vec2(-1.0,  1.0)).rgb;
  vec3 k = texture2D(u_source, v_uv + u_texel * vec2( 1.0,  1.0)).rgb;
  vec3 l = texture2D(u_source, v_uv + u_texel * vec2(-1.0, -1.0)).rgb;
  vec3 m = texture2D(u_source, v_uv + u_texel * vec2( 1.0, -1.0)).rgb;

  // The inner quad carries half the weight; the outer ring shares the rest.
  vec3 sum = (j + k + l + m) * 0.125
           + (a + c + g + i) * 0.03125
           + (b + d + f + h) * 0.0625
           + e * 0.125;
  gl_FragColor = vec4(sum, 1.0);
}`

/**
 * A 3×3 tent, used going back up the chain. Additive blending does the accumulation, so this pass
 * only has to produce one level's own contribution.
 *
 * `u_radius` is in texels of the level being written into, which is what keeps the spread the same
 * fraction of the screen at every level rather than collapsing as the textures get smaller.
 */
const upsampleFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_source;
uniform vec2 u_texel;          // one texel of the TARGET
uniform float u_radius;
uniform vec3 u_tint;

void main() {
  vec2 o = u_texel * u_radius;
  vec3 sum = texture2D(u_source, v_uv + vec2(-o.x,  o.y)).rgb * 1.0
           + texture2D(u_source, v_uv + vec2( 0.0,  o.y)).rgb * 2.0
           + texture2D(u_source, v_uv + vec2( o.x,  o.y)).rgb * 1.0
           + texture2D(u_source, v_uv + vec2(-o.x,  0.0)).rgb * 2.0
           + texture2D(u_source, v_uv).rgb                    * 4.0
           + texture2D(u_source, v_uv + vec2( o.x,  0.0)).rgb * 2.0
           + texture2D(u_source, v_uv + vec2(-o.x, -o.y)).rgb * 1.0
           + texture2D(u_source, v_uv + vec2( 0.0, -o.y)).rgb * 2.0
           + texture2D(u_source, v_uv + vec2( o.x, -o.y)).rgb * 1.0;
  gl_FragColor = vec4(sum * (1.0 / 16.0) * u_tint, 1.0);
}`

/**
 * Add the accumulated chain back over the frame.
 *
 * Alpha goes out as ZERO on purpose. The composite blends ONE / ONE, and the map canvas is itself
 * composited over the page — writing alpha here would let the bloom eat the alpha channel it is
 * supposed to be adding light to.
 */
const compositeFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_bloom;
uniform float u_strength;

void main() {
  gl_FragColor = vec4(texture2D(u_bloom, v_uv).rgb * u_strength, 0.0);
}`

/**
 * COLOUR GRADE — the frame itself, not the light added to it.
 *
 * The composite above can only ADD (ONE/ONE, alpha 0), which is right for glare and useless for
 * grading: nothing additive can take saturation out. So this redraws the captured frame over
 * itself with blending off, and the bloom then lands on top of the graded picture.
 *
 * Luma is Rec. 709, not a flat average — a flat average turns a blue ocean and a red border into
 * the same grey, and the whole point of desaturating a history map is that the LAND still reads.
 *
 * Hue rotation is the standard YIQ-space rotation, which is a matrix on the chroma pair and leaves
 * luma untouched: rotating hue must not change how bright anything is.
 *
 * Alpha is passed through rather than forced to 1. The globe leaves parts of the canvas
 * transparent and the page shows through them; writing 1 here would paint those black.
 */
const gradeFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_scene;
uniform float u_saturation;
uniform float u_hue;          // radians
uniform float u_exposure;
uniform float u_contrast;

const vec3 LUMA = vec3(0.2126, 0.7152, 0.0722);

void main() {
  vec4 texel = texture2D(u_scene, v_uv);
  vec3 colour = texel.rgb * u_exposure;

  if (abs(u_hue) > 0.0001) {
    float c = cos(u_hue);
    float s = sin(u_hue);
    // YIQ chroma rotation, folded into a single RGB matrix.
    mat3 rot = mat3(
      0.299 + 0.701 * c + 0.168 * s, 0.587 - 0.587 * c + 0.330 * s, 0.114 - 0.114 * c - 0.497 * s,
      0.299 - 0.299 * c - 0.328 * s, 0.587 + 0.413 * c + 0.035 * s, 0.114 - 0.114 * c + 0.292 * s,
      0.299 - 0.300 * c + 1.250 * s, 0.587 - 0.588 * c - 1.050 * s, 0.114 + 0.886 * c - 0.203 * s);
    colour = clamp(rot * colour, 0.0, 1.0);
  }

  float luma = dot(colour, LUMA);
  colour = mix(vec3(luma), colour, u_saturation);
  // Pivot on mid grey so contrast does not double as a brightness control.
  colour = clamp((colour - 0.5) * u_contrast + 0.5, 0.0, 1.0);

  gl_FragColor = vec4(colour, texel.a);
}`

/** How deep the chain goes. Exported so a test can assert the pass count rather than guess it. */
export const BLOOM_LEVELS = LEVELS

/** Grade values that mean "leave the picture alone", so the pass can be skipped entirely. */
export const GRADE_IDENTITY = { saturation: 1, hue: 0, exposure: 1, contrast: 1 }

/** True when any grade dial has moved off its neutral value. */
export const gradeIsActive = (g) => (
  Math.abs((g?.saturation ?? 1) - 1) > 0.001
  || Math.abs(g?.hue ?? 0) > 0.001
  || Math.abs((g?.exposure ?? 1) - 1) > 0.001
  || Math.abs((g?.contrast ?? 1) - 1) > 0.001
)

/**
 * @param {object} opts
 * @param {number} [opts.strength]    how much bloom is added back; 0 switches the pass off entirely
 * @param {number} [opts.threshold]   brightness a pixel has to reach before it blooms, 0..1
 * @param {number} [opts.knee]        half-width of the soft cut around that threshold
 * @param {number} [opts.radius]      upsample spread, in texels of the level being written
 * @param {boolean} [opts.chromatic]  false makes every level neutral, for an A/B
 */
export const createBloomLayer = ({
  strength = 0.9,
  threshold = 0.6,
  knee = 0.12,
  radius = 1.0,
  chromatic = true,
  ...grade
} = {}) => {
  let map = null
  let gl = null
  let quad = null
  let programs = null
  let scene = null          // a copy of MapLibre's frame; no framebuffer, only ever copied into
  let chain = null          // LEVELS targets, largest first
  let allocatedFor = null
  let state = { strength, threshold, knee, radius, chromatic, ...GRADE_IDENTITY, ...grade }

  const buildPrograms = () => {
    const compile = (fragment, label, uniformNames) => {
      const program = buildProgram(gl, SCREEN_QUAD_VERTEX_GLSL, fragment, label)
      const uniforms = {}
      uniformNames.forEach((name) => { uniforms[name] = gl.getUniformLocation(program, `u_${name}`) })
      return { program, corner: gl.getAttribLocation(program, 'a_corner'), ...uniforms }
    }
    return {
      bright: compile(brightFragmentSource(), 'tm-bloom-bright', ['scene', 'threshold', 'knee']),
      down: compile(downsampleFragmentSource(), 'tm-bloom-down', ['source', 'texel']),
      up: compile(upsampleFragmentSource(), 'tm-bloom-up', ['source', 'texel', 'radius', 'tint']),
      composite: compile(compositeFragmentSource(), 'tm-bloom-composite', ['bloom', 'strength']),
      grade: compile(gradeFragmentSource(), 'tm-grade', ['scene', 'saturation', 'hue', 'exposure', 'contrast']),
    }
  }

  const releaseTargets = () => {
    destroyRenderTarget(gl, scene)
    scene = null
    if (chain) chain.forEach((target) => destroyRenderTarget(gl, target))
    chain = null
    allocatedFor = null
  }

  const ensureTargets = (width, height) => {
    if (allocatedFor && allocatedFor.width === width && allocatedFor.height === height) return
    releaseTargets()
    scene = createRenderTarget(gl, width, height, false)
    chain = createMipChain(gl, width, height, LEVELS)
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
      // The grade runs on its own: strength 0 means "no glare", not "no post-processing".
      const grading = gradeIsActive(state)
      if (!quad || (state.strength <= 0 && !grading)) return
      const canvas = map.getCanvas()
      const width = Math.max(1, canvas.width)
      const height = Math.max(1, canvas.height)

      const saved = captureGlState(gl)
      programs = programs || buildPrograms()
      ensureTargets(width, height)

      // ── Grab the frame ─────────────────────────────────────────────────────────────────────
      // Straight out of whatever MapLibre is drawing into. This is the only way in: there is no
      // render target to borrow and no composer to insert a pass into.
      gl.bindTexture(gl.TEXTURE_2D, scene.texture)
      gl.copyTexImage2D(gl.TEXTURE_2D, 0, gl.RGBA, 0, 0, width, height, 0)

      gl.disable(gl.DEPTH_TEST)
      gl.disable(gl.BLEND)          // the chain overwrites; only the upsamples and composite add
      gl.depthMask(false)

      // ── Grade the frame in place ───────────────────────────────────────────────────────────
      // Straight back onto the default framebuffer with blending off, so this REPLACES the picture
      // rather than adding to it. Then re-grab it, so the bright pass measures the graded frame:
      // otherwise desaturating to grey would still bloom in colour, which looks like a bug.
      if (grading) {
        gl.bindFramebuffer(gl.FRAMEBUFFER, null)
        gl.viewport(0, 0, width, height)
        gl.useProgram(programs.grade.program)
        bindTextureUnit(gl, 0, scene.texture, programs.grade.scene)
        if (programs.grade.saturation) gl.uniform1f(programs.grade.saturation, state.saturation)
        if (programs.grade.hue) gl.uniform1f(programs.grade.hue, state.hue)
        if (programs.grade.exposure) gl.uniform1f(programs.grade.exposure, state.exposure)
        if (programs.grade.contrast) gl.uniform1f(programs.grade.contrast, state.contrast)
        drawScreenQuad(gl, quad, programs.grade.corner)

        gl.bindTexture(gl.TEXTURE_2D, scene.texture)
        gl.copyTexImage2D(gl.TEXTURE_2D, 0, gl.RGBA, 0, 0, width, height, 0)
      }

      if (state.strength <= 0) { restoreGlState(gl, saved); return }

      // ── Bright pass, into the top of the chain ─────────────────────────────────────────────
      beginPass(gl, chain[0], programs.bright.program)
      bindTextureUnit(gl, 0, scene.texture, programs.bright.scene)
      if (programs.bright.threshold) gl.uniform1f(programs.bright.threshold, state.threshold)
      if (programs.bright.knee) gl.uniform1f(programs.bright.knee, state.knee)
      drawScreenQuad(gl, quad, programs.bright.corner)

      // ── Down the chain ─────────────────────────────────────────────────────────────────────
      for (let level = 1; level < chain.length; level++) {
        const source = chain[level - 1]
        beginPass(gl, chain[level], programs.down.program)
        bindTextureUnit(gl, 0, source.texture, programs.down.source)
        if (programs.down.texel) gl.uniform2f(programs.down.texel, 1 / source.width, 1 / source.height)
        drawScreenQuad(gl, quad, programs.down.corner)
      }

      // ── And back up, adding each level into the one above ──────────────────────────────────
      // Additive, so every scale ends up summed into chain[0] without a separate accumulator.
      gl.enable(gl.BLEND)
      gl.blendFunc(gl.ONE, gl.ONE)
      for (let level = chain.length - 1; level > 0; level--) {
        const target = chain[level - 1]
        beginPass(gl, target, programs.up.program)
        bindTextureUnit(gl, 0, chain[level].texture, programs.up.source)
        if (programs.up.texel) gl.uniform2f(programs.up.texel, 1 / target.width, 1 / target.height)
        if (programs.up.radius) gl.uniform1f(programs.up.radius, state.radius)
        if (programs.up.tint) {
          // The source is chain[level]: the coarser it is, the wider its contribution and the
          // warmer it should be, so the coarsest step takes the first tint.
          const tint = state.chromatic ? CHROMATIC_TINTS[chain.length - 1 - level] : NEUTRAL_TINT
          gl.uniform3f(programs.up.tint, ...tint)
        }
        drawScreenQuad(gl, quad, programs.up.corner)
      }

      // ── Add it back over the frame ─────────────────────────────────────────────────────────
      gl.bindFramebuffer(gl.FRAMEBUFFER, saved.framebuffer)
      gl.viewport(saved.viewport[0], saved.viewport[1], saved.viewport[2], saved.viewport[3])
      gl.useProgram(programs.composite.program)
      bindTextureUnit(gl, 0, chain[0].texture, programs.composite.bloom)
      if (programs.composite.strength) gl.uniform1f(programs.composite.strength, state.strength)
      drawScreenQuad(gl, quad, programs.composite.corner)

      restoreGlState(gl, saved)
    },

    setOptions(next = {}) {
      state = { ...state, ...next }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    get levelCount() { return LEVELS },
  }
}

export const BLOOM_LAYER_ID = LAYER_ID

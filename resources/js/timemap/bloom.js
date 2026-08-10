/**
 * bloom.js — a real bloom pass, in a map that has nowhere to put one.
 *
 * The sun's own glare is written out analytically in `sun-disc.js`, and for the sun alone that is
 * the same picture a blur would give: bloom of a source twenty pixels across is just the blur
 * kernel, centred on it. What an analytic falloff cannot do is bloom anything ELSE. The specular
 * glint off the ocean, the sunlit crescent along the limb, city lights on the night side — each
 * would need its own hand-written halo, and none of them is a point.
 *
 * So this is the screen-space pass: take what the map has drawn, keep the bright parts, blur them
 * wide, and add them back.
 *
 * WHERE IT HOOKS IN. The reference method is three.js selective bloom — a second EffectComposer, an
 * UnrealBloomPass, every non-blooming object swapped to a black material. There is no composer
 * here, no scene graph, no render targets, and no post chain: MapLibre hands a custom layer a raw
 * WebGL context and its own bound framebuffer. The way in is `copyTexImage2D`, which copies
 * whatever is currently bound into a texture of our own. Added LAST in the style, this layer
 * therefore sees the finished frame, and everything below it blooms.
 *
 * That makes it whole-scene bloom rather than selective bloom, which is the better trade here: the
 * three.js method needs a list of what may bloom, and on this map the answer is "whatever happens
 * to be bright", which a threshold already knows.
 *
 * TWO LEVELS, NOT ONE. A single gaussian gives the soft airbrushed blob that reads as fog. Real
 * bloom has scales: a tight bright halo and a wide faint one. A half-resolution blur and an
 * eighth-resolution blur added together cost four small passes and have the right shape.
 *
 * THE STATE HAS TO GO BACK. This is the only layer here that binds a framebuffer, and if it leaves
 * one bound, every MapLibre layer drawn afterwards renders into a 160×90 texture nobody looks at.
 * The map stops updating with a perfectly good last frame still on screen and no error anywhere.
 * Same for the blend function, the viewport and the depth test.
 */

import { buildProgram } from './planet-mesh.js'

const LAYER_ID = 'tm-bloom'

/** How far each level is downsampled. Half for the tight halo, an eighth for the wide one. */
const LEVEL_DIVISORS = [2, 8]

/**
 * The size of each blur level for a given canvas.
 *
 * Clamped to at least one pixel: a pane collapsing to a sliver mid-resize would otherwise ask for a
 * 0×0 texture, which is an INVALID_VALUE on every driver and takes the map's whole GL context with
 * it — a black canvas from a resize, several layers away from anything to do with bloom.
 */
export const bloomTargetSizes = (width, height) => LEVEL_DIVISORS.map((divisor) => ({
  width: Math.max(1, Math.floor(width / divisor)),
  height: Math.max(1, Math.floor(height / divisor)),
}))

// One triangle strip covering clip space. Every pass in here is a full-screen quad.
const QUAD = new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1])

const quadVertexSource = () => `precision highp float;
attribute vec2 a_corner;
varying vec2 v_uv;
void main() {
  v_uv = a_corner * 0.5 + 0.5;
  gl_Position = vec4(a_corner, 0.0, 1.0);
}`

/**
 * Keep what is bright, throw away what is not.
 *
 * The remap matters as much as the threshold. `max(colour - threshold, 0)` alone leaves a
 * near-white pixel contributing only what it had above the cut, so with a high threshold nothing
 * blooms and with a low one the whole picture does. Dividing by the headroom restores a saturated
 * pixel to full strength, which is what an HDR buffer would have given without the arithmetic —
 * and this map has no HDR buffer to work in.
 *
 * The soft knee stops the bloom having a visible outline of its own along the terminator, where a
 * lot of the frame sits within a few percent of the threshold and a hard cut would flicker as the
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
 * One direction of a separable gaussian, nine taps, sampled at the half-texel offsets that let the
 * hardware's bilinear filter do half the work: nine weights, five fetches.
 */
const blurFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_source;
uniform vec2 u_step;          // one texel, along the axis being blurred

const float w0 = 0.2270270270;
const float w1 = 0.3162162162;
const float w2 = 0.0702702703;
const float o1 = 1.3846153846;
const float o2 = 3.2307692308;

void main() {
  vec3 sum = texture2D(u_source, v_uv).rgb * w0;
  sum += texture2D(u_source, v_uv + u_step * o1).rgb * w1;
  sum += texture2D(u_source, v_uv - u_step * o1).rgb * w1;
  sum += texture2D(u_source, v_uv + u_step * o2).rgb * w2;
  sum += texture2D(u_source, v_uv - u_step * o2).rgb * w2;
  gl_FragColor = vec4(sum, 1.0);
}`

/**
 * Add the levels back over the frame.
 *
 * Alpha goes out as ZERO on purpose. MapLibre blends custom layers premultiplied, ONE /
 * ONE_MINUS_SRC_ALPHA, and this pass composites with ONE / ONE — but the map canvas is itself
 * composited over the page, so writing alpha here would let the bloom eat the alpha channel it is
 * supposed to be adding light to.
 */
const compositeFragmentSource = () => `precision highp float;
varying vec2 v_uv;
uniform sampler2D u_tight;
uniform sampler2D u_wide;
uniform float u_strength;
uniform vec3 u_tint;

void main() {
  vec3 bloom = texture2D(u_tight, v_uv).rgb * 0.62 + texture2D(u_wide, v_uv).rgb * 0.38;
  gl_FragColor = vec4(bloom * u_tint * u_strength, 0.0);
}`

/**
 * @param {object} opts
 * @param {number} [opts.strength]    how much bloom is added back; 0 switches the pass off entirely
 * @param {number} [opts.threshold]   brightness a pixel has to reach before it blooms, 0..1
 * @param {number} [opts.knee]        half-width of the soft cut around that threshold
 * @param {number[]} [opts.tint]      linear RGB the bloom is multiplied by
 */
export const createBloomLayer = ({
  strength = 0.85,
  threshold = 0.62,
  knee = 0.12,
  tint = [1, 0.93, 0.82],
} = {}) => {
  let map = null
  let gl = null
  let quad = null
  let programs = null
  let scene = null          // a copy of MapLibre's frame
  let levels = null         // per level: { ping, pong, size }
  let allocatedFor = null   // the canvas size `levels` was built for
  let state = { strength, threshold, knee, tint }

  const buildPrograms = () => {
    const compile = (fragment, label) => {
      const program = buildProgram(gl, quadVertexSource(), fragment, label)
      return { program, corner: gl.getAttribLocation(program, 'a_corner') }
    }
    const bright = compile(brightFragmentSource(), 'tm-bloom-bright')
    const blur = compile(blurFragmentSource(), 'tm-bloom-blur')
    const composite = compile(compositeFragmentSource(), 'tm-bloom-composite')
    return {
      bright: {
        ...bright,
        scene: gl.getUniformLocation(bright.program, 'u_scene'),
        threshold: gl.getUniformLocation(bright.program, 'u_threshold'),
        knee: gl.getUniformLocation(bright.program, 'u_knee'),
      },
      blur: {
        ...blur,
        source: gl.getUniformLocation(blur.program, 'u_source'),
        step: gl.getUniformLocation(blur.program, 'u_step'),
      },
      composite: {
        ...composite,
        tight: gl.getUniformLocation(composite.program, 'u_tight'),
        wide: gl.getUniformLocation(composite.program, 'u_wide'),
        strength: gl.getUniformLocation(composite.program, 'u_strength'),
        tint: gl.getUniformLocation(composite.program, 'u_tint'),
      },
    }
  }

  const makeTexture = (width, height) => {
    const texture = gl.createTexture()
    gl.bindTexture(gl.TEXTURE_2D, texture)
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, width, height, 0, gl.RGBA, gl.UNSIGNED_BYTE, null)
    // CLAMP_TO_EDGE, not REPEAT: a blur kernel reaching past the edge of a wrapping texture pulls
    // the far side of the screen into the near one, and a bright sun on the left puts a ghost of
    // itself on the right.
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
    return texture
  }

  const makeTarget = (width, height) => {
    const texture = makeTexture(width, height)
    const framebuffer = gl.createFramebuffer()
    gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer)
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0)
    return { texture, framebuffer, width, height }
  }

  const releaseTargets = () => {
    if (scene) { gl.deleteTexture(scene.texture); scene = null }
    if (levels) {
      levels.forEach(({ ping, pong }) => {
        gl.deleteTexture(ping.texture); gl.deleteFramebuffer(ping.framebuffer)
        gl.deleteTexture(pong.texture); gl.deleteFramebuffer(pong.framebuffer)
      })
      levels = null
    }
    allocatedFor = null
  }

  /** Build (or rebuild) the targets for the canvas as it is now. */
  const ensureTargets = (width, height) => {
    if (allocatedFor && allocatedFor.width === width && allocatedFor.height === height) return
    releaseTargets()
    // The scene copy has no framebuffer of its own — `copyTexImage2D` fills it from whatever
    // MapLibre has bound, which is the whole point.
    scene = { texture: makeTexture(width, height), width, height }
    levels = bloomTargetSizes(width, height).map((size) => ({
      ping: makeTarget(size.width, size.height),
      pong: makeTarget(size.width, size.height),
      size,
    }))
    allocatedFor = { width, height }
  }

  const drawQuad = (pass) => {
    gl.bindBuffer(gl.ARRAY_BUFFER, quad)
    gl.enableVertexAttribArray(pass.corner)
    gl.vertexAttribPointer(pass.corner, 2, gl.FLOAT, false, 0, 0)
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4)
  }

  const renderTo = (target, pass) => {
    gl.bindFramebuffer(gl.FRAMEBUFFER, target.framebuffer)
    gl.viewport(0, 0, target.width, target.height)
    gl.useProgram(pass.program)
    return pass
  }

  return {
    id: LAYER_ID,
    type: 'custom',
    renderingMode: '2d',

    onAdd(mapInstance, glContext) {
      map = mapInstance
      gl = glContext
      quad = gl.createBuffer()
      gl.bindBuffer(gl.ARRAY_BUFFER, quad)
      gl.bufferData(gl.ARRAY_BUFFER, QUAD, gl.STATIC_DRAW)
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

    render(glContext) {
      if (!quad || state.strength <= 0) return
      const canvas = map.getCanvas()
      const width = Math.max(1, canvas.width)
      const height = Math.max(1, canvas.height)

      // Everything MapLibre expects to still be true afterwards.
      const previousFramebuffer = gl.getParameter(gl.FRAMEBUFFER_BINDING)
      const previousViewport = gl.getParameter(gl.VIEWPORT)

      programs = programs || buildPrograms()
      ensureTargets(width, height)

      // ── Grab the frame ─────────────────────────────────────────────────────────────────────
      // Straight out of whatever MapLibre is drawing into. This is the only way in: there is no
      // render target to borrow and no composer to insert a pass into.
      gl.bindTexture(gl.TEXTURE_2D, scene.texture)
      gl.copyTexImage2D(gl.TEXTURE_2D, 0, gl.RGBA, 0, 0, width, height, 0)

      gl.disable(gl.DEPTH_TEST)
      gl.disable(gl.BLEND)          // the intermediate passes overwrite; only the composite adds
      gl.depthMask(false)

      // ── Bright pass, straight into the first level ─────────────────────────────────────────
      const bright = renderTo(levels[0].ping, programs.bright)
      gl.activeTexture(gl.TEXTURE0)
      gl.bindTexture(gl.TEXTURE_2D, scene.texture)
      if (bright.scene) gl.uniform1i(bright.scene, 0)
      if (bright.threshold) gl.uniform1f(bright.threshold, state.threshold)
      if (bright.knee) gl.uniform1f(bright.knee, state.knee)
      drawQuad(bright)

      // ── Blur each level, horizontally then vertically ──────────────────────────────────────
      // Level 1 starts from level 0's result rather than from the bright pass, so the wide halo is
      // a blur of a blur — which is what gives the two scales a common centre instead of two
      // independently-sized discs that betray themselves as rings.
      levels.forEach((level, index) => {
        const source = index === 0 ? level.ping : levels[index - 1].ping
        const passes = [
          { from: source, to: level.pong, step: [1 / level.size.width, 0] },
          { from: level.pong, to: level.ping, step: [0, 1 / level.size.height] },
        ]
        passes.forEach(({ from, to, step }) => {
          const blur = renderTo(to, programs.blur)
          gl.activeTexture(gl.TEXTURE0)
          gl.bindTexture(gl.TEXTURE_2D, from.texture)
          if (blur.source) gl.uniform1i(blur.source, 0)
          if (blur.step) gl.uniform2f(blur.step, step[0], step[1])
          drawQuad(blur)
        })
      })

      // ── Add it back over the frame ─────────────────────────────────────────────────────────
      gl.bindFramebuffer(gl.FRAMEBUFFER, previousFramebuffer)
      gl.viewport(previousViewport[0], previousViewport[1], previousViewport[2], previousViewport[3])
      gl.enable(gl.BLEND)
      gl.blendFunc(gl.ONE, gl.ONE)
      const composite = programs.composite
      gl.useProgram(composite.program)
      gl.activeTexture(gl.TEXTURE0)
      gl.bindTexture(gl.TEXTURE_2D, levels[0].ping.texture)
      gl.activeTexture(gl.TEXTURE1)
      gl.bindTexture(gl.TEXTURE_2D, levels[1].ping.texture)
      if (composite.tight) gl.uniform1i(composite.tight, 0)
      if (composite.wide) gl.uniform1i(composite.wide, 1)
      if (composite.strength) gl.uniform1f(composite.strength, state.strength)
      if (composite.tint) gl.uniform3f(composite.tint, ...state.tint)
      drawQuad(composite)

      // ── Put it all back ────────────────────────────────────────────────────────────────────
      gl.activeTexture(gl.TEXTURE0)
      gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA)   // MapLibre's premultiplied default
      gl.depthMask(true)
    },

    setOptions(next = {}) {
      state = { ...state, ...next }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
  }
}

export const BLOOM_LAYER_ID = LAYER_ID

/**
 * render-target.js — offscreen framebuffers, and putting MapLibre's state back afterwards.
 *
 * Any screen-space pass on this map needs the same three things, and none of them is interesting
 * enough to write twice: a colour texture with a framebuffer on it, a full-screen quad, and a way
 * to leave the GL context exactly as MapLibre handed it over.
 *
 * THE LAST ONE IS WHY THIS FILE EXISTS. A custom layer that binds a framebuffer and forgets to
 * unbind it sends every MapLibre layer drawn afterwards into a texture nobody looks at. The map
 * stops updating, the last good frame stays on screen, and there is no error anywhere — so the
 * symptom is "the map froze" and the cause is four files away. Same for the viewport, the blend
 * function and the depth mask. Getting that restore right once, here, is worth more than any of
 * the rendering above it.
 *
 * UNSIGNED_BYTE, not float. Half-float render targets need EXT_color_buffer_half_float, which is
 * not universal on the hardware in a classroom, and the fallback path for a missing extension is
 * exactly the sort of thing that works on the developer's machine forever. A pass that wants
 * headroom above 1.0 should encode it rather than assume the buffer has it.
 */

/**
 * A colour texture with a framebuffer attached.
 *
 * @param {WebGLRenderingContext} gl
 * @param {number} width   in device pixels, clamped to at least 1
 * @param {number} height
 * @param {boolean} [attachFramebuffer]  false for a texture that is only ever copied INTO, such as
 *                                       a scene grab via copyTexImage2D
 */
export const createRenderTarget = (gl, width, height, attachFramebuffer = true) => {
  // A pane collapsing to a sliver mid-resize would otherwise ask for a 0-sized texture, which is an
  // INVALID_VALUE on every driver and takes the map's whole GL context down with it: a black canvas
  // from a resize, several layers away from anything to do with rendering.
  const w = Math.max(1, Math.floor(width))
  const h = Math.max(1, Math.floor(height))

  const texture = gl.createTexture()
  gl.bindTexture(gl.TEXTURE_2D, texture)
  gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, w, h, 0, gl.RGBA, gl.UNSIGNED_BYTE, null)
  // CLAMP_TO_EDGE, never REPEAT: a blur kernel reaching past the edge of a wrapping texture pulls
  // the far side of the screen into the near one, so a bright sun on the left leaves a ghost of
  // itself on the right.
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
  // LINEAR both ways: the whole point of a downsampled chain is that the hardware's bilinear filter
  // does half the blurring for free.
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)

  let framebuffer = null
  if (attachFramebuffer) {
    framebuffer = gl.createFramebuffer()
    gl.bindFramebuffer(gl.FRAMEBUFFER, framebuffer)
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0)
  }

  return { texture, framebuffer, width: w, height: h }
}

/** Free a target. Safe to call on a half-built one. */
export const destroyRenderTarget = (gl, target) => {
  if (!target) return
  if (target.texture) gl.deleteTexture(target.texture)
  if (target.framebuffer) gl.deleteFramebuffer(target.framebuffer)
}

/**
 * The sizes of a halving chain: full/2, /4, /8 … `levels` deep.
 *
 * Every level is clamped to at least one pixel, so a deep chain on a small canvas ends in a run of
 * 1×1 targets rather than in an INVALID_VALUE.
 */
export const mipChainSizes = (width, height, levels) => {
  const sizes = []
  for (let level = 1; level <= levels; level++) {
    const divisor = 2 ** level
    sizes.push({
      width: Math.max(1, Math.floor(width / divisor)),
      height: Math.max(1, Math.floor(height / divisor)),
    })
  }
  return sizes
}

/** A halving chain of targets, largest first. */
export const createMipChain = (gl, width, height, levels) =>
  mipChainSizes(width, height, levels).map((size) => createRenderTarget(gl, size.width, size.height))

/** One triangle strip covering clip space, for every full-screen pass there will ever be. */
const SCREEN_QUAD = new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1])

export const createScreenQuad = (gl) => {
  const buffer = gl.createBuffer()
  gl.bindBuffer(gl.ARRAY_BUFFER, buffer)
  gl.bufferData(gl.ARRAY_BUFFER, SCREEN_QUAD, gl.STATIC_DRAW)
  return buffer
}

/** The vertex shader every screen-space pass shares. No projection: this is already clip space. */
export const SCREEN_QUAD_VERTEX_GLSL = /* glsl */`precision highp float;
attribute vec2 a_corner;
varying vec2 v_uv;
void main() {
  v_uv = a_corner * 0.5 + 0.5;
  gl_Position = vec4(a_corner, 0.0, 1.0);
}`

/**
 * Everything MapLibre expects to still be true when a custom layer hands the context back.
 *
 * Read once at the top of `render`, restored once at the bottom. Reading the framebuffer binding
 * rather than assuming `null` matters: with terrain on, MapLibre is drawing into a framebuffer of
 * its own, and unbinding to the default would put the rest of the frame on the screen instead of
 * into the terrain buffer.
 */
export const captureGlState = (gl) => ({
  framebuffer: gl.getParameter(gl.FRAMEBUFFER_BINDING),
  viewport: gl.getParameter(gl.VIEWPORT),
})

export const restoreGlState = (gl, saved) => {
  gl.bindFramebuffer(gl.FRAMEBUFFER, saved.framebuffer)
  gl.viewport(saved.viewport[0], saved.viewport[1], saved.viewport[2], saved.viewport[3])
  gl.activeTexture(gl.TEXTURE0)
  // MapLibre blends custom layers premultiplied. Leaving it additive makes the next layer glow.
  gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA)
  gl.enable(gl.BLEND)
  gl.depthMask(true)
}

/** Point a pass at a target: bind its framebuffer, match the viewport to it, use its program. */
export const beginPass = (gl, target, program) => {
  gl.bindFramebuffer(gl.FRAMEBUFFER, target.framebuffer)
  gl.viewport(0, 0, target.width, target.height)
  gl.useProgram(program)
}

/** Draw the full-screen quad through the currently bound program. */
export const drawScreenQuad = (gl, quadBuffer, cornerAttrib) => {
  gl.bindBuffer(gl.ARRAY_BUFFER, quadBuffer)
  gl.enableVertexAttribArray(cornerAttrib)
  gl.vertexAttribPointer(cornerAttrib, 2, gl.FLOAT, false, 0, 0)
  gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4)
}

/** Bind a texture to a unit and point a sampler uniform at it. */
export const bindTextureUnit = (gl, unit, texture, location) => {
  gl.activeTexture(gl.TEXTURE0 + unit)
  gl.bindTexture(gl.TEXTURE_2D, texture)
  if (location) gl.uniform1i(location, unit)
}

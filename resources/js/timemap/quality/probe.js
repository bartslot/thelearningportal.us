/**
 * probe.js — measure the GPU instead of asking it what it is called.
 *
 * A renderer string tells you a marketing name. It does not tell you that this Chromebook is
 * throttled, that this Windows box fell back to a software rasteriser, or that the machine is
 * already running two other WebGL pages. A short measured render does.
 *
 * The probe draws a deliberately fragment-bound shader into a small offscreen target and times it.
 * It is bounded twice — by frame count and by a wall-clock ceiling — because the device it matters
 * most on is the one where it would take longest, and a startup check that costs 400 ms has made
 * the problem it was measuring worse.
 *
 * `gl.finish()` is not enough on its own to know a frame landed; a one-pixel `readPixels` forces
 * the pipeline to drain, which is the same trick the tile harness uses to measure honestly.
 */

import { summarizeFrames } from './monitor.js'

const VERTEX = `
attribute vec2 a_pos;
void main() { gl_Position = vec4(a_pos, 0.0, 1.0); }`

/**
 * Enough arithmetic per fragment to be limited by the GPU rather than by the driver call. The loop
 * count is a constant so it unrolls the same way everywhere; a uniform bound would let a clever
 * compiler skip it.
 */
const FRAGMENT = `
precision highp float;
uniform float u_seed;
void main() {
  vec2 p = gl_FragCoord.xy * 0.01;
  float acc = 0.0;
  for (int i = 0; i < 64; i++) {
    p = vec2(p.x * 1.31 + sin(p.y + u_seed), p.y * 1.17 + cos(p.x - u_seed));
    acc += 1.0 / (1.0 + dot(p, p));
  }
  gl_FragColor = vec4(fract(acc), fract(acc * 0.5), fract(acc * 0.25), 1.0);
}`

const compile = (gl, type, source) => {
  const shader = gl.createShader(type)
  gl.shaderSource(shader, source)
  gl.compileShader(shader)
  if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
    gl.deleteShader(shader)
    return null
  }
  return shader
}

const DEFAULTS = { frames: 12, size: 128, budgetMs: 60, warmupFrames: 2 }

/**
 * Run the probe against a live context.
 *
 * @param {object} opts
 * @param {WebGLRenderingContext|WebGL2RenderingContext} opts.gl
 * @param {number} [opts.frames]     frames to time after warm-up
 * @param {number} [opts.size]       square render target edge
 * @param {number} [opts.budgetMs]   hard ceiling on total wall time
 * @param {Function} [opts.now]      injected clock
 * @returns {{medianMs:number, p95Ms:number, frames:number, aborted:boolean}|null}
 *          null when the probe could not run at all, which is itself not evidence of a slow device
 */
export const runRenderProbe = ({ gl, ...opts } = {}) => {
  const config = { ...DEFAULTS, ...opts }
  const now = config.now || (() => (typeof performance !== 'undefined' ? performance.now() : Date.now()))
  if (!gl) return null

  const created = { program: null, vertex: null, fragment: null, buffer: null, texture: null, framebuffer: null }

  const release = () => {
    // A probe that leaked a framebuffer on every page load would be a slow memory fault in the one
    // part of the system whose entire job is not to cost anything.
    try {
      if (created.framebuffer) gl.deleteFramebuffer(created.framebuffer)
      if (created.texture) gl.deleteTexture(created.texture)
      if (created.buffer) gl.deleteBuffer(created.buffer)
      if (created.program) gl.deleteProgram(created.program)
      if (created.vertex) gl.deleteShader(created.vertex)
      if (created.fragment) gl.deleteShader(created.fragment)
    } catch { /* context already lost; nothing left to free */ }
  }

  try {
    created.vertex = compile(gl, gl.VERTEX_SHADER, VERTEX)
    created.fragment = compile(gl, gl.FRAGMENT_SHADER, FRAGMENT)
    if (!created.vertex || !created.fragment) { release(); return null }

    created.program = gl.createProgram()
    gl.attachShader(created.program, created.vertex)
    gl.attachShader(created.program, created.fragment)
    gl.linkProgram(created.program)
    if (!gl.getProgramParameter(created.program, gl.LINK_STATUS)) { release(); return null }

    created.texture = gl.createTexture()
    gl.bindTexture(gl.TEXTURE_2D, created.texture)
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, config.size, config.size, 0, gl.RGBA, gl.UNSIGNED_BYTE, null)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST)

    created.framebuffer = gl.createFramebuffer()
    gl.bindFramebuffer(gl.FRAMEBUFFER, created.framebuffer)
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, created.texture, 0)
    if (gl.checkFramebufferStatus(gl.FRAMEBUFFER) !== gl.FRAMEBUFFER_COMPLETE) { release(); return null }

    created.buffer = gl.createBuffer()
    gl.bindBuffer(gl.ARRAY_BUFFER, created.buffer)
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW)

    const position = gl.getAttribLocation(created.program, 'a_pos')
    const seed = gl.getUniformLocation(created.program, 'u_seed')
    const pixel = new Uint8Array(4)

    gl.useProgram(created.program)
    gl.enableVertexAttribArray(position)
    gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0)
    gl.viewport(0, 0, config.size, config.size)
    gl.disable(gl.BLEND)
    gl.disable(gl.DEPTH_TEST)

    const drawOnce = (index) => {
      gl.uniform1f(seed, index * 0.37)
      gl.drawArrays(gl.TRIANGLES, 0, 3)
      // Drains the pipeline. Without it every measurement is the cost of queueing, not of drawing.
      gl.readPixels(0, 0, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, pixel)
    }

    for (let i = 0; i < config.warmupFrames; i++) drawOnce(i)

    const durations = []
    const startedAt = now()
    let aborted = false
    for (let i = 0; i < config.frames; i++) {
      const frameStart = now()
      drawOnce(i + config.warmupFrames)
      durations.push(now() - frameStart)
      if (now() - startedAt > config.budgetMs) { aborted = true; break }
    }

    gl.bindFramebuffer(gl.FRAMEBUFFER, null)
    release()

    // The probe's own frames are far cheaper than a real one, so `stallMs` is raised: a 400 ms probe
    // frame is the finding, not an outlier to discard.
    const summary = summarizeFrames(durations, { stallMs: Number.POSITIVE_INFINITY })
    if (summary.medianMs == null) return null
    return { probeMs: summary.medianMs, probeP95Ms: summary.p95Ms, frames: summary.frames, pixels: config.size * config.size, aborted }
  } catch {
    release()
    return null
  }
}

/**
 * Cost of one real fragment relative to one probe fragment.
 *
 * Below 1, and that direction is the whole point: the probe runs 64 iterations of transcendentals
 * per fragment, which is far heavier per pixel than anything the globe actually draws. Scaling the
 * probe up by pixel count alone would report a discrete GPU as taking half a second a frame.
 *
 * **This number is not calibrated.** Calibrating it needs paired measurements — probe result
 * against real `render` timings — on GPU-backed hardware in at least two classes of device, and
 * figures from a software rasteriser are meaningless for it. The value below is a placeholder
 * derived from the shader's instruction count, not from a measurement.
 *
 * Because it is uncalibrated, `estimateFrameMs` marks the result `calibrated: false`, and
 * `tiers.js` refuses to make a fine-grained judgement from it — an uncalibrated reading can only
 * trigger the extreme "this device cannot render at all" rule, which survives being wrong by an
 * order of magnitude. Letting an unverified constant quietly downgrade every device would be a
 * worse failure than not probing at all.
 */
const WORKLOAD_FACTOR = 0.3

/**
 * Turn a probe reading into an estimate of one real frame, which is what `tiers.js` thresholds are
 * expressed in.
 *
 * @param {object} probe    from `runRenderProbe`
 * @param {object} profile  needs `viewport` and `dpr` — the probe's 128² says nothing on its own
 */
export const estimateFrameMs = (probe, profile) => {
  if (!probe || probe.probeMs == null) return null
  const width = (profile?.viewport?.width || 0) * (profile?.dpr || 1)
  const height = (profile?.viewport?.height || 0) * (profile?.dpr || 1)
  const framePixels = width * height
  if (!framePixels) return null

  return {
    medianMs: (probe.probeMs / probe.pixels) * framePixels * WORKLOAD_FACTOR,
    probeMs: probe.probeMs,
    frames: probe.frames,
    aborted: probe.aborted,
    calibrated: false,
  }
}

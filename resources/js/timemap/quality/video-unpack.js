/**
 * video-unpack.js — recombine an alpha-packed video into a transparent picture.
 *
 * The capture pipeline stacks colour over a greyscale alpha plane in one ordinary H.264 frame,
 * because VP9-with-alpha does not play on Safari or iOS and this product ships an iOS PWA. Reading
 * it back is the two lines of GLSL below: sample the top half for colour, the bottom half for
 * coverage.
 *
 * Loaded only by `video-fallback.js`, and only when the packed source is the one that was chosen.
 * A device on VP9-alpha or on the opaque loop never fetches this file.
 *
 * Premultiplied, because that is what the compositor expects and what MapLibre custom layers use.
 * Multiplying colour by alpha here rather than leaving it to blending is what stops a dark halo
 * appearing around every edge.
 */

const VERTEX = `
attribute vec2 a_pos;
varying vec2 v_uv;
void main() {
  v_uv = a_pos * 0.5 + 0.5;
  gl_Position = vec4(a_pos, 0.0, 1.0);
}`

const FRAGMENT = `
precision mediump float;
uniform sampler2D u_frame;
varying vec2 v_uv;
void main() {
  // Colour lives in the top half, alpha in the bottom. Texture V runs bottom-up, so the top half of
  // the picture is the upper half of the range.
  vec2 colourUv = vec2(v_uv.x, v_uv.y * 0.5 + 0.5);
  vec2 alphaUv  = vec2(v_uv.x, v_uv.y * 0.5);
  vec3 colour = texture2D(u_frame, colourUv).rgb;
  float alpha = texture2D(u_frame, alphaUv).r;
  gl_FragColor = vec4(colour * alpha, alpha);
}`

const compile = (gl, type, source) => {
  const shader = gl.createShader(type)
  gl.shaderSource(shader, source)
  gl.compileShader(shader)
  if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
    const log = gl.getShaderInfoLog(shader)
    gl.deleteShader(shader)
    throw new Error(`quality: unpack shader failed to compile — ${log}`)
  }
  return shader
}

/**
 * @param {object}  opts
 * @param {Element} opts.container  the unpacked canvas is appended here and the video is hidden
 * @param {HTMLVideoElement} opts.video
 */
export const createPackedAlphaUnpacker = ({ container, video }) => {
  const canvas = container.ownerDocument.createElement('canvas')
  canvas.style.cssText = 'width:100%;height:100%;display:block'
  container.appendChild(canvas)
  video.style.display = 'none'

  const gl = canvas.getContext('webgl', { premultipliedAlpha: true, alpha: true, antialias: false })
  if (!gl) throw new Error('quality: no WebGL context for the packed-alpha fallback')

  const program = gl.createProgram()
  const vertex = compile(gl, gl.VERTEX_SHADER, VERTEX)
  const fragment = compile(gl, gl.FRAGMENT_SHADER, FRAGMENT)
  gl.attachShader(program, vertex)
  gl.attachShader(program, fragment)
  gl.linkProgram(program)
  if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
    throw new Error(`quality: unpack program failed to link — ${gl.getProgramInfoLog(program)}`)
  }

  const buffer = gl.createBuffer()
  gl.bindBuffer(gl.ARRAY_BUFFER, buffer)
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW)

  const texture = gl.createTexture()
  gl.bindTexture(gl.TEXTURE_2D, texture)
  for (const [key, value] of [
    [gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE],
    [gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE],
    [gl.TEXTURE_MIN_FILTER, gl.LINEAR],
    [gl.TEXTURE_MAG_FILTER, gl.LINEAR],
  ]) gl.texParameteri(gl.TEXTURE_2D, key, value)

  const position = gl.getAttribLocation(program, 'a_pos')
  const frameUniform = gl.getUniformLocation(program, 'u_frame')
  let raf = 0
  let disposed = false

  const draw = () => {
    if (disposed) return
    raf = requestAnimationFrame(draw)
    if (video.readyState < 2) return

    // Each packed frame is twice the height of the picture it carries.
    const width = video.videoWidth
    const height = Math.floor(video.videoHeight / 2)
    if (canvas.width !== width || canvas.height !== height) {
      canvas.width = width
      canvas.height = height
    }

    gl.bindTexture(gl.TEXTURE_2D, texture)
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false)
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, video)

    gl.viewport(0, 0, canvas.width, canvas.height)
    gl.clearColor(0, 0, 0, 0)
    gl.clear(gl.COLOR_BUFFER_BIT)
    gl.useProgram(program)
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer)
    gl.enableVertexAttribArray(position)
    gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0)
    gl.uniform1i(frameUniform, 0)
    gl.drawArrays(gl.TRIANGLES, 0, 3)
  }

  raf = requestAnimationFrame(draw)

  return {
    canvas,
    dispose () {
      disposed = true
      cancelAnimationFrame(raf)
      gl.deleteTexture(texture)
      gl.deleteBuffer(buffer)
      gl.deleteProgram(program)
      gl.deleteShader(vertex)
      gl.deleteShader(fragment)
      canvas.remove()
      video.style.display = ''
    },
  }
}

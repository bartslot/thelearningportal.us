/**
 * A WebGL context that answers, counts, and can be told to fail.
 *
 * Enough of the API for `probe.js` and `video-unpack.js` to run end to end under jsdom, which has
 * no GL at all. It tracks every object it hands out and whether that object was deleted, so a test
 * can assert the thing that is otherwise invisible: that a probe running on every page load does
 * not leak a framebuffer each time.
 */

import { vi } from 'vitest'

const ENUMS = {
  VERTEX_SHADER: 0x8b31,
  FRAGMENT_SHADER: 0x8b30,
  COMPILE_STATUS: 0x8b81,
  LINK_STATUS: 0x8b82,
  ARRAY_BUFFER: 0x8892,
  STATIC_DRAW: 0x88e4,
  TEXTURE_2D: 0x0de1,
  RGBA: 0x1908,
  UNSIGNED_BYTE: 0x1401,
  FLOAT: 0x1406,
  TEXTURE_MIN_FILTER: 0x2801,
  TEXTURE_MAG_FILTER: 0x2800,
  TEXTURE_WRAP_S: 0x2802,
  TEXTURE_WRAP_T: 0x2803,
  CLAMP_TO_EDGE: 0x812f,
  NEAREST: 0x2600,
  LINEAR: 0x2601,
  FRAMEBUFFER: 0x8d40,
  COLOR_ATTACHMENT0: 0x8ce0,
  FRAMEBUFFER_COMPLETE: 0x8cd5,
  TRIANGLES: 0x0004,
  BLEND: 0x0be2,
  DEPTH_TEST: 0x0b71,
  COLOR_BUFFER_BIT: 0x4000,
  UNPACK_FLIP_Y_WEBGL: 0x9240,
}

/**
 * @param {object} [opts]
 * @param {boolean} [opts.compiles=true]
 * @param {boolean} [opts.links=true]
 * @param {boolean} [opts.framebufferComplete=true]
 * @param {number}  [opts.msPerDraw=0]   advances the injected clock, so timing can be asserted
 * @param {string}  [opts.throwsOn]      name of a method that raises, as a lost context does
 */
export const createFakeGl = ({
  compiles = true,
  links = true,
  framebufferComplete = true,
  msPerDraw = 0,
  throwsOn = null,
} = {}) => {
  const live = new Set()
  const deleted = new Set()
  let clock = 0
  let handles = 0

  const make = (kind) => {
    const handle = { kind, id: ++handles }
    live.add(handle)
    return handle
  }
  const drop = (handle) => {
    if (handle) { live.delete(handle); deleted.add(handle) }
  }
  const guard = (name, fn) => (...args) => {
    if (throwsOn === name) throw new Error(`fake-gl: ${name} on a lost context`)
    return fn(...args)
  }

  const gl = {
    ...ENUMS,

    createShader: vi.fn(() => make('shader')),
    shaderSource: vi.fn(),
    compileShader: vi.fn(),
    getShaderParameter: vi.fn(() => compiles),
    getShaderInfoLog: vi.fn(() => 'fake compile log'),
    deleteShader: vi.fn(drop),

    createProgram: vi.fn(() => make('program')),
    attachShader: vi.fn(),
    linkProgram: vi.fn(),
    getProgramParameter: vi.fn(() => links),
    getProgramInfoLog: vi.fn(() => 'fake link log'),
    deleteProgram: vi.fn(drop),
    useProgram: vi.fn(),

    createBuffer: vi.fn(() => make('buffer')),
    bindBuffer: vi.fn(),
    bufferData: vi.fn(),
    deleteBuffer: vi.fn(drop),

    createTexture: vi.fn(() => make('texture')),
    bindTexture: vi.fn(),
    texImage2D: vi.fn(),
    texParameteri: vi.fn(),
    pixelStorei: vi.fn(),
    deleteTexture: vi.fn(drop),

    createFramebuffer: vi.fn(() => make('framebuffer')),
    bindFramebuffer: vi.fn(),
    framebufferTexture2D: vi.fn(),
    checkFramebufferStatus: vi.fn(() => (framebufferComplete ? ENUMS.FRAMEBUFFER_COMPLETE : 0)),
    deleteFramebuffer: vi.fn(drop),

    getAttribLocation: vi.fn(() => 0),
    getUniformLocation: vi.fn(() => ({ kind: 'uniform' })),
    enableVertexAttribArray: vi.fn(),
    vertexAttribPointer: vi.fn(),
    uniform1f: vi.fn(),
    uniform1i: vi.fn(),
    viewport: vi.fn(),
    disable: vi.fn(),
    clear: vi.fn(),
    clearColor: vi.fn(),

    drawArrays: vi.fn(guard('drawArrays', () => { clock += msPerDraw })),
    readPixels: vi.fn(guard('readPixels', () => {})),

    /** Injected clock, advanced only by drawing, so probe timings are deterministic. */
    now: () => clock,
    advance: (ms) => { clock += ms },
    /** GL objects handed out and never deleted. Should be empty once a module has disposed. */
    leaked: () => [...live],
    deletedCount: () => deleted.size,
  }

  return gl
}

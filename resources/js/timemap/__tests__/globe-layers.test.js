import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { CLOUD_ALTITUDE_M } from '../cloud-field.js'
import { EARTH_RADIUS_M } from '../planet-mesh.js'
import { createAtmosphereLayer, ATMOSPHERE_LAYER_ID } from '../atmosphere.js'
import { createDaylightLayer, DAYLIGHT_LAYER_ID } from '../daylight.js'
import { createStarfieldLayer, STARFIELD_LAYER_ID } from '../starfield.js'
import { createMoonLayer, MOON_LAYER_ID } from '../moon.js'
import { createCloudLayer, CLOUD_LAYER_ID } from '../clouds.js'

/**
 * The contract every globe overlay has to keep.
 *
 * These are the failures that cost the most time on this map, and every one of them is silent — no
 * exception, no console error, just an empty sky:
 *
 *  - Reading MapLibre v4's arguments. v5 hands `render` an options object, not a matrix; a layer
 *    that assumes the old shape feeds `uniformMatrix4fv` a `{farZ, shaderData, ...}` and draws
 *    nothing. Every layer must REFUSE rather than mis-draw.
 *  - Compiling one program forever. The projection prelude differs between globe and mercator, and
 *    the variant flips mid-flight when the map crosses the threshold.
 *  - Leaking GL objects on every style reload.
 *  - Drawing when told not to. A layer at zero strength must cost nothing.
 */

const glStub = () => {
  const calls = { deleteProgram: 0, deleteBuffer: 0, deleteTexture: 0, drawElements: 0, uniformMatrix4fv: [] }
  const gl = {
    ARRAY_BUFFER: 1, ELEMENT_ARRAY_BUFFER: 2, STATIC_DRAW: 3, FLOAT: 4, TRIANGLES: 5,
    UNSIGNED_SHORT: 6, VERTEX_SHADER: 7, FRAGMENT_SHADER: 8, COMPILE_STATUS: 9, LINK_STATUS: 10,
    DEPTH_TEST: 11, LEQUAL: 12, TEXTURE_2D: 20, TEXTURE0: 21, TEXTURE1: 33, RGBA: 22,
    UNSIGNED_BYTE: 23, TEXTURE_WRAP_S: 24, TEXTURE_WRAP_T: 25, TEXTURE_MIN_FILTER: 26,
    TEXTURE_MAG_FILTER: 27, REPEAT: 28, CLAMP_TO_EDGE: 29, LINEAR_MIPMAP_LINEAR: 30, LINEAR: 31,
    UNPACK_FLIP_Y_WEBGL: 32,
    createBuffer: () => ({}), bindBuffer: () => {}, bufferData: () => {},
    createShader: () => ({}), shaderSource: () => {}, compileShader: () => {}, deleteShader: () => {},
    createProgram: () => ({}), attachShader: () => {}, linkProgram: () => {},
    getShaderParameter: () => true, getProgramParameter: () => true,
    getAttribLocation: (_p, n) => n, getUniformLocation: (_p, n) => n,
    useProgram: () => {}, enableVertexAttribArray: () => {}, vertexAttribPointer: () => {},
    enable: () => {}, disable: () => {}, depthFunc: () => {}, depthMask: () => {},
    uniform1f: () => {}, uniform1i: () => {}, uniform2f: () => {}, uniform3f: () => {}, uniform4f: () => {},
    activeTexture: () => {}, bindTexture: () => {}, createTexture: () => ({}),
    deleteTexture: () => { calls.deleteTexture++ },
    texImage2D: () => {}, texParameteri: () => {}, generateMipmap: () => {}, pixelStorei: () => {},
    uniformMatrix4fv: (_l, _t, v) => calls.uniformMatrix4fv.push(v),
    deleteProgram: () => { calls.deleteProgram++ },
    deleteBuffer: () => { calls.deleteBuffer++ },
    drawElements: () => { calls.drawElements++ },
  }
  return { gl, calls }
}

const mapStub = () => ({
  getCenter: () => ({ lng: 0, lat: 40 }),
  getZoom: () => 2,
  getCanvas: () => ({ width: 1200, height: 800 }),
  transform: { getCameraLngLat: () => ({ lng: 0, lat: 40 }), getCameraAltitude: () => 9e6 },
  triggerRepaint: vi.fn(),
})

const v5Args = (variantName = 'globe') => ({
  farZ: 1e7, nearZ: 1, fov: 0.6435,
  modelViewProjectionMatrix: new Float32Array(16),
  projectionMatrix: new Float32Array(16),
  shaderData: {
    variantName,
    vertexShaderPrelude: 'vec4 projectTileFor3D(vec2 p, float e);',
    define: variantName === 'globe' ? '#define GLOBE' : '',
  },
  defaultProjectionData: {
    mainMatrix: new Float32Array(16),
    tileMercatorCoords: [0, 0, 1, 1],
    clippingPlane: [0, 0, 0, 0],
    projectionTransition: 0,
    fallbackMatrix: new Float32Array(16),
  },
})

// Each layer, with the options that make it draw, and the option that switches it off.
const LAYERS = [
  { name: 'atmosphere', id: ATMOSPHERE_LAYER_ID, make: (o) => createAtmosphereLayer(o), off: { strength: 0 }, buffers: 3 },
  { name: 'daylight', id: DAYLIGHT_LAYER_ID, make: (o) => createDaylightLayer(o), off: { nightDarkness: 0 }, buffers: 3 },
  { name: 'starfield', id: STARFIELD_LAYER_ID, make: (o) => createStarfieldLayer(o), off: { brightness: 0 }, buffers: 3 },
  { name: 'moon', id: MOON_LAYER_ID, make: (o) => createMoonLayer(o), off: { brightness: 0 }, buffers: 2 },
  { name: 'clouds', id: CLOUD_LAYER_ID, make: (o) => createCloudLayer(o), off: { opacity: 0 }, buffers: 3 },
]

describe.each(LAYERS)('$name layer', ({ id, make, off, buffers }) => {
  it('draws from v5 arguments, with real matrices', () => {
    const { gl, calls } = glStub()
    const layer = make()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(calls.drawElements).toBe(1)
    calls.uniformMatrix4fv.forEach((v) => expect(v).toBeInstanceOf(Float32Array))
  })

  it("refuses v4's bare matrix rather than drawing garbage", () => {
    const { gl, calls } = glStub()
    const layer = make()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, new Float32Array(16))
    expect(calls.drawElements).toBe(0)
  })

  it('compiles one program per projection variant and reuses it', () => {
    const { gl } = glStub()
    const created = vi.spyOn(gl, 'createProgram')
    const layer = make()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args('globe'))
    layer.render(gl, v5Args('globe'))
    expect(created).toHaveBeenCalledTimes(1)
    layer.render(gl, v5Args('mercator'))
    expect(created).toHaveBeenCalledTimes(2)
  })

  it('frees its program and buffers on removal', () => {
    const { gl, calls } = glStub()
    const layer = make()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    layer.onRemove()
    expect(calls.deleteProgram).toBe(1)
    expect(calls.deleteBuffer).toBe(buffers)
  })

  it('draws nothing when switched off, so hiding it is free', () => {
    const { gl, calls } = glStub()
    const layer = make(off)
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(calls.drawElements).toBe(0)
  })

  it('keeps a stable id — styles anchor other layers to it', () => {
    expect(make().id).toBe(id)
    expect(id.startsWith('tm-')).toBe(true)
  })

  it('survives being removed before it was ever added', () => {
    const layer = make()
    expect(() => layer.onRemove()).not.toThrow()
  })
})

describe('the moon is not drawn where the moon is', () => {
  /**
   * MapLibre's globe projection cannot reach the moon. Past roughly six earth radii from the centre
   * a point's clip-space w goes negative and it is dropped before rasterisation — measured at z1.9:
   * w = +115 at five radii, -194 at six, and -17,282 for the moon at its true sixty. The layer drew
   * exactly zero pixels across a full-canvas scan of 990,000, silently, because nothing was wrong
   * with it.
   *
   * So it is drawn on the same sight line at a reachable distance, which costs nothing: direction
   * and angular size are still computed from the true geometry, and distance is not observable.
   * This test exists because "put it at its real distance" is the obvious, well-meant change that
   * would make the moon disappear again with no error to explain why.
   */
  const recordingGl = () => {
    const uniforms = {}
    const { gl, calls } = glStub()
    // getUniformLocation in the stub returns the uniform's NAME, so these key by name.
    gl.uniform1f = (name, value) => { uniforms[name] = value }
    gl.uniform2f = (name, x, y) => { uniforms[name] = [x, y] }
    return { gl, calls, uniforms }
  }

  const TRUE_LUNAR_DISTANCE_M = 3.6e8   // nearest perigee is about 3.63e8

  it('places the billboard within the projection\'s reach, not at 384,000 km', () => {
    const { gl, uniforms } = recordingGl()
    const layer = createMoonLayer({ date: new Date(Date.UTC(2026, 7, 7, 12)) })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())

    const elevation = uniforms.a_elevation_globe
    expect(Number.isFinite(elevation)).toBe(true)
    expect(elevation).toBeGreaterThan(0)
    expect(elevation).toBeLessThan(TRUE_LUNAR_DISTANCE_M / 2)
  })

  it('keeps the disc at its true angular size — half a degree, whatever the date', () => {
    // The size must come from the REAL distance. If it were derived from the shortened draw
    // distance the moon would balloon to fill the sky, which is the tell that the two have been
    // conflated.
    const FOV = 0.6435
    for (const month of [0, 3, 6, 9]) {
      const { gl, uniforms } = recordingGl()
      const layer = createMoonLayer({ date: new Date(Date.UTC(2026, month, 15)) })
      layer.onAdd(mapStub(), gl)
      layer.render(gl, { ...v5Args(), fov: FOV })

      // u_size.y is the disc's half-height as a fraction of the half field of view.
      const halfHeight = uniforms.u_size[1]
      const apparentDeg = 2 * Math.atan(halfHeight * Math.tan(FOV / 2)) * 180 / Math.PI
      // The moon runs 0.49° at apogee to 0.57° at perigee. Anything outside that means the draw
      // distance has leaked into the size.
      expect(apparentDeg).toBeGreaterThan(0.47)
      expect(apparentDeg).toBeLessThan(0.59)
    }
  })
})

describe('equirectangular lookups', () => {
  /**
   * A sphere seen from inside is the MIRROR of the same sphere seen from outside, so the sky and
   * the planet cannot share one UV lookup. Getting this wrong flips the galaxy east for west, and
   * nothing catches it: the sky still looks like a sky, still parallaxes correctly as the camera
   * orbits, and raises no error anywhere. Only someone who knows the constellations would notice.
   *
   * There is no numeric output to assert on — the mapping exists only as GLSL — so the source is
   * read as text. Crude, but it pins the one property that matters.
   */
  // vitest rewrites import.meta.url to a bare path, so resolve from the repo root instead.
  const read = (name) => readFileSync(resolve(process.cwd(), 'resources/js/timemap', name), 'utf8')

  it('defines the inside lookup as the outside one with the horizontal angle reversed', () => {
    const mesh = read('planet-mesh.js')
    const inside = mesh.slice(mesh.indexOf('vec2 equirectUVInside('))
    const outside = mesh.slice(mesh.indexOf('vec2 equirectUV('), mesh.indexOf('vec2 equirectUVInside('))
    expect(outside).toContain('atan(dir.z, dir.x) / 6.28318530718')
    expect(inside).toContain('-atan(dir.z, dir.x) / 6.28318530718')
  })

  it('samples the sky from inside and the planet from outside', () => {
    expect(read('starfield.js')).toContain('equirectUVInside(dir, u_rotation)')
    const daylight = read('daylight.js')
    expect(daylight).toContain('equirectUV(normal, 0.0)')
    expect(daylight).not.toContain('equirectUVInside')
  })
})

describe('relief and cloud shadow on the ground', () => {
  /**
   * The two things that turn a lit ball into a lit planet, and the two that are easiest to get
   * silently wrong: relief shading that leans the wrong way, and a cloud field loaded twice.
   */
  let images = []

  beforeEach(() => {
    images = []
    vi.stubGlobal('Image', class {
      constructor() { images.push(this); this.onload = null; this.onerror = null }
      finish() { this.onload && this.onload() }
    })
  })
  afterEach(() => { vi.unstubAllGlobals() })

  const recordingGl = () => {
    const uniforms = {}
    const { gl, calls } = glStub()
    gl.uniform1f = (name, value) => { uniforms[name] = value }
    gl.uniform1i = (name, value) => { uniforms[name] = value }
    gl.uniform3f = (name, x, y, z) => { uniforms[name] = [x, y, z] }
    return { gl, calls, uniforms }
  }

  const zoomedMap = (zoom) => ({ ...mapStub(), getZoom: () => zoom })

  it('loads the cloud field once, however many layers read it', () => {
    // The deck draws the clouds; the ground shades itself with their shadow. Two loads would land
    // on different frames, so for a moment the shadows belong to a sky that has not arrived.
    const { gl } = glStub()
    const deck = createCloudLayer({ fieldUrl: '/clouds.webp' })
    const ground = createDaylightLayer({ fieldUrl: '/clouds.webp' })
    deck.onAdd(mapStub(), gl)
    ground.onAdd(mapStub(), gl)
    expect(images).toHaveLength(1)
    images[0].finish()
    expect(deck.hasField).toBe(true)
    deck.onRemove()
    ground.onRemove()
  })

  it('keeps the field alive for the ground when the deck is removed', () => {
    const { gl, calls } = glStub()
    const deck = createCloudLayer({ fieldUrl: '/clouds.webp' })
    const ground = createDaylightLayer({ fieldUrl: '/clouds.webp' })
    deck.onAdd(mapStub(), gl)
    ground.onAdd(mapStub(), gl)
    images[0].finish()
    deck.onRemove()
    expect(calls.deleteTexture).toBe(0)
    ground.onRemove()
    expect(calls.deleteTexture).toBe(1)
  })

  it('asks for no relief at all without a relief map, so the texture is never sampled', () => {
    const { gl, uniforms } = recordingGl()
    const layer = createDaylightLayer({ reliefPower: 1.5 })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(uniforms.u_reliefPower).toBe(0)
    expect(layer.hasRelief).toBe(false)
  })

  it('shades with the relief once the map has arrived', () => {
    const { gl, uniforms } = recordingGl()
    const layer = createDaylightLayer({ reliefUrl: '/earth-normal.webp', reliefPower: 1.5 })
    layer.onAdd(zoomedMap(2), gl)
    images[0].finish()
    layer.render(gl, v5Args())
    expect(layer.hasRelief).toBe(true)
    expect(uniforms.u_reliefPower).toBeCloseTo(1.5, 5)
  })

  it('retires the relief as the camera descends past the map\'s resolution', () => {
    // The cloud deck already learned this: a globe-tuned texture becomes a smooth wash on approach
    // and nothing errors. Here it would be a low-frequency bulge over imagery that already carries
    // its own baked-in relief shading.
    const at = (zoom) => {
      const { gl, uniforms } = recordingGl()
      const layer = createDaylightLayer({ reliefUrl: '/n.webp', reliefWidth: 8192, reliefPower: 1.5 })
      layer.onAdd(zoomedMap(zoom), gl)
      images[images.length - 1].finish()
      layer.render(gl, v5Args())
      return uniforms.u_reliefPower
    }
    // Retuned against measurement: relief keeps full amplitude well past one texel per pixel, so
    // it holds to z7 and lets go by z11 rather than retiring at z7.5 as the first curve did.
    expect(at(2)).toBeCloseTo(1.5, 5)
    expect(at(7)).toBeCloseTo(1.5, 5)
    expect(at(8)).toBeGreaterThan(0)
    expect(at(8)).toBeLessThan(1.5)
    expect(at(11)).toBe(0)
  })

  it('throws the shadow from the height the deck is actually drawn at', () => {
    // A shadow cast from a truthful 12 km, under a deck drawn at an exaggerated 90 km, separates
    // from the cloud visibly at low sun. The two have to use one number.
    const { gl, uniforms } = recordingGl()
    const layer = createDaylightLayer({ fieldUrl: '/clouds.webp' })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(uniforms.u_cloudAltitude).toBeCloseTo(CLOUD_ALTITUDE_M / EARTH_RADIUS_M, 9)
  })

  it('gives the ground and the deck the same clock, so shadows sit under their clouds', () => {
    const readClock = (layer) => {
      const { gl, uniforms } = recordingGl()
      layer.onAdd(mapStub(), gl)
      images[images.length - 1].finish()
      layer.render(gl, v5Args())
      return uniforms
    }
    const deck = readClock(createCloudLayer({ fieldUrl: '/c.webp', windUrl: '/w.webp', animate: false }))
    const ground = readClock(createDaylightLayer({ fieldUrl: '/c.webp', windUrl: '/w.webp', animate: false }))
    for (const key of ['u_time', 'u_drift', 'u_windScale', 'u_windRate', 'u_windAmount']) {
      expect(ground[key]).toBe(deck[key])
    }
  })
})

describe('the relief conventions the bake and the shader share', () => {
  /**
   * Three things have to agree between an offline script and a fragment shader, and nothing checks
   * them at runtime — a disagreement renders perfectly and simply lights the mountains wrong.
   * There is no numeric output to assert on, so the source is read as text, the way the
   * equirectangular lookups above are.
   */
  const read = (name) => readFileSync(resolve(process.cwd(), 'resources/js/timemap', name), 'utf8')

  it('shades by the difference from the sphere, not by the terrain normal', () => {
    // Shading by the terrain normal alone relights the whole planet and fights imagery that
    // already carries its own relief. The difference is zero over flat ground and largest at the
    // terminator, which is the entire reason this works.
    expect(read('terrain-normals.js'))
      .toContain('1.0 + power * (dot(perturbed, sunDir) - dot(up, sunDir))')
  })

  it('builds the tangent frame as east, south, up — matching R, G, B in the bake', () => {
    const source = read('terrain-normals.js')
    const frame = source.slice(source.indexOf('mat3 equirectTangentFrame'))
    expect(frame).toContain('cross(up, vec3(0.0, 1.0, 0.0))')
    expect(frame).toContain('mat3(east, cross(up, east), up)')
  })

  it('bakes green as south, the direction equirectangular v increases', () => {
    // The classic normal-map bug. Green pointing north would put every mountain's shadow on the
    // wrong side of the ridge, consistently and plausibly.
    const source = read('terrain-normals.js')
    expect(source).toContain('+G  SOUTH')
    expect(source).toContain('out[k + 1] = y / length')
    expect(source).toContain('const y = -dhdySouth')
  })

  it('leaves the sea floor flat, so the ocean is water and not a bathymetric chart', () => {
    expect(read('terrain-normals.js')).toContain('Math.max(0, heights[index])')
  })
})

describe('starfield without its texture', () => {
  it('still draws, because the stars are procedural', () => {
    const { gl, calls } = glStub()
    const layer = createStarfieldLayer({ textureUrl: null })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    // The nebula is an image; the stars are code. A missing asset costs the nebula, not the sky.
    expect(calls.drawElements).toBe(1)
    expect(layer.hasSky).toBe(false)
  })
})

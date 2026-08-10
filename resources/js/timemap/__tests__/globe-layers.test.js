import { describe, it, expect, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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
  const calls = {
    deleteProgram: 0, deleteBuffer: 0, deleteTexture: 0, drawElements: 0, drawArrays: 0,
    uniformMatrix4fv: [], uniformMatrix3fv: [],
  }
  const gl = {
    ARRAY_BUFFER: 1, ELEMENT_ARRAY_BUFFER: 2, STATIC_DRAW: 3, FLOAT: 4, TRIANGLES: 5,
    UNSIGNED_SHORT: 6, VERTEX_SHADER: 7, FRAGMENT_SHADER: 8, COMPILE_STATUS: 9, LINK_STATUS: 10,
    DEPTH_TEST: 11, LEQUAL: 12, TEXTURE_2D: 20, TEXTURE0: 21, TEXTURE1: 33, RGBA: 22,
    UNSIGNED_BYTE: 23, TEXTURE_WRAP_S: 24, TEXTURE_WRAP_T: 25, TEXTURE_MIN_FILTER: 26,
    TEXTURE_MAG_FILTER: 27, REPEAT: 28, CLAMP_TO_EDGE: 29, LINEAR_MIPMAP_LINEAR: 30, LINEAR: 31,
    UNPACK_FLIP_Y_WEBGL: 32, POINTS: 34, MAX_TEXTURE_SIZE: 35,
    getParameter: () => 4096,
    drawArrays: () => { calls.drawArrays++ },
    disableVertexAttribArray: () => {},
    uniformMatrix3fv: (_l, _t, v) => calls.uniformMatrix3fv.push(v),
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

// Each layer, with the options that make it draw, and the option that switches it off. `programs`
// is how many GL programs the layer builds per projection variant — one each, except the starfield,
// which draws the sky as a screen-space quad and the catalogue stars as points, and cannot do both
// from one program.
const LAYERS = [
  { name: 'atmosphere', id: ATMOSPHERE_LAYER_ID, make: (o) => createAtmosphereLayer(o), off: { strength: 0 }, buffers: 3, programs: 1 },
  { name: 'daylight', id: DAYLIGHT_LAYER_ID, make: (o) => createDaylightLayer(o), off: { nightDarkness: 0 }, buffers: 3, programs: 1 },
  { name: 'starfield', id: STARFIELD_LAYER_ID, make: (o) => createStarfieldLayer(o), off: { brightness: 0 }, buffers: 3, programs: 2 },
  { name: 'moon', id: MOON_LAYER_ID, make: (o) => createMoonLayer(o), off: { brightness: 0 }, buffers: 2, programs: 1 },
  { name: 'clouds', id: CLOUD_LAYER_ID, make: (o) => createCloudLayer(o), off: { opacity: 0 }, buffers: 3, programs: 1 },
]

describe.each(LAYERS)('$name layer', ({ id, make, off, buffers, programs }) => {
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
    expect(created).toHaveBeenCalledTimes(programs)
    layer.render(gl, v5Args('mercator'))
    expect(created).toHaveBeenCalledTimes(programs * 2)
  })

  it('frees its program and buffers on removal', () => {
    const { gl, calls } = glStub()
    const layer = make()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    layer.onRemove()
    expect(calls.deleteProgram).toBe(programs)
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

  it('keeps the planet on the outside lookup', () => {
    const daylight = read('daylight.js')
    expect(daylight).toContain('equirectUV(normal, 0.0)')
    expect(daylight).not.toContain('equirectUVInside')
  })

  it('gives the sky its own lookup, derived from the panorama rather than from either of these', () => {
    /**
     * The sky used to share this pair, on the reasoning that a shell seen from inside is the mirror
     * of one seen from outside. That reasoning is sound and it is no longer the question being
     * asked. The panorama is now in celestial coordinates, so what matters is not which side of a
     * sphere it is glued to but what is actually in the file — which was measured, not argued
     * about, and lives in `celestial.js` with the measurement written down beside it.
     *
     * `celestial.test.js` checks that lookup against a thumbnail of the shipped image. This only
     * checks that the sky is not quietly reconnected to the planet's lookup, which would pass every
     * visual review ever conducted on it.
     */
    const sky = read('starfield.js')
    expect(sky).toContain('panoramaUV(skyDir)')
    expect(sky).not.toContain('equirectUV')
  })
})

describe('starfield without its assets', () => {
  it('still draws, because the stars are procedural', () => {
    const { gl, calls } = glStub()
    const layer = createStarfieldLayer({ textureUrl: null, placeholderUrl: null, catalogueUrl: null })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    // The nebula is an image and the bright stars are a download; the field behind them is code. A
    // missing asset costs the nebula, not the sky.
    expect(calls.drawElements).toBe(1)
    expect(layer.hasSky).toBe(false)
    expect(layer.starCount).toBe(0)
  })

  it('draws no catalogue points until the catalogue has arrived', () => {
    const { gl, calls } = glStub()
    const layer = createStarfieldLayer({ textureUrl: null, placeholderUrl: null, catalogueUrl: null })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(calls.drawArrays).toBe(0)
  })
})

describe('the catalogue, once it lands', () => {
  const CATALOGUE = () => {
    const rows = [[101.29, -16.72, -1.46, 9940], [88.79, 7.41, 0.42, 3600], [279.23, 38.78, 0.03, 9600]]
    const data = new Float32Array(rows.length * 4)
    rows.forEach((row, i) => data.set(row, i * 4))
    return data.buffer
  }

  const withCatalogue = async (options = {}) => {
    const { gl, calls } = glStub()
    const fetched = vi.fn(async () => ({ ok: true, arrayBuffer: async () => CATALOGUE() }))
    vi.stubGlobal('fetch', fetched)
    const layer = createStarfieldLayer({ textureUrl: null, placeholderUrl: null, catalogueUrl: '/stars.bin', ...options })
    const map = mapStub()
    layer.onAdd(map, gl)
    await new Promise((resolve) => setTimeout(resolve, 0))
    vi.unstubAllGlobals()
    return { gl, calls, layer, map }
  }

  it('draws the real stars as points, on top of the sky', async () => {
    const { gl, calls, layer } = await withCatalogue()
    expect(layer.starCount).toBe(3)
    layer.render(gl, { ...v5Args(), defaultProjectionData: { ...v5Args().defaultProjectionData, projectionTransition: 1 } })
    expect(calls.drawElements).toBe(1)   // the sky
    expect(calls.drawArrays).toBe(1)     // the stars
  })

  it('leaves them off the flat map, where there is no sky to put them on', async () => {
    const { gl, calls, layer } = await withCatalogue()
    // projectionTransition 0 is the mercator map. Stars over Kansas are the failure here.
    layer.render(gl, v5Args())
    expect(calls.drawArrays).toBe(0)
  })

  it('hands the shader a rotation for the date it was given', async () => {
    const { gl, calls } = await withCatalogue({ date: new Date(Date.UTC(1492, 9, 12, 5)) })
    const layer = createStarfieldLayer({ textureUrl: null, placeholderUrl: null, catalogueUrl: null, date: new Date(Date.UTC(1492, 9, 12, 5)) })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(calls.uniformMatrix3fv.length).toBeGreaterThan(0)
    calls.uniformMatrix3fv.forEach((m) => expect(m).toHaveLength(9))
  })

  it('turns the sky between two dates, because the earth turned', async () => {
    // Six hours apart is a quarter of a turn. A sky that hands the shader the same matrix for both
    // is a sky pinned to the earth rather than to the stars, and looks entirely normal.
    const matrixFor = (date) => {
      const { gl, calls } = glStub()
      const layer = createStarfieldLayer({ textureUrl: null, placeholderUrl: null, catalogueUrl: null, date })
      layer.onAdd(mapStub(), gl)
      layer.render(gl, v5Args())
      return Array.from(calls.uniformMatrix3fv[0])
    }
    const morning = matrixFor(new Date(Date.UTC(2026, 5, 1, 0)))
    const evening = matrixFor(new Date(Date.UTC(2026, 5, 1, 6)))
    const difference = morning.reduce((sum, v, i) => sum + Math.abs(v - evening[i]), 0)
    expect(difference).toBeGreaterThan(1)
  })
})

describe('the sky has no geometry, so it has no distance to be clipped at', () => {
  /**
   * The failure this replaced: the sky was a sphere at a fixed 30,000 km, and a camera further out
   * than that flew through it, leaving a dark ball hanging in space with the earth inside. Making
   * the sphere bigger does not help — past about six earth radii MapLibre's globe projection sends
   * clip-space w negative and drops the geometry entirely, so there is no radius that works at both
   * ends.
   *
   * A screen-space pass has no radius to get wrong. These tests pin that: the layer must not go
   * looking for an altitude, an elevation or a shell, because the moment it does the ceiling is back.
   */
  const read = () => readFileSync(resolve(process.cwd(), 'resources/js/timemap/starfield.js'), 'utf8')

  it('never asks the projection to place anything', () => {
    const source = read()
    expect(source).not.toContain('projectTileFor3D')
    expect(source).not.toContain('a_elevation_globe')
    expect(source).not.toContain('SKY_ALTITUDE')
  })

  it('draws four corners, whatever the camera is doing', () => {
    const { gl, calls } = glStub()
    const layer = createStarfieldLayer({ textureUrl: null, placeholderUrl: null, catalogueUrl: null })
    // Ten earth radii out: nearly twice as far as the old sphere sat, and well past the distance
    // where the projection drops geometry. The layer neither knows nor cares.
    const distant = mapStub()
    distant.transform.getCameraAltitude = () => 6371008.8 * 9
    layer.onAdd(distant, gl)
    layer.render(gl, v5Args())
    expect(calls.drawElements).toBe(1)
  })
})

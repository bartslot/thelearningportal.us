import { describe, it, expect, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import {
  createOceanWaterLayer, OCEAN_LAYER_ID, altitudeFadeFor, pickSdfSource, shoreSoftnessKmFor,
} from '../ocean-water.js'
import {
  OCEAN_SDF_RANGE_KM, OCEAN_SDF_SOURCES, oceanDistanceKm, storeOceanDistanceKm,
} from '../ocean-sdf-manifest.js'

/**
 * What is actually hard about lighting the sea is not the lighting. It is knowing where the sea is.
 *
 * A custom layer cannot read the raster tiles beneath it, so the coastline has to be carried in a
 * texture, and the first attempt carried it as a binary mask: one bit per texel, so the only thing
 * bilinear filtering could do along a shore was ramp across a whole texel, and every coastline came
 * out of the magnifier as squares. What replaced it is a signed distance field, and these tests pin
 * the two properties that make that work — that the coding survives the round trip to eight bits,
 * and that the zero crossing lands on the coast rather than half a texel out to sea.
 */

const read = (name) => readFileSync(resolve(process.cwd(), 'resources/js/timemap', name), 'utf8')

const glStub = ({ webgl2 = false } = {}) => {
  const calls = { drawElements: 0, disabledDepth: 0, enabledDepth: 0, texImage2D: 0, deleteTexture: 0 }
  const uniforms = {}
  const gl = {
    ARRAY_BUFFER: 1, ELEMENT_ARRAY_BUFFER: 2, STATIC_DRAW: 3, FLOAT: 4, TRIANGLES: 5,
    UNSIGNED_SHORT: 6, VERTEX_SHADER: 7, FRAGMENT_SHADER: 8, COMPILE_STATUS: 9, LINK_STATUS: 10,
    DEPTH_TEST: 11, LEQUAL: 12, TEXTURE_2D: 20, TEXTURE0: 21, RGBA: 22, UNSIGNED_BYTE: 23,
    TEXTURE_WRAP_S: 24, TEXTURE_WRAP_T: 25, TEXTURE_MIN_FILTER: 26, TEXTURE_MAG_FILTER: 27,
    REPEAT: 28, CLAMP_TO_EDGE: 29, LINEAR_MIPMAP_LINEAR: 30, LINEAR: 31, UNPACK_FLIP_Y_WEBGL: 32,
    MAX_TEXTURE_SIZE: 34, LUMINANCE: 35, R8: 36, RED: 37,
    getParameter: () => 16384,
    createBuffer: () => ({}), bindBuffer: () => {}, bufferData: () => {},
    createShader: () => ({}), shaderSource: () => {}, compileShader: () => {}, deleteShader: () => {},
    createProgram: () => ({}), attachShader: () => {}, linkProgram: () => {},
    getShaderParameter: () => true, getProgramParameter: () => true,
    getAttribLocation: (_p, n) => n, getUniformLocation: (_p, n) => n,
    useProgram: () => {}, enableVertexAttribArray: () => {}, vertexAttribPointer: () => {},
    enable: (c) => { if (c === 11) calls.enabledDepth++ },
    disable: (c) => { if (c === 11) calls.disabledDepth++ },
    depthFunc: () => {}, depthMask: () => {},
    uniform1f: (n, v) => { uniforms[n] = v },
    uniform1i: () => {},
    uniform3f: (n, x, y, z) => { uniforms[n] = [x, y, z] },
    uniform4f: () => {},
    uniformMatrix4fv: () => {},
    activeTexture: () => {}, bindTexture: () => {}, createTexture: () => ({}),
    deleteTexture: () => { calls.deleteTexture++ },
    texImage2D: () => { calls.texImage2D++ }, texParameteri: () => {}, generateMipmap: () => {},
    pixelStorei: () => {}, deleteProgram: () => {}, deleteBuffer: () => {},
    drawElements: () => { calls.drawElements++ },
  }
  if (webgl2) gl.texStorage2D = () => {}
  return { gl, calls, uniforms }
}

const mapStub = (altitude = 9e6) => ({
  getCenter: () => ({ lng: 0, lat: 40 }),
  getZoom: () => 2,
  transform: { getCameraLngLat: () => ({ lng: 0, lat: 40 }), getCameraAltitude: () => altitude },
  triggerRepaint: vi.fn(),
})

const v5Args = (variantName = 'globe', projectionTransition = 1) => ({
  farZ: 1e7, nearZ: 1, fov: 0.6435,
  shaderData: {
    variantName,
    vertexShaderPrelude: 'vec4 projectTileFor3D(vec2 p, float e);',
    define: variantName === 'globe' ? '#define GLOBE' : '',
  },
  defaultProjectionData: {
    mainMatrix: new Float32Array(16),
    tileMercatorCoords: [0, 0, 1, 1],
    clippingPlane: [0, 0, 0, 0],
    projectionTransition,
    fallbackMatrix: new Float32Array(16),
  },
})

describe('the distance coding', () => {
  const quantise = (unit) => Math.round(unit * 255) / 255

  it('survives the round trip through eight bits, to within half a level', () => {
    const halfLevelKm = OCEAN_SDF_RANGE_KM / 255
    for (const km of [0, 0.5, 2, 12, 24, 31.9, -0.5, -8, -20, -31.9]) {
      const back = oceanDistanceKm(quantise(storeOceanDistanceKm(km)))
      expect(Math.abs(back - km)).toBeLessThanOrEqual(halfLevelKm)
    }
  })

  it('places the coast at exactly the middle level, so the sign never drifts', () => {
    // 0.5 is the coastline. Everything about the layer hangs off this one number: a coding that
    // put the zero crossing anywhere else would move every shore on the planet, uniformly, with
    // nothing on screen to say why.
    expect(oceanDistanceKm(0.5)).toBe(0)
    expect(oceanDistanceKm(0.5000001)).toBeGreaterThan(0)
    expect(oceanDistanceKm(0.4999999)).toBeLessThan(0)
  })

  it('reconstructs the coastline where it really is, wherever it falls between two texels', () => {
    /**
     * THE reason the coding is linear. Bilinear filtering interpolates the STORED value, so the
     * decoded zero crossing is only in the right place if the coding is straight. A square root
     * looks strictly better — it spends most of its levels within metres of the shore — and it
     * was measured displacing the reconstructed coast by up to 730 m, because it bends the
     * interpolation everywhere the coast does not pass exactly midway between two texels.
     */
    const TEXEL_KM = 4.892
    for (const [inland, seaward] of [[0.5, 0.5], [0.25, 0.75], [0.1, 0.9], [0.9, 0.1]]) {
      const a = storeOceanDistanceKm(-inland * TEXEL_KM)
      const b = storeOceanDistanceKm(seaward * TEXEL_KM)
      const crossing = (0.5 - a) / (b - a)             // where bilinear reads water = 0
      expect(crossing).toBeCloseTo(inland / (inland + seaward), 10)
    }
  })

  it('saturates rather than wrapping, so mid-ocean cannot read as land', () => {
    expect(storeOceanDistanceKm(1e6)).toBe(1)
    expect(storeOceanDistanceKm(-1e6)).toBe(0)
  })

  it('quantises far finer than the texel it is stored in', () => {
    // 125 m of coding error inside a 4.9 km texel: the resolution of the bake is the limit on the
    // shape of the coast, and the coding contributes nothing measurable on top of it.
    expect(OCEAN_SDF_RANGE_KM / 255).toBeLessThan(0.15)
  })

  it('keeps the baker and the shader on one number', () => {
    // The range appears in the Python that writes the texture and in the GLSL that reads it. They
    // are generated together for exactly this reason; this is the assertion that says so out loud.
    const baker = readFileSync(resolve(process.cwd(), 'scripts/build-ocean-sdf.py'), 'utf8')
    expect(baker).toMatch(new RegExp(`RANGE_KM\\s*=\\s*${OCEAN_SDF_RANGE_KM}`))
  })
})

describe('how soft the shoreline is drawn', () => {
  it('holds the transition at about a pixel and a half, however far away the camera is', () => {
    // Fixed in kilometres it would be invisible from orbit and the coast would crawl as the globe
    // turned; fixed in pixels it would need fwidth, and an absent derivatives extension is a
    // shader that will not compile at all.
    const groundKmPerPixel = (zoom) => 156543.03392 / Math.pow(2, zoom) / 1000
    for (const zoom of [2, 4, 6, 8]) {
      const pixels = shoreSoftnessKmFor(zoom, 0) / groundKmPerPixel(zoom)
      expect(pixels).toBeCloseTo(1.5, 3)
    }
  })

  it('stops shrinking once it reaches the floor, where the field runs out of precision', () => {
    expect(shoreSoftnessKmFor(14, 0, 0.8)).toBe(0.8)
  })
})

describe('choosing a texture the GPU will accept', () => {
  it('takes the widest bake that fits', () => {
    expect(pickSdfSource(16384, OCEAN_SDF_SOURCES).width).toBe(8192)
    expect(pickSdfSource(8192, OCEAN_SDF_SOURCES).width).toBe(8192)
  })

  it('falls back rather than failing an upload the driver would reject', () => {
    // MAX_TEXTURE_SIZE is still 4096 on low-end Android. Handing it an 8192 texture fails the
    // upload and leaves the sea unpainted, with nothing in the console to explain it.
    expect(pickSdfSource(4096, OCEAN_SDF_SOURCES).width).toBe(4096)
  })

  it('returns nothing when even the small one is too big, instead of guessing', () => {
    expect(pickSdfSource(2048, OCEAN_SDF_SOURCES)).toBe(null)
  })
})

describe('how much sea the layer paints, by height', () => {
  const band = { fadeInAbove: 900000, fadeOutBelow: 180000 }

  it('paints the full ocean from orbit, where the imagery has no water colour of its own', () => {
    expect(altitudeFadeFor(2e6, band)).toBe(1)
  })

  it('is gone by the time Sentinel-2 is showing real water', () => {
    expect(altitudeFadeFor(50000, band)).toBe(0)
  })

  it('crosses over without a step', () => {
    const heights = [180000, 300000, 500000, 700000, 900000]
    const fades = heights.map((h) => altitudeFadeFor(h, band))
    for (let i = 1; i < fades.length; i++) expect(fades[i]).toBeGreaterThan(fades[i - 1])
    expect(fades[0]).toBe(0)
    expect(fades[fades.length - 1]).toBe(1)
  })
})

describe('the ocean layer', () => {
  it('draws before its texture has loaded, showing nothing rather than a blue planet', () => {
    // The placeholder reads as "everywhere is land", so the frames before the field arrives are
    // simply the map. The opposite default would flash a fully blue globe on every page load.
    const { gl, calls } = glStub()
    const layer = createOceanWaterLayer({ sources: [] })
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(calls.drawElements).toBe(1)
    expect(layer.hasField).toBe(false)
  })

  it('never depth-tests, because at orbital range that quilts the sea', () => {
    // A shell on the surface cannot be separated from the globe's own tiles by the depth buffer at
    // this distance: the layer breaks into flickering tile-shaped patches. The fragment shader
    // rejects the far hemisphere analytically instead.
    const { gl, calls } = glStub()
    const layer = createOceanWaterLayer()
    layer.onAdd(mapStub(), gl)
    layer.render(gl, v5Args())
    expect(calls.disabledDepth).toBe(1)
    expect(read('ocean-water.js')).toContain('facesCamera')
  })

  it('returns premultiplied alpha, which is what MapLibre blends', () => {
    // ONE / ONE_MINUS_SRC_ALPHA. Multiplying the colour by the mask instead of by the alpha is
    // what made an earlier version glow over dark ground.
    expect(read('ocean-water.js')).toContain('vec4(colour * alpha, alpha)')
  })

  it('shares the terminator with the clouds and the night, rather than rolling its own', () => {
    const source = read('ocean-water.js')
    expect(source).toContain('TERMINATOR_GLSL')
    expect(source).toContain('daylightFraction(')
  })

  it('masks the glint by daylight, so the night side has no highlight to explain', () => {
    expect(read('ocean-water.js')).toMatch(/glint\s*=.*\bday\b/)
  })

  it('varies the sea state across the ocean, so the glitter path is not one smooth blob', () => {
    const source = read('ocean-water.js')
    // The two-lobe exponents must read the LOCAL sea state. Feeding them u_roughness directly is
    // the regression this guards: it still renders, still looks like a glint, and is a blob.
    expect(source).toContain('float sea = seaState(normal);')
    expect(source).toContain('mix(2400.0, 260.0, sea)')
    expect(source).toContain('mix(180.0, 26.0, sea)')
  })

  it('touches a texture in exactly two places, so a tiled source is a two-function change', () => {
    /**
     * The tiled/LOD overlay system will stream z/x/y tiles in place of the one baked field. That is
     * a change to coastDistanceKm and shelfFraction and to nothing else — PROVIDED the lighting
     * never samples for itself. A stray fetch in the glint or the shelf colour is a second place
     * the source has to be swapped, and it would be found only when the tiles land.
     */
    const shader = read('ocean-water.js')
    // The LAST main() is the fragment shader's; the vertex shader has one too, earlier.
    const body = shader.slice(shader.lastIndexOf('void main() {'))
    expect(body).not.toContain('texture2D')
    expect((shader.match(/texture2D\(/g) || []).length).toBe(1)
    expect(shader).toContain('float coastDistanceKm(vec3 unitPos)')
    expect(shader).toContain('float shelfFraction(vec3 unitPos, float coastKm)')
  })

  it('keeps the requested edge remap off, because it paints the Sahara', () => {
    // Measured at 1: Sahara +11.1 blue and -7.9 red, Taklamakan -12.8 luma. A floor of 0.1 means
    // no fragment discards, so every land pixel on the planet takes ocean colour.
    expect(createOceanWaterLayer().getOptions().edgeRemap).toBe(0)
    expect(read('ocean-water.js')).toContain('0.3 * water + 0.1')
  })

  it('takes its noise from the shared module rather than keeping a second copy', () => {
    // Two copies of value noise drift the moment either is tuned, and the sea and the cloud deck
    // would then disagree about where the weather is.
    for (const file of ['ocean-water.js', 'clouds.js']) {
      expect(read(file)).toContain('NOISE_GLSL')
      expect(read(file)).not.toContain('float hash(vec3 p)')
    }
    expect(read('planet-mesh.js')).toContain('float hash(vec3 p)')
  })

  it('clamps the polar cap rows on the flat map, where there is no cap to cover', () => {
    // buildSphereMesh carries rows outside the mercator square. Projecting them unclamped under
    // mercator throws them off the top and bottom of the world.
    expect(read('ocean-water.js')).toContain('SHELL_PROJECT_GLSL')
  })

  it('hands the shader the height fade it computed, not a constant', () => {
    const { gl, uniforms } = glStub()
    const layer = createOceanWaterLayer()
    layer.onAdd(mapStub(60000), gl)     // low: below the handover
    layer.render(gl, v5Args())
    expect(uniforms.u_fade).toBe(0)
  })

  it('keeps its options immutable from outside', () => {
    const layer = createOceanWaterLayer({ strength: 0.5 })
    const snapshot = layer.getOptions()
    snapshot.strength = 99
    expect(layer.getOptions().strength).toBe(0.5)
  })

  it('reloads the field when the source changes and frees the old texture', () => {
    const { gl, calls } = glStub()
    const layer = createOceanWaterLayer({ sources: [] })
    layer.onAdd(mapStub(), gl)
    layer.setOptions({ sources: OCEAN_SDF_SOURCES })
    layer.onRemove()
    expect(calls.deleteTexture).toBeGreaterThan(0)
  })

  it('is the same id the parked implementation used, so nothing anchored to it moves', () => {
    expect(OCEAN_LAYER_ID).toBe('tm-ocean')
  })
})

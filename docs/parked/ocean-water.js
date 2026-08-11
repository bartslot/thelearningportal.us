/**
 * ocean-water.js — the sea, lit, as a MapLibre custom layer.
 *
 * The single strongest cue that a globe is a photograph rather than a texture. In every picture
 * taken from orbit the ocean is not evenly blue: there is a bright patch where the sun's reflection
 * happens to point at the camera, smeared into a streak by waves, and it moves as the camera does.
 * A flat blue ocean is the giveaway that nothing is being lit.
 *
 * The physics, kept to what actually shows at this scale:
 *
 *  - Water is a mirror, so the highlight sits where the surface normal bisects the directions to the
 *    sun and to the eye. That is Blinn-Phong's half-vector, and on a sphere it lands in one place.
 *  - The sea is a ROUGH mirror. Wind tilts the facets, which spreads that point into the elongated
 *    glitter path (Cox-Munk). Two lobes approximate it: a tight one for the core and a broad one for
 *    the smear, which is cheaper than a real roughness distribution and indistinguishable here.
 *  - Water reflects far more at grazing angles than face-on (Fresnel), which is why the sea near the
 *    limb goes silver while the sea below the camera stays blue.
 *
 * Three things stacked, in the order the eye reads them:
 *
 *  1. WATER COLOUR. The base imagery's ocean is now near-black on purpose (see map-imagery.js: the
 *     bathymetry variant drew the sea floor, which is not something you can see). This paints it
 *     back as water — one deep colour, no terrain in it.
 *  2. SKY REFLECTION. Water reflects almost nothing when you look straight down and almost
 *     everything at a glancing angle (Fresnel). This is why the ocean below you is near-black and
 *     the ocean out toward the limb is bright silver-blue. It is the single biggest reason a
 *     flat-tinted ocean looks fake — the tint is uniform, and real water never is.
 *  3. SUN GLINT. The bright patch where the sun's own reflection points at the camera, smeared by
 *     waves into the glitter path. Two lobes approximate the roughness distribution (Cox-Munk):
 *     a tight one for the core, a broad one for the smear.
 *
 * Where water IS comes from a baked equirectangular mask rather than the live imagery, because a
 * custom layer cannot sample MapLibre's raster tiles. The mask is derived from Blue Marble's own
 * blue-dominance, so it agrees with the imagery underneath it.
 */

import maplibregl from 'maplibre-gl'
import { buildSphereMesh, cameraInPlanetSpace, cameraAltitudeMetres, buildProgram, EQUIRECT_GLSL } from './planet-mesh.js'

const LAYER_ID = 'tm-ocean'

const vertexSource = (shaderData) => `${shaderData.vertexShaderPrelude}
${shaderData.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
varying vec3 v_sphere;
void main() {
  v_sphere = a_sphere;
  // Sea level: the glint belongs on the water, not above it.
  gl_Position = projectTileFor3D(a_pos, 0.0);
}`

const fragmentSource = () => `precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;     // camera in planet space
uniform vec3 u_sun;        // direction TO the sun
uniform sampler2D u_ocean; // 1 where there is sea, 0 on land
uniform float u_strength;  // glint intensity
uniform float u_roughness; // 0 glassy, 1 whipped up
uniform vec3 u_deep;       // open-ocean colour
uniform vec3 u_sky;        // what the water reflects at a glancing angle
uniform float u_water;     // how strongly the water colour is painted at all
uniform float u_fade;      // 1 from orbit, 0 close up where real imagery takes over
${EQUIRECT_GLSL}

void main() {
  vec3 normal = normalize(v_sphere);
  float water = texture2D(u_ocean, equirectUV(normal, 0.0)).r;
  if (water < 0.01) discard;                      // land: leave the imagery alone

  vec3 view = normalize(u_camera - normal);
  float facing = max(dot(normal, view), 0.0);
  // Fresnel. Looking straight down, water reflects ~2% and you see into it; at a glancing angle it
  // reflects nearly everything. This one term is why the sea under you is near-black while the sea
  // toward the limb is bright silver-blue — and why a flat tint never looks like water.
  float fresnel = 0.02 + 0.98 * pow(1.0 - facing, 5.0);

  float sunAngle = dot(normal, u_sun);
  float daylight = smoothstep(-0.12, 0.25, sunAngle);   // soft terminator, not a line
  float lambert = max(sunAngle, 0.0);

  // Sun glint: the mirror direction, roughened by waves into the glitter path. Two lobes stand in
  // for the roughness distribution — a tight core and a broad smear.
  vec3 halfway = normalize(u_sun + view);
  float alignment = max(dot(normal, halfway), 0.0);
  float sharp = pow(alignment, mix(2400.0, 260.0, u_roughness));
  float broad = pow(alignment, mix(180.0, 26.0, u_roughness));
  float glint = (sharp + 0.35 * broad) * daylight * u_strength;

  vec3 body = u_deep * (0.08 + 0.92 * lambert);         // the night ocean really is nearly black
  vec3 skyTerm = u_sky * fresnel * daylight;
  vec3 sunTerm = mix(vec3(0.85, 0.90, 1.0), vec3(1.0, 0.96, 0.86), fresnel) * glint;
  vec3 colour = body + skyTerm + sunTerm;

  // OPAQUE over open water, not a tint. The base imagery is deliberately colourless at sea now that
  // the bathymetry is gone, so there is nothing underneath worth blending with — this layer is the
  // ocean. It fades out as the camera drops, where Sentinel-2 shows the real water instead; the
  // glint is added back on top of the fade so the highlight survives the handover.
  float alpha = clamp(water * u_water * u_fade + glint * water, 0.0, 1.0);
  if (alpha < 0.003) discard;
  gl_FragColor = vec4(colour * alpha, alpha);
}`

/**
 * @param {object} opts
 * @param {string} [opts.maskUrl]   equirectangular ocean mask (white = sea)
 * @param {number} [opts.strength]  0 disables the layer entirely
 * @param {number} [opts.roughness] 0 glassy, 1 whipped up — widens the glitter path
 * @param {number[]} [opts.sun]     direction TO the sun, planet space; share it with the cloud deck
 */
export const createOceanWaterLayer = ({
  maskUrl = null, strength = 0.9, roughness = 0.55, sun = [0.4, 0.5, 0.75],
  water = 1,
  deep = [0.020, 0.075, 0.160],   // open ocean: dark, with a touch of green in the blue
  sky = [0.34, 0.50, 0.76],       // daylight sky, what the sea mirrors near the limb
  fadeInAbove = 900000,           // metres: fully painted above this
  fadeOutBelow = 180000,          // metres: gone below this, where real imagery has the detail
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  let buffers = null
  let maskTexture = null
  let maskReady = false
  const programs = new Map()
  let state = { maskUrl, strength, roughness, sun, water, deep, sky, fadeInAbove, fadeOutBelow }

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)
    const program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), 'tm-ocean')
    const entry = {
      program,
      attribs: {
        pos: gl.getAttribLocation(program, 'a_pos'),
        sphere: gl.getAttribLocation(program, 'a_sphere'),
      },
      uniforms: {
        camera: gl.getUniformLocation(program, 'u_camera'),
        sun: gl.getUniformLocation(program, 'u_sun'),
        ocean: gl.getUniformLocation(program, 'u_ocean'),
        strength: gl.getUniformLocation(program, 'u_strength'),
        roughness: gl.getUniformLocation(program, 'u_roughness'),
        water: gl.getUniformLocation(program, 'u_water'),
        deep: gl.getUniformLocation(program, 'u_deep'),
        sky: gl.getUniformLocation(program, 'u_sky'),
        fade: gl.getUniformLocation(program, 'u_fade'),
        matrix: gl.getUniformLocation(program, 'u_projection_matrix'),
        tileMercatorCoords: gl.getUniformLocation(program, 'u_projection_tile_mercator_coords'),
        clippingPlane: gl.getUniformLocation(program, 'u_projection_clipping_plane'),
        transition: gl.getUniformLocation(program, 'u_projection_transition'),
        fallbackMatrix: gl.getUniformLocation(program, 'u_projection_fallback_matrix'),
      },
    }
    programs.set(key, entry)
    return entry
  }

  /**
   * How much of the sea this layer paints, by camera altitude. Full from orbit, where the imagery
   * has no ocean colour of its own; gone by the time Sentinel-2 has faded in and is showing real
   * water, shelves and turbidity that no shader should be painting over.
   */
  const altitudeFade = () => {
    const altitude = cameraAltitudeMetres(map, maplibregl)
    const t = (altitude - state.fadeOutBelow) / (state.fadeInAbove - state.fadeOutBelow)
    return Math.max(0, Math.min(1, t))
  }

  const loadMask = (url) => {
    const image = new Image()
    image.crossOrigin = 'anonymous'
    image.onload = () => {
      if (!gl) return
      maskTexture = maskTexture || gl.createTexture()
      gl.bindTexture(gl.TEXTURE_2D, maskTexture)
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
      gl.generateMipmap(gl.TEXTURE_2D)
      maskReady = true
      map?.triggerRepaint()
    }
    // Decorative: without the mask there is simply no glint, never a broken globe.
    image.src = url
  }

  return {
    id: LAYER_ID,
    type: 'custom',
    renderingMode: '3d',

    onAdd(mapInstance, glContext) {
      map = mapInstance
      gl = glContext
      const buffer = (target, data) => {
        const b = gl.createBuffer()
        gl.bindBuffer(target, b)
        gl.bufferData(target, data, gl.STATIC_DRAW)
        return b
      }
      buffers = {
        pos: buffer(gl.ARRAY_BUFFER, mesh.positions),
        sphere: buffer(gl.ARRAY_BUFFER, mesh.spheres),
        index: buffer(gl.ELEMENT_ARRAY_BUFFER, mesh.indices),
      }
      if (state.maskUrl) loadMask(state.maskUrl)
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      if (maskTexture) { gl.deleteTexture(maskTexture); maskTexture = null; maskReady = false }
      if (buffers) {
        gl.deleteBuffer(buffers.pos)
        gl.deleteBuffer(buffers.sphere)
        gl.deleteBuffer(buffers.index)
        buffers = null
      }
      map = null
      gl = null
    },

    render(glContext, args) {
      // Nothing to paint without the mask; nothing to paint with water off and glint off either.
      if (!buffers || !maskReady || (state.strength <= 0 && state.water <= 0)) return
      const shaderData = args && args.shaderData
      const projection = args && args.defaultProjectionData
      if (!shaderData || !projection) return   // v4-style arguments: refuse rather than mis-draw

      const { program, attribs, uniforms } = programFor(shaderData)
      gl.useProgram(program)
      if (uniforms.matrix) gl.uniformMatrix4fv(uniforms.matrix, false, projection.mainMatrix)
      if (uniforms.tileMercatorCoords) gl.uniform4f(uniforms.tileMercatorCoords, ...projection.tileMercatorCoords)
      if (uniforms.clippingPlane) gl.uniform4f(uniforms.clippingPlane, ...projection.clippingPlane)
      if (uniforms.transition) gl.uniform1f(uniforms.transition, projection.projectionTransition)
      if (uniforms.fallbackMatrix) gl.uniformMatrix4fv(uniforms.fallbackMatrix, false, projection.fallbackMatrix)

      const camera = cameraInPlanetSpace(map, maplibregl)
      if (uniforms.camera) gl.uniform3f(uniforms.camera, camera[0], camera[1], camera[2])
      if (uniforms.sun) gl.uniform3f(uniforms.sun, ...state.sun)
      if (uniforms.strength) gl.uniform1f(uniforms.strength, state.strength)
      if (uniforms.roughness) gl.uniform1f(uniforms.roughness, state.roughness)
      if (uniforms.water) gl.uniform1f(uniforms.water, state.water)
      if (uniforms.deep) gl.uniform3f(uniforms.deep, ...state.deep)
      if (uniforms.sky) gl.uniform3f(uniforms.sky, ...state.sky)
      if (uniforms.fade) gl.uniform1f(uniforms.fade, altitudeFade())
      if (uniforms.ocean) {
        gl.activeTexture(gl.TEXTURE0)
        gl.bindTexture(gl.TEXTURE_2D, maskTexture)
        gl.uniform1i(uniforms.ocean, 0)
      }

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(attribs.pos)
      gl.vertexAttribPointer(attribs.pos, 2, gl.FLOAT, false, 0, 0)
      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.sphere)
      gl.enableVertexAttribArray(attribs.sphere)
      gl.vertexAttribPointer(attribs.sphere, 3, gl.FLOAT, false, 0, 0)

      gl.enable(gl.DEPTH_TEST)
      gl.depthFunc(gl.LEQUAL)
      gl.depthMask(false)   // a reflection is not geometry; it must not occlude the deck above it
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, mesh.indices.length, gl.UNSIGNED_SHORT, 0)
      gl.depthMask(true)
    },

    /** Live controls — sun direction, sea state, water colour, and how hard the highlight burns. */
    setOptions(next = {}) {
      const previousUrl = state.maskUrl
      state = { ...state, ...next }
      if (next.maskUrl !== undefined && next.maskUrl !== previousUrl) {
        maskReady = false
        if (next.maskUrl) loadMask(next.maskUrl)
      }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    get hasMask () { return maskReady },
  }
}

export const OCEAN_LAYER_ID = LAYER_ID

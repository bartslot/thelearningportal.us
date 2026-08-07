/**
 * starfield.js — the galaxy behind the earth, as a MapLibre custom layer.
 *
 * A globe on flat black reads as a diagram. The same globe against the Milky Way reads as a place.
 * It is the cheapest shot of drama available and it costs one textured sphere.
 *
 * WHY A SPHERE AND NOT A BACKGROUND IMAGE. A CSS backdrop stays nailed to the screen: orbit the
 * planet and the stars slide with the viewport, which instantly reads as a printed backdrop. This
 * is a sphere drawn far outside the earth with the camera inside it, so the sky is fixed in SPACE —
 * pan east and the stars pan west, exactly as they should.
 *
 * TWO LAYERS OF SKY. The texture — ESO's Milky Way panorama (CC BY 4.0) — supplies the nebulosity:
 * the band of the galaxy, the dust lanes, the diffuse glow. It cannot supply stars, because a
 * 2048-wide panorama stretched across a whole sky gives every star several degrees of blur.
 *
 * So the stars are GENERATED. Space is diced into cells; each cell hashes to at most one star with
 * its own position, brightness and colour, drawn as a point with a sub-pixel falloff. They stay
 * pin-sharp at any zoom because they are evaluated per pixel rather than sampled from an image, and
 * they cost no bandwidth at all.
 *
 * The brightness distribution is deliberately lopsided — `pow(hash, 7)` — because the real sky is:
 * a handful of bright stars and a great many faint ones. A uniform distribution reads as static
 * noise, which is the usual tell of a procedural starfield.
 *
 * The panorama's coordinates are galactic rather than terrestrial, so the band is not aligned to
 * anything on the map; it is scenery. `rotation` exists so a scene can place the galactic centre.
 */

import maplibregl from 'maplibre-gl'
import { buildSphereMesh, buildProgram, EQUIRECT_GLSL } from './planet-mesh.js'

const LAYER_ID = 'tm-starfield'
/**
 * How far out the sky sits.
 *
 * It cannot be a constant. Fix the sky at any one distance and a camera that pulls back far enough
 * flies straight through it — the backdrop then reads as a dark BALL hanging in space with the
 * earth inside it, which is exactly as odd as it sounds. Nor can it simply be enormous: MapLibre
 * derives its far clip plane from the camera's altitude, so a sky at an astronomical distance is
 * clipped away entirely.
 *
 * There is only a narrow band that works, and it is fixed rather than camera-relative because both
 * ends fail and they fail differently. Too near and the camera flies THROUGH the sky, which reads
 * as a dark ball hanging in space with the earth inside it. Too far and MapLibre's far clip plane —
 * derived from the camera's altitude — eats it, leaving no sky at all. Scaling with the camera was
 * tried and it simply moved the failure: at ordinary globe zoom the multiple overshot the far plane.
 *
 * KNOWN LIMIT, measured rather than guessed: this distance renders correctly across the zoom range
 * a lesson uses (roughly z1.4 and closer, where the camera sits under ~30,000 km). Pull the camera
 * further back than that and it passes outside the sphere, and the sky reads as a dark ball with
 * the earth inside it. Scaling the distance with the camera was tried and made it worse — the
 * multiple then overshoots MapLibre's far plane and the sky vanishes entirely at ordinary zoom.
 * Fixing this properly means either clamping how far a scene may pull back, or rendering the sky
 * as a screen-space pass instead of geometry. Neither is done.
 */
const SKY_ALTITUDE_M = 30000000

const vertexSource = (shaderData) => `${shaderData.vertexShaderPrelude}
${shaderData.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
void main() {
  v_sphere = a_sphere;
  #ifdef GLOBE
    gl_Position = projectTileFor3D(a_pos, a_elevation_globe);
  #else
    gl_Position = projectTileFor3D(a_pos, a_elevation_mercator);
  #endif
}`

const fragmentSource = () => `precision highp float;
varying vec3 v_sphere;
uniform sampler2D u_sky;
uniform float u_brightness;
uniform float u_rotation;
uniform float u_nebula;      // 0 drops the panorama and leaves bare stars
uniform float u_starDensity; // cells per radian: higher is more, smaller stars
uniform float u_starAmount;
uniform float u_time;
uniform float u_twinkle;
${EQUIRECT_GLSL}

vec3 hash3(vec3 p) {
  p = vec3(dot(p, vec3(127.1, 311.7, 74.7)),
           dot(p, vec3(269.5, 183.3, 246.1)),
           dot(p, vec3(113.5, 271.9, 124.6)));
  return fract(sin(p) * 43758.5453123);
}

// One star per cell at most, placed by hash. Sampling the 3x3x3 neighbourhood would let stars sit
// near cell edges without clipping, at 27x the cost; at these densities a single cell is enough,
// and the grid never becomes visible because the cell size is far below a pixel of sky.
vec3 stars(vec3 dir) {
  vec3 p = dir * u_starDensity;
  vec3 cell = floor(p);
  vec3 h = hash3(cell);

  // Most of the sky is empty. This threshold is what keeps stars sparse rather than a dot screen.
  if (h.x > 0.055) return vec3(0.0);

  vec3 offset = hash3(cell + 17.0);
  float dist = length(fract(p) - offset);

  // A steep falloff makes a point rather than a blob; the exponent is the apparent star size.
  float core = pow(max(0.0, 1.0 - dist * 2.6), 14.0);
  // Lopsided on purpose: a few bright, very many faint — the real distribution.
  float magnitude = pow(h.y, 7.0);

  // Blue-white through to amber, weighted toward white as real naked-eye stars are.
  vec3 tint = mix(vec3(0.72, 0.82, 1.0), vec3(1.0, 0.82, 0.62), pow(h.z, 1.6));

  // Atmospheric scintillation, per star, at its own rate. Off by default: from orbit there is no
  // air to twinkle through, so it is a stylistic choice rather than a physical one.
  float flicker = 1.0 - u_twinkle * 0.5 * (0.5 + 0.5 * sin(u_time * (1.7 + h.z * 4.0) + h.y * 31.4));

  return tint * core * magnitude * flicker;
}

void main() {
  vec3 dir = normalize(v_sphere);
  vec3 sky = texture2D(u_sky, equirectUV(dir, u_rotation)).rgb * u_nebula;
  sky += stars(dir) * u_starAmount;
  // Opaque: this is the backdrop, and everything else in the style draws over it.
  gl_FragColor = vec4(sky * u_brightness, 1.0);
}`

/**
 * @param {object} opts
 * @param {string} opts.textureUrl      equirectangular sky image
 * @param {number} [opts.brightness]    the panorama is bright enough to compete with the planet;
 *                                      this is usually turned well down
 * @param {number} [opts.rotation]      0..1, spins the sky to place the galactic centre
 */
export const createStarfieldLayer = ({
  textureUrl = null, brightness = 0.55, rotation = 0,
  nebula = 1, starDensity = 190, starAmount = 1, twinkle = 0, animate = false,
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  let buffers = null
  let texture = null
  let ready = false
  const programs = new Map()
  let state = { textureUrl, brightness, rotation, nebula, starDensity, starAmount, twinkle, animate }

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)
    const program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), 'tm-starfield')
    const entry = {
      program,
      attribs: {
        pos: gl.getAttribLocation(program, 'a_pos'),
        sphere: gl.getAttribLocation(program, 'a_sphere'),
      },
      uniforms: {
        elevationGlobe: gl.getUniformLocation(program, 'a_elevation_globe'),
        elevationMercator: gl.getUniformLocation(program, 'a_elevation_mercator'),
        sky: gl.getUniformLocation(program, 'u_sky'),
        brightness: gl.getUniformLocation(program, 'u_brightness'),
        rotation: gl.getUniformLocation(program, 'u_rotation'),
        nebula: gl.getUniformLocation(program, 'u_nebula'),
        starDensity: gl.getUniformLocation(program, 'u_starDensity'),
        starAmount: gl.getUniformLocation(program, 'u_starAmount'),
        twinkle: gl.getUniformLocation(program, 'u_twinkle'),
        time: gl.getUniformLocation(program, 'u_time'),
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

  const loadSky = (url) => {
    const image = new Image()
    image.crossOrigin = 'anonymous'
    image.onload = () => {
      if (!gl) return
      texture = texture || gl.createTexture()
      gl.bindTexture(gl.TEXTURE_2D, texture)
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
      // LINEAR, and deliberately NO mipmaps. Seen from inside, this sphere's triangles are stretched
      // across huge angles, so the UV derivative per pixel is enormous and mipmapping collapses to a
      // 2x2 level over most of the screen — the galaxy renders as a few soft grey blocks. Ground
      // textures want mipmaps; a skybox wants the base level.
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
      ready = true
      map?.triggerRepaint()
    }
    // Decorative: without it space is simply black, which is what it was before.
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
      if (state.textureUrl) loadSky(state.textureUrl)
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      if (texture) { gl.deleteTexture(texture); texture = null; ready = false }
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
      // Stars are procedural, so the sky draws with or without the nebula texture.
      if (!buffers || state.brightness <= 0) return
      const shaderData = args && args.shaderData
      const projection = args && args.defaultProjectionData
      if (!shaderData || !projection) return

      const { program, attribs, uniforms } = programFor(shaderData)
      gl.useProgram(program)
      if (uniforms.matrix) gl.uniformMatrix4fv(uniforms.matrix, false, projection.mainMatrix)
      if (uniforms.tileMercatorCoords) gl.uniform4f(uniforms.tileMercatorCoords, ...projection.tileMercatorCoords)
      if (uniforms.clippingPlane) gl.uniform4f(uniforms.clippingPlane, ...projection.clippingPlane)
      if (uniforms.transition) gl.uniform1f(uniforms.transition, projection.projectionTransition)
      if (uniforms.fallbackMatrix) gl.uniformMatrix4fv(uniforms.fallbackMatrix, false, projection.fallbackMatrix)

      const lat = map.getCenter().lat
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, SKY_ALTITUDE_M)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([0, lat], SKY_ALTITUDE_M).z)
      }
      if (uniforms.brightness) gl.uniform1f(uniforms.brightness, state.brightness)
      if (uniforms.rotation) gl.uniform1f(uniforms.rotation, state.rotation)
      if (uniforms.nebula) gl.uniform1f(uniforms.nebula, state.nebula)
      if (uniforms.starDensity) gl.uniform1f(uniforms.starDensity, state.starDensity)
      if (uniforms.starAmount) gl.uniform1f(uniforms.starAmount, state.starAmount)
      if (uniforms.twinkle) gl.uniform1f(uniforms.twinkle, state.twinkle)
      if (uniforms.time) gl.uniform1f(uniforms.time, state.animate ? performance.now() * 0.001 : 0)
      if (uniforms.nebula) gl.uniform1f(uniforms.nebula, ready ? state.nebula : 0)
      if (ready && uniforms.sky) {
        gl.activeTexture(gl.TEXTURE0)
        gl.bindTexture(gl.TEXTURE_2D, texture)
        gl.uniform1i(uniforms.sky, 0)
      }

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(attribs.pos)
      gl.vertexAttribPointer(attribs.pos, 2, gl.FLOAT, false, 0, 0)
      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.sphere)
      gl.enableVertexAttribArray(attribs.sphere)
      gl.vertexAttribPointer(attribs.sphere, 3, gl.FLOAT, false, 0, 0)

      // The sky is behind everything: no depth test to fail, and no depth written, so nothing that
      // draws afterwards is ever occluded by it.
      gl.disable(gl.DEPTH_TEST)
      gl.depthMask(false)
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, mesh.indices.length, gl.UNSIGNED_SHORT, 0)
      gl.enable(gl.DEPTH_TEST)
      gl.depthMask(true)

      if (state.animate && state.twinkle > 0) map.triggerRepaint()
    },

    setOptions(next = {}) {
      const previousUrl = state.textureUrl
      state = { ...state, ...next }
      if (next.textureUrl !== undefined && next.textureUrl !== previousUrl) {
        ready = false
        if (next.textureUrl) loadSky(next.textureUrl)
      }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    get hasSky () { return ready },
  }
}

export const STARFIELD_LAYER_ID = LAYER_ID

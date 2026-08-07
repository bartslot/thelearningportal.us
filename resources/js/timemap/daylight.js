/**
 * daylight.js — day and night on the globe, as a MapLibre custom layer.
 *
 * Satellite basemaps are a lie about light: Blue Marble and Sentinel-2 are composites of cloud-free
 * DAYLIGHT passes, so every part of the earth is lit at once and the planet has no terminator. It
 * reads as a lamp rather than a world. This layer puts the night back.
 *
 * Three things, all driven by one sun vector from sun.js:
 *
 *  - THE TERMINATOR is not a line. The sun's disc is half a degree wide and the air scatters light
 *    well past the geometric edge, so the day/night boundary is a soft band a few hundred
 *    kilometres across — civil, nautical and astronomical twilight, in order. Drawing it as a hard
 *    edge is the tell-tale of a cheap day/night globe.
 *  - TWILIGHT IS WARM. The last light through the low atmosphere has lost its blue, which is why
 *    the band running down the terminator glows orange rather than simply darkening.
 *  - CITY LIGHTS appear only where it is properly dark. NASA's Black Marble composite, faded in
 *    across the same twilight band, so the eastern seaboard and Europe come alight as they turn
 *    away from the sun.
 *
 * Nothing here is a filter over the map — it is a translucent shell in front of it, so the imagery
 * underneath keeps its own detail and only its brightness changes.
 */

import maplibregl from 'maplibre-gl'
import { buildSphereMesh, buildProgram, EQUIRECT_GLSL } from './planet-mesh.js'

const LAYER_ID = 'tm-daylight'

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
uniform vec3 u_sun;            // direction TO the sun
uniform float u_nightDarkness; // how black the unlit side goes
uniform vec3 u_nightColour;
uniform vec3 u_twilightColour;
uniform sampler2D u_lights;    // NASA Black Marble, equirectangular
uniform float u_lightsAmount;  // 0 when the texture has not loaded
${EQUIRECT_GLSL}

void main() {
  vec3 normal = normalize(v_sphere);
  float sunAngle = dot(normal, u_sun);

  // Twilight spans roughly -0.31 to +0.09 in this cosine — about 18° of sun elevation below the
  // horizon down to just above it, which is astronomical twilight through to sunrise.
  float day = smoothstep(-0.31, 0.09, sunAngle);
  float night = 1.0 - day;
  if (night < 0.004) discard;                 // full daylight: leave the imagery untouched

  // The warm band peaks in the middle of the transition and vanishes at both ends.
  float twilight = 1.0 - abs(day * 2.0 - 1.0);
  twilight *= twilight;

  vec3 shade = mix(u_nightColour, u_twilightColour, twilight * 0.85);
  float alpha = night * u_nightDarkness;

  // City lights, but only where it is genuinely dark — they have no business showing at dusk.
  if (u_lightsAmount > 0.0) {
    float lights = texture2D(u_lights, equirectUV(normal, 0.0)).r;
    float visible = lights * smoothstep(0.25, 0.75, night) * u_lightsAmount;
    // Added, not blended: lights EMIT. Blending them would make the cities darker than the sea.
    shade = shade + vec3(1.0, 0.86, 0.6) * visible * 1.6;
    alpha = min(1.0, alpha + visible * 0.5);
  }

  // Premultiplied, matching MapLibre's ONE / ONE_MINUS_SRC_ALPHA for custom layers.
  gl_FragColor = vec4(shade * alpha, alpha);
}`

/**
 * @param {object} opts
 * @param {number[]} [opts.sun]            direction TO the sun; share it with the clouds and air
 * @param {number} [opts.nightDarkness]    0 disables the layer, 1 is a black night side
 * @param {string} [opts.lightsUrl]        equirectangular city-lights image (NASA Black Marble)
 * @param {number} [opts.lightsAmount]     brightness of the cities
 * @param {number[]} [opts.nightColour]    the unlit side's tint
 * @param {number[]} [opts.twilightColour] the warm band along the terminator
 */
export const createDaylightLayer = ({
  sun = [1, 0, 0],
  nightDarkness = 0.82,
  lightsUrl = null,
  lightsAmount = 1,
  nightColour = [0.02, 0.035, 0.07],
  twilightColour = [0.85, 0.35, 0.12],
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  let buffers = null
  let lightsTexture = null
  let lightsReady = false
  const programs = new Map()
  let state = { sun, nightDarkness, lightsUrl, lightsAmount, nightColour, twilightColour }

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)
    const program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), 'tm-daylight')
    const entry = {
      program,
      attribs: {
        pos: gl.getAttribLocation(program, 'a_pos'),
        sphere: gl.getAttribLocation(program, 'a_sphere'),
      },
      uniforms: {
        elevationGlobe: gl.getUniformLocation(program, 'a_elevation_globe'),
        elevationMercator: gl.getUniformLocation(program, 'a_elevation_mercator'),
        sun: gl.getUniformLocation(program, 'u_sun'),
        nightDarkness: gl.getUniformLocation(program, 'u_nightDarkness'),
        nightColour: gl.getUniformLocation(program, 'u_nightColour'),
        twilightColour: gl.getUniformLocation(program, 'u_twilightColour'),
        lights: gl.getUniformLocation(program, 'u_lights'),
        lightsAmount: gl.getUniformLocation(program, 'u_lightsAmount'),
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

  const loadLights = (url) => {
    const image = new Image()
    image.crossOrigin = 'anonymous'
    image.onload = () => {
      if (!gl) return
      lightsTexture = lightsTexture || gl.createTexture()
      gl.bindTexture(gl.TEXTURE_2D, lightsTexture)
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
      gl.generateMipmap(gl.TEXTURE_2D)
      lightsReady = true
      map?.triggerRepaint()
    }
    // Decorative: without it the night side is simply dark, which is still correct.
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
      if (state.lightsUrl) loadLights(state.lightsUrl)
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      if (lightsTexture) { gl.deleteTexture(lightsTexture); lightsTexture = null; lightsReady = false }
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
      if (!buffers || state.nightDarkness <= 0) return
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

      // Just clear of the surface: at exactly 0 the shell z-fights the globe's own tiles and the
      // night side flickers in bands as the camera moves.
      const lat = map.getCenter().lat
      const LIFT_M = 3000
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, LIFT_M)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([0, lat], LIFT_M).z)
      }
      if (uniforms.sun) gl.uniform3f(uniforms.sun, ...state.sun)
      if (uniforms.nightDarkness) gl.uniform1f(uniforms.nightDarkness, state.nightDarkness)
      if (uniforms.nightColour) gl.uniform3f(uniforms.nightColour, ...state.nightColour)
      if (uniforms.twilightColour) gl.uniform3f(uniforms.twilightColour, ...state.twilightColour)
      if (uniforms.lightsAmount) gl.uniform1f(uniforms.lightsAmount, lightsReady ? state.lightsAmount : 0)
      if (lightsReady && uniforms.lights) {
        gl.activeTexture(gl.TEXTURE0)
        gl.bindTexture(gl.TEXTURE_2D, lightsTexture)
        gl.uniform1i(uniforms.lights, 0)
      }

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(attribs.pos)
      gl.vertexAttribPointer(attribs.pos, 2, gl.FLOAT, false, 0, 0)
      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.sphere)
      gl.enableVertexAttribArray(attribs.sphere)
      gl.vertexAttribPointer(attribs.sphere, 3, gl.FLOAT, false, 0, 0)

      gl.enable(gl.DEPTH_TEST)
      gl.depthFunc(gl.LEQUAL)
      gl.depthMask(false)      // night is not geometry
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, mesh.indices.length, gl.UNSIGNED_SHORT, 0)
      gl.depthMask(true)
    },

    /** Live controls — chiefly the sun, which a lesson moves as its date moves. */
    setOptions(next = {}) {
      const previousUrl = state.lightsUrl
      state = { ...state, ...next }
      if (next.lightsUrl !== undefined && next.lightsUrl !== previousUrl) {
        lightsReady = false
        if (next.lightsUrl) loadLights(next.lightsUrl)
      }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    get hasLights () { return lightsReady },
  }
}

export const DAYLIGHT_LAYER_ID = LAYER_ID

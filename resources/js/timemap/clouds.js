/**
 * clouds.js — a weather shell over the globe, as a MapLibre custom layer.
 *
 * A sphere of cloud drawn above the map, lit from the sun's side and drifting slowly. It reads as
 * weather over a planet rather than a texture on a map, so it earns its place on the globe.
 *
 * WHERE THE CLOUDS COME FROM. Given a `fieldUrl` it samples a real cloud field — NASA's Blue Marble
 * cloud composite, actual cyclones and frontal bands and a real ITCZ. Without one it falls back to
 * procedural noise, banded by latitude to imitate the same thing. The real field wins every time;
 * the fallback exists so a missing asset degrades instead of emptying the sky.
 *
 * WHY A SHELL AND NOT A VOLUME. A raymarched slab gives genuine depth and mist when you fly through
 * it, and it was built and measured here: 21 fps close up on an M3 Pro, against 60 for this. That
 * is not a classroom-safe live layer, and the cost is structural — up to 112 density evaluations
 * per pixel — not a matter of tuning. Cinematic cloud belongs in a PRE-RENDERED pass, where a frame
 * may take half a second and nobody notices. This layer is what the live map can always afford.
 *
 * PROJECTION. MapLibre v5 does not hand a custom layer a matrix. It hands an options object, and
 * the way to project is its own shader prelude: `projectTileFor3D(vec2 mercator01, float elevation)`
 * maps mercator coordinates in 0..1 onto whichever projection is live, at a height above the
 * surface. That prelude changes when the projection does, so the program is compiled per
 * `shaderData.variantName` and cached under it.
 *
 * Elevation units differ by projection, which is the one genuine trap here: metres above the sphere
 * under globe, mercator z units under mercator. Both are passed and `#ifdef GLOBE` picks.
 */

import maplibregl from 'maplibre-gl'
import { buildSphereMesh, buildProgram, EQUIRECT_GLSL } from './planet-mesh.js'

const LAYER_ID = 'tm-clouds'
// Deck height. Real cloud tops out around 12 km; on a 6371 km globe that is invisible, so this is
// exaggerated until the shell parallaxes against the ground as the camera moves.
const ALTITUDE_M = 90000

// Value noise + fbm over the unit sphere. Cheap, and at cloud scale nobody can tell it from
// gradient noise. `u_time` drifts the field along one axis: weather moving over the planet.
const NOISE_GLSL = /* glsl */`
  float hash(vec3 p) {
    return fract(sin(dot(p, vec3(12.9898, 78.233, 45.164))) * 43758.5453123);
  }
  float noise(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float n000 = hash(i);
    float n100 = hash(i + vec3(1.0, 0.0, 0.0));
    float n010 = hash(i + vec3(0.0, 1.0, 0.0));
    float n110 = hash(i + vec3(1.0, 1.0, 0.0));
    float n001 = hash(i + vec3(0.0, 0.0, 1.0));
    float n101 = hash(i + vec3(1.0, 0.0, 1.0));
    float n011 = hash(i + vec3(0.0, 1.0, 1.0));
    float n111 = hash(i + vec3(1.0, 1.0, 1.0));
    return mix(
      mix(mix(n000, n100, f.x), mix(n010, n110, f.x), f.y),
      mix(mix(n001, n101, f.x), mix(n011, n111, f.x), f.y),
      f.z);
  }
  float fbm(vec3 p) {
    float value = 0.0;
    float amplitude = 0.5;
    for (int i = 0; i < 5; i++) {
      value += amplitude * noise(p);
      p *= 2.0;
      amplitude *= 0.5;
    }
    return value;
  }
`

const vertexSource = (shaderData) => `${shaderData.vertexShaderPrelude}
${shaderData.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
void main() {
  v_sphere = a_sphere;
  // Globe wants metres above the sphere; mercator wants z in mercator units. Same deck, two rulers.
  #ifdef GLOBE
    gl_Position = projectTileFor3D(a_pos, a_elevation_globe);
  #else
    gl_Position = projectTileFor3D(a_pos, a_elevation_mercator);
  #endif
}`

const fragmentSource = () => `precision highp float;
varying vec3 v_sphere;
uniform float u_time;
uniform float u_opacity;
uniform float u_drift;
uniform vec3 u_sun;           // direction TO the sun
uniform sampler2D u_field;    // real cloud cover, equirectangular
uniform float u_fieldAmount;  // 0 = procedural weather, 1 = the real field
${NOISE_GLSL}
${EQUIRECT_GLSL}

void main() {
  vec3 p = normalize(v_sphere);
  // Fine noise, used either as the detail that breaks up the real field's edges or as an octave of
  // the procedural fallback. Sampled on the SPHERE, so cloud cells stay the same size at Iceland as
  // at the equator and the field wraps seamlessly across ±180°.
  float detail = fbm(p * 9.0 - vec3(0.0, u_time * 0.01, 0.0));

  float coverage;
  if (u_fieldAmount > 0.0) {
    // Real weather. The texture decides WHERE cloud is; the noise decides what its edges look like,
    // which is the only part a 39 km-per-pixel field cannot tell us.
    float field = texture2D(u_field, equirectUV(p, u_drift)).r;
    coverage = smoothstep(0.16, 0.62, field + 0.14 * (detail - 0.5));
  } else {
    float cover = fbm(p * 3.2 + vec3(u_time * 0.006, 0.0, u_time * 0.004)) + 0.35 * detail;
    // Earth's cloud is banded, not evenly scattered: a wet belt at the equator, the clear
    // subtropical highs where the deserts are, then cloudy mid-latitudes again. Without this the
    // fallback reads as a snowball — every ocean and every desert under the same porridge.
    float sinLat = p.y;
    float band = 1.0
      + 0.35 * exp(-pow(sinLat / 0.16, 2.0))                    // ITCZ
      - 0.40 * exp(-pow((abs(sinLat) - 0.50) / 0.16, 2.0))      // subtropical highs
      + 0.20 * exp(-pow((abs(sinLat) - 0.82) / 0.18, 2.0));     // storm tracks
    coverage = smoothstep(0.70, 1.02, cover * band);
  }

  // A lit face and a grey face, like the real thing.
  float sun = max(dot(p, normalize(u_sun)), 0.0);
  vec3 base = mix(vec3(0.62, 0.66, 0.72), vec3(1.0), 0.35 + 0.65 * sun);
  float alpha = coverage * u_opacity;
  if (alpha < 0.002) discard;
  // MapLibre blends custom layers with gl.ONE / gl.ONE_MINUS_SRC_ALPHA, i.e. PREMULTIPLIED alpha.
  // Returning straight alpha here is what makes a custom layer glow white over dark ground.
  gl_FragColor = vec4(base * alpha, alpha);
}`

/**
 * @param {object} opts
 * @param {number} [opts.opacity]   deck density, 0 hides it entirely
 * @param {boolean} [opts.animate]  false freezes the drift (reduced-motion callers)
 * @param {string} [opts.fieldUrl]  equirectangular cloud-cover image (NASA Blue Marble clouds)
 * @param {number} [opts.driftRate] revolutions per second of the real field, west to east
 * @param {number[]} [opts.sun]     direction TO the sun, in planet space
 */
export const createCloudLayer = ({
  opacity = 0.5, animate = true, fieldUrl = null, driftRate = 0.0004, sun = [0.4, 0.5, 0.75],
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  let buffers = null
  // One compiled program per projection variant: the prelude is different under globe and
  // mercator, and the variant flips mid-flight when the map crosses the globe/mercator threshold.
  const programs = new Map()
  let state = { opacity, animate, fieldUrl, driftRate, sun }
  let fieldTexture = null
  let fieldReady = false

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)

    const program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), 'tm-clouds')
    const entry = {
      program,
      attribs: {
        pos: gl.getAttribLocation(program, 'a_pos'),
        sphere: gl.getAttribLocation(program, 'a_sphere'),
      },
      uniforms: {
        elevationGlobe: gl.getUniformLocation(program, 'a_elevation_globe'),
        elevationMercator: gl.getUniformLocation(program, 'a_elevation_mercator'),
        time: gl.getUniformLocation(program, 'u_time'),
        opacity: gl.getUniformLocation(program, 'u_opacity'),
        drift: gl.getUniformLocation(program, 'u_drift'),
        sun: gl.getUniformLocation(program, 'u_sun'),
        field: gl.getUniformLocation(program, 'u_field'),
        fieldAmount: gl.getUniformLocation(program, 'u_fieldAmount'),
        // Projection uniforms the prelude declares. Only the globe variant has the last four, so
        // every one of these is allowed to come back null.
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

  const setProjectionUniforms = (u, data) => {
    if (u.matrix) gl.uniformMatrix4fv(u.matrix, false, data.mainMatrix)
    if (u.tileMercatorCoords) gl.uniform4f(u.tileMercatorCoords, ...data.tileMercatorCoords)
    if (u.clippingPlane) gl.uniform4f(u.clippingPlane, ...data.clippingPlane)
    if (u.transition) gl.uniform1f(u.transition, data.projectionTransition)
    if (u.fallbackMatrix) gl.uniformMatrix4fv(u.fallbackMatrix, false, data.fallbackMatrix)
  }

  // The field wraps in longitude and clamps at the poles — REPEAT on T would fold the Arctic onto
  // the Antarctic at the seam.
  const loadField = (url) => {
    const image = new Image()
    image.crossOrigin = 'anonymous'
    image.onload = () => {
      if (!gl) return
      fieldTexture = fieldTexture || gl.createTexture()
      gl.bindTexture(gl.TEXTURE_2D, fieldTexture)
      gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false)
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
      gl.generateMipmap(gl.TEXTURE_2D)
      fieldReady = true
      map?.triggerRepaint()
    }
    // Decorative: a failed load leaves the procedural weather in place rather than an empty sky.
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
      if (state.fieldUrl) loadField(state.fieldUrl)
    },

    // Without this every style reload leaks a program, a texture and three buffers.
    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      if (fieldTexture) { gl.deleteTexture(fieldTexture); fieldTexture = null; fieldReady = false }
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
      if (!buffers || state.opacity <= 0) return
      // v4 handed a matrix here; v5 hands this options object. Bail rather than feed the old code
      // path something it will silently mis-read.
      const shaderData = args && args.shaderData
      const projection = args && args.defaultProjectionData
      if (!shaderData || !projection) return

      const { program, attribs, uniforms } = programFor(shaderData)
      gl.useProgram(program)
      setProjectionUniforms(uniforms, projection)

      const lat = map.getCenter().lat
      const seconds = performance.now() * 0.001
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, ALTITUDE_M)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([0, lat], ALTITUDE_M).z)
      }
      if (uniforms.time) gl.uniform1f(uniforms.time, state.animate ? seconds : 0)
      if (uniforms.opacity) gl.uniform1f(uniforms.opacity, state.opacity)
      if (uniforms.sun) gl.uniform3f(uniforms.sun, ...state.sun)
      if (uniforms.fieldAmount) gl.uniform1f(uniforms.fieldAmount, fieldReady ? 1 : 0)
      // Real weather tracks west to east. Slow enough that a lesson never sees it move, fast enough
      // that the planet is not visibly frozen across a long scene.
      if (uniforms.drift) gl.uniform1f(uniforms.drift, state.animate ? seconds * state.driftRate : 0)
      if (fieldReady && uniforms.field) {
        gl.activeTexture(gl.TEXTURE0)
        gl.bindTexture(gl.TEXTURE_2D, fieldTexture)
        gl.uniform1i(uniforms.field, 0)
      }

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(attribs.pos)
      gl.vertexAttribPointer(attribs.pos, 2, gl.FLOAT, false, 0, 0)
      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.sphere)
      gl.enableVertexAttribArray(attribs.sphere)
      gl.vertexAttribPointer(attribs.sphere, 3, gl.FLOAT, false, 0, 0)

      gl.enable(gl.DEPTH_TEST)
      gl.depthFunc(gl.LEQUAL)
      // Transparent: test against the globe's depth, never write. Writing is what would cull the
      // ships and voyage lines beneath the deck (see voyage-ships.js).
      gl.depthMask(false)
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, mesh.indices.length, gl.UNSIGNED_SHORT, 0)
      gl.depthMask(true)

      // Only ask for another frame when something is actually moving.
      if (state.animate) map.triggerRepaint()
    },

    /** Live controls — density, drift, sun, and which cloud field is shown. */
    setOptions(next = {}) {
      const previousUrl = state.fieldUrl
      state = { ...state, ...next }
      if (next.fieldUrl !== undefined && next.fieldUrl !== previousUrl) {
        fieldReady = false
        if (next.fieldUrl) loadField(next.fieldUrl)
      }
      if (map) map.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    /** True once a real cloud field is uploaded — the deck is procedural until then. */
    get hasField () { return fieldReady },
  }
}

export const CLOUD_LAYER_ID = LAYER_ID

/**
 * atmosphere.js — the blue band around the earth's edge, as a MapLibre custom layer.
 *
 * In every photograph from orbit the planet does not simply stop: there is a thin bright rim above
 * the horizon, brightest where you are looking along the most air, fading to black over a few
 * hundred kilometres. It is the single cheapest cue that a globe is a world with air on it rather
 * than a textured ball, and MapLibre's own `sky` only approximates it as a flat colour ramp.
 *
 * HOW IT WORKS. Draw a sphere a little larger than the planet and, for each pixel, measure how far
 * that view ray travels through the shell of air between the ground and the top of the atmosphere.
 * Looking straight down, the ray crosses the shell almost perpendicular — a short path, so barely
 * any haze. Looking at the limb, it skims tangentially and crosses hundreds of kilometres of air —
 * a long path, so a bright band. That single geometric fact is the whole effect; everything else
 * here is colour.
 *
 * Density falls off exponentially with height (the atmosphere is half gone by 5 km), so the path is
 * sampled rather than taken raw, which is what puts the brightest part of the band just above the
 * horizon instead of at the very top of the shell.
 *
 * WHAT IT IS NOT. This is not Rayleigh scattering integrated properly — no precomputed transmittance
 * tables, no multiple scattering. Those need the machinery a full atmosphere library brings, and
 * measured here, that machinery costs more than the whole live map. This is the shape of the thing
 * at a few dozen instructions a pixel.
 */

import maplibregl from 'maplibre-gl'
import { buildSphereMesh, cameraInPlanetSpace, buildProgram, SHELL_PROJECT_GLSL, SPHERE_SPAN_GLSL } from './planet-mesh.js'

const LAYER_ID = 'tm-atmosphere'
const EARTH_RADIUS_M = 6371008.8
/**
 * Top of the drawn shell. The real atmosphere fades out around 100 km — 1.6% of the earth's radius,
 * which at globe zoom is a handful of pixels. This is exaggerated to roughly 3%, far enough to read
 * as a band on a classroom projector while still looking like a skin on the planet rather than a
 * halo around it.
 */
const ATMOSPHERE_M = 200000

const vertexSource = (shaderData) => `${shaderData.vertexShaderPrelude}
${shaderData.define}
attribute vec2 a_pos;
attribute vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
varying vec3 v_sphere;
${SHELL_PROJECT_GLSL}
void main() {
  v_sphere = a_sphere;
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`

const fragmentSource = () => `precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;      // camera in planet space (earth = unit sphere)
uniform vec3 u_sun;         // direction TO the sun
uniform float u_top;        // top of the atmosphere, in earth radii
uniform float u_strength;
uniform vec3 u_dayColour;
uniform vec3 u_duskColour;
uniform float u_forward;      // how much of the forward-scattering lobe to keep, 0 = none
uniform float u_scatter_g;    // its narrowness; 0 is uniform, 0.9 is a searchlight

const int SAMPLES = 6;

${SPHERE_SPAN_GLSL}

/**
 * Is this parcel of air in sunlight, or is the earth in the way?
 *
 * THE WHOLE POINT. Air only scatters light that reaches it, so the band must be gated on whether
 * the air itself is lit, NOT on which way it faces. This used to be a smoothstep from -0.35 to
 * 0.25 over dot(normalize(pos), u_sun), an ANGULAR test, and that is what ringed the planet: at
 * the terminator it returns 0.62, and it keeps returning something twenty degrees past it, so the
 * unlit limb was handed a fifth of full brightness and drew a rim all the way round. In the NASA
 * photograph the night limb is black — not dim, absent — and the reason is geometric, not
 * angular. Air behind the earth is in its shadow.
 *
 * NO BACKTICKS IN THIS COMMENT. It lives inside the fragment shader's template literal, so one
 * would end the string and the file would fail to parse a long way from here.
 *
 * So the test is the shadow itself: is the earth between this air and the sun.
 *
 * THE TEST IS TAKEN AT THE GROUND BENEATH THE SAMPLE, NOT AT THE SAMPLE. That looks like a
 * needless approximation and it is the entire difference between this working and not. The drawn
 * shell is EXAGGERATED — 200 km, about 3% of the radius, because the real 100 km is a couple of
 * pixels at globe zoom. Air at a made-up altitude clears the shadow cylinder at a made-up angle,
 * so testing the sample's own position lets the band survive a long way onto the dark side. That
 * was measured, not feared: the first version of this function tested the sample directly and made
 * the 90-120° sector BRIGHTER than the angular gate it replaced, 50.6 to 83.4. It fixed nothing and
 * would have shipped looking like a fix.
 *
 * Projecting to the unit sphere first asks the question the exaggeration cannot distort: is the
 * ground under this column in daylight. That is the terminator in the photograph.
 *
 * The window is deliberately tight — a sliver of twilight rather than an arc, dying within about
 * eight degrees of the terminator. A wider one is more physical and is exactly the halo being
 * removed; Bart's reference is a night side with no glow on the dark limb AT ALL.
 */
float sunlitFraction(vec3 pos) {
  vec3 ground = normalize(pos);                            // the surface below this parcel of air
  float alongSun = dot(ground, u_sun);
  if (alongSun > 0.0) return 1.0;                          // daylit hemisphere
  float axisDistance = length(ground - u_sun * alongSun);  // 1.0 exactly at the terminator
  return smoothstep(0.995, 1.0, axisDistance);
}

void main() {
  vec3 shell = normalize(v_sphere) * u_top;
  vec3 toCamera = u_camera - shell;
  // The mesh is a whole sphere, so the far hemisphere covers the same pixels as the near one and
  // would double the brightness. Keep the half facing the camera.
  if (dot(normalize(toCamera), normalize(shell)) < 0.0 && length(u_camera) > u_top) discard;

  vec3 ray = normalize(shell - u_camera);
  vec2 air = sphereSpan(u_camera, ray, u_top);
  if (air.x > air.y) discard;

  float near = max(air.x, 0.0);
  float far = air.y;
  // Air in FRONT of the ground only: past the surface the ray is underground, and counting it
  // would ring the planet in haze from the inside.
  vec2 ground = sphereSpan(u_camera, ray, 1.0);
  bool hitsGround = ground.x <= ground.y && ground.y > 0.0;
  if (hitsGround) far = min(far, max(ground.x, 0.0));
  if (far <= near) discard;

  // Walk the ray, weighting each sample by air density at that height. Density halves every ~5 km,
  // which on this scale is a very fast falloff — hence brightest just above the horizon.
  float stepLen = (far - near) / float(SAMPLES);
  float density = 0.0;
  float lit = 0.0;
  for (int i = 0; i < SAMPLES; i++) {
    vec3 pos = u_camera + ray * (near + stepLen * (float(i) + 0.5));
    float height = (length(pos) - 1.0) / (u_top - 1.0);       // 0 at the ground, 1 at the top
    float d = exp(-max(height, 0.0) * 4.5) * stepLen;
    density += d;
    lit += d * sunlitFraction(pos);
  }
  if (density <= 0.0) discard;
  lit /= density;

  // Sunset colour at the terminator: the low sun's light has crossed enough air to lose its blue,
  // which is why the band goes orange exactly where day meets night.
  float grazing = 1.0 - abs(dot(normalize(shell), normalize(u_camera - shell)));
  vec3 colour = mix(u_duskColour, u_dayColour, smoothstep(0.15, 0.65, lit));

  float alpha = (1.0 - exp(-density * 150.0 * u_strength)) * lit;
  // Face-on the ray crosses the shell almost perpendicular — a few hundred kilometres of thin air,
  // which from orbit is very nearly clear. At the limb it skims tangentially through the whole
  // depth of the atmosphere. Squaring the grazing term puts almost all the brightness in the band,
  // which is where a photograph puts it; a linear falloff leaves the disc under a blue wash and
  // buries the imagery it is supposed to sit on.
  alpha *= 0.04 + 0.96 * pow(grazing, 2.5);

  // FORWARD SCATTERING, which is why haze is not equally bright all the way round.
  //
  // Air does not scatter light evenly in all directions. Particles comparable to the wavelength
  // throw most of it very nearly FORWARD, so looking through haze toward the sun is many times
  // brighter than looking through the same haze away from it. It is why a windscreen you cannot see
  // through driving west is perfectly clear driving east, and on a globe it is why the limb beside
  // the sun blazes while the far limb stays a thin cold line.
  //
  // Henyey-Greenstein, the standard one-parameter phase function. Normalised at right angles so
  // u_forward = 0 is exactly the old look and the term only ever adds. Clamped because the true
  // lobe runs to 25x within a couple of degrees of the sun's centre, where the disc is drawn over
  // it anyway — uncapped it produces a white blob on the limb rather than a bright limb.
  float cosTheta = dot(ray, u_sun);
  float g = u_scatter_g;
  float phase = (1.0 - g * g) / pow(1.0 + g * g - 2.0 * g * cosTheta, 1.5);
  float sideways = (1.0 - g * g) / pow(1.0 + g * g, 1.5);
  float boost = u_forward * min(phase / sideways - 1.0, 5.0);

  // Bright haze is WHITE haze. Scattering that strong is light that has bounced several times, and
  // multiple scattering washes the colour out — which is why a hazy sun is a white glare and not a
  // saturated blue one. Without this the boost merely amplifies the existing day/dusk ramp, and
  // since that ramp runs from orange to blue the amplified overlap lands on magenta: a rainbow
  // fringe around the limb that looks exactly like chromatic aberration and is nothing of the sort.
  colour = mix(colour, vec3(1.0), clamp(boost * 0.2, 0.0, 0.7));
  alpha *= 1.0 + boost;

  // Alpha ABOVE ONE is the other half of that bug. The output is premultiplied, so an alpha of 1.6
  // writes a colour brighter than its own coverage; the channels then clip one at a time and the
  // hue shifts as it saturates rather than simply going white.
  alpha = min(alpha, 1.0);

  if (alpha < 0.002) discard;
  gl_FragColor = vec4(colour * alpha, alpha);
}`

/**
 * @param {object} opts
 * @param {number} [opts.strength]    0 hides the band entirely
 * @param {number[]} [opts.sun]       direction TO the sun, planet space; share it with the clouds
 * @param {number[]} [opts.dayColour] the band's colour in full daylight
 * @param {number[]} [opts.duskColour] its colour at the terminator
 * @param {number} [opts.forwardScatter] strength of the forward lobe; 0 is the old even haze
 * @param {number} [opts.scatterG]    how narrow that lobe is, 0..0.95
 */
export const createAtmosphereLayer = ({
  strength = 1,
  sun = [0.4, 0.5, 0.75],
  dayColour = [0.32, 0.55, 1.0],
  duskColour = [1.0, 0.45, 0.18],
  forwardScatter = 0.35,
  scatterG = 0.6,
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  let buffers = null
  const programs = new Map()
  let state = { strength, sun, dayColour, duskColour, forwardScatter, scatterG }

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)
    const program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), 'tm-atmosphere')
    const entry = {
      program,
      attribs: {
        pos: gl.getAttribLocation(program, 'a_pos'),
        sphere: gl.getAttribLocation(program, 'a_sphere'),
      },
      uniforms: {
        elevationGlobe: gl.getUniformLocation(program, 'a_elevation_globe'),
        elevationMercator: gl.getUniformLocation(program, 'a_elevation_mercator'),
        camera: gl.getUniformLocation(program, 'u_camera'),
        sun: gl.getUniformLocation(program, 'u_sun'),
        top: gl.getUniformLocation(program, 'u_top'),
        strength: gl.getUniformLocation(program, 'u_strength'),
        dayColour: gl.getUniformLocation(program, 'u_dayColour'),
        duskColour: gl.getUniformLocation(program, 'u_duskColour'),
        forward: gl.getUniformLocation(program, 'u_forward'),
        scatterG: gl.getUniformLocation(program, 'u_scatter_g'),
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
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
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
      if (!buffers || state.strength <= 0) return
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

      const lat = map.getCenter().lat
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, ATMOSPHERE_M)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([0, lat], ATMOSPHERE_M).z)
      }
      const camera = cameraInPlanetSpace(map, maplibregl)
      if (uniforms.camera) gl.uniform3f(uniforms.camera, camera[0], camera[1], camera[2])
      if (uniforms.sun) gl.uniform3f(uniforms.sun, ...state.sun)
      if (uniforms.top) gl.uniform1f(uniforms.top, 1 + ATMOSPHERE_M / EARTH_RADIUS_M)
      if (uniforms.strength) gl.uniform1f(uniforms.strength, state.strength)
      if (uniforms.dayColour) gl.uniform3f(uniforms.dayColour, ...state.dayColour)
      if (uniforms.duskColour) gl.uniform3f(uniforms.duskColour, ...state.duskColour)
      if (uniforms.forward) gl.uniform1f(uniforms.forward, state.forwardScatter)
      if (uniforms.scatterG) gl.uniform1f(uniforms.scatterG, state.scatterG)

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(attribs.pos)
      gl.vertexAttribPointer(attribs.pos, 2, gl.FLOAT, false, 0, 0)
      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.sphere)
      gl.enableVertexAttribArray(attribs.sphere)
      gl.vertexAttribPointer(attribs.sphere, 3, gl.FLOAT, false, 0, 0)

      // Air is not geometry: it must never occlude the clouds or the ground beneath it.
      gl.enable(gl.DEPTH_TEST)
      gl.depthFunc(gl.LEQUAL)
      gl.depthMask(false)
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, mesh.indices.length, gl.UNSIGNED_SHORT, 0)
      gl.depthMask(true)
    },

    /** Live controls — brightness and sun direction, shared with the cloud deck. */
    setOptions(next = {}) {
      state = { ...state, ...next }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
  }
}

export const ATMOSPHERE_LAYER_ID = LAYER_ID

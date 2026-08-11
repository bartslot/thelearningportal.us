/**
 * starfield.js — the real sky behind the earth, as a MapLibre custom layer.
 *
 * A globe on flat black reads as a diagram. The same globe against the Milky Way reads as a place.
 * And on a map with a real date and a real ephemeris, the sky can be the sky that was actually
 * there: the panorama is in celestial coordinates, the stars come out of a catalogue, and both are
 * precessed and turned to the moment the lesson is set in. Orion is where Orion was.
 *
 * ── WHY THIS IS DRAWN IN SCREEN SPACE, AND NOT AS A SPHERE ────────────────────────────────────
 *
 * The obvious way to draw a sky is a big textured sphere with the camera inside it, and that is
 * what this file used to do. It fails, in a way that cannot be tuned out.
 *
 * A sphere has a radius, and the radius has to be simultaneously bigger than the camera's distance
 * from the earth — or the camera flies through it and the "sky" becomes a dark BALL hanging in
 * space with the earth inside — and small enough for MapLibre's globe projection to reach. It
 * cannot be both, because that projection has a hard ceiling: past roughly six earth radii from the
 * centre a point's clip-space w goes NEGATIVE and the geometry is dropped before rasterisation.
 * Measured at z1.9: w = +115 at five radii, −194 at six. Scaling the radius with the camera was
 * tried and only moved the failure — the multiple overshot the far plane at ordinary globe zoom
 * and the sky vanished entirely.
 *
 * A ray has no radius. So the sky is now a full-screen quad, and each fragment reconstructs the ray
 * leaving the camera through it, from the camera frame `sky-camera.js` builds. There is no
 * geometry, no distance, and nothing for a clip plane to have an opinion about. It is correct at
 * every zoom, including zooms far past where a shell could ever have been put.
 *
 * ── WHAT STOPS IT PAINTING OVER THE EARTH ─────────────────────────────────────────────────────
 *
 * A custom layer draws AFTER the style's raster layers, not before them, so an opaque sky with
 * nothing to stop it wipes the planet off the screen. The depth buffer is no help at this range —
 * at orbital distance it has no precision left. So the occlusion is analytic: the earth is the unit
 * sphere at the origin in this space, and a ray that hits it in front of the camera is a ray with
 * no sky on it. That test is exact at any altitude, which is the same reason the rest of this works.
 *
 * ── TWO KINDS OF STAR ─────────────────────────────────────────────────────────────────────────
 *
 * The bright ones are REAL: about 9,100 of them from the Yale Bright Star Catalogue, at their true
 * right ascension and declination, sized by their true magnitude and tinted by their true surface
 * temperature. That is what makes constellations findable rather than merely suggestive.
 *
 * Behind them, the procedural field fills in the ones no catalogue of naked-eye stars contains.
 * They are hashed in CELESTIAL coordinates, not in planet space, so they hold still against the
 * turning earth exactly as the real ones do. See `stars()` for the trap that made this layer
 * measure as exactly zero lit pixels for a while.
 */

import maplibregl from 'maplibre-gl'
import { buildProgram } from './planet-mesh.js'
import { skyFrameMatrix, PANORAMA_UV_GLSL } from './celestial.js'
import { viewRayBasis } from './sky-camera.js'
import { acquireEquirectTexture } from './equirect-texture.js'
import { chooseSkyTier, readDeviceFacts, SKY_PLACEHOLDER, SKY_TIERS } from './sky-tiers.js'

const LAYER_ID = 'tm-starfield'

/**
 * The panorama is not one file — it is a ladder, and which rung this device gets is decided at
 * runtime from its texture limit and what it will admit about itself. See `sky-tiers.js`; the
 * short version is that above `MAX_TEXTURE_SIZE` a texture does not soften, it fails to bind, and
 * the result is a black sky with no error on hardware nobody reviewing this owns.
 *
 * Passing `textureUrl` explicitly overrides the choice, which is what the bench does.
 */
export const SKY_PLACEHOLDER_URL = SKY_PLACEHOLDER.url
export const SKY_CATALOGUE_URL = '/data/sky/bright-stars.bin'

/**
 * A star's colour from its surface temperature, as an approximation of the blackbody curve
 * (Tanner Helland's fit, which is accurate to a couple of percent over the range stars occupy).
 *
 * Real naked-eye stars run from about 2,500 K — deep orange, Betelgeuse — to 30,000 K, the blue of
 * Rigel and the belt of Orion. Most sit near the middle and read as white, so the spread is subtle
 * and the tell of a fake sky is having too much of it.
 *
 * @param {number} kelvin
 * @returns {number[]} linear-ish rgb in 0..1, normalised so the brightest channel is 1
 */
export const starColour = (kelvin) => {
  // Math.max propagates NaN rather than clamping it, and a NaN colour draws as nothing at all —
  // one of the rows in this catalogue would simply be missing from the sky, with no error.
  const safe = Number.isFinite(kelvin) ? kelvin : 5800
  const t = Math.min(40000, Math.max(1000, safe)) / 100
  const channel = (v) => Math.min(1, Math.max(0, v / 255))

  const red = t <= 66 ? 255 : 329.698727446 * Math.pow(t - 60, -0.1332047592)
  const green = t <= 66
    ? 99.4708025861 * Math.log(t) - 161.1195681661
    : 288.1221695283 * Math.pow(t - 60, -0.0755148492)
  const blue = t >= 66 ? 255
    : t <= 19 ? 0
      : 138.5177312231 * Math.log(t - 10) - 305.0447927307

  const rgb = [channel(red), channel(green), channel(blue)]
  const peak = Math.max(...rgb) || 1
  return rgb.map((v) => v / peak)
}

/**
 * The catalogue as a vertex buffer: right ascension, declination, a brightness, and a colour.
 *
 * Brightness comes from the magnitude scale, which is logarithmic and upside down — every five
 * magnitudes is a factor of a hundred in flux, and SMALLER is brighter. Sirius at −1.46 is about
 * a thousand times the flux of the faintest star here at 6.5. Drawn linearly in flux the sky is one
 * white dot and nine thousand invisible ones, so the flux is raised to a fractional power: enough
 * range that Sirius is obviously the brightest thing up there, not so much that everything else
 * disappears. That compression is exactly what the eye does anyway.
 *
 * @param {ArrayBuffer} buffer  interleaved float32 [ra, dec, magnitude, kelvin] per star
 */
export const buildStarVertices = (buffer, { limitMagnitude = 6.5 } = {}) => {
  const source = new Float32Array(buffer)
  const count = Math.floor(source.length / 4)
  const rows = []
  for (let i = 0; i < count; i++) {
    const magnitude = source[i * 4 + 2]
    if (magnitude > limitMagnitude) continue
    const flux = Math.pow(10, -0.4 * magnitude)
    rows.push([
      source[i * 4],
      source[i * 4 + 1],
      Math.pow(flux, 0.36),
      ...starColour(source[i * 4 + 3]),
    ])
  }
  const vertices = new Float32Array(rows.length * 6)
  rows.forEach((row, i) => vertices.set(row, i * 6))
  return { vertices, count: rows.length }
}

/**
 * The two pieces of geometry every fragment here needs: the ray through this pixel, and whether the
 * earth is in front of it. Shared as source between the sky pass and the star pass so the stars can
 * never be occluded on a different horizon from the sky behind them.
 */
const RAY_GLSL = /* glsl */`
  uniform vec3 u_camera;      // camera in planet space, earth = unit sphere
  uniform vec3 u_forward;
  uniform vec3 u_right;
  uniform vec3 u_up;
  uniform vec2 u_halfExtent;  // tan(fov/2), times aspect for x

  vec3 rayThrough(vec2 ndc) {
    return normalize(u_forward + u_right * (ndc.x * u_halfExtent.x) + u_up * (ndc.y * u_halfExtent.y));
  }

  // Standard ray against the unit sphere. A hit in front of the camera means the planet is in the
  // way — exact at any distance, which the depth buffer is not at this one.
  bool earthBlocks(vec3 ray) {
    float b = dot(u_camera, ray);
    float c = dot(u_camera, u_camera) - 1.0;
    float disc = b * b - c;
    if (disc < 0.0) return false;
    return (-b - sqrt(disc)) > 0.0;
  }
`

const skyVertexSource = (shaderData) => `${shaderData.define}
attribute vec2 a_pos;
varying vec2 v_ndc;
void main() {
  v_ndc = a_pos;
  // Straight to clip space. No projection, no elevation, no prelude — that is the whole point.
  gl_Position = vec4(a_pos, 0.0, 1.0);
}`

const skyFragmentSource = (shaderData) => `${shaderData.define}
precision highp float;
varying vec2 v_ndc;
uniform sampler2D u_sky;
uniform mat3 u_skyFrame;     // planet space -> the J2000 grid the panorama is drawn in
uniform float u_globeness;   // 1 on the globe, 0 on the flat map
uniform float u_brightness;
uniform float u_nebula;      // 0 drops the panorama and leaves bare stars
uniform float u_nebulaContrast;
uniform float u_starDensity; // cells per radian: higher is more, smaller stars
uniform float u_starAmount;
uniform float u_time;
uniform float u_twinkle;
${RAY_GLSL}
${PANORAMA_UV_GLSL}

vec3 hash3(vec3 p) {
  p = vec3(dot(p, vec3(127.1, 311.7, 74.7)),
           dot(p, vec3(269.5, 183.3, 246.1)),
           dot(p, vec3(113.5, 271.9, 124.6)));
  return fract(sin(p) * 43758.5453123);
}

// One star per cell at most, placed by hash. Sampling the 3x3x3 neighbourhood would let stars sit
// near cell edges without clipping, at 27x the cost; at these densities a single cell is enough.
vec3 stars(vec3 skyDir) {
  vec3 p = skyDir * u_starDensity;
  vec3 cell = floor(p);
  vec3 h = hash3(cell);

  // Most of the sky is empty. This threshold is what keeps stars sparse rather than a dot screen.
  if (h.x > 0.05) return vec3(0.0);

  vec3 offset = hash3(cell + 17.0);
  float dist = length(fract(p) - offset);

  // A STAR HAS TO BE BIGGER THAN A PIXEL OR IT IS NOT THERE.
  //
  // The obvious way to draw a point source is a very steep falloff, and it silently produces an
  // empty sky. At this density a cell is about seven screen pixels, so a profile that has decayed
  // by a tenth of a cell is a third of a pixel wide — narrower than the sample grid. Nothing is
  // clipped and nothing is discarded; the pixel centres simply never land on a star, and the whole
  // layer measures as exactly zero across a hundred thousand pixels while looking, in code,
  // entirely reasonable.
  //
  // So the profile is a gaussian roughly a pixel and a half across, which always registers, and
  // which is also what a real point source looks like once optics have spread it.
  float core = exp(-dist * dist * 60.0);

  // These are the stars no naked-eye catalogue lists, so they sit BELOW the real ones: lopsided
  // toward the faint end, and capped well under the brightness of a catalogue star. The floor is
  // what stops the faint majority being crushed below one 255th and vanishing for the same reason.
  float magnitude = 0.10 + 0.55 * pow(h.y, 4.5);

  // Blue-white through to amber, weighted toward white as real naked-eye stars are.
  vec3 tint = mix(vec3(0.74, 0.83, 1.0), vec3(1.0, 0.84, 0.66), pow(h.z, 1.6));

  // Atmospheric scintillation, per star, at its own rate. Off by default: from orbit there is no
  // air to twinkle through, so it is a stylistic choice rather than a physical one.
  float flicker = 1.0 - u_twinkle * 0.5 * (0.5 + 0.5 * sin(u_time * (1.7 + h.z * 4.0) + h.y * 31.4));

  return tint * core * magnitude * flicker;
}

void main() {
  #ifdef GLOBE
    vec3 ray = rayThrough(v_ndc);
    if (earthBlocks(ray)) discard;

    vec3 skyDir = u_skyFrame * ray;

    // The panorama is stored with a gamma on it, which is what gets the faint structure into eight
    // bits at all. Read back flat it is a grey haze with the dust lanes floating in it, so a little
    // of that gamma is taken out again here — enough that empty sky reads as empty and the band
    // reads as a band, not enough to lose what the gamma was for.
    vec3 sky = pow(texture2D(u_sky, panoramaUV(skyDir)).rgb, vec3(u_nebulaContrast)) * u_nebula;
    sky += stars(skyDir) * u_starAmount;
    // Premultiplied, because MapLibre blends ONE / ONE_MINUS_SRC_ALPHA. The fade is what dissolves
    // the sky as the globe flattens into the mercator map, where there is no space to look into.
    gl_FragColor = vec4(sky * u_brightness * u_globeness, u_globeness);
  #else
    // A flat map has no sky around it. Drawing one would put stars over Kansas.
    discard;
  #endif
}`

const starVertexSource = (shaderData) => `${shaderData.define}
attribute vec3 a_star;       // right ascension and declination in degrees, then brightness
attribute vec3 a_colour;
uniform mat3 u_skyFrame;
uniform float u_pixelRatio;
uniform float u_starSize;
uniform float u_catalogueAmount;
varying vec3 v_colour;
${RAY_GLSL}

void main() {
  float ra = radians(a_star.x);
  float dec = radians(a_star.y);
  vec3 celestial = vec3(cos(dec) * cos(ra), sin(dec), cos(dec) * sin(ra));

  // v * M is transpose(M) * v, and the transpose of the sky frame is the star frame. One uniform
  // does both directions, which is the only way to be sure they agree.
  vec3 dir = celestial * u_skyFrame;

  float depth = dot(dir, u_forward);
  if (depth <= 0.0 || earthBlocks(dir)) {
    // Behind the camera, or behind the earth. Park it off screen at zero size rather than
    // discarding in the fragment shader, which would still cost the rasterisation.
    gl_Position = vec4(2.0, 2.0, 2.0, 1.0);
    gl_PointSize = 0.0;
    return;
  }

  vec2 ndc = vec2(dot(dir, u_right) / u_halfExtent.x, dot(dir, u_up) / u_halfExtent.y) / depth;
  gl_Position = vec4(ndc, 0.0, 1.0);

  // Brighter stars are drawn bigger as well as brighter, which is how every star chart ever printed
  // conveys magnitude, and how a telescope-free eye perceives it too.
  gl_PointSize = u_pixelRatio * u_starSize * (0.85 + 1.9 * a_star.z);
  v_colour = a_colour * a_star.z * u_catalogueAmount;
}`

const starFragmentSource = (shaderData) => `${shaderData.define}
precision highp float;
varying vec3 v_colour;
void main() {
  // A gaussian across the point sprite. Same reasoning as the procedural field: a hard disc reads
  // as a dot of paint, and anything much tighter than a pixel measures as nothing at all.
  vec2 offset = gl_PointCoord - 0.5;
  float core = exp(-dot(offset, offset) * 22.0);
  // Additive against premultiplied blending: zero alpha adds the colour and takes nothing away, so
  // a star lies on top of the nebula behind it rather than punching a hole in it.
  gl_FragColor = vec4(v_colour * core, 0.0);
}`

/**
 * @param {object} opts
 * @param {string} [opts.textureUrl]       equirectangular sky panorama, celestial coordinates
 * @param {string} [opts.catalogueUrl]     the bright star table; null draws only procedural stars
 * @param {Date}   [opts.date]             the moment the sky is shown for
 * @param {number} [opts.brightness]       the panorama competes with a lit planet; usually turned down
 * @param {number} [opts.catalogueAmount]  how loud the real stars are against the procedural fill
 */
export const createStarfieldLayer = ({
  textureUrl = null, placeholderUrl = SKY_PLACEHOLDER_URL, catalogueUrl = SKY_CATALOGUE_URL,
  date = new Date(), brightness = 0.55, nebula = 0.3, nebulaContrast = 1.45,
  starDensity = 210, starAmount = 1.5, catalogueAmount = 2.2, starSize = 3,
  limitMagnitude = 6.5, twinkle = 0, animate = false,
} = {}) => {
  let map = null
  let gl = null
  let buffers = null
  let skyHandle = null
  let placeholderHandle = null
  let tier = null
  let tierReason = 'not chosen yet'
  let starCount = 0
  const programs = new Map()
  let state = {
    textureUrl, placeholderUrl, catalogueUrl, date, brightness, nebula, nebulaContrast,
    starDensity, starAmount, catalogueAmount, starSize, limitMagnitude, twinkle, animate,
  }

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)

    const sky = buildProgram(gl, skyVertexSource(shaderData), skyFragmentSource(shaderData), 'tm-starfield')
    const stars = buildProgram(gl, starVertexSource(shaderData), starFragmentSource(shaderData), 'tm-starfield-catalogue')
    const uniform = (program, name) => gl.getUniformLocation(program, name)
    const entry = {
      sky: {
        program: sky,
        pos: gl.getAttribLocation(sky, 'a_pos'),
        uniforms: {
          camera: uniform(sky, 'u_camera'),
          forward: uniform(sky, 'u_forward'),
          right: uniform(sky, 'u_right'),
          up: uniform(sky, 'u_up'),
          halfExtent: uniform(sky, 'u_halfExtent'),
          skyFrame: uniform(sky, 'u_skyFrame'),
          sky: uniform(sky, 'u_sky'),
          globeness: uniform(sky, 'u_globeness'),
          brightness: uniform(sky, 'u_brightness'),
          nebula: uniform(sky, 'u_nebula'),
          nebulaContrast: uniform(sky, 'u_nebulaContrast'),
          starDensity: uniform(sky, 'u_starDensity'),
          starAmount: uniform(sky, 'u_starAmount'),
          twinkle: uniform(sky, 'u_twinkle'),
          time: uniform(sky, 'u_time'),
        },
      },
      stars: {
        program: stars,
        star: gl.getAttribLocation(stars, 'a_star'),
        colour: gl.getAttribLocation(stars, 'a_colour'),
        uniforms: {
          camera: uniform(stars, 'u_camera'),
          forward: uniform(stars, 'u_forward'),
          right: uniform(stars, 'u_right'),
          up: uniform(stars, 'u_up'),
          halfExtent: uniform(stars, 'u_halfExtent'),
          skyFrame: uniform(stars, 'u_skyFrame'),
          pixelRatio: uniform(stars, 'u_pixelRatio'),
          starSize: uniform(stars, 'u_starSize'),
          catalogueAmount: uniform(stars, 'u_catalogueAmount'),
        },
      },
    }
    programs.set(key, entry)
    return entry
  }

  /**
   * Take shares in the panorama and its placeholder.
   *
   * Two of them, on purpose. The placeholder arrives in a blink and holds the sky while the real
   * one is still coming down a school connection; nobody watches a black rectangle, and a sky that
   * sharpens after a second is not something anyone notices. Once the full one lands the
   * placeholder is released, so only one of them is on the card for the life of the map.
   *
   * Both go through the shared loader with `mipmap: false` — see the note in `equirect-texture.js`
   * for why that is not a preference. Sharing matters even though nothing else currently loads
   * these two images: a second copy of a panorama is exactly the kind of thing that goes unnoticed
   * until video memory runs out on a school tablet.
   */
  const loadSky = () => {
    if (!gl) return
    const claim = (url, onReady) => (url ? acquireEquirectTexture(gl, url, onReady, { mipmap: false }) : null)

    // An explicit URL wins; otherwise the device is asked what it can take. The reason is recorded
    // rather than only the answer, because "why did this class get the small one" is the question
    // that gets asked, and nothing else in the frame can answer it.
    if (state.textureUrl === null) {
      const chosen = chooseSkyTier(readDeviceFacts(gl))
      tier = chosen.tier
      tierReason = chosen.reason
    } else {
      tier = SKY_TIERS.find((entry) => entry.url === state.textureUrl) ?? { name: 'explicit', url: state.textureUrl }
      tierReason = 'the caller named a panorama'
    }

    placeholderHandle = claim(state.placeholderUrl, () => { map?.triggerRepaint() })
    skyHandle = claim(tier.url === state.placeholderUrl ? null : tier.url, () => {
      // The full panorama is in. Let go of the stand-in.
      placeholderHandle?.release()
      placeholderHandle = null
      map?.triggerRepaint()
    })
  }

  /** Whichever panorama is the best one currently on the card. */
  const currentSky = () => (skyHandle?.ready ? skyHandle : (placeholderHandle?.ready ? placeholderHandle : null))

  const releaseSky = () => {
    skyHandle?.release()
    placeholderHandle?.release()
    skyHandle = null
    placeholderHandle = null
  }

  const loadCatalogue = (url) => {
    if (!url || typeof fetch !== 'function') return
    fetch(url)
      .then((response) => {
        if (!response.ok) throw new Error(`${response.status} ${response.statusText}`)
        return response.arrayBuffer()
      })
      .then((buffer) => {
        if (!gl || !buffers) return
        const { vertices, count } = buildStarVertices(buffer, { limitMagnitude: state.limitMagnitude })
        gl.bindBuffer(gl.ARRAY_BUFFER, buffers.stars)
        gl.bufferData(gl.ARRAY_BUFFER, vertices, gl.STATIC_DRAW)
        starCount = count
        map?.triggerRepaint()
      })
      // The sky is still a sky without the catalogue — the procedural field covers for it — so this
      // must not take the layer down with it. It must not be silent either.
      .catch((error) => console.warn(`[starfield] bright star catalogue unavailable: ${error.message}`))
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
        if (data) gl.bufferData(target, data, gl.STATIC_DRAW)
        return b
      }
      buffers = {
        // A full-screen quad in clip space. Four corners is the entire geometry of this layer.
        pos: buffer(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1])),
        index: buffer(gl.ELEMENT_ARRAY_BUFFER, new Uint16Array([0, 1, 2, 2, 1, 3])),
        // Allocated empty so that removal frees the same three buffers whether or not the
        // catalogue ever arrived.
        stars: buffer(gl.ARRAY_BUFFER, null),
      }
      loadSky()
      loadCatalogue(state.catalogueUrl)
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ sky, stars }) => {
        gl.deleteProgram(sky.program)
        gl.deleteProgram(stars.program)
      })
      programs.clear()
      releaseSky()
      if (buffers) {
        gl.deleteBuffer(buffers.pos)
        gl.deleteBuffer(buffers.index)
        gl.deleteBuffer(buffers.stars)
        buffers = null
      }
      map = null
      gl = null
    },

    render(glContext, args) {
      // Stars are procedural as well as catalogued, so the sky draws with or without its texture.
      if (!buffers || state.brightness <= 0) return
      const shaderData = args && args.shaderData
      const projection = args && args.defaultProjectionData
      if (!shaderData || !projection) return   // v4-style arguments: refuse rather than mis-draw

      const canvas = map.getCanvas()
      const aspect = canvas.width / Math.max(1, canvas.height)
      const basis = viewRayBasis(map, maplibregl, args.fov || 0.6435, aspect)
      const halfHeight = Math.tan((args.fov || 0.6435) / 2)
      const frame = skyFrameMatrix(state.date)
      const globeness = projection.projectionTransition

      const { sky, stars } = programFor(shaderData)

      const rayUniforms = ({ uniforms }) => {
        if (uniforms.camera) gl.uniform3f(uniforms.camera, ...basis.origin)
        if (uniforms.forward) gl.uniform3f(uniforms.forward, ...basis.forward)
        if (uniforms.right) gl.uniform3f(uniforms.right, ...basis.rightUnit)
        if (uniforms.up) gl.uniform3f(uniforms.up, ...basis.upUnit)
        if (uniforms.halfExtent) gl.uniform2f(uniforms.halfExtent, halfHeight * aspect, halfHeight)
        if (uniforms.skyFrame) gl.uniformMatrix3fv(uniforms.skyFrame, false, frame)
      }

      // ── the sky ───────────────────────────────────────────────────────────────────────────
      gl.useProgram(sky.program)
      rayUniforms(sky)
      if (sky.uniforms.globeness) gl.uniform1f(sky.uniforms.globeness, globeness)
      if (sky.uniforms.brightness) gl.uniform1f(sky.uniforms.brightness, state.brightness)
      const panorama = currentSky()
      if (sky.uniforms.nebula) gl.uniform1f(sky.uniforms.nebula, panorama ? state.nebula : 0)
      if (sky.uniforms.nebulaContrast) gl.uniform1f(sky.uniforms.nebulaContrast, state.nebulaContrast)
      if (sky.uniforms.starDensity) gl.uniform1f(sky.uniforms.starDensity, state.starDensity)
      if (sky.uniforms.starAmount) gl.uniform1f(sky.uniforms.starAmount, state.starAmount)
      if (sky.uniforms.twinkle) gl.uniform1f(sky.uniforms.twinkle, state.twinkle)
      if (sky.uniforms.time) gl.uniform1f(sky.uniforms.time, state.animate ? performance.now() * 0.001 : 0)
      if (panorama && sky.uniforms.sky) {
        gl.activeTexture(gl.TEXTURE0)
        gl.bindTexture(gl.TEXTURE_2D, panorama.texture)
        gl.uniform1i(sky.uniforms.sky, 0)
      }

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(sky.pos)
      gl.vertexAttribPointer(sky.pos, 2, gl.FLOAT, false, 0, 0)

      // The sky is behind everything: no depth test to fail, and no depth written, so nothing that
      // draws afterwards is ever occluded by it.
      gl.disable(gl.DEPTH_TEST)
      gl.depthMask(false)
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, 6, gl.UNSIGNED_SHORT, 0)

      // ── the real stars ────────────────────────────────────────────────────────────────────
      if (starCount > 0 && globeness > 0.5 && state.catalogueAmount > 0) {
        gl.useProgram(stars.program)
        rayUniforms(stars)
        if (stars.uniforms.pixelRatio) gl.uniform1f(stars.uniforms.pixelRatio, (typeof devicePixelRatio === 'number' ? devicePixelRatio : 1))
        if (stars.uniforms.starSize) gl.uniform1f(stars.uniforms.starSize, state.starSize)
        if (stars.uniforms.catalogueAmount) {
          gl.uniform1f(stars.uniforms.catalogueAmount, state.catalogueAmount * state.brightness)
        }
        gl.bindBuffer(gl.ARRAY_BUFFER, buffers.stars)
        gl.enableVertexAttribArray(stars.star)
        gl.vertexAttribPointer(stars.star, 3, gl.FLOAT, false, 24, 0)
        gl.enableVertexAttribArray(stars.colour)
        gl.vertexAttribPointer(stars.colour, 3, gl.FLOAT, false, 24, 12)
        gl.drawArrays(gl.POINTS, 0, starCount)
        // Left enabled, a strided attribute array follows this layer into whatever draws next.
        gl.disableVertexAttribArray(stars.star)
        gl.disableVertexAttribArray(stars.colour)
      }

      gl.enable(gl.DEPTH_TEST)
      gl.depthMask(true)

      if (state.animate && state.twinkle > 0) map.triggerRepaint()
    },

    setOptions(next = {}) {
      const previousUrl = state.textureUrl
      const previousCatalogue = state.catalogueUrl
      state = { ...state, ...next }
      if (next.textureUrl !== undefined && next.textureUrl !== previousUrl) {
        // Let go of the old shares before taking new ones, or a style that swaps panoramas twice
        // leaves the first one on the card with nobody left to free it.
        releaseSky()
        loadSky()
      }
      if (next.catalogueUrl !== undefined && next.catalogueUrl !== previousCatalogue) {
        starCount = 0
        loadCatalogue(next.catalogueUrl)
      }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    get hasSky () { return currentSky() !== null },
    get starCount () { return starCount },
    /** Which rung of the ladder this device was given, and why. */
    get skyTier () { return tier ? { ...tier, reason: tierReason } : null },
  }
}

export const STARFIELD_LAYER_ID = LAYER_ID

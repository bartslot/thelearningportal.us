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
import {
  buildSphereMesh, buildProgram, EARTH_RADIUS_M,
  EQUIRECT_GLSL, NOISE_GLSL, SHELL_PROJECT_GLSL, TERMINATOR_GLSL,
} from './planet-mesh.js'

const LAYER_ID = 'tm-clouds'

/**
 * How the deck should be drawn at the camera's current height.
 *
 * A cloud layer built for the globe falls apart on approach, and it does it quietly: the field is
 * 2048x1024 — about 19.5 km per pixel — so it is magnified 16x by z7 and 256x by z11, and long
 * before that it has stopped being weather and become a smooth grey wash sitting on top of sharp
 * ground. Nothing errors. It just looks wrong, and worse the closer you get.
 *
 * Three numbers come out of this, all driven by how much ground one screen pixel covers:
 *
 *  - `frequency` holds the procedural cells at a roughly constant size ON SCREEN, so there is
 *    always structure at the scale being looked at rather than one cell filling the window.
 *  - `amount` hands the noise more of the work as the field runs out of pixels, until at close
 *    range the field only says where cloud is and the noise says what it looks like.
 *  - `fade` retires the deck entirely once the camera is below it, because at that height there
 *    is no honest way to draw it — you would be under the clouds, not looking down on them.
 *
 * @param {number} zoom  MapLibre zoom
 * @param {number} lat   latitude at the centre, for the mercator scale
 */
export const deckDetailFor = (zoom, lat) => {
  const metresPerPixel = 156543.03392 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom)

  // The real cloud field's own resolution, at the equator.
  const FIELD_METRES_PER_PIXEL = 19543
  // 1 while one screen pixel still covers a whole field pixel; falls away as it is magnified.
  const fieldQuality = Math.min(1, metresPerPixel / FIELD_METRES_PER_PIXEL)

  // Cloud cells roughly this many screen pixels across — big enough to read as cloud, small
  // enough that several fit on screen.
  const CELL_PIXELS = 110
  const wanted = EARTH_RADIUS_M / Math.max(1, metresPerPixel * CELL_PIXELS)

  return {
    // The floor is the globe-view value this layer was tuned at; the ceiling keeps the noise from
    // aliasing into static once the cells approach a pixel.
    frequency: Math.min(2600, Math.max(9, wanted)),
    amount: 0.14 + (1 - fieldQuality) * 0.42,
    fade: 1 - smoothstep(8.5, 11.5, zoom),
  }
}

const smoothstep = (edge0, edge1, x) => {
  const t = Math.min(1, Math.max(0, (x - edge0) / (edge1 - edge0)))
  return t * t * (3 - 2 * t)
}
// Deck height. Real cloud tops out around 12 km; on a 6371 km globe that is invisible, so this is
// exaggerated until the shell parallaxes against the ground as the camera moves.
const ALTITUDE_M = 90000

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
  // Globe wants metres above the sphere; mercator wants z in mercator units. Same deck, two rulers.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`

const fragmentSource = () => `precision highp float;
varying vec3 v_sphere;
uniform float u_time;
uniform float u_opacity;
uniform float u_drift;
uniform vec3 u_sun;           // direction TO the sun
uniform sampler2D u_field;    // real cloud cover, equirectangular
uniform float u_fieldAmount;  // 0 = procedural weather, 1 = the real field
uniform sampler2D u_wind;     // real wind field: R = eastward, G = southward, 0.5 = still
uniform float u_windAmount;   // 0 = the field sits still, 1 = fully advected
uniform float u_windScale;    // how far the flow carries per cycle, in UV
uniform float u_windRate;     // cycles per second
uniform float u_detailFreq;   // noise cycles per unit sphere; rises as the camera descends
uniform float u_detailAmount; // how much of the structure the noise carries, vs the real field
uniform float u_deckFade;     // 0 once the camera is below the deck and clouds stop making sense
${NOISE_GLSL}
${EQUIRECT_GLSL}
${TERMINATOR_GLSL}

// ── Wind advection ────────────────────────────────────────────────────────────────────────────
// Sliding the whole texture makes clouds drift like a painted backdrop; real weather TURNS. The
// field here is a genuine GFS wind grid, so the rotation comes from actual circulation — the low
// off Newfoundland spins because it was really spinning.
//
// The trick is the double sample. Offsetting UVs by flow*time smears without limit — after a few
// seconds every cloud is a streak. So the flow is sampled at two clocks half a cycle apart and
// cross-faded, and each one resets before it has time to smear. Standard flow-map practice.
vec2 windAt(vec2 uv, float lat) {
  vec2 wind = texture2D(u_wind, uv).rg * 2.0 - 1.0;
  // Degrees of longitude per metre grow toward the poles; without this correction the flow slows
  // to a crawl at high latitude and the polar cells stop turning.
  wind.x /= max(cos(lat), 0.25);
  return wind * u_windScale;
}

float advectedField(vec2 uv, float lat) {
  if (u_windAmount <= 0.0) return texture2D(u_field, uv).r;
  vec2 flow = windAt(uv, lat);
  float cycle = u_time * u_windRate;
  float phase1 = fract(cycle);
  float phase2 = fract(cycle + 0.5);
  float blend = abs(1.0 - 2.0 * phase1);       // triangle wave: 1 at the resets, 0 mid-cycle
  float a = texture2D(u_field, uv - flow * phase1).r;
  float b = texture2D(u_field, uv - flow * phase2).r;
  return mix(mix(a, b, blend), texture2D(u_field, uv).r, 1.0 - u_windAmount);
}

void main() {
  vec3 p = normalize(v_sphere);

  // ── Detail ────────────────────────────────────────────────────────────────────────────────
  // Sampled on the SPHERE, so cloud cells stay the same size at Iceland as at the equator and the
  // field wraps seamlessly across ±180°.
  //
  // The frequency is NOT fixed. A fixed one is what makes the deck fall apart on approach: the
  // real field is 2048x1024, about 19.5 km per pixel, so it is already magnified 16x by z7 and
  // 256x by z11. Past that it is a smooth gradient, the noise on top of it is far too coarse to
  // read, and the whole deck becomes a milky wash over sharp ground. u_detailFreq rises as the
  // camera descends to hold cloud cells at a roughly constant size ON SCREEN, so there is always
  // structure to look at.
  //
  // The warp is what makes it look like weather rather than blobs: displacing the sample point by
  // a lower-frequency noise stretches the cells into filaments and hooks, which is the shape air
  // actually leaves. One extra fbm buys most of the difference.
  float warp = fbm(p * u_detailFreq * 0.35 + vec3(0.0, 0.0, u_time * 0.003));
  float detail = fbm(p * u_detailFreq
                     + vec3(warp, warp * 0.7, -warp * 0.4) * 0.6
                     - vec3(0.0, u_time * 0.01, 0.0));

  float coverage;
  if (u_fieldAmount > 0.0) {
    // Real weather. The texture decides WHERE cloud is; the noise decides what its edges look
    // like — and as the field runs out of resolution, u_detailAmount hands the noise more of the
    // job, until at close range it is carrying the structure on its own.
    float field = advectedField(equirectUV(p, u_drift), asin(clamp(p.y, -1.0, 1.0)));
    coverage = smoothstep(0.16, 0.62, field + u_detailAmount * (detail - 0.5));
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

  // ── Lighting ──────────────────────────────────────────────────────────────────────────────
  // A cloud emits nothing. It is only ever as bright as what is falling on it, so on the night
  // side it has to go dark along with the ground — a white deck glowing over a black planet is the
  // tell of clouds pasted on as decoration rather than lit as part of the scene.
  float sunAngle = dot(p, normalize(u_sun));
  float day = daylightFraction(sunAngle);

  // The deck's own modelling: a lit face and a grey shaded face, like the real thing.
  vec3 base = mix(vec3(0.62, 0.66, 0.72), vec3(1.0), 0.35 + 0.65 * max(sunAngle, 0.0));

  // At the terminator the light reaching them has crossed the most air and lost its blue, so the
  // tops go orange while the ground below is already dark. It is the best thing clouds do.
  float twilight = 1.0 - abs(day * 2.0 - 1.0);
  base = mix(base, base * vec3(1.30, 0.74, 0.44), twilight * twilight * 0.85);

  // Not quite zero at night: a deck at pure black reads as a hole punched in the planet rather
  // than as cloud. This is roughly what starlight and airglow leave on a real night-side deck.
  const float NIGHT_FLOOR = 0.05;
  base *= NIGHT_FLOOR + (1.0 - NIGHT_FLOOR) * day;

  // Below the deck there is no deck. A cloud shell drawn over a street is not weather, it is a
  // fog filter, and no amount of detail rescues it — so it fades out rather than being faked.
  float alpha = coverage * u_opacity * u_deckFade;
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
  // Wind advection: a real GFS field carrying the clouds along actual circulation. Off without a
  // texture, so a missing asset costs nothing but motion.
  windUrl = null, windAmount = 1, windScale = 0.06, windRate = 0.05,
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  let buffers = null
  // One compiled program per projection variant: the prelude is different under globe and
  // mercator, and the variant flips mid-flight when the map crosses the globe/mercator threshold.
  const programs = new Map()
  let state = { opacity, animate, fieldUrl, driftRate, sun, windUrl, windAmount, windScale, windRate }
  let fieldTexture = null
  let fieldReady = false
  let windTexture = null
  let windReady = false

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
        wind: gl.getUniformLocation(program, 'u_wind'),
        windAmount: gl.getUniformLocation(program, 'u_windAmount'),
        windScale: gl.getUniformLocation(program, 'u_windScale'),
        windRate: gl.getUniformLocation(program, 'u_windRate'),
        detailFreq: gl.getUniformLocation(program, 'u_detailFreq'),
        detailAmount: gl.getUniformLocation(program, 'u_detailAmount'),
        deckFade: gl.getUniformLocation(program, 'u_deckFade'),
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
  const loadEquirect = (url, onReady) => {
    const image = new Image()
    image.crossOrigin = 'anonymous'
    image.onload = () => {
      if (!gl) return
      const texture = gl.createTexture()
      gl.bindTexture(gl.TEXTURE_2D, texture)
      gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false)
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
      gl.generateMipmap(gl.TEXTURE_2D)
      onReady(texture)
      map?.triggerRepaint()
    }
    // Decorative: a failed load leaves the procedural weather in place rather than an empty sky.
    image.src = url
  }

  const loadField = (url) => loadEquirect(url, (t) => { fieldTexture = t; fieldReady = true })
  const loadWind = (url) => loadEquirect(url, (t) => { windTexture = t; windReady = true })

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
      if (state.windUrl) loadWind(state.windUrl)
    },

    // Without this every style reload leaks a program, a texture and three buffers.
    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      if (fieldTexture) { gl.deleteTexture(fieldTexture); fieldTexture = null; fieldReady = false }
      if (windTexture) { gl.deleteTexture(windTexture); windTexture = null; windReady = false }
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
      if (uniforms.windAmount) gl.uniform1f(uniforms.windAmount, windReady ? state.windAmount : 0)
      if (uniforms.windScale) gl.uniform1f(uniforms.windScale, state.windScale)
      if (uniforms.windRate) gl.uniform1f(uniforms.windRate, state.animate ? state.windRate : 0)

      const detail = deckDetailFor(map.getZoom(), map.getCenter().lat)
      if (uniforms.detailFreq) gl.uniform1f(uniforms.detailFreq, detail.frequency)
      if (uniforms.detailAmount) gl.uniform1f(uniforms.detailAmount, detail.amount)
      if (uniforms.deckFade) gl.uniform1f(uniforms.deckFade, detail.fade)
      if (windReady && uniforms.wind) {
        gl.activeTexture(gl.TEXTURE1)
        gl.bindTexture(gl.TEXTURE_2D, windTexture)
        gl.uniform1i(uniforms.wind, 1)
      }
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

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
  buildSphereMesh, buildProgram, cameraAltitudeMetres, EARTH_RADIUS_M,
  EQUIRECT_GLSL, NOISE_GLSL, SHELL_PROJECT_GLSL, TERMINATOR_GLSL,
} from './planet-mesh.js'
import { CLOUD_ALTITUDE_M, CLOUD_FIELD_GLSL, CLOUD_FIELD_UNIFORMS, setCloudFieldUniforms } from './cloud-field.js'
import { acquireEquirectTexture } from './equirect-texture.js'

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
 *  - `fade` retires the deck BEFORE the camera reaches it, because at that height there is no
 *    honest way to draw it — you would be under the clouds, not looking down on them.
 *
 * THE FADE IS IN ALTITUDE, NOT IN ZOOM, and that distinction was a real bug. It used to be
 * `1 - smoothstep(8.5, 11.5, zoom)`, which sounds equivalent and is not: the thing being avoided is
 * the camera reaching a shell at a FIXED HEIGHT, and zoom is only loosely coupled to height because
 * the mercator scale carries cos(lat). Measured, the camera crossed the 90 km deck at z10.01 with
 * the deck still 50% opaque — so you flew through a half-solid shell that filled the view and
 * clipped against the near plane. Reported as "the clouds are glitchy when flying through them",
 * which is precisely what it was.
 *
 * Worse, the crossing moved a whole zoom level with latitude: z10.03 at the equator, z9.03 at 60°,
 * where the deck was 92% opaque. No pair of zoom constants can be right everywhere. An altitude
 * ratio is right everywhere for free.
 *
 * @param {number} zoom       MapLibre zoom
 * @param {number} lat        latitude at the centre, for the mercator scale
 * @param {number} altitudeM  camera height above the surface; see cameraAltitudeMetres
 */
export const deckDetailFor = (zoom, lat, altitudeM) => {
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
    /**
     * Texture on top of the real field, NOT a replacement for it.
     *
     * This used to ramp to 0.56 as the field was magnified — so past about z5 more than half the
     * deck was invented, and the wind advection curled the invention into swirls. Reported as
     * "trippy and fake", and that is exactly what it was: procedural noise standing in for weather
     * nobody has data for, at a scale where the eye can see it is not weather.
     *
     * The deck stays — voyages are sailed at this zoom and clouds belong there — but it stops
     * pretending to a detail it does not have. Softer and honest beats busy and invented.
     */
    amount: 0.14 + (1 - fieldQuality) * 0.10,
    // Gone by 1.35 deck heights, full again by 2.75. Both ends are strictly ABOVE the deck, so the
    // shell has stopped being drawn before the camera can reach it — a deck at even 0.02 opacity
    // still writes and still clips, so "nearly gone" is not gone.
    //
    // No altitude means no deck. A NaN here would reach a uniform and draw who-knows-what, and of
    // the two ways to be wrong, "the clouds are missing" is loud and safe while "the clouds are
    // always drawn" is silent and is the exact bug this replaced.
    fade: Number.isFinite(altitudeM) ? smoothstep(1.35, 2.75, altitudeM / CLOUD_ALTITUDE_M) : 0,
  }
}

const smoothstep = (edge0, edge1, x) => {
  const t = Math.min(1, Math.max(0, (x - edge0) / (edge1 - edge0)))
  return t * t * (3 - 2 * t)
}

// GLSL ES 3.00 — required of every tile-pyramid consumer, and the deck shares CLOUD_FIELD_GLSL
// with the ground, so the two convert together or neither does. See daylight.js for the details.
const vertexSource = (shaderData) => `#version 300 es
${shaderData.define}
${shaderData.vertexShaderPrelude}
in vec2 a_pos;
in vec3 a_sphere;
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
out vec3 v_sphere;
${SHELL_PROJECT_GLSL}
void main() {
  v_sphere = a_sphere;
  // Globe wants metres above the sphere; mercator wants z in mercator units. Same deck, two rulers.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`

const fragmentSource = () => `#version 300 es
precision highp float;
in vec3 v_sphere;
out vec4 fragColour;
uniform float u_opacity;
uniform vec3 u_sun;           // direction TO the sun
uniform float u_detailFreq;   // noise cycles per unit sphere; rises as the camera descends
uniform float u_detailAmount; // how much of the structure the noise carries, vs the real field
uniform float u_deckFade;     // 0 once the camera is below the deck and clouds stop making sense
${NOISE_GLSL}
${EQUIRECT_GLSL}
${TERMINATOR_GLSL}
// The field, the wind and the clock the ground's cloud shadows read from the same source.
${CLOUD_FIELD_GLSL}

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

  // The deck's own modelling: a lit face and a shaded face, like the real thing — but cloud is
  // WHITE, and its shaded face is white in shadow, not grey paint. The old pair (0.62,0.66,0.72
  // lifted from 0.35) left everything away from the subsolar point reading as blue-grey smoke,
  // which at high latitude is most of what is on screen. Bright, barely-tinted shadow instead.
  vec3 base = mix(vec3(0.86, 0.88, 0.92), vec3(1.0), 0.55 + 0.45 * max(sunAngle, 0.0));

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
  fragColour = vec4(base * alpha, alpha);
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
  // Shared with daylight.js, which shades the ground with the shadow of these same clouds.
  let field = null
  let wind = null

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
        opacity: gl.getUniformLocation(program, 'u_opacity'),
        sun: gl.getUniformLocation(program, 'u_sun'),
        // time, drift, field, fieldAmount, wind, windAmount, windScale, windRate
        ...Object.fromEntries(CLOUD_FIELD_UNIFORMS.map((name) =>
          [name, gl.getUniformLocation(program, `u_${name}`)])),
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

  // Decorative: a failed load leaves the procedural weather in place rather than an empty sky.
  const repaint = () => map?.triggerRepaint()

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
      if (state.fieldUrl) field = acquireEquirectTexture(gl, state.fieldUrl, repaint)
      if (state.windUrl) wind = acquireEquirectTexture(gl, state.windUrl, repaint)
    },

    // Without this every style reload leaks a program, a texture and three buffers. The fields are
    // released rather than deleted — daylight.js may still be casting their shadows.
    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      field?.release(); field = null
      wind?.release(); wind = null
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
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, CLOUD_ALTITUDE_M)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([0, lat], CLOUD_ALTITUDE_M).z)
      }
      if (uniforms.opacity) gl.uniform1f(uniforms.opacity, state.opacity)
      if (uniforms.sun) gl.uniform3f(uniforms.sun, ...state.sun)
      // The clock, the drift and both textures — set the same way the ground sets them, so the
      // shadows land under the clouds that cast them.
      setCloudFieldUniforms(gl, uniforms, state, { seconds: performance.now() * 0.001, field, wind }, 0, 1)

      const detail = deckDetailFor(map.getZoom(), map.getCenter().lat, cameraAltitudeMetres(map, maplibregl))
      if (uniforms.detailFreq) gl.uniform1f(uniforms.detailFreq, detail.frequency)
      if (uniforms.detailAmount) gl.uniform1f(uniforms.detailAmount, detail.amount)
      if (uniforms.deckFade) gl.uniform1f(uniforms.deckFade, detail.fade)

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
      if (gl && next.fieldUrl !== undefined && next.fieldUrl !== previousUrl) {
        field?.release()
        field = next.fieldUrl ? acquireEquirectTexture(gl, next.fieldUrl, repaint) : null
      }
      if (map) map.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    /** True once a real cloud field is uploaded — the deck is procedural until then. */
    get hasField () { return !!field?.ready },
  }
}

export const CLOUD_LAYER_ID = LAYER_ID

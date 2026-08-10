/**
 * sun-disc.js — the sun itself, finally drawn.
 *
 * Every layer on this globe is lit by `sunDirection()`, and until now not one of them drew the
 * thing doing the lighting. A planet with a terminator, a lit cloud deck and a blue limb, and no
 * light source anywhere in the sky.
 *
 * WHY THERE IS NO POST-PROCESSING PASS HERE. The reference for this is Sangil Lee's selective
 * bloom: put the sun on its own three.js layer, render it through a second EffectComposer with an
 * UnrealBloomPass, blacken everything else, and add the two buffers. None of that exists here. This
 * globe is MapLibre custom layers with hand-written GLSL drawing straight into MapLibre's
 * framebuffer — no scene graph, no composer, no layer system, nothing to hook a post pass onto.
 *
 * It turns out not to matter, and the reason is worth stating because it is the whole design:
 *
 *   BLOOM OF A POINT SOURCE IS A RADIAL FALLOFF. A gaussian blur of a small bright disc is that
 *   disc convolved with a radially symmetric kernel, which for anything much smaller than the
 *   kernel is just the kernel — centred on the disc. The sun is half a degree across, about eleven
 *   pixels on a 800px canvas at the fields of view this map uses. Blurring eleven pixels into a
 *   hundred-and-fifty-pixel halo and evaluating that halo directly are the same picture, and the
 *   second one costs one quad instead of two framebuffers, four passes and an FBO lifecycle that
 *   has to survive every MapLibre style reload.
 *
 * So the falloff is written out rather than blurred into existence. It is a three-term fit to the
 * shape real glare has — a tight flare hugging the limb, a broad aureole, and a wide faint skirt —
 * because a single gaussian gives the soft airbrushed blob that reads as a bug light, not a star.
 *
 * WHAT A TRUE POST PASS WOULD STILL BUY, if it is ever wanted: bloom on everything else that is
 * bright — the specular glint off the ocean, city lights, the sunlit limb — not just on the sun.
 * That is a different feature, and it is the one that needs the framebuffers.
 *
 * OCCLUSION IS COMPUTED, NOT TESTED. The glare is composited over the planet on purpose, because
 * that is what glare does: at sunrise over the limb the halo spills across the earth's edge. So the
 * depth buffer cannot be the thing that hides the sun — it would clip the halo flat against the
 * horizon. Instead the earth's silhouette is solved for directly, which is exact at any altitude
 * and gives a partially-risen sun the correct bitten-off shape for free.
 */

import maplibregl from 'maplibre-gl'
import { buildProgram, cameraInPlanetSpace, planetSpacePosition, SPHERE_SPAN_GLSL } from './planet-mesh.js'
import { sunAngularRadius, sunDirection, sunDistance } from './sun.js'

const LAYER_ID = 'tm-sun'
const EARTH_RADIUS_M = 6371008.8

/**
 * How much wider than the sun the glow is drawn, in solar radii. Fourteen puts the quad's edge
 * about 3.7° out — a hand's width at arm's length, which is roughly where a real aureole stops
 * being distinguishable from sky.
 */
const DEFAULT_HALO_SCALE = 18

/**
 * How much of a disc of angular radius `discRadius` is still visible with a disc of angular radius
 * `blockerRadius` in front of it, their centres `separation` apart. All angles in radians.
 *
 * The standard circular-lens overlap, which matters because the interesting case is exactly the
 * halfway one: a sun sliding out from behind the planet has to fade, not snap. Snapping is what a
 * plain `is the centre hidden` test gives, and on a slowly turning globe it reads as a flicker.
 *
 * @returns {number} 1 when nothing is in the way, 0 when the disc is completely covered
 */
export const visibleDiscFraction = (separation, discRadius, blockerRadius) => {
  const d = Math.abs(separation)
  const r = discRadius
  const R = blockerRadius
  if (r <= 0) return 0
  if (d >= r + R) return 1                       // clear of each other
  if (d <= R - r) return 0                       // wholly behind
  if (d <= r - R) return 1 - (R * R) / (r * r)   // blocker wholly inside: a ring of sun, annular

  const lens =
    r * r * Math.acos(clamp((d * d + r * r - R * R) / (2 * d * r), -1, 1)) +
    R * R * Math.acos(clamp((d * d + R * R - r * r) / (2 * d * R), -1, 1)) -
    0.5 * Math.sqrt(Math.max(0, (-d + r + R) * (d + r - R) * (d - r + R) * (d + r + R)))

  return clamp(1 - lens / (Math.PI * r * r), 0, 1)
}

/**
 * Limb darkening, the standard quadratic law with visible-light coefficients.
 *
 *   I(mu)/I(0) = 1 - u1*(1 - mu) - u2*(1 - mu)^2
 *
 * `mu` is the cosine of the angle between the line of sight and the local surface normal, so on a
 * disc of normalised radius rho it is simply sqrt(1 - rho^2) — the sphere does the geometry.
 *
 * These two numbers are the physics, and they are defined ONCE and templated into the shader,
 * because a JS copy for tests and a GLSL copy for rendering is exactly the pair that drifts: the
 * tests keep passing against a curve nothing draws any more.
 */
export const LIMB_DARKENING = { u1: 0.93, u2: -0.23 }

/** The same law in JS. @param {number} rho 0 at the disc's centre, 1 at its limb */
export const limbDarkening = (rho) => {
  const mu = Math.sqrt(Math.max(0, 1 - rho * rho))
  const t = 1 - mu
  return Math.max(0, 1 - LIMB_DARKENING.u1 * t - LIMB_DARKENING.u2 * t * t)
}

/** A float GLSL will accept, negatives parenthesised so `a - -0.23` never has to be parsed. */
const glslFloat = (value) => (value < 0 ? `(${value.toFixed(4)})` : value.toFixed(4))

const LIMB_DARKENING_GLSL = /* glsl */`
  float limbDarkening(float rho) {
    float mu = sqrt(max(1.0 - rho * rho, 0.0));
    float t = 1.0 - mu;
    return max(1.0 - ${glslFloat(LIMB_DARKENING.u1)} * t - ${glslFloat(LIMB_DARKENING.u2)} * t * t, 0.0);
  }
`

// A billboard the size of the whole GLOW, not of the disc. The disc is a fraction of it, so one
// quad carries both and the halo needs no second draw.
const CORNERS = new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1])
const CORNER_INDICES = new Uint16Array([0, 1, 2, 1, 3, 2])

const vertexSource = (shaderData) => `${shaderData.vertexShaderPrelude}
${shaderData.define}
attribute vec2 a_corner;
uniform vec2 a_centre;              // where the sun lies, in mercator 0..1
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
uniform vec2 u_size;                // half-size of the glow quad in clip units, x and y
varying vec2 v_corner;
void main() {
  v_corner = a_corner;
  #ifdef GLOBE
    vec4 centre = projectTileFor3D(a_centre, a_elevation_globe);
  #else
    vec4 centre = projectTileFor3D(a_centre, a_elevation_mercator);
  #endif
  // Scaling by w cancels the perspective divide, so the glow keeps its angular size.
  gl_Position = centre + vec4(a_corner * u_size * centre.w, 0.0, 0.0);
}`

const fragmentSource = () => `precision highp float;
varying vec2 v_corner;
uniform vec3 u_camera;              // planet space, earth as a unit sphere at the origin
uniform vec3 u_forward;             // from the camera toward the sun
uniform vec3 u_right;               // the billboard's basis, same space
uniform vec3 u_up;
uniform float u_glow_angle;         // angular radius of the whole quad, radians
uniform float u_disc_fraction;      // where the sun's limb falls, as a fraction of the quad
uniform float u_disc_gain;          // exposure: how far above clipping the disc's centre sits
uniform float u_visible;            // how much of the disc the planet leaves showing, 0..1
uniform float u_brightness;
uniform float u_halo_strength;
uniform vec3 u_core_colour;
uniform vec3 u_halo_colour;
${SPHERE_SPAN_GLSL}
${LIMB_DARKENING_GLSL}

void main() {
  float r = length(v_corner);
  if (r > 1.0) discard;                          // the quad's corners are not the glow

  // ── The disc ───────────────────────────────────────────────────────────────────────────────
  // Anti-aliased over a twelfth of its own radius. At eleven pixels across a hard cut is a
  // visible staircase, and one wide enough to hide it turns the sun into a smudge.
  float disc = 1.0 - smoothstep(u_disc_fraction * 0.94, u_disc_fraction * 1.06, r);

  // LIMB DARKENING. The sun is not a flat white circle: its edge is about 30% as bright as its
  // centre in visible light, because looking at the limb you see only the thin, cool top of the
  // photosphere while at the centre you see down into the hotter gas below. It is the reason a
  // filtered photograph of the sun has a soft grey rim, and at twenty pixels across it reads.
  //
  //   I(mu)/I(0) = 1 - u1*(1-mu) - u2*(1-mu)^2,  mu = cos(angle between sight line and normal)
  //
  // with the standard visible-light coefficients. On a disc of normalised radius rho, mu is just
  // sqrt(1 - rho^2), because the sphere is doing the geometry for us.
  //
  // The GAIN is what makes it look right rather than dirty. Radiance is computed first and clipped
  // afterwards, exactly as an over-exposed photograph does it, so the disc saturates to white
  // across most of its face and only the outer quarter of the radius visibly falls away. Turn the
  // gain up and the darkening disappears into the clip; turn it down and the sun goes grey. The
  // physics is in the law, not in the gain.
  float rho = clamp(r / max(u_disc_fraction, 1e-6), 0.0, 1.0);
  float limb = limbDarkening(rho);
  disc *= limb * u_disc_gain;

  // Bitten off by the planet, per pixel. The view ray for this fragment, then the oldest test
  // there is: does it meet the earth before it gets anywhere.
  if (disc > 0.0) {
    vec3 ray = normalize(u_forward + (u_right * v_corner.x + u_up * v_corner.y) * u_glow_angle);
    vec2 ground = sphereSpan(u_camera, ray, 1.0);
    if (ground.x <= ground.y && ground.y > 0.0) disc = 0.0;
  }

  // ── The glow ───────────────────────────────────────────────────────────────────────────────
  // In solar radii, so the shape is anchored to the limb rather than to the quad: rs = 1 is the
  // edge of the disc. Three terms, because glare has three scales and one gaussian has one.
  float rs = r / max(u_disc_fraction, 1e-6);
  float beyond = max(rs - 1.0, 0.0);
  // The weights and rates matter more than the choice of terms. Flatten the middle one and the sun
  // stops being a star and becomes a cotton-wool ball with a dot in it: half the core's brightness
  // was still there a solar radius out, which is a fat bright ring, not glare.
  float glow = 0.58 * exp(-beyond * 3.4)             // the flare clinging to the limb
             + 0.28 * exp(-beyond * 1.35)            // the aureole
             + 0.08 / (1.0 + 0.22 * rs * rs);        // the wide skirt, which is what sells the scale
  // The quad has to end somewhere, and against near-black space the eye finds that edge instantly:
  // a taper over the last fifth of the radius leaves a faint but perfectly visible disc outline
  // around the sun. Fading from a THIRD of the way out hides the boundary inside the falloff, at
  // the cost of thinning a part of the skirt that is already under a hundredth of the core.
  glow *= smoothstep(1.0, 0.32, r);
  // The halo is glare, so it spills over the planet's edge rather than stopping at it — but it
  // dims with however much of the sun is actually still showing.
  glow *= u_visible;

  float intensity = clamp(disc + glow * u_halo_strength, 0.0, 1.0) * u_brightness;
  if (intensity < 0.003) discard;

  // White at the core, amber everywhere else.
  //
  // The disc is far too bright for any eye or sensor to read as anything but white, and only the
  // wings keep a colour — so the question is how fast the white gives way, and the honest answer is
  // "almost at once". Blending on the glow's own value drags white out through the bright part of
  // the aureole and leaves the halo nearly neutral; a smoothstep with its foot near the limb's
  // brightness confines white to about a solar radius and hands the whole skirt to the amber.
  float toCore = clamp(disc + smoothstep(0.35, 0.95, glow), 0.0, 1.0);
  vec3 colour = mix(u_halo_colour, u_core_colour, toCore);

  // LIMB REDDENING, which is the same fact as the darkening seen in colour: the shallow gas at the
  // limb is cooler, so it is not merely dimmer but redder. Driven by the darkening term rather than
  // given a control of its own, because the two cannot disagree: one temperature gradient makes both.
  // (No backticks in here. This is a template literal, and one would end the shader.)
  colour = mix(colour, colour * vec3(1.0, 0.88, 0.7), (1.0 - limb) * step(rho, 1.0));

  // MapLibre blends custom layers ONE / ONE_MINUS_SRC_ALPHA, so colour goes out premultiplied.
  gl_FragColor = vec4(colour * intensity, intensity);
}`

/**
 * @param {object} opts
 * @param {Date}   [opts.date]           the moment to place the sun at
 * @param {number} [opts.haloScale]      how far the glow reaches, in solar radii
 * @param {number} [opts.haloStrength]   0 leaves a bare disc; 1 is the tuned aureole
 * @param {number} [opts.discGain]       exposure of the disc. 1 shows the full limb-darkening
 *                                       curve and looks grey; higher clips the middle to white and
 *                                       leaves the darkening only at the edge, as a photograph does
 * @param {number} [opts.brightness]     0 switches the layer off entirely, and it then costs nothing
 * @param {number[]} [opts.coreColour]   linear RGB of the disc
 * @param {number[]} [opts.haloColour]   linear RGB of the outer glow
 */
export const createSunLayer = ({
  date = new Date(),
  haloScale = DEFAULT_HALO_SCALE,
  haloStrength = 1,
  // 1.15 puts the clip at 87% of centre brightness, which the law reaches at about half the radius:
  // a white core over the inner half and a visible falloff across the outer half, which is what a
  // filtered photograph of the sun looks like. Measured at 1.45 the darkening survived only in the
  // outer two pixels of a twenty-pixel disc, which is the physics being computed and then thrown
  // away by the exposure.
  discGain = 1.15,
  brightness = 1,
  coreColour = [1, 0.985, 0.95],
  // The theme's own amber (#f59e0b), lifted a little so it stays amber rather than going brown
  // once the falloff has taken most of the intensity out of it.
  haloColour = [1, 0.65, 0.2],
} = {}) => {
  let map = null
  let gl = null
  let buffers = null
  const programs = new Map()
  let state = { date, haloScale, haloStrength, discGain, brightness, coreColour, haloColour }

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)
    const program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), 'tm-sun')
    const entry = {
      program,
      attribs: { corner: gl.getAttribLocation(program, 'a_corner') },
      uniforms: {
        centre: gl.getUniformLocation(program, 'a_centre'),
        elevationGlobe: gl.getUniformLocation(program, 'a_elevation_globe'),
        elevationMercator: gl.getUniformLocation(program, 'a_elevation_mercator'),
        size: gl.getUniformLocation(program, 'u_size'),
        camera: gl.getUniformLocation(program, 'u_camera'),
        forward: gl.getUniformLocation(program, 'u_forward'),
        right: gl.getUniformLocation(program, 'u_right'),
        up: gl.getUniformLocation(program, 'u_up'),
        glowAngle: gl.getUniformLocation(program, 'u_glow_angle'),
        discFraction: gl.getUniformLocation(program, 'u_disc_fraction'),
        discGain: gl.getUniformLocation(program, 'u_disc_gain'),
        visible: gl.getUniformLocation(program, 'u_visible'),
        brightness: gl.getUniformLocation(program, 'u_brightness'),
        haloStrength: gl.getUniformLocation(program, 'u_halo_strength'),
        coreColour: gl.getUniformLocation(program, 'u_core_colour'),
        haloColour: gl.getUniformLocation(program, 'u_halo_colour'),
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
      const corner = gl.createBuffer()
      gl.bindBuffer(gl.ARRAY_BUFFER, corner)
      gl.bufferData(gl.ARRAY_BUFFER, CORNERS, gl.STATIC_DRAW)
      const index = gl.createBuffer()
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, index)
      gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, CORNER_INDICES, gl.STATIC_DRAW)
      buffers = { corner, index }
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      if (buffers) {
        gl.deleteBuffer(buffers.corner)
        gl.deleteBuffer(buffers.index)
        buffers = null
      }
      map = null
      gl = null
    },

    render(glContext, args) {
      if (!buffers || state.brightness <= 0) return
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

      // ── Where to actually put it ────────────────────────────────────────────────────────────
      //
      // NOT at one AU, and the reason is not the one you will read first.
      //
      // The known wall is that past about six earth radii a point's clip-space w goes negative and
      // it is dropped. True, and at 23,000 radii the sun is far past it. But there is a nearer wall
      // that bites long before: THE FAR PLANE. MapLibre sizes farZ to just enclose the globe, and
      // measured at this pose the antipodal ground point sits at exactly z/w = 1.000 — the plane
      // is drawn through the back of the planet. Anything beyond it is clipped, silently, with the
      // draw call issued and not one fragment produced.
      //
      // That is what killed the first version of this layer. It borrowed the moon's rule of
      // `camera + sightLine * (radius + 1.2)`, which at the moon's usual altitude lands about 1.2
      // radii out and squeaks under the plane, but from eight radii out lands at 1.74 radii — an
      // altitude of 4,684 km where the measured z/w is 1.00002. Two parts in a hundred thousand
      // over the line, and the sun vanishes completely.
      //
      // So the distance is not chosen to sit near the planet at all. DISTANCE ALONG THE SIGHT LINE
      // IS UNOBSERVABLE HERE: every point on the ray projects to the same pixel, and the quad's
      // corners are offset in clip space scaled by w, so its screen size is `u_size` exactly, at
      // any depth. The sun is therefore drawn close to the CAMERA — a quarter of the camera's own
      // height above the ground — which puts w at a fixed fraction of the camera distance and
      // therefore comfortably between the near and far planes at every zoom, without the layer
      // having to know where either plane is.
      //
      // Nothing is lost by this. Direction and angular size still come from the real geometry, and
      // the planet still hides the sun, because that is solved below rather than left to the depth
      // buffer.
      const camPos = cameraInPlanetSpace(map, maplibregl)
      const distance = sunDistance(state.date)
      const trueSun = planetSpacePosition(
        ...directionToLngLat(sunDirection(state.date)), distance - EARTH_RADIUS_M,
      )
      const sightLine = normalise([
        trueSun[0] - camPos[0], trueSun[1] - camPos[1], trueSun[2] - camPos[2],
      ])
      const camRadius = Math.hypot(...camPos)
      // A floor for the pathological case of a camera on the ground, which would otherwise put the
      // quad behind the near plane. Globe projection has handed over to mercator long before this.
      const renderDistance = Math.max(0.25 * (camRadius - 1), 0.002)
      const drawAt = [
        camPos[0] + sightLine[0] * renderDistance,
        camPos[1] + sightLine[1] * renderDistance,
        camPos[2] + sightLine[2] * renderDistance,
      ]
      const drawRadius = Math.hypot(...drawAt)
      const drawLat = Math.asin(drawAt[1] / drawRadius) * 180 / Math.PI
      const drawLng = Math.atan2(drawAt[2], drawAt[0]) * 180 / Math.PI
      const altitude = (drawRadius - 1) * EARTH_RADIUS_M

      const mercator = maplibregl.MercatorCoordinate.fromLngLat([drawLng, drawLat], 0)
      if (uniforms.centre) gl.uniform2f(uniforms.centre, mercator.x, mercator.y)
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, altitude)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([drawLng, drawLat], altitude).z)
      }

      // ── How big ────────────────────────────────────────────────────────────────────────────
      // From the TRUE distance, never the draw distance. Conflating the two is the mistake this
      // whole layer is arranged to avoid: the sun would balloon to fill the sky and look intended.
      const discRadius = sunAngularRadius(state.date)
      const glowAngle = discRadius * state.haloScale
      const halfFov = (args.fov || 0.6435) / 2
      const canvas = map.getCanvas()
      const halfHeight = Math.tan(glowAngle) / Math.tan(halfFov)
      if (uniforms.size) {
        gl.uniform2f(uniforms.size, halfHeight * (canvas.height / canvas.width), halfHeight)
      }
      if (uniforms.glowAngle) gl.uniform1f(uniforms.glowAngle, glowAngle)
      if (uniforms.discFraction) gl.uniform1f(uniforms.discFraction, 1 / state.haloScale)
      if (uniforms.discGain) gl.uniform1f(uniforms.discGain, state.discGain)

      // ── How much of it the planet leaves ───────────────────────────────────────────────────
      // Two discs in the sky: the sun, and the earth as seen from the camera. Their overlap is the
      // eclipse, and it is exact — no depth buffer, no resolution limit, correct at any altitude.
      const towardCentre = [-camPos[0] / camRadius, -camPos[1] / camRadius, -camPos[2] / camRadius]
      const separation = Math.acos(clamp(dot(towardCentre, sightLine), -1, 1))
      const earthRadius = Math.asin(clamp(1 / camRadius, -1, 1))
      const visible = visibleDiscFraction(separation, discRadius, earthRadius)

      // The billboard's basis, in the same planet space the occlusion test runs in.
      const forward = sightLine
      const right = normalise(cross([0, 1, 0], forward))
      const up = cross(forward, right)
      if (uniforms.forward) gl.uniform3f(uniforms.forward, ...forward)
      if (uniforms.right) gl.uniform3f(uniforms.right, ...right)
      if (uniforms.up) gl.uniform3f(uniforms.up, ...up)
      if (uniforms.camera) gl.uniform3f(uniforms.camera, ...camPos)
      if (uniforms.visible) gl.uniform1f(uniforms.visible, visible)
      if (uniforms.brightness) gl.uniform1f(uniforms.brightness, state.brightness)
      if (uniforms.haloStrength) gl.uniform1f(uniforms.haloStrength, state.haloStrength)
      if (uniforms.coreColour) gl.uniform3f(uniforms.coreColour, ...state.coreColour)
      if (uniforms.haloColour) gl.uniform3f(uniforms.haloColour, ...state.haloColour)

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.corner)
      gl.enableVertexAttribArray(attribs.corner)
      gl.vertexAttribPointer(attribs.corner, 2, gl.FLOAT, false, 0, 0)

      // No depth test at all. The glare belongs over everything — that is what makes a sunrise over
      // the limb read as a sunrise — and the disc's own occlusion is solved above, exactly.
      gl.disable(gl.DEPTH_TEST)
      gl.depthMask(false)
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, CORNER_INDICES.length, gl.UNSIGNED_SHORT, 0)
      gl.depthMask(true)
    },

    /** Live controls — chiefly the date, which a lesson moves as its story moves. */
    setOptions(next = {}) {
      state = { ...state, ...next }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
  }
}

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))
const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2]
const normalise = (v) => {
  const length = Math.hypot(...v) || 1
  return [v[0] / length, v[1] / length, v[2] / length]
}
const cross = (a, b) => [
  a[1] * b[2] - a[2] * b[1],
  a[2] * b[0] - a[0] * b[2],
  a[0] * b[1] - a[1] * b[0],
]
/** The inverse of `planetSpacePosition`'s angles, so a direction can be re-expressed as a place. */
const directionToLngLat = ([x, y, z]) => [
  Math.atan2(z, x) * 180 / Math.PI,
  Math.asin(clamp(y, -1, 1)) * 180 / Math.PI,
]

export const SUN_LAYER_ID = LAYER_ID

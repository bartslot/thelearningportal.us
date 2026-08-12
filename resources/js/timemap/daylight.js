/**
 * daylight.js — day and night on the globe, as a MapLibre custom layer.
 *
 * Satellite basemaps are a lie about light: Blue Marble and Sentinel-2 are composites of cloud-free
 * DAYLIGHT passes, so every part of the earth is lit at once and the planet has no terminator. It
 * reads as a lamp rather than a world. This layer puts the night back.
 *
 * Everything here is driven by one sun vector from sun.js:
 *
 *  - THE TERMINATOR is not a line. The sun's disc is half a degree wide and the air scatters light
 *    well past the geometric edge, so the day/night boundary is a soft band a few hundred
 *    kilometres across — civil, nautical and astronomical twilight, in order. Drawing it as a hard
 *    edge is the tell-tale of a cheap day/night globe.
 *  - TWILIGHT IS WARM. The last light through the low atmosphere has lost its blue, which is why
 *    the band running down the terminator glows orange rather than simply darkening.
 *  - MOUNTAINS CATCH THE LOW SUN. See "Relief" below — this is the one that turns a lit ball into
 *    a lit planet.
 *  - CLOUDS FALL ON THE GROUND. The deck in clouds.js casts its shadow here, from the same field.
 *  - CITY LIGHTS, and they are OFF by default. Not because they look wrong — they look very good —
 *    but because this map is usually showing a date. Electric light on the night side of a globe
 *    tracking a 1596 voyage is an anachronism a few hundred years wide, and a bright one. Turn
 *    `lightsAmount` up for a modern-era scene and they fade in across the same twilight band.
 *
 * Nothing here is a filter over the map — it is a translucent shell in front of it, so the imagery
 * underneath keeps its own detail and only its brightness changes.
 *
 * ── Relief ────────────────────────────────────────────────────────────────────────────────────
 *
 * A shaded sphere is a lit BALL. What makes an orbital photograph read as a lit PLANET is that
 * mountain ranges rake into relief along the terminator, where the light comes in almost flat and
 * every ridge throws its own shadow.
 *
 * The trick, after Sangil Lee, is to shade by the DIFFERENCE between the terrain's normal and the
 * smooth sphere's, not by the terrain's normal alone. Shading by the terrain normal would relight
 * the whole planet and fight the imagery, which already carries its own baked-in relief. The
 * difference is zero wherever the ground is flat, so it adds nothing over the oceans and nothing
 * over the plains — and it is naturally largest at the terminator, for free: at the subsolar point
 * the sphere's normal already points at the sun and a tilt can only reduce the cosine, to second
 * order; near the terminator the cosine is near zero and a tilt moves it to first order.
 *
 * A SHELL CAN ONLY DARKEN, which is the one place this departs from Lee. He mixes a day texture
 * into a night texture and can push either way. This layer sits IN FRONT of imagery it cannot
 * brighten by turning its alpha up, so the two halves of the effect leave by different doors: the
 * shaded half becomes extra night, and the sunlit half becomes a genuinely additive term with no
 * alpha at all (premultiplied blending makes `vec4(colour, 0.0)` pure addition). Dropping the lit
 * half would lose most of the raking — ridges read as ridges because the sunward face brightens as
 * the shaded face darkens.
 */

import maplibregl from 'maplibre-gl'
import {
  buildSphereMesh, buildProgram, cameraInPlanetSpace, EARTH_RADIUS_M,
  EQUIRECT_GLSL, SHELL_PROJECT_GLSL, FACING_CAMERA_GLSL, TERMINATOR_GLSL,
} from './planet-mesh.js'
import { TANGENT_FRAME_GLSL, RELIEF_GLSL, CLOUD_SHADOW_GLSL, reliefDetailFor } from './terrain-normals.js'
import { ECLIPSE_GLSL, MOON_RADIUS_EARTHS, solarAngularRadius, eclipseIsPossible } from './eclipse.js'
import { moonVector } from './moon.js'
import { CLOUD_ALTITUDE_M, CLOUD_FIELD_GLSL, CLOUD_FIELD_UNIFORMS, setCloudFieldUniforms } from './cloud-field.js'
import { acquireEquirectTexture } from './equirect-texture.js'

const LAYER_ID = 'tm-daylight'

/** Texture units. Field and wind match clouds.js, because they are literally the same textures. */
const UNIT = { field: 0, wind: 1, relief: 2, lights: 3, patches: 4 }

/**
 * GLSL ES 3.00, which the tile pyramid requires of every consumer.
 *
 * `#version 300 es` has to be the literal first line — not the first non-blank line, the first
 * line. MapLibre's own prelude carries no version directive and uses none of the removed keywords,
 * so it compiles unchanged under either version, and v5 asks for a webgl2 context first. The
 * define goes before the prelude so anything version-dependent inside it sees GLOBE.
 */
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
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`

const fragmentSource = () => `#version 300 es
precision highp float;
in vec3 v_sphere;
out vec4 fragColour;
uniform vec3 u_sun;            // direction TO the sun
uniform vec3 u_camera;         // camera in planet space, earth = unit sphere
uniform float u_globeness;     // 1 on the globe, 0 on the flat map
uniform float u_nightDarkness; // how black the unlit side goes
uniform vec3 u_nightColour;
uniform vec3 u_twilightColour;  // the warm edge, sunward of the line
uniform vec3 u_twilightCool;    // the blue hour, nightward of it
uniform float u_twilightStrength;
uniform sampler2D u_lights;    // NASA Black Marble, equirectangular
uniform float u_lightsAmount;  // 0 when the texture has not loaded
uniform sampler2D u_relief;    // baked terrain normals, equirectangular; see terrain-normals.js
uniform float u_reliefPower;   // 0 when there is no relief map, or the camera is too close for it
uniform float u_cloudShadow;   // how much of the light a full cloud takes away
uniform float u_cloudAltitude; // deck height in earth radii — sets how far a shadow is thrown
uniform vec3 u_moon;           // the moon's POSITION in earth radii, not its direction
uniform float u_sunRadius;     // the sun's angular radius; 0 on any date without an eclipse
${EQUIRECT_GLSL}
${FACING_CAMERA_GLSL}
${TERMINATOR_GLSL}
${TANGENT_FRAME_GLSL}
${RELIEF_GLSL}
${CLOUD_SHADOW_GLSL}
${ECLIPSE_GLSL}
// The same field, wind and clock the deck in clouds.js draws itself from.
${CLOUD_FIELD_GLSL}

void main() {
  vec3 normal = normalize(v_sphere);

  // The mesh is a closed sphere, so the far side covers the same pixels as the near side. On the
  // globe, drop it here rather than leaning on the depth buffer — see FACING_CAMERA_GLSL. On the
  // flat map there is no far side to drop.
  if (u_globeness > 0.5 && !facesCamera(normal, u_camera)) discard;

  vec3 sunDir = normalize(u_sun);

  // TWO DIFFERENT QUANTITIES, and conflating them is a bug that renders beautifully.
  //   sunlight  is where the sun is — pure geometry, shared with the deck so the two agree.
  //   day       is how much light actually lands here, after terrain and cloud have had their say.
  // Colour and the city-lights gate belong to the FIRST. Getting that wrong paints every cloud
  // shadow sunset-orange in the middle of the afternoon, because a shadow drops the light to about
  // half and half is exactly where the twilight band peaks. It looks like weather until you notice
  // the sun is overhead.
  float sunlight = daylightFraction(dot(normal, sunDir));
  float day = sunlight;

  // ── Relief ────────────────────────────────────────────────────────────────────────────────
  // Lee writes this as one multiply, day *= 1 + power*delta. That is the same thing as adding
  // day*power*delta, and splitting it that way is what lets the negative half become night and the
  // positive half become light. Both are scaled by the daylight already there, so nothing happens
  // on the night side, where there is no sun to rake.
  float lit = 0.0;
  if (u_reliefPower > 0.0) {
    vec3 tangentNormal = decodeTerrainNormal(texture(u_relief, equirectUV(normal, 0.0)).xyz);
    vec3 perturbed = normalize(equirectTangentFrame(normal) * tangentNormal);
    float change = day * (reliefLightFactor(normal, perturbed, sunDir, u_reliefPower) - 1.0);
    day = day + min(0.0, change);
    lit = max(0.0, change);
  }

  // ── Cloud shadow ──────────────────────────────────────────────────────────────────────────
  // Walk from this patch of ground toward the sun until the deck, and see what is up there. The
  // offset is what makes a shadow fall AWAY from the light rather than sitting under the cloud
  // like a decal — and it is why an afternoon deck lays its shadows out to the east.
  if (u_cloudShadow > 0.0 && u_fieldAmount > 0.0) {
    vec3 above = cloudShadowDirection(normal, sunDir, u_cloudAltitude);
    float cover = advectedField(equirectUV(above, u_drift), asin(clamp(above.y, -1.0, 1.0)));
    // The same threshold the deck opens its coverage with, so a shadow exists exactly where a
    // cloud does. No procedural detail: from orbit, a shadow thrown from the deck is a soft blob
    // and the filaments do not survive the trip down.
    float shadow = smoothstep(0.16, 0.62, cover) * cloudShadowFade(normal, sunDir);
    day *= 1.0 - u_cloudShadow * shadow;
    lit *= 1.0 - shadow;
  }

  // ── Eclipse ───────────────────────────────────────────────────────────────────────────────
  // The moon covering some fraction of the sun's disc, worked out for THIS point rather than for
  // the planet: the moon is only sixty radii away, and that parallax is the whole reason totality
  // is a hundred-kilometre track instead of a hemisphere. Costs one comparison on the millions of
  // dates with no eclipse, because u_sunRadius is 0 on all of them.
  if (u_sunRadius > 0.0) {
    float remaining = eclipseLight(normal, sunDir, u_moon, u_sunRadius, ${MOON_RADIUS_EARTHS.toFixed(8)});
    day *= remaining;
    lit *= remaining;
  }

  day = clamp(day, 0.0, 1.0);
  float night = 1.0 - day;

  // Sunlit rock, added rather than blended: premultiplied alpha makes vec4(colour, 0.0) a pure
  // addition, which is the only way a darkening shell can put light back.
  vec3 emitted = lit * vec3(1.0, 0.97, 0.92);

  if (night < 0.004 && lit < 0.004) discard;   // full daylight, flat ground: leave the imagery alone

  // TWILIGHT IS TWO COLOURS ON OPPOSITE SIDES OF THE TERMINATOR, NOT ONE.
  //
  // Sunward of the line, the light still reaching the ground has crossed the most air and lost its
  // blue — that is the warm edge, and it is THIN. Nightward, the ground is already dark but the air
  // ABOVE it is still lit, and what comes back down has been Rayleigh-scattered, so it is BLUE.
  // That is the blue hour, and it is the half everyone forgets.
  //
  // Mixing the whole band to one saturated orange is what made it read as a stripe painted pole to
  // pole rather than as a sunset seen from orbit: there is no single orange anywhere in the real
  // thing, and the cool outer edge is what stops the warm one looking like a decal.
  //
  // It still follows the SUN's angle, so a cloud shadow at noon is grey rather than a private sunset.
  float band = 1.0 - abs(sunlight * 2.0 - 1.0);
  band = band * band * band;                          // narrower than the old square
  float warmSide = smoothstep(0.30, 0.70, sunlight);  // 1 sunward of the line, 0 nightward
  vec3 twilightTint = mix(u_twilightCool, u_twilightColour, warmSide);

  vec3 shade = mix(u_nightColour, twilightTint, band * u_twilightStrength);
  float alpha = night * u_nightDarkness;

  // City lights, but only where it is genuinely dark — they have no business showing at dusk, and
  // a passing cloud is not dusk.
  if (u_lightsAmount > 0.0) {
    float lights = texture(u_lights, equirectUV(normal, 0.0)).r;
    float visible = lights * smoothstep(0.25, 0.75, 1.0 - sunlight) * u_lightsAmount;
    // Added, not blended: lights EMIT. Blending them would make the cities darker than the sea.
    shade = shade + vec3(1.0, 0.86, 0.6) * visible * 1.6;
    alpha = min(1.0, alpha + visible * 0.5);
  }

  // Premultiplied, matching MapLibre's ONE / ONE_MINUS_SRC_ALPHA for custom layers.
  fragColour = vec4(shade * alpha + emitted, alpha);
}`

/**
 * @param {object} opts
 * @param {number[]} [opts.sun]            direction TO the sun; share it with the clouds and air
 * @param {number} [opts.nightDarkness]    0 disables the layer, 1 is a black night side.
 *   Judge this over ICE, not over ocean. Blue Marble's night ocean is already near-black, so it
 *   looks convincing at almost any setting, while its Antarctica is near-white: measured on the
 *   globe, the ice reads 237 unshaded and still 78 at 0.82, a grey continent glowing in the middle
 *   of the polar night. At 0.965 it lands around 12 and the night finally reads as night.
 * @param {string} [opts.reliefUrl]        baked equirectangular terrain normals
 * @param {number} [opts.reliefWidth]      that map's width in texels — it decides at what zoom the
 *   relief retires. Getting it wrong costs nothing visible until the camera descends, at which
 *   point the relief either vanishes early or smears into a low-frequency bulge over sharp ground.
 * @param {number} [opts.reliefPower]      how hard terrain leans on the light. 0 switches it off.
 * @param {number} [opts.cloudShadow]      0..1, how much light a fully clouded sky takes away
 * @param {boolean} [opts.eclipse]         put the moon's shadow on the earth when there is one
 * @param {Date} [opts.date]               the moment the eclipse geometry is computed for. Pass the
 *   lesson's own date and the shadow lands where it actually landed — 1919 off West Africa, 1999
 *   across Europe. This is not a look; it is a dated event, which is why it is worth computing.
 * @param {string} [opts.fieldUrl]         cloud cover; shared with clouds.js, pass them the same URL
 * @param {string} [opts.windUrl]          wind field, likewise
 * @param {string} [opts.lightsUrl]        equirectangular city-lights image (NASA Black Marble)
 * @param {number} [opts.lightsAmount]     brightness of the cities
 * @param {number[]} [opts.nightColour]    the unlit side's tint
 * @param {number[]} [opts.twilightColour] the warm band along the terminator
 */
export const createDaylightLayer = ({
  sun = [1, 0, 0],
  nightDarkness = 0.965,
  lightsUrl = null,
  lightsAmount = 0,
  nightColour = [0.02, 0.035, 0.07],
  // Desaturated from [0.85, 0.35, 0.12] — the old one is a traffic cone, and there is no single
  // orange anywhere in real twilight.
  twilightColour = [0.62, 0.32, 0.16],
  twilightCool = [0.10, 0.15, 0.28],
  twilightStrength = 0.55,
  reliefUrl = null,
  reliefWidth = 8192,
  reliefPower = 1.5,
  cloudShadow = 0.5,
  eclipse = true,
  date = null,
  // The cloud field, in the same shape clouds.js takes it. Both layers must be given the same
  // values or the shadows drift away from the clouds casting them. `patchUrl` included: the deck
  // draws the tiled atlas, so the ground has to cast the shadow of THAT sky and not of the old
  // whole-planet field, which is a different sky entirely.
  fieldUrl = null, patchUrl = null, windUrl = null, windAmount = 1, windScale = 0.06, windRate = 0.05,
  driftRate = 0.0004, animate = true,
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  let buffers = null
  let lights = null
  let relief = null
  let field = null
  let wind = null
  let patches = null
  const programs = new Map()
  let state = {
    sun, nightDarkness, lightsUrl, lightsAmount, nightColour, twilightColour, twilightCool, twilightStrength,
    reliefUrl, reliefWidth, reliefPower, cloudShadow, eclipse, date,
    fieldUrl, patchUrl, windUrl, windAmount, windScale, windRate, driftRate, animate,
  }

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
        camera: gl.getUniformLocation(program, 'u_camera'),
        globeness: gl.getUniformLocation(program, 'u_globeness'),
        nightDarkness: gl.getUniformLocation(program, 'u_nightDarkness'),
        nightColour: gl.getUniformLocation(program, 'u_nightColour'),
        twilightColour: gl.getUniformLocation(program, 'u_twilightColour'),
        twilightCool: gl.getUniformLocation(program, 'u_twilightCool'),
        twilightStrength: gl.getUniformLocation(program, 'u_twilightStrength'),
        lights: gl.getUniformLocation(program, 'u_lights'),
        lightsAmount: gl.getUniformLocation(program, 'u_lightsAmount'),
        relief: gl.getUniformLocation(program, 'u_relief'),
        reliefPower: gl.getUniformLocation(program, 'u_reliefPower'),
        cloudShadow: gl.getUniformLocation(program, 'u_cloudShadow'),
        cloudAltitude: gl.getUniformLocation(program, 'u_cloudAltitude'),
        moon: gl.getUniformLocation(program, 'u_moon'),
        sunRadius: gl.getUniformLocation(program, 'u_sunRadius'),
        // time, drift, field, fieldAmount, wind, windAmount, windScale, windRate
        ...Object.fromEntries(CLOUD_FIELD_UNIFORMS.map((name) =>
          [name, gl.getUniformLocation(program, `u_${name}`)])),
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

  // Every texture here is decoration: without them the night side is simply dark, which is still
  // correct. So a failed load costs the effect and never the map.
  const repaint = () => map?.triggerRepaint()

  const bind = (uniforms, handle, name, unit) => {
    if (!handle?.ready || !uniforms[name]) return
    gl.activeTexture(gl.TEXTURE0 + unit)
    gl.bindTexture(gl.TEXTURE_2D, handle.texture)
    gl.uniform1i(uniforms[name], unit)
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
      if (state.lightsUrl) lights = acquireEquirectTexture(gl, state.lightsUrl, repaint)
      if (state.reliefUrl) relief = acquireEquirectTexture(gl, state.reliefUrl, repaint)
      if (state.fieldUrl) field = acquireEquirectTexture(gl, state.fieldUrl, repaint)
      if (state.windUrl) wind = acquireEquirectTexture(gl, state.windUrl, repaint)
      if (state.patchUrl) patches = acquireEquirectTexture(gl, state.patchUrl, repaint)
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      // Released, not deleted: the cloud deck is holding the same field and wind.
      lights?.release(); lights = null
      relief?.release(); relief = null
      field?.release(); field = null
      wind?.release(); wind = null
      patches?.release(); patches = null
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

      // Right on the surface. Nothing is lifted to dodge the depth buffer any more — the shader
      // rejects the far hemisphere itself — and lifting it would drag the terminator off the
      // ground it is meant to fall on.
      const lat = map.getCenter().lat
      const LIFT_M = 0
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, LIFT_M)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([0, lat], LIFT_M).z)
      }
      if (uniforms.sun) gl.uniform3f(uniforms.sun, ...state.sun)
      if (uniforms.camera) gl.uniform3f(uniforms.camera, ...cameraInPlanetSpace(map, maplibregl))
      if (uniforms.globeness) gl.uniform1f(uniforms.globeness, projection.projectionTransition)
      if (uniforms.nightDarkness) gl.uniform1f(uniforms.nightDarkness, state.nightDarkness)
      if (uniforms.nightColour) gl.uniform3f(uniforms.nightColour, ...state.nightColour)
      if (uniforms.twilightColour) gl.uniform3f(uniforms.twilightColour, ...state.twilightColour)
      if (uniforms.twilightCool) gl.uniform3f(uniforms.twilightCool, ...state.twilightCool)
      if (uniforms.twilightStrength) gl.uniform1f(uniforms.twilightStrength, state.twilightStrength)
      if (uniforms.lightsAmount) gl.uniform1f(uniforms.lightsAmount, lights?.ready ? state.lightsAmount : 0)

      // The relief retires as the camera descends — see reliefDetailFor. Without a map, or below
      // it, the term is zero and the texture is never sampled.
      const reliefFade = relief?.ready
        ? reliefDetailFor(map.getZoom(), lat, state.reliefWidth).strength
        : 0
      if (uniforms.reliefPower) gl.uniform1f(uniforms.reliefPower, state.reliefPower * reliefFade)
      if (uniforms.cloudShadow) gl.uniform1f(uniforms.cloudShadow, state.cloudShadow)
      if (uniforms.cloudAltitude) gl.uniform1f(uniforms.cloudAltitude, CLOUD_ALTITUDE_M / EARTH_RADIUS_M)

      // The eclipse block is skipped entirely unless the moon is actually near the sun today, and
      // that is decided here rather than per fragment — a cheap geocentric test on the CPU, once a
      // frame, in place of trigonometry on every pixel of the planet for the whole of history.
      const eclipseDate = state.eclipse ? state.date : null
      const moon = eclipseDate && eclipseIsPossible(eclipseDate) ? moonVector(eclipseDate) : null
      if (uniforms.sunRadius) gl.uniform1f(uniforms.sunRadius, moon ? solarAngularRadius(eclipseDate) : 0)
      if (moon && uniforms.moon) gl.uniform3f(uniforms.moon, ...moon)

      setCloudFieldUniforms(gl, uniforms, state,
        { seconds: performance.now() * 0.001, field, wind, patches },
        UNIT.field, UNIT.wind, UNIT.patches)
      bind(uniforms, relief, 'relief', UNIT.relief)
      bind(uniforms, lights, 'lights', UNIT.lights)

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(attribs.pos)
      gl.vertexAttribPointer(attribs.pos, 2, gl.FLOAT, false, 0, 0)
      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.sphere)
      gl.enableVertexAttribArray(attribs.sphere)
      gl.vertexAttribPointer(attribs.sphere, 3, gl.FLOAT, false, 0, 0)

      // No depth test at all. A shell this close to the surface cannot be told apart from it by a
      // depth buffer at orbital range, and testing anyway is what broke the night into a quilt of
      // tile-shaped patches. The horizon test in the fragment shader replaces it exactly.
      gl.disable(gl.DEPTH_TEST)
      gl.depthMask(false)      // night is not geometry
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, mesh.indices.length, gl.UNSIGNED_SHORT, 0)
      gl.enable(gl.DEPTH_TEST)
      gl.depthMask(true)

      // The cloud shadows move with the deck, so the ground has to be redrawn with it. Either
      // source counts: with only the atlas loaded the shadows still move, and asking about the old
      // field alone would freeze them against a sky that is still drifting.
      if (state.animate && (field?.ready || patches?.ready) && state.cloudShadow > 0) map.triggerRepaint()
    },

    /** Live controls — chiefly the sun, which a lesson moves as its date moves. */
    setOptions(next = {}) {
      const previous = state
      state = { ...state, ...next }
      if (!gl) { map?.triggerRepaint(); return }
      const swap = (handle, key) => {
        if (next[key] === undefined || next[key] === previous[key]) return handle
        handle?.release()
        return state[key] ? acquireEquirectTexture(gl, state[key], repaint) : null
      }
      lights = swap(lights, 'lightsUrl')
      relief = swap(relief, 'reliefUrl')
      field = swap(field, 'fieldUrl')
      wind = swap(wind, 'windUrl')
      patches = swap(patches, 'patchUrl')
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    get hasLights () { return !!lights?.ready },
    /** True once the terrain normals are uploaded — until then the globe is a smooth sphere. */
    get hasRelief () { return !!relief?.ready },
  }
}

export const DAYLIGHT_LAYER_ID = LAYER_ID

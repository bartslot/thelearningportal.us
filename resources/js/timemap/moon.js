/**
 * moon.js — the moon, where it really is, lit the way it really is.
 *
 * Two halves: an ephemeris that says where the moon is for a given moment, and a layer that draws
 * it there.
 *
 * DISTANCE IS THE POINT. Almost every illustration puts the moon a few earth-diameters away because
 * the truth does not fit on a page: it is 384,400 km out — thirty earth diameters — and subtends
 * barely half a degree, the same as the sun. Drawing it at its real distance and real angular size
 * is the whole reason to bother, so `sizeScale` defaults to 1 and anything else is a lie the caller
 * has chosen deliberately.
 *
 * PHASE COMES FREE. The moon is a sphere lit by the same sun vector as the earth, so the phase is
 * not drawn — it is a consequence. Full when the moon is opposite the sun, new when it is between
 * us and the sun, and the terminator falls exactly where the geometry puts it. Getting a crescent
 * to point the right way is a classic illustration error, and this cannot make it.
 *
 * WHY AN IMPOSTOR, NOT A MODEL. At half a degree the moon is a few dozen pixels: a billboarded
 * quad whose shader reconstructs a sphere's normals is pixel-identical to a real mesh at that size,
 * for one quad instead of thousands of triangles and no glTF loader. NASA's model is the right
 * answer for a moon that fills the screen; this is the right answer for a moon in the sky.
 * The albedo texture IS theirs — LRO's colour map, public domain.
 */

import maplibregl from 'maplibre-gl'
import { buildProgram, cameraInPlanetSpace, EQUIRECT_GLSL, planetSpacePosition } from './planet-mesh.js'

const LAYER_ID = 'tm-moon'
const EARTH_RADIUS_M = 6371008.8
const MOON_RADIUS_M = 1737400
const DEG = Math.PI / 180
const J2000 = Date.UTC(2000, 0, 1, 12)

/**
 * "2000 January 0.0" — 1999-12-31 00:00 UT, a day and a half BEFORE J2000, and the epoch the
 * classical orbital elements below are actually counted from.
 *
 * This used to be J2000, which put the moon roughly twenty degrees wrong: it covers 19.6° in a day
 * and a half. Nothing in `__tests__/moon.test.js` saw it, because every assertion there is
 * invariant under a shift in time — the distance range, the latitude bound, the westward drift
 * rate and the phase geometry are all still perfectly self-consistent for a moon that is simply in
 * the wrong place. It took an absolute position checked against JPL Horizons.
 *
 * The obliquity and sidereal-time series further down are genuinely referred to J2000 and must
 * keep using `d`.
 */
const ELEMENT_EPOCH = Date.UTC(1999, 11, 31, 0)

const sind = (deg) => Math.sin(deg * DEG)
const cosd = (deg) => Math.cos(deg * DEG)

/**
 * Geocentric position of the moon: which way it lies from the earth's centre, and how far.
 *
 * The orbital elements are the standard low-precision set, plus the three largest perturbations —
 * evection, variation and the annual equation. Those three are not optional decoration: the moon's
 * orbit is pulled about by the sun enough that leaving them out costs over a degree, which is two
 * full moon-widths of error. With them this is good to roughly a tenth of a degree.
 *
 * @returns {{lng: number, lat: number, distance: number}} sub-lunar point in degrees, distance in metres
 */
export const moonPosition = (date = new Date()) => {
  const d = (date.getTime() - J2000) / 86400000
  const t = (date.getTime() - ELEMENT_EPOCH) / 86400000   // the elements' own epoch, see above

  // Orbital elements at date.
  const N = 125.1228 - 0.0529538083 * t        // longitude of the ascending node
  const i = 5.1454                             // inclination to the ecliptic
  const w = 318.0634 + 0.1643573223 * t        // argument of perigee
  const a = 60.2666                            // semi-major axis, in earth radii
  const e = 0.054900
  const M = 115.3654 + 13.0649929509 * t       // mean anomaly

  // Kepler, iterated. Three passes is ample at this eccentricity.
  let E = M + (e * 180 / Math.PI) * sind(M) * (1 + e * cosd(M))
  for (let pass = 0; pass < 3; pass++) {
    E -= (E - (e * 180 / Math.PI) * sind(E) - M) / (1 - e * cosd(E))
  }

  // Position in the orbital plane, then rotated into ecliptic coordinates.
  const xv = a * (cosd(E) - e)
  const yv = a * (Math.sqrt(1 - e * e) * sind(E))
  const v = Math.atan2(yv, xv) / DEG
  const r = Math.sqrt(xv * xv + yv * yv)

  let xec = r * (cosd(N) * cosd(v + w) - sind(N) * sind(v + w) * cosd(i))
  let yec = r * (sind(N) * cosd(v + w) + cosd(N) * sind(v + w) * cosd(i))
  let zec = r * (sind(v + w) * sind(i))

  // The sun's pull on the moon's orbit. Ms/Ls are the sun's mean anomaly and longitude.
  const Ms = 356.0470 + 0.9856002585 * t
  const Ls = 282.9404 + 0.0000470935 * t + Ms
  const Lm = N + w + M
  const D = Lm - Ls                 // mean elongation from the sun
  const F = Lm - N                  // argument of latitude

  let lon = Math.atan2(yec, xec) / DEG
  let lat = Math.atan2(zec, Math.hypot(xec, yec)) / DEG
  lon += -1.274 * sind(M - 2 * D)   // evection
       + 0.658 * sind(2 * D)        // variation
       - 0.186 * sind(Ms)           // annual equation
  lat += -0.173 * sind(F - 2 * D)
  const dist = (r - 0.58 * cosd(M - 2 * D) - 0.46 * cosd(2 * D)) * EARTH_RADIUS_M

  // Ecliptic to equatorial.
  const obliquity = 23.4393 - 3.563e-7 * d
  const xe = cosd(lon) * cosd(lat)
  const ye = sind(lon) * cosd(lat) * cosd(obliquity) - sind(lat) * sind(obliquity)
  const ze = sind(lon) * cosd(lat) * sind(obliquity) + sind(lat) * cosd(obliquity)
  const rightAscension = Math.atan2(ye, xe) / DEG
  const declination = Math.atan2(ze, Math.hypot(xe, ye)) / DEG

  // Equatorial to a point on the turning earth: subtract the sidereal rotation to get longitude.
  const gmst = (18.697374558 + 24.06570982441908 * d) % 24
  let lng = rightAscension - gmst * 15
  lng = ((lng % 360) + 540) % 360 - 180

  return { lng, lat: declination, distance: dist }
}

/** The moon's position as a planet-space vector, in earth radii from the centre. */
export const moonVector = (date = new Date()) => {
  const { lng, lat, distance } = moonPosition(date)
  return planetSpacePosition(lng, lat, distance - EARTH_RADIUS_M)
}

/**
 * Illuminated fraction of the disc, 0 at new moon and 1 at full — the number everyone means by
 * "the phase". Derived from the angle at the moon between the earth and the sun, so it agrees with
 * whatever the shader draws rather than being an independent guess.
 */
export const moonPhase = (date, sunDirection) => {
  const moon = moonVector(date)
  const length = Math.hypot(...moon)
  // From the moon: which way is earth, and which way is the sun. The sun is far enough that its
  // direction from the moon and from the earth are the same to well under a degree.
  const toEarth = moon.map((v) => -v / length)
  const cosPhaseAngle = toEarth[0] * sunDirection[0] + toEarth[1] * sunDirection[1] + toEarth[2] * sunDirection[2]
  // FULL is when earth and sun lie in the same direction as seen from the moon — the near face is
  // fully lit. The opposite convention gives a moon that is full at new, which is the sort of error
  // that survives every visual check because a crescent still looks like a crescent.
  return (1 + cosPhaseAngle) / 2
}

// A billboard: four corners, offset in clip space after the centre is projected. The moon is far
// enough away that a quad facing the camera and a real sphere are the same picture.
const CORNERS = new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1])
const CORNER_INDICES = new Uint16Array([0, 1, 2, 1, 3, 2])

const vertexSource = (shaderData) => `${shaderData.vertexShaderPrelude}
${shaderData.define}
attribute vec2 a_corner;
uniform vec2 a_centre;          // sub-lunar point in mercator 0..1
uniform float a_elevation_globe;
uniform float a_elevation_mercator;
uniform vec2 u_size;            // half-size in clip units, x and y
varying vec2 v_corner;
void main() {
  v_corner = a_corner;
  #ifdef GLOBE
    vec4 centre = projectTileFor3D(a_centre, a_elevation_globe);
  #else
    vec4 centre = projectTileFor3D(a_centre, a_elevation_mercator);
  #endif
  // Scale by w so the disc keeps its angular size through the perspective divide.
  gl_Position = centre + vec4(a_corner * u_size * centre.w, 0.0, 0.0);
}`

const fragmentSource = () => `precision highp float;
varying vec2 v_corner;
uniform sampler2D u_albedo;
uniform vec3 u_sun;             // direction TO the sun, planet space
uniform vec3 u_right;           // the billboard's basis, in planet space
uniform vec3 u_up;
uniform vec3 u_forward;         // from the camera toward the moon
uniform float u_brightness;
uniform float u_hasAlbedo;
${EQUIRECT_GLSL}

void main() {
  float r2 = dot(v_corner, v_corner);
  if (r2 > 1.0) discard;                       // the quad's corners are not the moon

  // Rebuild the sphere the quad stands in for: z out of the disc toward the viewer.
  float z = sqrt(1.0 - r2);
  vec3 normal = normalize(u_right * v_corner.x + u_up * v_corner.y - u_forward * z);

  // Phase. Not drawn — a consequence of lighting a sphere from where the sun actually is.
  float lambert = max(dot(normal, u_sun), 0.0);
  // A little wrap: the lunar terminator is softened by the roughness of the surface, and a hard
  // cosine cut makes a crescent look like a paper cutout.
  float light = pow(lambert, 0.72);

  // The moon keeps one face toward earth, so a fixed lookup is the correct one.
  vec3 albedo = u_hasAlbedo > 0.0
    ? texture2D(u_albedo, equirectUV(normal, 0.0)).rgb
    : vec3(0.62, 0.60, 0.57);

  // Earthshine: the dark limb is not black, it is lit by a nearly-full earth overhead.
  vec3 colour = albedo * (light + 0.045);
  float edge = smoothstep(1.0, 0.985, r2);     // one pixel of anti-aliasing on the limb
  float alpha = edge;
  gl_FragColor = vec4(colour * u_brightness * alpha, alpha);
}`

/**
 * @param {object} opts
 * @param {Date} [opts.date]         the moment to place the moon at
 * @param {number[]} [opts.sun]      direction TO the sun; share it with the earth's layers
 * @param {string} [opts.albedoUrl]  equirectangular lunar albedo (NASA LRO colour map)
 * @param {number} [opts.sizeScale]  1 is the true angular size — half a degree. Larger is a lie.
 * @param {number} [opts.brightness]
 */
export const createMoonLayer = ({
  date = new Date(), sun = [1, 0, 0], albedoUrl = null, sizeScale = 1, brightness = 1,
} = {}) => {
  let map = null
  let gl = null
  let buffers = null
  let albedoTexture = null
  let albedoReady = false
  const programs = new Map()
  let state = { date, sun, albedoUrl, sizeScale, brightness }

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)
    const program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), 'tm-moon')
    const entry = {
      program,
      attribs: { corner: gl.getAttribLocation(program, 'a_corner') },
      uniforms: {
        centre: gl.getUniformLocation(program, 'a_centre'),
        elevationGlobe: gl.getUniformLocation(program, 'a_elevation_globe'),
        elevationMercator: gl.getUniformLocation(program, 'a_elevation_mercator'),
        size: gl.getUniformLocation(program, 'u_size'),
        albedo: gl.getUniformLocation(program, 'u_albedo'),
        hasAlbedo: gl.getUniformLocation(program, 'u_hasAlbedo'),
        sun: gl.getUniformLocation(program, 'u_sun'),
        right: gl.getUniformLocation(program, 'u_right'),
        up: gl.getUniformLocation(program, 'u_up'),
        forward: gl.getUniformLocation(program, 'u_forward'),
        brightness: gl.getUniformLocation(program, 'u_brightness'),
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

  const loadAlbedo = (url) => {
    const image = new Image()
    image.crossOrigin = 'anonymous'
    image.onload = () => {
      if (!gl) return
      albedoTexture = albedoTexture || gl.createTexture()
      gl.bindTexture(gl.TEXTURE_2D, albedoTexture)
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR)
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
      albedoReady = true
      map?.triggerRepaint()
    }
    // Decorative: without it the moon is a plain grey sphere, still correctly lit and placed.
    image.src = url
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
      if (state.albedoUrl) loadAlbedo(state.albedoUrl)
    },

    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      if (albedoTexture) { gl.deleteTexture(albedoTexture); albedoTexture = null; albedoReady = false }
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

      const { lng, lat, distance } = moonPosition(state.date)
      const { program, attribs, uniforms } = programFor(shaderData)
      gl.useProgram(program)
      if (uniforms.matrix) gl.uniformMatrix4fv(uniforms.matrix, false, projection.mainMatrix)
      if (uniforms.tileMercatorCoords) gl.uniform4f(uniforms.tileMercatorCoords, ...projection.tileMercatorCoords)
      if (uniforms.clippingPlane) gl.uniform4f(uniforms.clippingPlane, ...projection.clippingPlane)
      if (uniforms.transition) gl.uniform1f(uniforms.transition, projection.projectionTransition)
      if (uniforms.fallbackMatrix) gl.uniformMatrix4fv(uniforms.fallbackMatrix, false, projection.fallbackMatrix)

      // ── Where to actually put it ──────────────────────────────────────────────────────────
      //
      // NOT at 384,400 km. MapLibre's globe projection cannot reach that far: past roughly five
      // earth radii from the centre, a point's clip-space w goes NEGATIVE and it is dropped before
      // rasterisation. Measured at z1.9 — w = +1350 on the ground, +115 at five radii, −194 at six,
      // and −17,282 for the moon at its true sixty. The layer drew exactly zero pixels across a
      // full-canvas scan of 990,000, with no error anywhere, because there is nothing wrong with
      // it: it was simply being clipped.
      //
      // So the moon is drawn on the same SIGHT LINE, at a distance the projection can reach. That
      // costs nothing real. A sphere is defined on screen by its direction and its angular size,
      // and both are computed from the true geometry below — the distance itself is unobservable.
      // Parallax is preserved because the direction is measured from the CAMERA, not from the
      // earth's centre, and at sixty radii those differ by up to two degrees, four moon-widths.
      //
      // It sits just beyond the earth's far limb, so the globe's own depth still occludes it when
      // the moon is behind the planet, which it must.
      const camPos = cameraInPlanetSpace(map, maplibregl)
      const trueMoon = planetSpacePosition(lng, lat, distance - EARTH_RADIUS_M)
      const sightLine = normalise([
        trueMoon[0] - camPos[0], trueMoon[1] - camPos[1], trueMoon[2] - camPos[2],
      ])
      const camRadius = Math.hypot(...camPos)
      const renderDistance = camRadius + 1.2
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

      // Angular radius, turned into clip-space half-size through the field of view.
      const angularRadius = Math.atan((MOON_RADIUS_M * state.sizeScale) / distance)
      const halfFov = (args.fov || 0.6435) / 2
      const canvas = map.getCanvas()
      const halfHeight = Math.tan(angularRadius) / Math.tan(halfFov)
      if (uniforms.size) {
        gl.uniform2f(uniforms.size, halfHeight * (canvas.height / canvas.width), halfHeight)
      }

      // The billboard's basis in planet space, so the shader can build sphere normals in the same
      // frame the sun vector lives in — which is what makes the phase come out right. Built from
      // the TRUE sight line, not from where the disc is drawn, so moving it closer cannot rotate
      // the terminator across its face.
      const forward = sightLine
      const right = normalise(cross([0, 1, 0], forward))
      const up = cross(forward, right)
      if (uniforms.forward) gl.uniform3f(uniforms.forward, ...forward)
      if (uniforms.right) gl.uniform3f(uniforms.right, ...right)
      if (uniforms.up) gl.uniform3f(uniforms.up, ...up)
      if (uniforms.sun) gl.uniform3f(uniforms.sun, ...state.sun)
      if (uniforms.brightness) gl.uniform1f(uniforms.brightness, state.brightness)
      if (uniforms.hasAlbedo) gl.uniform1f(uniforms.hasAlbedo, albedoReady ? 1 : 0)
      if (albedoReady && uniforms.albedo) {
        gl.activeTexture(gl.TEXTURE0)
        gl.bindTexture(gl.TEXTURE_2D, albedoTexture)
        gl.uniform1i(uniforms.albedo, 0)
      }

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.corner)
      gl.enableVertexAttribArray(attribs.corner)
      gl.vertexAttribPointer(attribs.corner, 2, gl.FLOAT, false, 0, 0)

      // No depth test: the moon is beyond everything, and the earth occluding it is handled by the
      // fact that a moon behind the planet projects behind the planet's own opaque pixels.
      gl.enable(gl.DEPTH_TEST)
      gl.depthFunc(gl.LEQUAL)
      gl.depthMask(false)
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, CORNER_INDICES.length, gl.UNSIGNED_SHORT, 0)
      gl.depthMask(true)
    },

    /** Live controls — chiefly the date, which a lesson moves as its story moves. */
    setOptions(next = {}) {
      const previousUrl = state.albedoUrl
      state = { ...state, ...next }
      if (next.albedoUrl !== undefined && next.albedoUrl !== previousUrl) {
        albedoReady = false
        if (next.albedoUrl) loadAlbedo(next.albedoUrl)
      }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    get hasAlbedo () { return albedoReady },
  }
}

const normalise = (v) => {
  const length = Math.hypot(...v) || 1
  return [v[0] / length, v[1] / length, v[2] / length]
}
const cross = (a, b) => [
  a[1] * b[2] - a[2] * b[1],
  a[2] * b[0] - a[0] * b[2],
  a[0] * b[1] - a[1] * b[0],
]

export const MOON_LAYER_ID = LAYER_ID

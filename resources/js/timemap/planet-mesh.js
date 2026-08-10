/**
 * planet-mesh.js — the geometry and the coordinate space every globe overlay shares.
 *
 * Both the cloud deck and the ocean glint draw the same thing: a sphere around the earth, shaded
 * per pixel. They need the same three answers — what the mesh is, where a point on it sits in a
 * space a shader can reason about, and where the camera is in that same space. Keeping one copy is
 * what stops the two layers drifting into disagreeing about which way is north.
 */

export const EARTH_RADIUS_M = 6371008.8

/**
 * A point in PLANET SPACE: earth as a unit sphere at the origin, altitude in earth radii, +Y north
 * and +X at (0°N, 0°E) — the same convention `buildCloudMesh` gives each vertex, so the raymarch's
 * camera and its density field agree without a matrix between them.
 */
export const planetSpacePosition = (lng, lat, altitudeMetres = 0) => {
  const radius = 1 + Math.max(0, altitudeMetres) / EARTH_RADIUS_M
  const latRad = lat * Math.PI / 180
  const lngRad = lng * Math.PI / 180
  return [
    Math.cos(latRad) * Math.cos(lngRad) * radius,
    Math.sin(latRad) * radius,
    Math.cos(latRad) * Math.sin(lngRad) * radius,
  ]
}

/** Latitude of a mercator y in 0..1 (y = 0 is the north edge). Inverse of the Gudermannian. */
export const latFromMercatorY = (y) => (2 * Math.atan(Math.exp((1 - 2 * y) * Math.PI)) - Math.PI / 2) * 180 / Math.PI

/** Mercator y of a latitude. The inverse of `latFromMercatorY`, and unbounded past ±85.05°. */
export const mercatorYFromLat = (lat) =>
  0.5 - Math.log(Math.tan(Math.PI / 4 + (lat * Math.PI / 180) / 2)) / (2 * Math.PI)

/** The latitude where the mercator square ends. Everything beyond it is the polar cap. */
export const MERCATOR_EDGE_LAT = 85.0511287798066

/**
 * How far the cap rows reach. Stopping short of 90° is not optional — mercator y diverges
 * logarithmically at the pole itself — but the remaining gap has to be smaller than a pixel at the
 * zoom the poles are actually looked at, and that zoom is higher than it sounds: MapLibre measures
 * zoom in mercator units, so at 85°S a nominal z1.8 is magnified about 12× and a 3 km gap becomes a
 * visible white dot. 0.001° leaves 111 m, which never resolves.
 */
const POLE_LAT = 89.999
const POLE_ROWS = 6

/**
 * A sphere around the earth, as a triangle grid over the mercator square.
 *
 * Vertices are laid out in MERCATOR space (x, y in 0..1) because that is what the projection
 * prelude consumes — under globe it wraps that square onto the sphere for us.
 *
 * THE MERCATOR SQUARE IS NOT THE WHOLE PLANET. It stops at ±85.0511°, so a mesh spanning exactly
 * 0..1 leaves a disc of bare imagery at each pole — under a night shell, a perfect circle of
 * midday Antarctica sitting in the middle of the polar night. Hence the cap rows: extra rows of
 * vertices at y < 0 and y > 1, stepping in LATITUDE up to ±89.97°. MapLibre's own prelude computes
 * `2·atan(exp(π − 2πy)) − π/2` with no clamp, so those rows land exactly where the arithmetic says
 * they should; its pole special-case only fires near y = ±32767, nowhere near this.
 *
 * Under mercator there is nothing outside the square to cover, so each layer's vertex shader
 * clamps y back to 0..1 there and the cap rows collapse harmlessly onto the map edge.
 *
 * Each vertex also carries its position on the unit sphere. The noise is sampled in THAT space, not
 * in mercator: sampling a 3D field on the sphere is what keeps cloud cells the same size at Iceland
 * as at the equator, and makes the field wrap seamlessly across ±180°.
 */
export const buildSphereMesh = (gridX = 64, gridY = 48) => {
  const positions = []
  const spheres = []
  const indices = []

  // Rows, north to south: the north cap, the mercator square, then the south cap.
  const capLat = (i) => MERCATOR_EDGE_LAT + (POLE_LAT - MERCATOR_EDGE_LAT) * (i / POLE_ROWS)
  const rows = []
  for (let i = POLE_ROWS; i >= 1; i--) rows.push(mercatorYFromLat(capLat(i)))
  for (let iy = 0; iy <= gridY; iy++) rows.push(iy / gridY)
  for (let i = 1; i <= POLE_ROWS; i++) rows.push(mercatorYFromLat(-capLat(i)))

  for (const my of rows) {
    const latRad = latFromMercatorY(my) * Math.PI / 180
    for (let ix = 0; ix <= gridX; ix++) {
      const mx = ix / gridX
      const lonRad = (mx * 360 - 180) * Math.PI / 180
      positions.push(mx, my)
      spheres.push(
        Math.cos(latRad) * Math.cos(lonRad),
        Math.sin(latRad),
        Math.cos(latRad) * Math.sin(lonRad),
      )
    }
  }

  const stride = gridX + 1
  for (let iy = 0; iy < rows.length - 1; iy++) {
    for (let ix = 0; ix < gridX; ix++) {
      const a = iy * stride + ix
      const b = a + stride
      indices.push(a, b, a + 1, b, b + 1, a + 1)
    }
  }

  return {
    positions: new Float32Array(positions),
    spheres: new Float32Array(spheres),
    indices: new Uint16Array(indices),
    vertexCount: (gridX + 1) * rows.length,
    rowCount: rows.length,
  }
}

/**
 * The vertex-shader line every shell shares.
 *
 * Two jobs in one place: pick the elevation unit for the projection in play (metres under globe,
 * mercator-z under mercator — the same number in the wrong one puts the shell in orbit), and keep
 * the polar cap rows off the mercator map, where there is no cap to cover.
 */
export const SHELL_PROJECT_GLSL = /* glsl */`
  vec4 projectShell(vec2 pos, float elevationGlobe, float elevationMercator) {
    #ifdef GLOBE
      return projectTileFor3D(pos, elevationGlobe);
    #else
      return projectTileFor3D(clamp(pos, 0.0, 1.0), elevationMercator);
    #endif
  }
`

/**
 * How lit a point is, from the cosine between its normal and the sun.
 *
 * ONE function, shared by the ground and by the cloud deck above it, because they have to agree.
 * The band runs from about 18° of sun below the horizon up to just above it — astronomical twilight
 * through to sunrise — which is why the terminator is a soft band a few hundred kilometres wide
 * rather than a line. Two copies of this drift apart the moment either is tuned, and the deck's
 * terminator then visibly separates from the one on the ground it is casting shadows onto.
 */
export const TERMINATOR_GLSL = /* glsl */`
  float daylightFraction(float sunAngle) {
    return smoothstep(-0.31, 0.09, sunAngle);
  }
`

/**
 * Value noise and fbm over the unit sphere — shared by the cloud deck and the sea.
 *
 * Sampled in SPHERE space, not in mercator, which is what keeps a cell the same size at Iceland as
 * at the equator and makes the field wrap seamlessly across ±180°. Cheap value noise rather than
 * gradient noise: at the scales these layers use it, nothing can tell them apart.
 */
export const NOISE_GLSL = /* glsl */`
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

/**
 * Is a point on the unit sphere on the half facing the camera?
 *
 * For a unit sphere the horizon is exactly the plane dot(p, camera) = 1, so this is the whole test
 * — no depth buffer needed. That matters: a shell sitting a few kilometres above a 6371 km sphere
 * is far inside the depth buffer's precision at orbital distance, and testing it against the
 * globe's own tiles breaks the shading into a quilt of tile-shaped patches that flickers as the
 * camera moves. Rejecting the far side analytically is exact at every altitude.
 */
export const FACING_CAMERA_GLSL = /* glsl */`
  bool facesCamera(vec3 unitPos, vec3 camera) {
    return dot(unitPos, camera) > 1.0;
  }
`


/**
 * Camera altitude in metres.
 *
 * `transform.getCameraAltitude()` is the direct answer but returns null unless the map has an
 * elevation source, and a null here is invisible: a raymarch silently uses a fallback altitude, the
 * rays point somewhere the geometry isn't, and the sky comes back empty. So it falls back to
 * `cameraToCenterDistance` — which is in SCREEN PIXELS — converted through the mercator scale at
 * the current latitude.
 *
 * @param {object} map          MapLibre map
 * @param {object} maplibregl   the module, for MercatorCoordinate
 */
export const cameraAltitudeMetres = (map, maplibregl) => {
  const transform = map?.transform
  const direct = typeof transform?.getCameraAltitude === 'function' ? transform.getCameraAltitude() : null
  if (Number.isFinite(direct)) return direct

  const distancePx = transform?.cameraToCenterDistance
  if (!Number.isFinite(distancePx)) return 1e7
  const worldSizePx = 512 * Math.pow(2, map.getZoom())
  const metresPerMercatorUnit = 1 / maplibregl.MercatorCoordinate.fromLngLat(map.getCenter(), 0).meterInMercatorCoordinateUnits()
  return ((distancePx / worldSizePx) * metresPerMercatorUnit) * Math.cos(map.getPitch() * Math.PI / 180)
}

/** The camera as a planet-space point — the form every overlay's shader wants it in. */
export const cameraInPlanetSpace = (map, maplibregl) => {
  const transform = map?.transform
  const lngLat = typeof transform?.getCameraLngLat === 'function' ? transform.getCameraLngLat() : map.getCenter()
  return planetSpacePosition(lngLat.lng, lngLat.lat, cameraAltitudeMetres(map, maplibregl))
}

/** Compile and link a program, throwing with the driver's own message rather than a blank screen. */
export const buildProgram = (gl, vertexSource, fragmentSource, label = 'layer') => {
  const compile = (type, source) => {
    const shader = gl.createShader(type)
    gl.shaderSource(shader, source)
    gl.compileShader(shader)
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
      const log = gl.getShaderInfoLog(shader)
      gl.deleteShader(shader)
      throw new Error(`${label} shader: ${log}`)
    }
    return shader
  }
  const program = gl.createProgram()
  const vs = compile(gl.VERTEX_SHADER, vertexSource)
  const fs = compile(gl.FRAGMENT_SHADER, fragmentSource)
  gl.attachShader(program, vs)
  gl.attachShader(program, fs)
  gl.linkProgram(program)
  gl.deleteShader(vs)
  gl.deleteShader(fs)
  if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
    const log = gl.getProgramInfoLog(program)
    gl.deleteProgram(program)
    throw new Error(`${label} link: ${log}`)
  }
  return program
}

/**
 * Equirectangular lookups from a unit-sphere direction — shared by every overlay shader.
 *
 * THERE ARE TWO OF THESE AND THEY ARE MIRROR IMAGES, because there are two ways to be looking at a
 * textured sphere and they are not the same way.
 *
 * `equirectUV` is for a sphere seen from OUTSIDE — the cloud deck, the city lights, anything
 * wrapped on the planet. You are looking at the printed side of the map.
 *
 * `equirectUVInside` is for a sphere seen from INSIDE — the sky. The camera sits inside that shell,
 * so it sees the texture through the back of the sheet, and east and west swap over. Using the
 * outside lookup there paints the sky as its own mirror image: it still looks like a sky, still
 * moves correctly as the camera orbits, and never triggers anything that could be called a bug —
 * which is exactly why it survives review. The only thing that gives it away is knowing the sky.
 */
export const EQUIRECT_GLSL = /* glsl */`
  vec2 equirectUV(vec3 dir, float drift) {
    return vec2(atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }

  vec2 equirectUVInside(vec3 dir, float drift) {
    return vec2(-atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }
`

/**
 * planet-mesh.js — the geometry and the coordinate space every globe overlay shares.
 *
 * Both the cloud deck and the ocean glint draw the same thing: a sphere around the earth, shaded
 * per pixel. They need the same three answers — what the mesh is, where a point on it sits in a
 * space a shader can reason about, and where the camera is in that same space. Keeping one copy is
 * what stops the two layers drifting into disagreeing about which way is north.
 */

const EARTH_RADIUS_M = 6371008.8

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

/**
 * A sphere around the earth, as a triangle grid over the mercator square.
 *
 * Vertices are laid out in MERCATOR space (x, y in 0..1) because that is what the projection
 * prelude consumes — under globe it wraps that square onto the sphere for us, poles included, so
 * there is no pole pinch and no dateline seam to stitch.
 *
 * Each vertex also carries its position on the unit sphere. The noise is sampled in THAT space, not
 * in mercator: sampling a 3D field on the sphere is what keeps cloud cells the same size at Iceland
 * as at the equator, and makes the field wrap seamlessly across ±180°.
 */
export const buildSphereMesh = (gridX = 64, gridY = 48) => {
  const positions = []
  const spheres = []
  const indices = []

  for (let iy = 0; iy <= gridY; iy++) {
    const my = iy / gridY
    const lat = latFromMercatorY(my)
    const latRad = lat * Math.PI / 180
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
  for (let iy = 0; iy < gridY; iy++) {
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
    vertexCount: (gridX + 1) * (gridY + 1),
  }
}


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

/** An equirectangular texture lookup from a unit-sphere direction — shared by every overlay shader. */
export const EQUIRECT_GLSL = /* glsl */`
  vec2 equirectUV(vec3 dir, float drift) {
    return vec2(atan(dir.z, dir.x) / 6.28318530718 + 0.5 + drift,
                0.5 - asin(clamp(dir.y, -1.0, 1.0)) / 3.14159265359);
  }
`

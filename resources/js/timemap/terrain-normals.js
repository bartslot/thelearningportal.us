/**
 * terrain-normals.js — the conventions the relief normal map is baked with and read back by.
 *
 * The bake happens once, offline (scripts/build-terrain-normals.mjs). The shader reads the result
 * every frame. Nothing checks that the two agree, and every way they can disagree is invisible:
 * the globe still renders, the mountains simply lean the wrong way, or lie flat, or shade the
 * ocean. So the conventions live here, in one file, imported by both.
 *
 * THE TANGENT FRAME. A texel's normal is stored in the frame equirectangular UV already implies:
 *
 *   +R  east,  the direction u increases
 *   +G  SOUTH, the direction v increases        <- the classic normal-map bug lives here
 *   +B  up,    away from the centre of the earth
 *
 * Green pointing south rather than north is not a preference. `equirectUV` in planet-mesh.js puts
 * v = 0 at the north pole, so a shader that builds its basis from that lookup gets south for free;
 * baking green as north would mean a flip in the shader that nothing would ever remind us about.
 *
 * WHY THE SEA FLOOR IS FLATTENED. Terrarium tiles carry bathymetry, and it is real data — but
 * three kilometres of water is opaque and shading the ridges under it turns the Atlantic into a
 * bathymetric chart. The satellite imagery already avoids exactly this by using NASA's plain
 * shaded-relief variant rather than the bathymetry one; the normal map has to make the same choice
 * or it puts back what the imagery took out. Heights are clamped at sea level before any slope is
 * measured, which leaves open water perfectly flat and costs it no shading at all.
 */

import { EARTH_RADIUS_M, mercatorYFromLat } from './planet-mesh.js'

/**
 * The tangent frame an equirectangular map already implies, built from the sphere normal alone.
 *
 * Lee's shader carries a `vTbn` varying, because three.js hands a mesh its tangents. There is no
 * mesh here in that sense and no attribute to carry — but on a SPHERE the basis is not extra data,
 * it is the position: east and south are fixed the moment you know which way is up. So it is
 * derived per fragment, which costs one cross product and cannot fall out of step with the UV
 * lookup the way a baked attribute can.
 *
 * The columns are east, south, up — in that order, matching the R, G, B the bake writes.
 */
export const TANGENT_FRAME_GLSL = /* glsl */`
  mat3 equirectTangentFrame(vec3 unitPos) {
    vec3 up = normalize(unitPos);
    vec3 east = cross(up, vec3(0.0, 1.0, 0.0));
    float span = length(east);
    // Standing on a pole, every direction is south and no direction is east. Pick one rather than
    // dividing by zero and putting a NaN in the middle of Antarctica.
    east = span > 1.0e-4 ? east / span : vec3(0.0, 0.0, 1.0);
    return mat3(east, cross(up, east), up);
  }
`

/**
 * Relief shading, as a multiplier on how lit a point is.
 *
 * This is the whole idea, and it is one line: shade by the DIFFERENCE between the perturbed normal
 * and the smooth sphere normal, so terrain only shows where it disagrees with the sphere.
 *
 * Shading by the perturbed normal alone would relight the entire planet and fight the imagery,
 * which already carries its own baked-in relief. Shading by the difference adds nothing where the
 * ground is flat, and — this is the part that makes it worth doing — the difference is naturally
 * largest at the terminator. At the subsolar point the smooth normal already points at the sun, so
 * any tilt can only reduce the cosine, and only to second order. Near the terminator the cosine is
 * near zero and a tilt moves it to FIRST order. The mountains rake into relief exactly where an
 * orbital photograph shows them raking, with nothing in the shader arranging for it.
 */
export const RELIEF_GLSL = /* glsl */`
  /**
   * Unpack a baked normal, around the same centre encodeNormals packed it around.
   *
   * The usual texel*2-1 is very slightly wrong here, and wrong in a way that shows. Flat ground
   * bakes to byte 128, and 128/255*2-1 is 0.0039 rather than 0 — a constant tilt, applied to every
   * flat texel on earth, which is to say a constant brightness offset over every ocean. Measured
   * against a known grey it moved the Bay of Bengal by a full 8-bit level while the mountains it
   * was meant to be shading moved by sixty. Subtracting the encoder's own centre leaves water
   * EXACTLY untouched, which is the property the whole difference formulation is built on.
   */
  vec3 decodeTerrainNormal(vec3 texel) {
    return (texel - ${(128 / 255).toFixed(8)}) * 2.0;
  }

  float reliefLightFactor(vec3 up, vec3 perturbed, vec3 sunDir, float power) {
    return 1.0 + power * (dot(perturbed, sunDir) - dot(up, sunDir));
  }
`

/**
 * Which cloud shadows this patch of ground.
 *
 * Lee linearises this into a small UV nudge. The exact answer is barely more expensive and does not
 * fall apart at low sun: walk from the ground point along the direction of the sun until you hit
 * the deck, and look up whatever cloud is there.
 *
 *   |P + t·S| = 1 + a   ->   t² + 2t·cos(zenith) - 2a - a² = 0
 *
 * The positive root is the distance to the deck. At the subsolar point it is just the deck height
 * and the shadow sits directly underneath; toward the terminator it grows without bound, which is
 * why shadows stretch into long streaks at sunset — and why they have to fade out before the
 * denominator does anything interesting.
 */
export const CLOUD_SHADOW_GLSL = /* glsl */`
  vec3 cloudShadowDirection(vec3 up, vec3 sunDir, float altitudeRadii) {
    float cosZenith = dot(up, sunDir);
    float reach = altitudeRadii * (2.0 + altitudeRadii);
    return normalize(up + sunDir * (-cosZenith + sqrt(max(0.0, cosZenith * cosZenith + reach))));
  }

  // Below a low sun the ground is already in twilight and a cast shadow means nothing; above it,
  // full strength. Without this the shadows stretch to the horizon as the sun sets.
  float cloudShadowFade(vec3 up, vec3 sunDir) {
    return smoothstep(0.05, 0.30, dot(up, sunDir));
  }
`

/**
 * Height in metres from a terrarium-encoded pixel.
 *
 * AWS's elevation tiles pack a signed height into RGB as `(R*256 + G + B/256) - 32768`. The blue
 * byte is not decoration: without it the smallest step is a whole metre, and a river floodplain
 * comes out as visible terracing.
 */
export const decodeTerrarium = (r, g, b) => (r * 256 + g + b / 256) - 32768

/**
 * The range a real elevation can be in, in metres: Challenger Deep to Everest, with headroom.
 *
 * THE FLOOR IS NOT DECORATION. Terrarium tiles carry bathymetry, and the relief bake throws it
 * away on purpose — you cannot see the sea floor through three kilometres of water. But the ocean
 * layer is being built on exactly that discarded half, so the depths have to SURVIVE the decode
 * and be dropped by each reader instead. A shared grid that arrives pre-flattened leaves the ocean
 * with no data and no error: relief still looks perfect, and the Atlantic quietly has no floor.
 *
 * This used to clamp at -1000 m, which flattens everything below the continental shelf — about
 * two thirds of the ocean by area, including every abyssal plain and every trench.
 */
export const MIN_ELEVATION_M = -11500
export const MAX_ELEVATION_M = 9000

/**
 * Fit a decoded height into the range a 16-bit grid stores, rejecting nonsense without discarding
 * anything real. Clamp to sea level at the CONSUMER that wants land only, never here.
 */
export const clampTerrainHeight = (metres) =>
  Math.round(Math.min(MAX_ELEVATION_M, Math.max(MIN_ELEVATION_M, metres)))

/**
 * Water as a flat surface at 0 m, ready to be resampled.
 *
 * THE ORDER MATTERS AND IT IS NOT OBVIOUS. Sea level has to be applied BEFORE the grid is averaged
 * down, not after. A coastal texel covering half a 2,000 m massif and half a 4,000 m trench
 * averages to -1,000 m if the depths are still signed, and clamping that afterwards gives zero —
 * the mountain is simply gone. Clamp first and the same texel averages to 1,000 m, which is what a
 * surface model of that ground actually is.
 *
 * This stayed hidden while the shared DEM floor was -1,000 m, because the error was small enough
 * to look like coastline softness. Restoring true ocean depth for the ocean layer made it four
 * times worse and visible.
 */
export const heightsAboveSeaLevel = (heights) => {
  const out = new Float32Array(heights.length)
  for (let i = 0; i < heights.length; i++) out[i] = Math.max(0, heights[i])
  return out
}

/** Metres of ground one texel of a `width`-wide equirectangular map covers, at a given latitude. */
export const equirectMetresPerPixel = (width, lat) =>
  (2 * Math.PI * EARTH_RADIUS_M * Math.cos(lat * Math.PI / 180)) / width

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))

const smoothstep = (edge0, edge1, x) => {
  const t = clamp((x - edge0) / (edge1 - edge0), 0, 1)
  return t * t * (3 - 2 * t)
}

/**
 * Resample a square mercator height grid onto an equirectangular one.
 *
 * The DEM arrives as web-mercator tiles; every other globe overlay here samples equirectangularly
 * (see EQUIRECT_GLSL). Converting once, offline, is free; converting per pixel in the shader is
 * not, and it would need the mercator inverse in the fragment stage.
 *
 * Each output texel is an AREA average of the source it covers, not a point sample. That matters
 * most where the two projections disagree most: at 70°N a single equirectangular row spans three
 * mercator rows, and point-sampling there throws away two thirds of the relief — which is exactly
 * where the terminator spends the northern winter.
 *
 * BEYOND ±85.05° THERE IS NO SOURCE. The mercator square simply stops, and the rows past it inherit
 * the nearest one they have. Antarctica's interior is a smooth four-kilometre dome, so a constant
 * fill there is close to the truth; the alternative is a hole in the middle of the polar night.
 *
 * @param {Float32Array} source  heights in metres, row-major, `size` x `size`, north-first
 * @param {number} size          side of the source grid
 * @param {number} outWidth      equirectangular width  (360° of longitude)
 * @param {number} outHeight     equirectangular height (180° of latitude)
 * @returns {Float32Array} heights in metres, row-major, north-first
 */
export const equirectHeightGrid = (source, size, outWidth, outHeight) => {
  const out = new Float32Array(outWidth * outHeight)

  // Source rows are the same for every column, so resolve them once per output row.
  for (let j = 0; j < outHeight; j++) {
    const latTop = 90 - 180 * (j / outHeight)
    const latBottom = 90 - 180 * ((j + 1) / outHeight)
    // mercatorYFromLat diverges at the poles; clamping is what fills the caps.
    const yTop = clamp(mercatorYFromLat(latTop), 0, 1) * size
    const yBottom = clamp(mercatorYFromLat(latBottom), 0, 1) * size
    const r0 = Math.min(size - 1, Math.floor(yTop))
    const r1 = Math.min(size, Math.max(r0 + 1, Math.ceil(yBottom)))

    for (let i = 0; i < outWidth; i++) {
      const xLeft = (i / outWidth) * size
      const xRight = ((i + 1) / outWidth) * size
      const c0 = Math.min(size - 1, Math.floor(xLeft))
      const c1 = Math.min(size, Math.max(c0 + 1, Math.ceil(xRight)))

      let total = 0
      let weight = 0
      for (let r = r0; r < r1; r++) {
        // Partial coverage at the two ends of the band, full in between.
        const rowWeight = Math.min(r + 1, yBottom) - Math.max(r, yTop)
        const wy = r1 - r0 === 1 ? 1 : Math.max(0, rowWeight)
        if (wy <= 0) continue
        for (let c = c0; c < c1; c++) {
          const colWeight = Math.min(c + 1, xRight) - Math.max(c, xLeft)
          const wx = c1 - c0 === 1 ? 1 : Math.max(0, colWeight)
          if (wx <= 0) continue
          total += source[r * size + c] * wy * wx
          weight += wy * wx
        }
      }
      out[j * outWidth + i] = weight > 0 ? total / weight : source[r0 * size + c0]
    }
  }

  return out
}

/**
 * Surface normals from an equirectangular height grid, in the tangent frame described at the top.
 *
 * The slope is measured in METRES OF GROUND, not in texels, and that is the whole subtlety. One
 * texel of longitude is 111 km wide at the equator and 38 km at 70°N, so the same 500 m rise across
 * it is nearly three times the slope in Scandinavia that it is in the Congo. Measure in texels
 * instead and the poles come out implausibly gentle — and plausibly enough that nobody looks twice.
 *
 * THE SAME COSINE BITES BACK AT THE POLES. Past about 80° the texels are crowded closer together
 * than the DEM they were resampled from — thirty metres apart at 89.9°N, from a source nineteen
 * kilometres coarse — so dividing a real height step by that baseline manufactures a cliff. The
 * first bake of this map reported an 87° wall, which is Greenland's three-kilometre ice edge
 * divided by two texels of nothing. So the stencil WIDENS as the texels narrow, holding the
 * measurement over a roughly constant distance of ground at every latitude.
 *
 * Longitude wraps: column 0's western neighbour is the last column. Without that there is a
 * meridian of artificial flatness down the middle of the Pacific.
 *
 * @param {Float32Array} heights  metres, row-major, north-first
 * @returns {Float32Array} unit normals, 3 floats per texel
 */
export const normalsFromHeights = (heights, width, height) => {
  const out = new Float32Array(width * height * 3)
  // Water is opaque; the sea floor is not terrain we can see. See the file header.
  const land = (index) => Math.max(0, heights[index])

  const dySouthM = (Math.PI * EARTH_RADIUS_M) / height
  const equatorTexelM = equirectMetresPerPixel(width, 0)
  // A quarter turn of longitude is as wide a stencil as means anything on a sphere.
  const maxStep = Math.max(1, Math.floor(width / 4))

  for (let j = 0; j < height; j++) {
    const lat = 90 - 180 * ((j + 0.5) / height)
    const texelM = equirectMetresPerPixel(width, lat)
    // However many texels it takes to span the ground one equatorial texel spans.
    const step = Math.min(maxStep, Math.max(1, Math.round(equatorTexelM / Math.max(texelM, 1e-6))))
    const spanEastM = texelM * step
    const rowAbove = Math.max(0, j - 1)
    const rowBelow = Math.min(height - 1, j + 1)
    const rowSpanM = (rowBelow - rowAbove) * dySouthM

    for (let i = 0; i < width; i++) {
      const east = (i + step) % width
      const west = (i - step + width) % width

      const dhdxEast = (land(j * width + east) - land(j * width + west)) / (2 * spanEastM)
      const dhdySouth = (land(rowBelow * width + i) - land(rowAbove * width + i)) / rowSpanM

      // A height field's normal: the gradient, negated, over a unit rise.
      const x = -dhdxEast
      const y = -dhdySouth
      const length = Math.hypot(x, y, 1)
      const k = (j * width + i) * 3
      out[k] = x / length
      out[k + 1] = y / length
      out[k + 2] = 1 / length
    }
  }

  return out
}

/**
 * Pack unit normals into bytes, the way the shader's `texture * 2 - 1` reads them back.
 *
 * Note what flat ground encodes to: 128, which decodes to 0.0039 rather than 0. That residue is
 * uniform across every ocean on earth, so it shows up not as noise but as a constant tilt — and a
 * constant tilt is a constant brightness offset. Rounding is what keeps it inside half a level.
 */
export const encodeNormals = (normals) => {
  const bytes = new Uint8Array(normals.length)
  for (let k = 0; k < normals.length; k++) {
    bytes[k] = clamp(Math.round((normals[k] * 0.5 + 0.5) * 255), 0, 255)
  }
  return bytes
}

/**
 * How much of the relief term survives at the camera's current height.
 *
 * The cloud deck already learned this the expensive way: a texture tuned for the globe becomes a
 * smooth wash on approach and NOTHING ERRORS. A normal map is worse than a cloud field when it
 * runs out of pixels, because what is left is a low-frequency bulge laid over imagery that is
 * still sharp — and the satellite imagery underneath already carries its own baked-in relief
 * shading by then, so the term is not merely soft, it is redundant.
 *
 * The fade is driven by the only thing that matters: how many texels one screen pixel covers. Both
 * the screen scale and the equirectangular texel carry the same cos(lat), so the ratio does not
 * depend on latitude, and the relief retires at the same zoom over Norway as over Kenya.
 *
 * @param {number} zoom          MapLibre zoom
 * @param {number} lat           latitude at the centre
 * @param {number} textureWidth  width of the baked normal map
 */
export const reliefDetailFor = (zoom, lat, textureWidth) => {
  /**
   * MapLibre's globe draws at exactly HALF the mercator scale for a given zoom. Measured, not
   * assumed: 4,315 m per device pixel at z4 over 28°N against the formula's 8,638, and the same
   * 0.500 ratio again at z8. The first version of this used the mercator figure and was therefore
   * wrong by a factor of two about how magnified the map was — which is a whole LOD level.
   */
  const metresPerPixel = 156543.03392 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom) / 2
  const pixelsPerTexel = equirectMetresPerPixel(textureWidth, lat) / metresPerPixel

  /**
   * How much relief survives that magnification — measured off the framebuffer, not guessed.
   *
   * Two quantities behave completely differently, and conflating them is what made the first
   * version of this fade far too aggressive:
   *
   *   pixels per texel      1     2     4     8    16
   *   amplitude          11.1  11.5  11.5  11.5  12.3    how hard the ground is shaded
   *   detail              6.90  4.17  2.31  1.21  0.67    how much survives at the pixel scale
   *
   * AMPLITUDE DOES NOT DECAY. A magnified normal map shades the ground by exactly the same amount;
   * it just does it smoothly. So the planet goes on reading as a lit planet rather than a lit ball
   * at every magnification, and there is no cliff to fall off — detail simply halves per doubling,
   * a clean 1/n trade with no threshold in it anywhere.
   *
   * The old curve retired the effect entirely by z7.5, which measurement says was throwing away
   * something still carrying full amplitude and a fifth of its detail. This holds full strength to
   * 8 pixels per texel and lets go by 32.
   */
  return {
    pixelsPerTexel,
    strength: 1 - smoothstep(3, 5, Math.log2(Math.max(pixelsPerTexel, 1e-6))),
  }
}

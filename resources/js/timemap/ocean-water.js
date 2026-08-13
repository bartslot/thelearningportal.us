/**
 * ocean-water.js — the sea, lit, as a MapLibre custom layer.
 *
 * The single strongest cue that a globe is a photograph rather than a texture. In every picture
 * taken from orbit the ocean is not evenly blue: there is a bright patch where the sun's reflection
 * happens to point at the camera, smeared into a streak by waves, and it moves as the camera does.
 * A flat blue ocean is the giveaway that nothing is being lit.
 *
 * Four things stacked, in the order the eye reads them:
 *
 *  1. WATER COLOUR. The base imagery's ocean is near-black on purpose (see map-imagery.js: the
 *     bathymetry variant drew the sea floor, which is not something you can see from orbit). This
 *     paints it back as water — one deep colour, no terrain in it.
 *  2. DEPTH. Water absorbs red within metres and blue only after tens of them, so a shelf and the
 *     open ocean are the same water at two path lengths — not two paints. Beer-Lambert per channel
 *     over twice the depth gives the real turquoise-to-navy gradient, and the greens in between,
 *     which a two-colour lerp can never do: it always passes through a muddy average on the way.
 *     It also overrides a defect in the imagery rather than inheriting it — Blue Marble treats the
 *     coast as a cliff and hillshades a shadow ONTO the water, so its coastal sea reads DARKER than
 *     open ocean (4.8 luma against 8.9) when reality is the exact opposite.
 *  3. SKY REFLECTION. Water reflects about 2% of the light when you look straight down and nearly
 *     all of it at a glancing angle (Fresnel), which is why the sea toward the limb goes bright
 *     silver-blue while the sea below the camera keeps its own colour. It is the single biggest
 *     reason a flat-tinted ocean looks fake — the tint is uniform, and real water never is.
 *     Note what this term is NOT: it is not what makes deep water blue. Looking straight down you
 *     get only 2% of the sky, and the blue you see is light scattered back out of the water itself.
 *     That is u_scatter, and getting it too dark is what made this layer read as a hole.
 *  4. SUN GLINT. The bright patch where the sun's own reflection points at the camera, smeared by
 *     waves into the glitter path. Water is a MIRROR, so the highlight sits where the surface normal
 *     bisects the directions to the sun and to the eye — Blinn-Phong's half vector, which on a
 *     sphere lands in exactly one place. It is a ROUGH mirror, and the wave facets spread that point
 *     into an elongated streak (Cox-Munk). Two lobes stand in for the roughness distribution: a
 *     tight one for the core and a broad one for the smear, which is cheaper than a real
 *     distribution and indistinguishable at this scale.
 *
 * WHERE THE SEA IS — WHICH IS NOT WHAT DEPTH SAYS. It is tempting to drop the coastline entirely
 * once there is bathymetry and call water "wherever elevation is below zero". Measured on the
 * terrarium DEM, that floods the Netherlands: Flevoland reads −5.7 m and the Zuidplaspolder −7.4 m,
 * both of them dry land, one of them a province. The Dead Sea shore reads −414.9 m. Depth says how
 * deep water IS, never where it is, and for this product in particular that distinction is not a
 * corner case.
 *
 * So extent comes from a texture of its own. It is a SIGNED DISTANCE FIELD — kilometres to the
 * nearest coast, negative inland — and not a mask, which is what the first attempt used and what
 * made it unusable. A mask holds one bit per texel, so the only thing bilinear filtering can do
 * between two texels is ramp across the whole texel, and every shore came out of the magnifier as
 * squares. A distance field is smooth, so interpolation reconstructs the coastline as a LINE
 * through the texel: sharp at any magnification, and what a coarse bake costs is the shape of the
 * coast rather than the crispness of it. See scripts/build-ocean-sdf.py.
 *
 * PROJECTION. MapLibre v5 hands `render` an options object, not a matrix, and the way to project is
 * its own prelude: `projectTileFor3D(vec2 mercator01, float elevation)`. The prelude changes with
 * the projection, so one program is compiled per `shaderData.variantName` and cached under it.
 */

import maplibregl from 'maplibre-gl'
import {
  buildSphereMesh, buildProgram, cameraInPlanetSpace, cameraAltitudeMetres,
  EQUIRECT_GLSL, NOISE_GLSL, SHELL_PROJECT_GLSL, FACING_CAMERA_GLSL, TERMINATOR_GLSL, isWebGL2,
} from './planet-mesh.js'
import { OCEAN_SDF_RANGE_KM, OCEAN_SDF_SOURCES, OCEAN_SDF_DECODE_GLSL } from './ocean-sdf-manifest.js'

const LAYER_ID = 'tm-ocean'

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
  // Sea level: the glint belongs on the water, not above it.
  gl_Position = projectShell(a_pos, a_elevation_globe, a_elevation_mercator);
}`

const fragmentSource = () => `precision highp float;
varying vec3 v_sphere;
uniform vec3 u_camera;      // camera in planet space, earth = unit sphere
uniform vec3 u_sun;         // direction TO the sun
uniform float u_globeness;  // 1 on the globe, 0 on the flat map
uniform sampler2D u_field;  // signed distance to the coast, equirectangular
uniform float u_rangeKm;    // kilometres at which that field saturates
uniform float u_shoreKm;    // half-width of the shoreline transition
uniform float u_shelfKm;    // how far out the shallow-water colour reaches
uniform float u_strength;   // glint intensity
uniform float u_roughness;  // 0 glassy, 1 whipped up — the sea's AVERAGE state
uniform float u_windPatch;  // how far the local sea state strays from that average
uniform float u_windScale;  // cycles of weather per unit sphere
uniform vec3 u_scatter;     // the water body's own colour — what deep water converges to
uniform vec3 u_bottom;      // sea-floor albedo, seen only where the water is shallow enough
uniform vec3 u_absorption;  // how fast each channel is absorbed, per metre of water
uniform float u_shelfDepthM; // depth the shelf proxy reaches at its outer edge
uniform vec3 u_sky;         // what the water mirrors at a glancing angle
uniform float u_water;      // how strongly the water colour is painted at all
uniform float u_fade;       // 1 from orbit, 0 close up where real imagery takes over
uniform float u_opacity;    // master
// Sangil Lee's reflectRatio remap, water -> 0.3 * water + 0.1, requested for evaluation. It stops a
// BINARY mask reading as a cut-out by giving land a little sheen and capping the sea below a full
// mirror. Measured here at 1: the Sahara gains 11.1 blue while losing 7.9 red, and the Taklamakan
// darkens by 12.8 luma — ocean colour on desert hundreds of kilometres from any coast, because a
// floor of 0.1 means no fragment ever discards. It softens an edge this layer no longer has, the
// distance field having put the coastline inside the texel. 0 by default; turn it up to see it.
uniform float u_edgeRemap;
${EQUIRECT_GLSL}
${NOISE_GLSL}
${FACING_CAMERA_GLSL}
${TERMINATOR_GLSL}
${OCEAN_SDF_DECODE_GLSL}

/**
 * The local sea state — how rough the water is HERE, rather than everywhere at once.
 *
 * A single roughness makes the glitter path a smooth analytic blob, and that is the tell that it
 * was computed rather than photographed. In a real orbital picture the glint is mottled: streaks
 * and calm patches inside the bright area, where wind and slicks have left the surface rougher or
 * smoother than its average.
 *
 * The perturbation is on ROUGHNESS, not on the normal. Displacing the normal at wave scale is the
 * usual trick and it is meaningless here: one screen pixel covers twenty to forty kilometres from
 * orbit, so wave facets are millions to a pixel and any wave-scale noise aliases into static. What
 * genuinely survives at this scale is the weather, which varies over tens to hundreds of
 * kilometres — low frequency, so it neither aliases nor needs a screen-space derivative to tame.
 */
float seaState(vec3 p) {
  float weather = noise(p * u_windScale) * 0.65 + noise(p * u_windScale * 2.7) * 0.35;
  return clamp(u_roughness + u_windPatch * (weather - 0.5) * 2.0, 0.04, 1.0);
}

// ── The two source seams ──────────────────────────────────────────────────────────────────────
// Everything below this pair is lighting and knows nothing about where the data came from. These
// two functions are the ONLY places that touch a texture, so moving from one baked equirectangular
// field to streamed z/x/y tiles is a change to these and to nothing else. Keep it that way: a
// texture fetch that creeps into the lighting is a second place the source has to be swapped, and
// it will be found the hard way.

/** Kilometres to the nearest coast, negative inland. */
float coastDistanceKm(vec3 unitPos) {
  return oceanDistanceKm(texture2D(u_field, equirectUV(unitPos, 0.0)).r, u_rangeKm);
}

/**
 * How deep the water is, in metres.
 *
 * Today this is a shelf profile inferred from distance to the coast — a PROXY, and a defensible one
 * at this scale, because a real shelf does broadly track the coastline. Squared so it deepens slowly
 * inshore and then falls away, which is the shape of a real shelf and its break.
 *
 * THE SOURCE SWAPS HERE AND NOWHERE ELSE. The terrarium DEM this map already streams carries
 * bathymetry as negative elevation — measured: −32 m in the North Sea, −21 m on Dogger Bank,
 * −3,415 m mid-Atlantic — so real depth costs no new data pipeline, only the half of that tile the
 * relief pass throws away. When it arrives, this function body is the whole change.
 *
 * WHERE ZERO IS, IF THE DEPTH EVER ARRIVES BYTE-ENCODED. It does not from the tile pyramid, which
 * carries signed height in metres with no encode step — so there is nothing to undo there, and
 * applying a decode would introduce the very bias one exists to remove. The hazard is real for any
 * BAKED byte channel: a field encoded as x*0.5+0.5 lands zero on byte 127.5, which rounds to 128,
 * and a decoder reading texel*2-1 returns 0.0039 rather than 0. On terrain normals that is a
 * constant tilt worth one 8-bit level. Here it would be 0.784 m of false depth at the shoreline,
 * where the ramp is steepest at 92 levels per metre — 51.6 levels of colour error along every coast
 * on the planet, fifty times what it costs the normals.
 *
 * The coastline field above does not have this problem and must not be "fixed" to match: its
 * encode and decode are both centred on 127.5, so they agree, and recentring only the decode would
 * introduce the very bias this warns about. Test-pinned both ways.
 *
 * WHAT THE SOURCE HAS TO DELIVER: metres over 0 to −200 m, and nothing past that. The colour model
 * saturates — 200 m sits 0.015 of an 8-bit level from open ocean, 1,000 m sits 1e-16 — so range
 * beyond it is wasted, while the first 50 m carries over nine tenths of the signal at up to 92
 * levels per metre. Eight bits spread linearly across that range band by 72 levels at the surface;
 * sixteen band by 0.28. See docs/globe-overlay-resolution.md for the table and for why block
 * compression is not safe on this channel.
 *
 * IT RETURNS A VALUE, AND IT MUST STAY THAT WAY. Hillshade the sea floor and the Atlantic becomes a
 * bathymetric chart: ridges and abyssal plains are not visible through three kilometres of water,
 * and drawing them reads as a map rather than an ocean. Depth belongs in the colour, under a lit
 * surface. This function may sample depth; it may never differentiate it.
 */
const float OPEN_OCEAN_M = 400.0;   // past the colour model's saturation at ~200 m

float waterDepthMetres(vec3 unitPos, float coastKm) {
  // Linear, and fitted rather than chosen. Sampling terrarium against distance-to-coast over five
  // shelves gives a median of 3.7 m at 4 km out, 8.1 m at 10 km and 14.2 m at 22 km — near enough
  // a straight line, and nothing like the 90 m an earlier squared profile reached at the same
  // distance. That overshoot is what turned the shelf into a thin bright rim around every coast
  // instead of a band: the water went from sand to open ocean within a few kilometres.
  float t = clamp(max(coastKm, 0.0) / u_shelfKm, 0.0, 1.0);
  float depth = u_shelfDepthM * t;

  // Past the shelf band, fall away to open ocean over the rest of the field's range. Without this
  // every sea beyond 22 km sits at shelf depth and the Atlantic renders as a shallow bank, never
  // reaching the deep colour the whole model is calibrated to. The distance field saturates at 32
  // km, so this is the last thing it can still tell us apart.
  //
  // It is also where the proxy is most obviously a proxy: a 32 km field cannot tell the North Sea
  // from the Atlantic, and this resolves that ambiguity toward deep, which is right for the 95% of
  // the ocean that IS deep and wrong for the shallow shelf seas. Real bathymetry ends the guess.
  depth = mix(depth, OPEN_OCEAN_M, smoothstep(0.72, 1.0, max(coastKm, 0.0) / u_rangeKm));

  // ABOVE THE MERCATOR LIMIT, SATURATE RATHER THAN SAMPLE.
  //
  // A STAND-IN, to be deleted at integration. The pyramid ships tiledSphereCoverage(vec3) for
  // exactly this, and three layers each choosing their own fade latitude is how one globe ends up
  // with the same edge drawn in three places. Multiply the ramp by that instead of this, and take
  // the argument about where the fade belongs to them rather than settling it here.
  //
  // Mercator stops at 85.0511°, so a tiled source has nothing past it — every sample clamps to the
  // same edge row. Under a colour ramp a smeared row does not read as blur, it reads as a band of
  // sea changing shade along a latitude line, which is the one artefact this layer cannot afford.
  //
  // It costs nothing to refuse, because there is no shallow water up there to lose. Measured on the
  // baked field: north of 85° is 100% water and EVERY texel sits at the field's saturated distance,
  // with 0.0% inside the shelf band; south of 85° is 0% water, being Antarctica. The ramp is already
  // constant at both poles, so forcing it constant changes nothing today and makes the swap to
  // tiles safe. The sphere mesh reaches ±89.999°, so without this the smear would be drawn.
  //
  // THE ARCTIC CAP IS A CONSTANT, AND IT IS EXACT RATHER THAN A COMPROMISE.
  //
  // Past 85.0511° mercator has no tiles and never will, so a tiled depth source has nothing to say
  // there. The cap is 0.191% of the earth and 100% water — no coastline in it, no land/water call
  // to make — but that alone does NOT license a constant, and the tile session was right to say so:
  // the basin runs from the Lomonosov crest down to −4393 m, and flattening that would be the same
  // class of error as flattening Antarctica for a shading term.
  //
  // It is licensed by something else. This ramp saturates at 200 m, so the whole range up there is
  // already one colour: 700 m against 4393 m differs by 3e-11 of an 8-bit level, and every depth
  // past 250 m sits within 0.002 of a level of this fill. A constant is not an approximation of the
  // Arctic bathymetry here — it is indistinguishable from having all of it.
  //
  // THE CONDITION, since it is the thing that would quietly stop being true: this holds only while
  // nothing above 85°N is shallower than about 250 m. Measured, 0.0% of the water up there is
  // within the shelf band and the shallowest known feature is around 700 m, so the margin is wide.
  // A ramp that ever gained deep-water structure — a bathymetric mode, say — would break it.
  //
  // Only the north needs saying. The south is land, and the coastline field discards it before this
  // function is reached, which is what carrying extent separately buys.
  const float SIN_FADE_START = 0.994522;   // 84°, one degree of run-up; tiledSphereCoverage replaces this
  const float SIN_MERCATOR_EDGE = 0.996272;
  const float ARCTIC_BASIN_M = OPEN_OCEAN_M;
  float polar = smoothstep(SIN_FADE_START, SIN_MERCATOR_EDGE, unitPos.y);
  return mix(depth, max(depth, ARCTIC_BASIN_M), polar);
}

/**
 * The colour of the water column itself, from how far light gets through it.
 *
 * NOT a lerp between a shallow colour and a deep one. Water absorbs light at wildly different rates
 * by wavelength — red is gone within a couple of metres, blue penetrates tens — so the turquoise of
 * a shelf and the navy of open ocean are the SAME water at two path lengths, not two paints. Beer-
 * Lambert per channel gets that for free, and gets the intermediate greens right, which is what a
 * two-colour lerp can never do: it always passes through a muddy average on the way.
 *
 * Light goes down, bounces off the bottom, and comes back up, so the path is twice the depth,
 * lengthened again when the water is seen at a slant. What survives that trip is the bottom; what
 * does not is replaced by the water's own scattered colour, which is what deep water converges to.
 */
vec3 waterColumn(float depthM, float facing) {
  float path = 2.0 * depthM / max(facing, 0.25);
  vec3 transmitted = exp(-u_absorption * path);
  return mix(u_scatter, u_bottom, transmitted);
}

void main() {
  vec3 normal = normalize(v_sphere);

  // The mesh is a closed sphere, so the far side covers the same pixels as the near side. Reject it
  // here rather than with the depth buffer: a shell sitting on a 6371 km sphere cannot be separated
  // from the globe's own tiles by depth at orbital range, and testing anyway breaks the layer into
  // a flickering quilt of tile-shaped patches. See FACING_CAMERA_GLSL.
  if (u_globeness > 0.5 && !facesCamera(normal, u_camera)) discard;

  float km = coastDistanceKm(normal);
  // u_shoreKm tracks the screen pixel, so this transition is always about a pixel and a half wide:
  // crisp on approach, and self-antialiasing at globe zoom where the coast is far below a texel.
  float water = smoothstep(-u_shoreKm, u_shoreKm, km);
  // Requested remap, off by default — see the u_edgeRemap note above for what it measures.
  water = mix(water, 0.3 * water + 0.1, u_edgeRemap);
  if (water < 0.004) discard;                    // land: leave the imagery alone

  vec3 view = normalize(u_camera - normal);
  float facing = max(dot(normal, view), 0.0);
  float fresnel = 0.02 + 0.98 * pow(1.0 - facing, 5.0);

  float sunAngle = dot(normal, u_sun);
  float day = daylightFraction(sunAngle);        // shared with the night and the clouds
  float lambert = max(sunAngle, 0.0);

  float depthM = waterDepthMetres(normal, km);
  vec3 body = waterColumn(depthM, facing) * (0.08 + 0.92 * lambert);

  vec3 skyTerm = u_sky * fresnel * day;

  vec3 halfway = normalize(u_sun + view);
  float alignment = max(dot(normal, halfway), 0.0);
  float sea = seaState(normal);
  float sharp = pow(alignment, mix(2400.0, 260.0, sea));
  float broad = pow(alignment, mix(180.0, 26.0, sea));
  // The highlight outlives the water colour on the way down — a square root rather than the fade
  // itself — so it survives the handover to Sentinel-2 instead of blinking out mid-descent.
  float glint = (sharp + 0.35 * broad) * day * u_strength * sqrt(u_fade);
  vec3 sunTerm = mix(vec3(0.85, 0.90, 1.0), vec3(1.0, 0.96, 0.86), fresnel) * glint;

  // ONE fade, in the alpha, and NOT a second one in the colour.
  //
  // The colour used to be scaled by the fade as well as the alpha. Premultiplied
  // output is colour * alpha, so the emitted light fell as fade SQUARED while the coverage fell as
  // fade — mid-handover the layer replaced a third of the imagery with a ninth of its own colour.
  // Measured over the North Sea at z7: Sentinel-2's (25,44,77) came out as (19,34,59). The layer
  // was fading toward BLACK rather than toward the picture underneath, and it darkened the very
  // imagery it hands over to. Alpha alone fades a layer out; scaling the colour too is a hole.
  vec3 colour = body + skyTerm + sunTerm;

  float alpha = clamp(water * (u_water * u_fade + glint), 0.0, 1.0) * u_opacity;
  if (alpha < 0.003) discard;
  // MapLibre blends custom layers with gl.ONE / gl.ONE_MINUS_SRC_ALPHA, i.e. PREMULTIPLIED alpha.
  // Multiplying the colour by the water mask instead of by the alpha is what made an earlier
  // version of this layer glow over dark ground.
  gl_FragColor = vec4(colour * alpha, alpha);
}`

/**
 * How much of the sea this layer paints, at a given camera height.
 *
 * Full from orbit, where the imagery has no ocean colour of its own; gone by the time Sentinel-2
 * has faded in and is showing real water, shelves and turbidity that no shader should be painting
 * over. Smoothstepped rather than linear so neither end of the handover has a visible corner.
 */
export const altitudeFadeFor = (altitudeMetres, { fadeInAbove = 900000, fadeOutBelow = 180000 } = {}) => {
  const t = Math.max(0, Math.min(1, (altitudeMetres - fadeOutBelow) / (fadeInAbove - fadeOutBelow)))
  return t * t * (3 - 2 * t)
}

/**
 * How soft the shoreline should be, in kilometres, at the current zoom.
 *
 * A fixed width cannot work at both ends of the range. Held constant in kilometres it is invisible
 * from orbit and the coast aliases into a crawling staircase as the globe turns; held constant in
 * pixels it would need the screen-space derivative of the field, and `fwidth` means depending on
 * an extension whose absence is a shader that fails to compile and an ocean that never appears.
 * The ground covered by one screen pixel is already known on the CPU, so this is the same answer
 * for free: about a pixel and a half of coast, always.
 */
export const shoreSoftnessKmFor = (zoom, lat, minimumKm = 0.8) => {
  const metresPerPixel = 156543.03392 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom)
  return Math.max(minimumKm, metresPerPixel * 1.5 / 1000)
}

/** Earth's circumference, for turning a field width into the ground one texel covers. */
const EQUATOR_KM = 40075

/**
 * The narrowest honest shoreline for a field of this width.
 *
 * A transition thinner than one texel of the data defining it does not draw a sharper coast — it
 * draws the TEXELS, as a staircase. The 8192 field is one texel per 4.9 km, and the floor here was
 * 0.8 km, so from about z6 inward the edge was asked to be six times finer than anything it knew,
 * and the answer was the jagged coastline.
 *
 * Half a texel, because the transition is a half-width either side of the line: the feather then
 * spans exactly the cell that the coast is known to lie somewhere within, which is the most precise
 * claim the field supports and no more.
 */
export const shoreFloorKmFor = (fieldWidth) =>
  Number.isFinite(fieldWidth) && fieldWidth > 0 ? (EQUATOR_KM / fieldWidth) / 2 : 0.8

/**
 * The widest bake this GPU will accept, or null if even the smallest is too big.
 *
 * MAX_TEXTURE_SIZE is still 4096 on low-end Android. Handing such a device the 8192 field fails the
 * upload, and the failure is silent — an unpainted sea with nothing in the console to explain it.
 */
export const pickSdfSource = (maxTextureSize, sources = OCEAN_SDF_SOURCES) =>
  sources.find((s) => s.width <= maxTextureSize && s.height <= maxTextureSize) || null

/**
 * @param {object} opts
 * @param {number} [opts.opacity]    master; 0 costs nothing at all
 * @param {number} [opts.strength]   glint intensity
 * @param {number} [opts.roughness]  0 glassy, 1 whipped up — widens the glitter path
 * @param {number[]} [opts.sun]      direction TO the sun, planet space; share it with the clouds
 * @param {object[]} [opts.sources]  candidate distance fields, widest first
 * @param {number} [opts.edgeRemap] 0..1 of the Lee reflectRatio remap; 0, and the shader says why
 */
export const createOceanWaterLayer = ({
  opacity = 1,
  strength = 0.9,
  roughness = 0.55,
  // The glitter path is mottled, not a smooth blob. 0 gives one sea state everywhere, which is
  // what makes a computed glint look computed.
  windPatch = 0.3,
  windScale = 14,        // cycles per unit sphere: cells of weather roughly 900 km across
  edgeRemap = 0,         // the Lee reflectRatio remap; see u_edgeRemap for what it measures
  sun = [0.4, 0.5, 0.75],
  water = 1,
  // Clear ocean water, absorption per metre by channel (Smith & Baker / Pope & Fry, near 620,
  // 550 and 450 nm). Red is gone in metres and blue survives tens, which is the whole reason the
  // sea grades turquoise to navy. Coastal water is far more turbid than this; that is not modelled.
  absorption = [0.45, 0.060, 0.020],
  // Open ocean as it reads from orbit: a proper mid blue, not navy. This is the water's own
  // volume-scattering colour — molecules scatter blue back out, which is most of why the sea is
  // blue at all — and it is the floor the ramp converges to once nothing returns from the bottom.
  // Calibrated against Sentinel-2's own North Sea, which measures (25,44,77) off England.
  scatter = [0.120, 0.265, 0.430],
  bottom = [0.42, 0.40, 0.33],      // sand and carbonate: the floor, where it can still be seen
  sky = [0.34, 0.50, 0.76],         // daylight sky, what the sea mirrors near the limb
  shelfKm = 22,                     // how far the shelf proxy reaches before it is open ocean
  shelfDepthM = 16,                 // measured median depth at the shelf band's outer edge
  shoreSoftnessKm = 0.8,
  fadeInAbove = 900000,            // metres: fully painted above this
  fadeOutBelow = 180000,           // metres: gone below this, where real imagery has the detail
  sources = OCEAN_SDF_SOURCES,
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  /** Width of the SDF that actually loaded — sets how fine a shoreline it can honestly draw. */
  let fieldWidth = null
  let buffers = null
  let fieldTexture = null
  let fieldReady = false
  const programs = new Map()
  let state = {
    opacity, strength, roughness, windPatch, windScale, edgeRemap, sun, water,
    absorption, scatter, bottom, sky, shelfKm, shelfDepthM, shoreSoftnessKm,
    fadeInAbove, fadeOutBelow, sources,
  }

  const programFor = (shaderData) => {
    const key = shaderData.variantName
    if (programs.has(key)) return programs.get(key)
    // ES 3.00 wherever the context allows it: the tile pyramid's GLSL is ES 3.00, and the depth
    // field arrives through it. On WebGL1 this is a no-op and the global-texture path is the one
    // in use anyway, since the pyramid reports itself unsupported there.
    const program = buildProgram(gl, vertexSource(shaderData), fragmentSource(), 'tm-ocean', { es300: true })
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
        globeness: gl.getUniformLocation(program, 'u_globeness'),
        field: gl.getUniformLocation(program, 'u_field'),
        rangeKm: gl.getUniformLocation(program, 'u_rangeKm'),
        shoreKm: gl.getUniformLocation(program, 'u_shoreKm'),
        shelfKm: gl.getUniformLocation(program, 'u_shelfKm'),
        strength: gl.getUniformLocation(program, 'u_strength'),
        roughness: gl.getUniformLocation(program, 'u_roughness'),
        windPatch: gl.getUniformLocation(program, 'u_windPatch'),
        windScale: gl.getUniformLocation(program, 'u_windScale'),
        water: gl.getUniformLocation(program, 'u_water'),
        scatter: gl.getUniformLocation(program, 'u_scatter'),
        bottom: gl.getUniformLocation(program, 'u_bottom'),
        absorption: gl.getUniformLocation(program, 'u_absorption'),
        shelfDepthM: gl.getUniformLocation(program, 'u_shelfDepthM'),
        sky: gl.getUniformLocation(program, 'u_sky'),
        fade: gl.getUniformLocation(program, 'u_fade'),
        opacity: gl.getUniformLocation(program, 'u_opacity'),
        edgeRemap: gl.getUniformLocation(program, 'u_edgeRemap'),
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

  /**
   * A single texel reading "far inland", uploaded before anything is fetched.
   *
   * Without it the first frames have no field to sample and the layer would have to skip drawing
   * until one arrives — one more branch, and a state the contract tests could not reach. Starting
   * at "everywhere is land" also fails in the right direction: the frames before the field lands
   * are simply the map, where an all-sea placeholder would flash a blue planet on every load.
   */
  const uploadPlaceholder = () => {
    fieldTexture = gl.createTexture()
    gl.bindTexture(gl.TEXTURE_2D, fieldTexture)
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.LUMINANCE, 1, 1, 0, gl.LUMINANCE, gl.UNSIGNED_BYTE,
      new Uint8Array([0]))
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
  }

  const upload = (bitmap) => {
    if (!gl) return
    if (fieldTexture) gl.deleteTexture(fieldTexture)
    fieldTexture = gl.createTexture()
    gl.bindTexture(gl.TEXTURE_2D, fieldTexture)
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false)

    // NOTE: this asks isWebGL2 a DIFFERENT question from the one the shader asks it. There the
    // answer picks a GLSL dialect; here it picks a texture format. In production they coincide, but
    // they are independent decisions, and forcing the ES 1.00 shader path on a WebGL2 context to
    // compare the two also drags the upload to LUMINANCE — whose mipmaps are invalid on WebGL2, so
    // the field silently reads black and the sea vanishes. That is a debugging trap, not a bug.
    //
    // One byte per texel, not four: the 8192 field is 33 MB as single-channel and 134 MB as RGBA.
    // WebGL2 needs the SIZED format for that — generateMipmap there requires a colour-renderable
    // format, and unsized LUMINANCE is not one, so the failure would land on the mipmap rather
    // than on the upload. WebGL1 has no R8, and LUMINANCE is fine.
    if (isWebGL2(gl)) {
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.R8, gl.RED, gl.UNSIGNED_BYTE, bitmap)
    } else {
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.LUMINANCE, gl.LUMINANCE, gl.UNSIGNED_BYTE, bitmap)
    }

    // Wraps in longitude, clamps at the poles: REPEAT on T would fold the Arctic coastline onto
    // the Antarctic one at the seam.
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR)
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
    // Averaging distances is very nearly the distance field of the coarser coast, so an SDF
    // mipmaps honestly — which is what stops the shoreline crawling at globe zoom.
    gl.generateMipmap(gl.TEXTURE_2D)
    fieldReady = true
    map?.triggerRepaint()
  }

  /**
   * Fetch and decode the field.
   *
   * `createImageBitmap` rather than `new Image()`, for decode timing: an 8192x4096 image decoded on
   * the main thread stalls it for a visible beat, and a hidden document may not decode an `<img>`
   * at all until something paints it — which is a page that never finishes loading in any automated
   * pane. This decodes off-thread and on demand.
   *
   * `colorSpaceConversion: 'none'` says "these are numbers, not colours", since a browser is free to
   * colour-manage a decoded image and a level here is 125 m of coastline. MEASURED, it changes
   * nothing: both decode paths were read back through a framebuffer and agreed on all 131,072
   * texels tested, these files carrying no ICC profile. It is kept as cheap insurance against a
   * tagged file or a wide-gamut display, not because anything is known to need it.
   *
   * WHEN THE BRANCHES MEET this should become `acquireEquirectTexture` from equirect-texture.js —
   * refcounting is the right answer and a third loader is not. The one thing that module would need
   * first is an optional single-channel upload: it uses RGBA, which for the 8192 field is 134 MB of
   * VRAM against 33 MB here, for one byte of actual data per texel.
   */
  const loadField = () => {
    const source = pickSdfSource(gl.getParameter(gl.MAX_TEXTURE_SIZE), state.sources)
    if (!source) return
    const wanted = source.url
    // Which field actually loaded decides how fine an edge it can honestly draw — a device that
    // fell back to 4096 has half the resolution and needs twice the feather.
    fieldWidth = source.width

    fetch(wanted, { credentials: 'omit' })
      .then((response) => (response.ok ? response.blob() : Promise.reject(new Error(response.status))))
      .then((blob) => createImageBitmap(blob, {
        colorSpaceConversion: 'none',
        premultiplyAlpha: 'none',
        imageOrientation: 'none',
      }))
      .then((bitmap) => {
        // setOptions may have moved on while this was in flight; the later request owns the slot.
        if (pickSdfSource(gl?.getParameter(gl.MAX_TEXTURE_SIZE) ?? 0, state.sources)?.url !== wanted) {
          bitmap.close?.()
          return
        }
        upload(bitmap)
        bitmap.close?.()
      })
      // Decorative: a failed load leaves the map as it was, never a broken globe.
      .catch(() => {})
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
      uploadPlaceholder()
      loadField()
    },

    // Without this every style reload leaks a program, a texture and three buffers.
    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
      if (fieldTexture) { gl.deleteTexture(fieldTexture); fieldTexture = null; fieldReady = false }
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
      if (uniforms.matrix) gl.uniformMatrix4fv(uniforms.matrix, false, projection.mainMatrix)
      if (uniforms.tileMercatorCoords) gl.uniform4f(uniforms.tileMercatorCoords, ...projection.tileMercatorCoords)
      if (uniforms.clippingPlane) gl.uniform4f(uniforms.clippingPlane, ...projection.clippingPlane)
      if (uniforms.transition) gl.uniform1f(uniforms.transition, projection.projectionTransition)
      if (uniforms.fallbackMatrix) gl.uniformMatrix4fv(uniforms.fallbackMatrix, false, projection.fallbackMatrix)

      // Right on the surface. Nothing is lifted to dodge the depth buffer — the shader rejects the
      // far hemisphere itself — and lifting it would slide the glint off the water it belongs to.
      const centre = map.getCenter()
      const LIFT_M = 0
      if (uniforms.elevationGlobe) gl.uniform1f(uniforms.elevationGlobe, LIFT_M)
      if (uniforms.elevationMercator) {
        gl.uniform1f(uniforms.elevationMercator, maplibregl.MercatorCoordinate.fromLngLat([0, centre.lat], LIFT_M).z)
      }
      if (uniforms.camera) gl.uniform3f(uniforms.camera, ...cameraInPlanetSpace(map, maplibregl))
      if (uniforms.sun) gl.uniform3f(uniforms.sun, ...state.sun)
      if (uniforms.globeness) gl.uniform1f(uniforms.globeness, projection.projectionTransition)
      if (uniforms.field) {
        gl.activeTexture(gl.TEXTURE0)
        gl.bindTexture(gl.TEXTURE_2D, fieldTexture)
        gl.uniform1i(uniforms.field, 0)
      }
      if (uniforms.rangeKm) gl.uniform1f(uniforms.rangeKm, OCEAN_SDF_RANGE_KM)
      if (uniforms.shoreKm) {
        // The floor is whichever is coarser: the caller's setting, or one texel of the field that
        // actually loaded. Asking for an edge finer than the data draws the texels, not the coast.
        gl.uniform1f(uniforms.shoreKm, shoreSoftnessKmFor(
          map.getZoom(), centre.lat,
          Math.max(state.shoreSoftnessKm, shoreFloorKmFor(fieldWidth)),
        ))
      }
      if (uniforms.shelfKm) gl.uniform1f(uniforms.shelfKm, state.shelfKm)
      if (uniforms.strength) gl.uniform1f(uniforms.strength, state.strength)
      if (uniforms.roughness) gl.uniform1f(uniforms.roughness, state.roughness)
      if (uniforms.windPatch) gl.uniform1f(uniforms.windPatch, state.windPatch)
      if (uniforms.windScale) gl.uniform1f(uniforms.windScale, state.windScale)
      if (uniforms.water) gl.uniform1f(uniforms.water, state.water)
      if (uniforms.scatter) gl.uniform3f(uniforms.scatter, ...state.scatter)
      if (uniforms.bottom) gl.uniform3f(uniforms.bottom, ...state.bottom)
      if (uniforms.absorption) gl.uniform3f(uniforms.absorption, ...state.absorption)
      if (uniforms.shelfDepthM) gl.uniform1f(uniforms.shelfDepthM, state.shelfDepthM)
      if (uniforms.sky) gl.uniform3f(uniforms.sky, ...state.sky)
      if (uniforms.opacity) gl.uniform1f(uniforms.opacity, state.opacity)
      if (uniforms.edgeRemap) gl.uniform1f(uniforms.edgeRemap, state.edgeRemap)
      if (uniforms.fade) {
        gl.uniform1f(uniforms.fade, altitudeFadeFor(cameraAltitudeMetres(map, maplibregl), state))
      }

      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.pos)
      gl.enableVertexAttribArray(attribs.pos)
      gl.vertexAttribPointer(attribs.pos, 2, gl.FLOAT, false, 0, 0)
      gl.bindBuffer(gl.ARRAY_BUFFER, buffers.sphere)
      gl.enableVertexAttribArray(attribs.sphere)
      gl.vertexAttribPointer(attribs.sphere, 3, gl.FLOAT, false, 0, 0)

      // No depth test at all — see the horizon rejection in the fragment shader, which replaces it
      // exactly and, unlike the depth buffer, works at orbital range.
      gl.disable(gl.DEPTH_TEST)
      gl.depthMask(false)      // a reflection is not geometry
      gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, buffers.index)
      gl.drawElements(gl.TRIANGLES, mesh.indices.length, gl.UNSIGNED_SHORT, 0)
      gl.enable(gl.DEPTH_TEST)
      gl.depthMask(true)
    },

    /** Live controls — the sun, the sea state, the water colour, and how hard the highlight burns. */
    setOptions(next = {}) {
      const previousSources = state.sources
      state = { ...state, ...next }
      if (next.sources !== undefined && next.sources !== previousSources) {
        fieldReady = false
        if (gl) loadField()
      }
      map?.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    /** True once the real distance field is uploaded; until then the sea is simply not painted. */
    get hasField () { return fieldReady },
  }
}

export const OCEAN_LAYER_ID = LAYER_ID

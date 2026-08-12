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
  buildSphereMesh, buildProgram, cameraAltitudeMetres, cameraInPlanetSpace, EARTH_RADIUS_M,
  EQUIRECT_GLSL, NOISE_GLSL, SHELL_PROJECT_GLSL, TERMINATOR_GLSL,
} from './planet-mesh.js'
import { CLOUD_ALTITUDE_M, CLOUD_FIELD_GLSL, CLOUD_FIELD_UNIFORMS, setCloudFieldUniforms } from './cloud-field.js'
import { acquireEquirectTexture } from './equirect-texture.js'
import { RELIEF_GLSL, TANGENT_FRAME_GLSL } from './terrain-normals.js'

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

  /**
   * The real cloud source's own resolution, at the equator.
   *
   * 2446 m, not the 19543 that stood here: the deck is drawn from harvested MODIS patches at z6
   * rather than from the 2048x1024 whole-planet field, and that is eight times finer. The number is
   * load-bearing rather than descriptive — it decides at what height the source is judged to have
   * run out of pixels, and so how much of the deck the procedural noise is asked to invent. Left at
   * 19543 the noise would take over eight times too early and paint invention over real cloud that
   * is actually there, which is the failure the source replacement exists to end.
   *
   * Derived rather than typed. The patches come from GIBS at zoom 6 of the standard Web Mercator
   * scheme, so their resolution is that scheme's, and writing it as the division keeps the ONE fact
   * behind it — which zoom they were fetched at — visible in the code. Re-harvest at another zoom
   * and this is the single line that moves.
   */
  const SOURCE_ZOOM = 6
  const FIELD_METRES_PER_PIXEL = 156543.03392 / 2 ** SOURCE_ZOOM
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
     *
     * LEFT AT 0.14/0.10, and that is a decision rather than an omission. The cut from 0.56 was made
     * blaming the procedural noise, and Bart has since said the artefact was wind advection curl —
     * so the ceiling was compensating for a cause it never had, and the obvious move is to put it
     * back. It is the wrong move. What was actually broken was WHEN the noise takes over: the
     * crossover was pinned to a source resolution eight times too coarse, so the noise began
     * inventing at about z3 instead of z6 and every complaint about it was made in a range where it
     * should not have been doing anything at all. That constant is now derived from the source (see
     * above), which fixes the cause rather than the symptom.
     *
     * Raising the ceiling on top of that fix would paint invention over real cloud that is now
     * genuinely there, which is the failure this whole change exists to end. The curl, which was the
     * real culprit, is dealt with where it lives: windAmount, in map-globe-layers.js.
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
uniform vec3 u_camera;        // camera in planet space, earth = unit sphere
// The four lighting terms, each zero-is-off so the deck returns exactly to its old look and each
// can be measured on its own. See the lighting block below for what every one of them buys.
uniform float u_cloudRelief;    // how tall fully covered sky stands, in km; 0 disables the normal
uniform float u_cloudDepth;     // optical depth of fully covered sky, for Beer-Powder
uniform float u_powder;         // how much thin edges darken beyond the plain exponential
uniform float u_forward;        // the silver lining: one Henyey-Greenstein lobe
uniform float u_forwardG;       // that lobe's asymmetry, 0 isotropic, ->1 sharply forward
uniform float u_selfShadow;     // one extra mask read offset toward the sun
uniform float u_selfShadowStep; // how far that read walks, in sphere radii
${NOISE_GLSL}
${EQUIRECT_GLSL}
${TERMINATOR_GLSL}
${TANGENT_FRAME_GLSL}
${RELIEF_GLSL}
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
  /**
   * The SHAPE of the cloud, without the texture on it — what the normal is taken from.
   *
   * Differentiation is a high-pass filter, so a normal computed from the drawn coverage is
   * dominated by whatever varies fastest in it. Lit by a normal, that came out as fine directional
   * hatching over the whole deck — brush strokes rather than cloud, unmissable over Ireland and
   * invisible at globe zoom. Turning this one term off was what identified it.
   *
   * IT TOOK THREE GOES, and the two failures are the useful part. Bounding the slope did almost
   * nothing: the trouble was never the size of the gradient. Removing the procedural noise from it
   * did nothing either, which ruled out the obvious culprit. What is left is the atlas itself — at
   * this range it is sampled at roughly one texel per pixel, and a screen-space derivative of a
   * field varying at the pixel scale is not a measurement of anything. Mipmapping does not save it,
   * because minification picks the level where one texel IS one pixel, so the estimate sits at
   * Nyquist at every zoom the deck is drawn at.
   *
   * So the shape comes from the whole-planet field alone: 19.5 km per texel, hopeless as texture and
   * ideal here, because it is smooth across a pixel at every zoom and there is nothing left to
   * alias. It is also the honest division of labour — light the weather SYSTEMS, whose tops really
   * do catch the sun, and leave the fine structure as what it is, which is texture.
   */
  float shapeCoverage;
  // Either real source will do here — the question is only whether there is real weather to draw at
  // all, or whether the deck falls back to latitude-banded noise. Asking about the global field
  // alone would put procedural cloud on a build where the atlas had loaded and it had not.
  if (u_fieldAmount > 0.0 || u_patchAmount > 0.0) {
    // Real weather. The texture decides WHERE cloud is; the noise decides what its edges look
    // like — and as the field runs out of resolution, u_detailAmount hands the noise more of the
    // job, until at close range it is carrying the structure on its own.
    vec2 uv = equirectUV(p, u_drift);
    float field = advectedField(uv, asin(clamp(p.y, -1.0, 1.0)));
    coverage = smoothstep(0.16, 0.62, field + u_detailAmount * (detail - 0.5));
    // One extra tap, straight from the smooth global field. Where it is absent the atlas is all
    // there is, and the hatching comes back — which is the honest trade, not a hidden one.
    shapeCoverage = u_fieldAmount > 0.0
      ? smoothstep(0.16, 0.62, texture(u_field, uv).r)
      : smoothstep(0.16, 0.62, field);
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
    // No real field to fall back on here, so the noise is all there is to take a shape from.
    shapeCoverage = coverage;
  }

  // ── Lighting ──────────────────────────────────────────────────────────────────────────────
  // A cloud emits nothing. It is only ever as bright as what is falling on it, so on the night
  // side it has to go dark along with the ground — a white deck glowing over a black planet is the
  // tell of clouds pasted on as decoration rather than lit as part of the scene.
  vec3 sunDir = normalize(u_sun);
  float sunAngle = dot(p, sunDir);
  float day = daylightFraction(sunAngle);

  // ── 1. Shape, from the mask's own gradient ────────────────────────────────────────────────
  //
  // CLOUD COVERAGE IS A HEIGHTFIELD — thicker cloud is taller cloud — so the deck can have a real
  // normal, and cyclone tops catch the light while their far sides fall into shade. It is the
  // single biggest thing separating cloud from a grey wash, and it is the relief pipeline the
  // ground already uses with a different input: the same tangent frame, the same difference
  // formulation, so a flat deck is left exactly as it was and the effect is naturally strongest at
  // the terminator.
  //
  // THE GRADIENT IS FREE. Three more samples of the field would be twenty-seven more texture reads
  // per pixel, on a layer that already costs nine. Screen-space derivatives cost nothing and are
  // better evidence besides: they are the derivative of the coverage ACTUALLY DRAWN, noise and
  // advection included, rather than of an approximation to it. dp/dx and dp/dy span the surface at
  // this pixel, so two scalar derivatives fix the surface gradient exactly.
  vec3 dpdx = dFdx(v_sphere);
  vec3 dpdy = dFdy(v_sphere);
  float dcdx = dFdx(shapeCoverage);
  float dcdy = dFdy(shapeCoverage);

  mat3 frame = equirectTangentFrame(p);
  vec2 stepX = vec2(dot(dpdx, frame[0]), dot(dpdx, frame[1]));
  vec2 stepY = vec2(dot(dpdy, frame[0]), dot(dpdy, frame[1]));
  float det = stepX.x * stepY.y - stepX.y * stepY.x;

  vec3 cloudNormal = frame[2];
  // A degenerate quad — the limb, a silhouette edge — has no surface to take a gradient across, and
  // solving anyway puts an arbitrary normal exactly where the deck is most visible.
  if (abs(det) > 1.0e-12) {
    vec2 slope = vec2(dcdx * stepY.y - dcdy * stepX.y, dcdy * stepX.x - dcdx * stepY.x) / det;
    // u_cloudRelief is a HEIGHT IN KILOMETRES — how tall fully covered sky stands — so the slope
    // here is the real one and the control means something a person can picture. A cumulus tops out
    // around 8 km, which is where the default sits; the gradient is per sphere radius, so the
    // conversion is the earth's own.
    slope *= u_cloudRelief / 6371.0;

    /**
     * BOUNDED AT 1.5, which is 56 degrees, and the bound is doing real work rather than guarding an
     * edge case.
     *
     * A screen-space derivative is a 2x2-quad estimate, and it is only honest where the field varies
     * slowly across a quad. Once the source became genuinely sharp that stopped being true: at close
     * range a cloud edge crosses a whole quad, the estimated normal alternates from quad to quad,
     * and the deck came out covered in fine directional hatching — brush strokes rather than cloud.
     * It is invisible at globe zoom and unmissable over Ireland, and turning this term off alone was
     * what identified it.
     *
     * The bound is a statement about cloud rather than about floating point: a real cloud top does
     * not stand steeper than about this, so anything past it was never a slope, it was the estimate
     * falling apart. Magnitude only — the direction is left alone, since that is the part that is
     * still right.
     */
    float steepness = length(slope);
    if (steepness > 1.5) slope *= 1.5 / steepness;
    cloudNormal = normalize(frame * normalize(vec3(-slope, 1.0)));
  }

  // Exactly reliefLightFactor, at power 1: the normal is already scaled above, and the strength
  // belongs there rather than here so that u_cloudRelief == 0 leaves the normal AS the sphere's and
  // this whole term collapses to 1.
  float shape = reliefLightFactor(frame[2], cloudNormal, sunDir, 1.0);

  // ── 2. Beer-Powder, NOT Lambert ───────────────────────────────────────────────────────────
  //
  // The counter-intuitive part, and where naive cloud lighting always fails. Cloud is dominated by
  // MULTIPLE scattering: light bounces its way back out, so thick cloud is BRIGHTER than thin —
  // the opposite of a solid, where thickness is irrelevant and only the angle matters. Lambert on
  // the normal above would give grey rock with weather-shaped bumps.
  //
  // Two exponentials. The first is the saturating rise of multiple scattering. The second is the
  // powder term, which takes MORE away from the thin edges than the first alone does, because at
  // low optical depth the in-scattering has not had the depth to build up. Without it a cloud's
  // rim fades out linearly and reads as painted-on fog.
  float depth = 1.0;
  if (u_cloudDepth > 0.0) {
    float tau = u_cloudDepth * coverage;
    depth = (1.0 - exp(-tau)) * mix(1.0, 1.0 - exp(-2.0 * tau), u_powder);
    // Against a fully covered pixel, so the term changes the RELATIONSHIP between thickness and
    // brightness rather than acting as a second density dial — turning the depth up must not simply
    // brighten the whole deck.
    float full = (1.0 - exp(-u_cloudDepth)) * mix(1.0, 1.0 - exp(-2.0 * u_cloudDepth), u_powder);
    depth /= max(full, 1.0e-3);

    /**
     * A FLOOR, and 0.65 rather than something near zero — because the term was double-counting.
     *
     * Thin cloud is more TRANSPARENT, and alpha already says so: coverage drives it directly. Using
     * the same thinness to make the cloud DARKER as well charges twice for one fact, and the second
     * charge is the wrong one. A deck at low brightness still writes alpha, so it subtracts from the
     * ground: wisps came out as a dark veil over the sea instead of white against it, and Map works
     * read the whole deck as grey rather than white.
     *
     * Measured, the thinnest quarter went to -0.07 of the brightness it should have had, and then to
     * -0.40 once the global distribution put more genuinely-thin cloud on screen. Not dim. INVERTED
     * — darker than the surface beneath, which no cloud is.
     *
     * Real thin cloud is grey-white, never black, because it scatters skylight from every direction
     * whether or not the sun reaches it. So the powder term keeps its job — thin edges read darker
     * than thick centres, which is what stops a cloud looking like painted-on fog — over a range
     * that stays above the sea rather than going through it.
     */
    depth = mix(0.65, 1.0, depth);
  }

  // ── 4. Self-shadowing ─────────────────────────────────────────────────────────────────────
  //
  // One extra read of the mask, offset toward the sun: whatever cloud is over there is what stands
  // between this cloud and the light, and that is what gives the underside real depth rather than
  // an evenly lit blanket. Gated on the uniform because it is the only term here that costs
  // another nine texture reads, so it is the first thing a slower machine should drop.
  float shadow = 1.0;
  if (u_selfShadow > 0.0) {
    vec3 toward = normalize(p + sunDir * u_selfShadowStep);
    float over = advectedField(equirectUV(toward, u_drift), asin(clamp(toward.y, -1.0, 1.0)));
    // Fades out as the sun drops: at grazing light the offset walks most of the way round the
    // planet and what it finds has nothing to do with this pixel.
    shadow = 1.0 - u_selfShadow * smoothstep(0.16, 0.62, over) * smoothstep(0.0, 0.35, sunAngle);
  }

  // The deck's own modelling: a lit face and a shaded face, like the real thing — but cloud is
  // WHITE, and its shaded face is white in shadow, not grey paint. The old pair (0.62,0.66,0.72
  // lifted from 0.35) left everything away from the subsolar point reading as blue-grey smoke,
  // which at high latitude is most of what is on screen. Bright, barely-tinted shadow instead.
  vec3 base = mix(vec3(0.86, 0.88, 0.92), vec3(1.0), 0.55 + 0.45 * max(sunAngle, 0.0));

  // Shape and depth multiply the light the top returns; the shadow takes some of it away. All three
  // are 1 at their off settings, so the deck is bit-for-bit its old self with the strengths at zero.
  base *= clamp(shape * depth * shadow, 0.0, 4.0);

  // At the terminator the light reaching them has crossed the most air and lost its blue, so the
  // tops go orange while the ground below is already dark. It is the best thing clouds do.
  float twilight = 1.0 - abs(day * 2.0 - 1.0);
  base = mix(base, base * vec3(1.30, 0.74, 0.44), twilight * twilight * 0.85);

  // ── 3. Forward scattering — the silver lining ─────────────────────────────────────────────
  //
  // Cloud droplets are far larger than the wavelength, so they throw light overwhelmingly FORWARD:
  // a cloud between you and the sun is brighter at its rim than anywhere on its lit face. One
  // Henyey-Greenstein lobe and a single dot product buys it, which is the best payoff per
  // instruction anywhere in this shader.
  //
  // It lands hardest at the terminator, because that is where the sun sits behind the cloud from
  // the camera's point of view — the same geometry that already makes this globe's best picture.
  //
  // ADDED, not multiplied. Forward scattering is light arriving by a path the diffuse term does not
  // model at all, so folding it into a multiplier would make a rim brighter only where the cloud
  // was already bright, which is precisely backwards.
  if (u_forward > 0.0) {
    vec3 toEye = normalize(u_camera - p);
    // Angle between where the photon was going and where it is going now. dot(toEye, -sunDir) is 1
    // when it carries straight on toward the camera, which is the forward peak.
    float phase = -dot(toEye, sunDir);
    float g = clamp(u_forwardG, 0.0, 0.95);
    float gg = g * g;
    float lobe = (1.0 - gg) / pow(max(1.0 + gg - 2.0 * g * phase, 1.0e-4), 1.5);
    // Normalised by its own peak, (1+g)/(1-g)^2, so u_forward is a strength in 0..1 rather than a
    // number whose meaning changes every time the asymmetry is dragged.
    float peak = (1.0 + g) / max((1.0 - g) * (1.0 - g), 1.0e-4);
    // Through the cloud. NOT gated on daylight here: the night floor below already crushes anything
    // on the dark side, and gating twice killed the effect exactly where it belongs. The deck sits
    // 90 km up and stays lit about ten degrees past the ground's terminator, which is the band this
    // term is for — a cloud edge glowing over ground that has already gone dark.
    base += vec3(1.0, 0.98, 0.94) * (u_forward * (lobe / peak) * coverage);
  }

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
 * @param {string} [opts.patchUrl]  harvested NASA cloud patches, tiled stochastically; supersedes
 *                                  fieldUrl where both are given, and is 8x its resolution
 * @param {number} [opts.driftRate] revolutions per second of the real field, west to east
 * @param {number[]} [opts.sun]     direction TO the sun, in planet space
 */
export const createCloudLayer = ({
  opacity = 0.5, animate = true, fieldUrl = null, patchUrl = null,
  driftRate = 0.0004, sun = [0.4, 0.5, 0.75],
  // Wind advection: a real GFS field carrying the clouds along actual circulation. Off without a
  // texture, so a missing asset costs nothing but motion.
  windUrl = null, windAmount = 1, windScale = 0.06, windRate = 0.05,
  /**
   * Lighting. Every one of these is zero-is-off, and with all four at zero the deck renders exactly
   * as it did before it was lit — which is what makes each of them separately measurable.
   *
   * They are listed in the order they have to be built, because each one alone looks wrong: the
   * normal by itself is grey terrain, the normal with Beer-Powder is cloud, and the forward lobe on
   * top is what makes it look photographed.
   */
  /**
   * 40 rather than a cumulus's real 8 km, because the heightfield it reads is not a cumulus.
   *
   * The conversion is still literally kilometres over the earth's radius — that has not changed.
   * What changed is the input: the shape is taken from the 19.5 km whole-planet field, since the
   * atlas aliases into hatching at close range (see the shader). A field at that resolution has
   * gently sloped edges by construction, so the same nominal height yields about a fifth of the
   * slope and the term all but vanished — measured, spread 27.8 against a flat deck's 27.5.
   *
   * So the number is a height applied to a SMOOTHED heightfield, and it has to be larger to recover
   * the slope a real cloud edge has. The 1.5 bound in the shader is what keeps that honest.
   */
  cloudRelief = 40,
  cloudDepth = 4,         // optical depth of fully covered sky
  powder = 1,
  forward = 0.5,
  forwardG = 0.7,         // droplets are strongly forward-scattering; 0.7 is the usual figure
  /**
   * Self-shadowing: 0.18 over a 10 km step, and both numbers are measurements rather than taste.
   *
   * At 0.35 over 25 km it took 33 luma off the deck's mean — a quarter of its brightness, applied
   * almost evenly. That is not a shadow, it is a dimmer. 25 km is far enough that the cloud found
   * over there has nothing to do with the cloud here, so nearly every pixel found something and the
   * term stopped varying. A shadow has to come from cloud CLOSE BY and slightly sunward, which is
   * what makes one side of a cumulus dark while the other stays lit.
   */
  selfShadow = 0.18,
  selfShadowStep = 0.0015, // sphere radii, so about 10 km toward the sun
} = {}) => {
  const mesh = buildSphereMesh()
  let map = null
  let gl = null
  let buffers = null
  // One compiled program per projection variant: the prelude is different under globe and
  // mercator, and the variant flips mid-flight when the map crosses the globe/mercator threshold.
  const programs = new Map()
  let state = {
    opacity, animate, fieldUrl, patchUrl, driftRate, sun, windUrl, windAmount, windScale, windRate,
    cloudRelief, cloudDepth, powder, forward, forwardG, selfShadow, selfShadowStep,
  }
  // Shared with daylight.js, which shades the ground with the shadow of these same clouds.
  let field = null
  let wind = null
  let patches = null

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
        camera: gl.getUniformLocation(program, 'u_camera'),
        cloudRelief: gl.getUniformLocation(program, 'u_cloudRelief'),
        cloudDepth: gl.getUniformLocation(program, 'u_cloudDepth'),
        powder: gl.getUniformLocation(program, 'u_powder'),
        forward: gl.getUniformLocation(program, 'u_forward'),
        forwardG: gl.getUniformLocation(program, 'u_forwardG'),
        selfShadow: gl.getUniformLocation(program, 'u_selfShadow'),
        selfShadowStep: gl.getUniformLocation(program, 'u_selfShadowStep'),
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
      if (state.patchUrl) patches = acquireEquirectTexture(gl, state.patchUrl, repaint)
    },

    // Without this every style reload leaks a program, a texture and three buffers. The fields are
    // released rather than deleted — daylight.js may still be casting their shadows.
    onRemove() {
      if (!gl) return
      programs.forEach(({ program }) => gl.deleteProgram(program))
      programs.clear()
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
      setCloudFieldUniforms(
        gl, uniforms, state, { seconds: performance.now() * 0.001, field, wind, patches }, 0, 1, 2,
      )

      const detail = deckDetailFor(map.getZoom(), map.getCenter().lat, cameraAltitudeMetres(map, maplibregl))
      if (uniforms.detailFreq) gl.uniform1f(uniforms.detailFreq, detail.frequency)
      if (uniforms.detailAmount) gl.uniform1f(uniforms.detailAmount, detail.amount)
      if (uniforms.deckFade) gl.uniform1f(uniforms.deckFade, detail.fade)

      // The forward-scattering lobe needs to know where the eye is; nothing else here does.
      if (uniforms.camera) gl.uniform3f(uniforms.camera, ...cameraInPlanetSpace(map, maplibregl))
      const light = (name) => { if (uniforms[name]) gl.uniform1f(uniforms[name], state[name]) }
      light('cloudRelief')
      light('cloudDepth')
      light('powder')
      light('forward')
      light('forwardG')
      light('selfShadow')
      light('selfShadowStep')

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
      const previous = state
      state = { ...state, ...next }
      if (gl) {
        const swap = (handle, key) => {
          if (next[key] === undefined || next[key] === previous[key]) return handle
          handle?.release()
          return state[key] ? acquireEquirectTexture(gl, state[key], repaint) : null
        }
        field = swap(field, 'fieldUrl')
        patches = swap(patches, 'patchUrl')
      }
      if (map) map.triggerRepaint()
    },
    getOptions: () => ({ ...state }),
    /**
     * True once a real cloud source is uploaded — the deck is procedural until then.
     *
     * Either source counts. The harness waits on this before it measures, so answering only for the
     * old field would have it give up after twenty seconds on a build where the atlas is the source
     * and the old field is not loaded at all.
     */
    get hasField () { return !!(field?.ready || patches?.ready) },
  }
}

export const CLOUD_LAYER_ID = LAYER_ID

/**
 * cloud-field.js — the cloud cover itself, separated from the deck that draws it.
 *
 * There are now two readers of the same weather. The deck (clouds.js) draws it; the ground
 * (daylight.js) shades itself with its shadow. They cannot each keep their own copy: the deck
 * advects the field along a real wind grid, so the shadow has to be advected by exactly the same
 * flow at exactly the same clock, or the shadows crawl away from the clouds casting them — slowly
 * enough that it reads as a rendering fault rather than as motion.
 *
 * So the sampling lives here, uniform declarations included. Declaring them in the shared block
 * rather than in each layer is deliberate: it makes it impossible for one layer to declare a
 * different set, and `cloudFieldUniforms` on the JS side makes it impossible for one to SET them
 * differently. The GLSL and the values travel together.
 *
 * The texture is shared too — see equirect-texture.js.
 */

/**
 * Deck height.
 *
 * Real cloud tops out around 12 km; on a 6371 km globe that is invisible, so this is exaggerated
 * until the shell parallaxes against the ground as the camera moves. The shadow has to use THIS
 * number rather than a truthful one: the deck's apparent position is where the eye puts the cloud,
 * and a shadow cast from 12 km under a deck drawn at 90 km separates from it visibly at low sun.
 */
export const CLOUD_ALTITUDE_M = 90000

/**
 * Sampling the cloud field, with wind.
 *
 * Sliding the whole texture makes clouds drift like a painted backdrop; real weather TURNS. The
 * field is a genuine GFS wind grid, so the rotation comes from actual circulation — the low off
 * Newfoundland spins because it was really spinning.
 *
 * The trick is the double sample. Offsetting UVs by flow*time smears without limit — after a few
 * seconds every cloud is a streak. So the flow is sampled at two clocks half a cycle apart and
 * cross-faded, and each one resets before it has time to smear. Standard flow-map practice.
 *
 * Requires EQUIRECT_GLSL to be in scope for callers that need `equirectUV`.
 */
export const CLOUD_FIELD_GLSL = /* glsl */`
  uniform float u_time;
  uniform float u_drift;        // slow west-to-east rotation of the whole field
  uniform sampler2D u_field;    // real cloud cover, equirectangular
  uniform float u_fieldAmount;  // 0 = no real field loaded
  uniform sampler2D u_wind;     // real wind field: R = eastward, G = southward, 0.5 = still
  uniform float u_windAmount;   // 0 = the field sits still, 1 = fully advected
  uniform float u_windScale;    // how far the flow carries per cycle, in UV
  uniform float u_windRate;     // cycles per second
  uniform sampler2D u_patches;  // harvested NASA cloud patches, 3x2 of 256px coverage tiles
  uniform float u_patchAmount;  // 0 = no atlas, fall back to the whole-planet field
  uniform float u_patchTiles;   // lattice cells around the equator
  uniform float u_patchMean;    // the atlas's own mean coverage; see the blend below

  vec2 windAt(vec2 uv, float lat) {
    vec2 wind = texture(u_wind, uv).rg * 2.0 - 1.0;
    // Degrees of longitude per metre grow toward the poles; without this correction the flow slows
    // to a crawl at high latitude and the polar cells stop turning.
    wind.x /= max(cos(lat), 0.25);
    return wind * u_windScale;
  }

  // ── The tiled source ──────────────────────────────────────────────────────────────────────────
  //
  // The whole-planet field is 2048x1024 — 19.5 km per texel — against ground running under a
  // kilometre, so it collapses to a grey wash on approach. The atlas is real MODIS cloud at 2446 m
  // per texel, eight times finer, but it is only six patches of open ocean: 626 km of sky each.
  // Laid down on a plain grid it would repeat every 626 km, and a visible repeat over open ocean is
  // a worse artefact than the blur it replaces.
  //
  // So it is laid down stochastically. Heitz & Neyret's triangle grid: every point falls inside one
  // triangle of a skewed lattice, and the three vertices of that triangle each choose a patch, a
  // window inside it and an angle, at random from their own coordinates. Three taps, blended by the
  // barycentric weights. Nothing about the result is periodic, because nothing about the choice is.
  //
  // WHY A WINDOW AND NOT A WRAPPED OFFSET, which is what the original technique uses. It assumes a
  // tileable exemplar. These are photographs of the Atlantic; wrapping one draws a hard straight
  // edge across the sky where its left meets its right. A window that stays inside the patch has no
  // seam to show, and the patch is twice the size of a cell, so there is room to move it.
  const vec2 CLOUD_PATCH_GRID = vec2(3.0, 2.0);
  const float CLOUD_PATCH_COUNT = 6.0;
  const float CLOUD_PATCH_TEXELS = 256.0;
  // Patch widths around the equator: 40075 km of circumference over 626 km of patch. It is what
  // makes the atlas display at its native 2446 m per texel whatever u_patchTiles is set to.
  const float CLOUD_PATCH_WIDTHS = 64.0;

  uint cloudMix(uint h) {
    h ^= h >> 16; h *= 2246822519u;
    h ^= h >> 13; h *= 3266489917u;
    h ^= h >> 16;
    return h;
  }

  // An integer hash rather than the usual fract(sin(dot(...))): sin at large arguments is where
  // GPUs disagree, and the cheap end of the range this has to run on is exactly where it would.
  float cloudUnit(uint h) { return float(h & 0x00ffffffu) / 16777216.0; }

  /**
   * One lattice vertex's choice of exemplar, sampled at "local" (cell units, -0.5..0.5).
   *
   * ddx and ddy are the atlas-space derivatives of the CONTINUOUS coordinate, handed in rather than
   * left to the sampler. This is the artefact that would otherwise define the whole effect: the UV
   * jumps at every cell boundary — different patch, different window, different angle — so the
   * automatic derivative there is enormous, the sampler drops to its coarsest mip, and a soft dark
   * line is drawn along every edge of the lattice. The tiling would announce itself in exactly the
   * shape it exists to hide. Rotation does not change a derivative's magnitude, so one unrotated
   * pair is right for all three vertices.
   */
  float cloudPatchAt(vec2 vertex, vec2 local, vec2 ddx, vec2 ddy) {
    // The lattice repeats every u_patchTiles columns, and skewing leaves that period intact — the
    // skew's x depends on x alone. Folding the column index here is what makes the antimeridian
    // join: without it the last column and the first choose different patches and a seam runs down
    // the middle of the Pacific.
    float column = mod(vertex.x, u_patchTiles);
    uint h = cloudMix(uint(int(column)) * 374761393u + uint(int(vertex.y)) * 668265263u);
    float pick = cloudUnit(h);
    h = cloudMix(h);
    float angle = cloudUnit(h) * 6.2831853;
    h = cloudMix(h);
    float ox = cloudUnit(h);
    h = cloudMix(h);
    float oy = cloudUnit(h);

    // A random angle as well as a random window. Without it every cell lays its cloud down on the
    // same axis, and the eye finds that even when it cannot find the repeat itself.
    float s = sin(angle);
    float c = cos(angle);
    vec2 turned = mat2(c, s, -s, c) * local;

    // How much of a patch one cell covers, and how much room that leaves the window to move in.
    // The rotated cell needs its diagonal, not its width — 1.414, not 1 — or a corner swings past
    // the patch edge and the clamp below flattens it into a streak.
    float span = clamp(CLOUD_PATCH_WIDTHS / max(u_patchTiles, 1.0), 0.02, 0.7);
    float room = max(0.0, 1.0 - 1.41421356 * span);
    vec2 inPatch = vec2(ox, oy) * room + 0.70710678 * span + turned * span;

    // Half a texel in from the patch's own edge. The six sit in one atlas, so bilinear at the edge
    // of a patch reaches into its neighbour — a different sky, bled in along a straight line.
    const float INSET = 0.5 / CLOUD_PATCH_TEXELS;
    inPatch = clamp(inPatch, vec2(INSET), vec2(1.0 - INSET));

    float index = min(floor(pick * CLOUD_PATCH_COUNT), CLOUD_PATCH_COUNT - 1.0);
    vec2 grid = vec2(mod(index, CLOUD_PATCH_GRID.x), floor(index / CLOUD_PATCH_GRID.x));
    return textureGrad(u_patches, (grid + inPatch) / CLOUD_PATCH_GRID, ddx, ddy).r;
  }

  /** The atlas, laid across the sphere with no repeat in it. */
  float tiledPatchField(vec2 uv, float lat) {
    // The lattice lives in equirect UV, which is the space both readers of this field already work
    // in — so the ground's shadows keep sampling exactly what the deck draws, with no second
    // coordinate system to get out of step.
    //
    // Cells are UV-square, so on the ground they narrow toward the poles. What is corrected is the
    // LOCAL coordinate, by cos(lat): that keeps the cloud SHAPES ground-square, which is what the
    // eye reads. A stretched lattice underneath them is invisible, because the blend below never
    // lets a cell boundary show.
    vec2 lattice = vec2(uv.x * u_patchTiles, uv.y * u_patchTiles * 0.5);
    const mat2 SKEW = mat2(1.0, 0.0, -0.57735027, 1.15470054);
    vec2 skewed = SKEW * lattice;

    vec2 base = floor(skewed);
    vec2 f = skewed - base;
    vec3 weights;
    vec2 v1, v2, v3;
    if (f.x + f.y < 1.0) {
      weights = vec3(1.0 - f.x - f.y, f.y, f.x);
      v1 = base;
      v2 = base + vec2(0.0, 1.0);
      v3 = base + vec2(1.0, 0.0);
    } else {
      weights = vec3(f.x + f.y - 1.0, 1.0 - f.x, 1.0 - f.y);
      v1 = base + vec2(1.0, 1.0);
      v2 = base + vec2(1.0, 0.0);
      v3 = base + vec2(0.0, 1.0);
    }

    // Back out of the skew to get each vertex's own position, so "local" is measured in the same
    // undistorted cell units the window maths above expects.
    const mat2 UNSKEW = mat2(1.0, 0.0, 0.5, 0.86602540);
    vec2 aspect = vec2(max(cos(lat), 0.05), 1.0);

    // The derivatives of the continuous coordinate, taken ONCE and before any of the per-vertex
    // choices. Clamped because the lattice steps a whole revolution at the antimeridian, and one
    // unbounded column of derivatives there would blur a line down the Pacific — the same artefact
    // as the cell edges, arriving by a different route.
    float span = clamp(CLOUD_PATCH_WIDTHS / max(u_patchTiles, 1.0), 0.02, 0.7);
    vec2 scale = span / CLOUD_PATCH_GRID;
    vec2 ddx = clamp(dFdx(lattice * aspect) * scale, vec2(-0.25), vec2(0.25));
    vec2 ddy = clamp(dFdy(lattice * aspect) * scale, vec2(-0.25), vec2(0.25));

    float c1 = cloudPatchAt(v1, (lattice - UNSKEW * v1) * aspect, ddx, ddy);
    float c2 = cloudPatchAt(v2, (lattice - UNSKEW * v2) * aspect, ddx, ddy);
    float c3 = cloudPatchAt(v3, (lattice - UNSKEW * v3) * aspect, ddx, ddy);

    // VARIANCE-PRESERVING, not a plain average. Averaging three independent skies is how stochastic
    // tiling loses its contrast: where the weights are even it flattens by 1/sqrt(3), where one
    // vertex dominates it does not, and that difference is itself a visible pattern in the shape of
    // the lattice. Re-normalising against the atlas's own mean holds the contrast constant, so the
    // grid stays where it belongs, which is invisible.
    vec3 centred = vec3(c1, c2, c3) - u_patchMean;
    float spread = max(0.001, sqrt(dot(weights, weights)));
    return clamp(u_patchMean + dot(weights, centred) / spread, 0.0, 1.0);
  }

  /** Cloud cover at an equirectangular UV: the tiled atlas if it is loaded, the old field if not. */
  float cloudSourceAt(vec2 uv, float lat) {
    if (u_patchAmount > 0.0) return tiledPatchField(uv, lat);
    return texture(u_field, uv).r;
  }

  float advectedField(vec2 uv, float lat) {
    if (u_windAmount <= 0.0) return cloudSourceAt(uv, lat);
    vec2 flow = windAt(uv, lat);
    float cycle = u_time * u_windRate;
    float phase1 = fract(cycle);
    float phase2 = fract(cycle + 0.5);
    float blend = abs(1.0 - 2.0 * phase1);       // triangle wave: 1 at the resets, 0 mid-cycle
    float a = cloudSourceAt(uv - flow * phase1, lat);
    float b = cloudSourceAt(uv - flow * phase2, lat);
    return mix(mix(a, b, blend), cloudSourceAt(uv, lat), 1.0 - u_windAmount);
  }
`

/** The uniform names CLOUD_FIELD_GLSL declares, for `getUniformLocation` in either layer. */
export const CLOUD_FIELD_UNIFORMS = [
  'time', 'drift', 'field', 'fieldAmount', 'wind', 'windAmount', 'windScale', 'windRate',
  'patches', 'patchAmount', 'patchTiles', 'patchMean',
]

/**
 * Lattice cells around the equator.
 *
 * 144 puts a cell at about 278 km, which is 0.44 of a patch — so the atlas is drawn at its own
 * 2446 m per texel with room left for the window to move, and cloud systems come out the size real
 * ones are. Raising it shrinks the cells and shows more of the atlas; lowering it past about 91
 * leaves no room for the window and the tiling starts to repeat.
 */
export const CLOUD_PATCH_TILES = 144

/**
 * The atlas's own mean coverage, measured over all six patches: 0.4715.
 *
 * Not decoration — the variance-preserving blend centres on it, and an error here reappears as a
 * brightness modulation in the shape of the lattice, which is the one artefact the tiling exists to
 * prevent. It MUST be re-measured whenever the atlas is rebuilt: it moved from 0.5177 to 0.4715 the
 * moment the patches were reselected on contrast, which is a big enough step to show. So
 * build-cloud-atlas.mjs prints it and names this constant, rather than leaving the pair to be kept
 * in step by whoever remembers.
 */
export const CLOUD_PATCH_MEAN = 0.4715

/**
 * Set every cloud-field uniform from one state object.
 *
 * Both layers call this with the same arguments, which is the point: sharing the GLSL only stops
 * the two sampling differently if they also feed it the same numbers. A layer that computed its
 * own clock here would drift apart from the other over minutes, not frames — the hardest kind of
 * disagreement to catch.
 *
 * @param {object} gl
 * @param {object} uniforms  locations, keyed by the names in CLOUD_FIELD_UNIFORMS
 * @param {object} state     { animate, driftRate, windAmount, windScale, windRate }
 * @param {object} sources   { seconds, field, wind, patches } — the shared texture handles
 * @param {number} fieldUnit texture unit for the cloud field
 * @param {number} windUnit  texture unit for the wind field
 * @param {number} patchUnit texture unit for the harvested patch atlas
 */
export const setCloudFieldUniforms = (gl, uniforms, state, sources, fieldUnit, windUnit, patchUnit) => {
  const seconds = state.animate ? sources.seconds : 0
  if (uniforms.time) gl.uniform1f(uniforms.time, seconds)
  // Real weather tracks west to east. Slow enough that a lesson never sees it move, fast enough
  // that the planet is not visibly frozen across a long scene.
  if (uniforms.drift) gl.uniform1f(uniforms.drift, seconds * state.driftRate)
  // The deck runs on EITHER source, so it counts as having a field if either has arrived. Reading
  // only the old one here is what would leave a fully-loaded atlas drawing procedural noise.
  const hasSource = sources.field?.ready || sources.patches?.ready
  if (uniforms.fieldAmount) gl.uniform1f(uniforms.fieldAmount, hasSource ? 1 : 0)
  if (uniforms.patchAmount) gl.uniform1f(uniforms.patchAmount, sources.patches?.ready ? 1 : 0)
  if (uniforms.patchTiles) gl.uniform1f(uniforms.patchTiles, state.patchTiles ?? CLOUD_PATCH_TILES)
  if (uniforms.patchMean) gl.uniform1f(uniforms.patchMean, state.patchMean ?? CLOUD_PATCH_MEAN)
  if (uniforms.windAmount) gl.uniform1f(uniforms.windAmount, sources.wind?.ready ? state.windAmount : 0)
  if (uniforms.windScale) gl.uniform1f(uniforms.windScale, state.windScale)
  if (uniforms.windRate) gl.uniform1f(uniforms.windRate, state.animate ? state.windRate : 0)

  if (sources.field?.ready && uniforms.field) {
    gl.activeTexture(gl.TEXTURE0 + fieldUnit)
    gl.bindTexture(gl.TEXTURE_2D, sources.field.texture)
    gl.uniform1i(uniforms.field, fieldUnit)
  }
  if (sources.wind?.ready && uniforms.wind) {
    gl.activeTexture(gl.TEXTURE0 + windUnit)
    gl.bindTexture(gl.TEXTURE_2D, sources.wind.texture)
    gl.uniform1i(uniforms.wind, windUnit)
  }
  if (sources.patches?.ready && uniforms.patches) {
    gl.activeTexture(gl.TEXTURE0 + patchUnit)
    gl.bindTexture(gl.TEXTURE_2D, sources.patches.texture)
    gl.uniform1i(uniforms.patches, patchUnit)
  }
}

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

  vec2 windAt(vec2 uv, float lat) {
    vec2 wind = texture(u_wind, uv).rg * 2.0 - 1.0;
    // Degrees of longitude per metre grow toward the poles; without this correction the flow slows
    // to a crawl at high latitude and the polar cells stop turning.
    wind.x /= max(cos(lat), 0.25);
    return wind * u_windScale;
  }

  float advectedField(vec2 uv, float lat) {
    if (u_windAmount <= 0.0) return texture(u_field, uv).r;
    vec2 flow = windAt(uv, lat);
    float cycle = u_time * u_windRate;
    float phase1 = fract(cycle);
    float phase2 = fract(cycle + 0.5);
    float blend = abs(1.0 - 2.0 * phase1);       // triangle wave: 1 at the resets, 0 mid-cycle
    float a = texture(u_field, uv - flow * phase1).r;
    float b = texture(u_field, uv - flow * phase2).r;
    return mix(mix(a, b, blend), texture(u_field, uv).r, 1.0 - u_windAmount);
  }
`

/** The uniform names CLOUD_FIELD_GLSL declares, for `getUniformLocation` in either layer. */
export const CLOUD_FIELD_UNIFORMS = [
  'time', 'drift', 'field', 'fieldAmount', 'wind', 'windAmount', 'windScale', 'windRate',
]

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
 * @param {object} sources   { seconds, field, wind } — the shared texture handles
 * @param {number} fieldUnit texture unit for the cloud field
 * @param {number} windUnit  texture unit for the wind field
 */
export const setCloudFieldUniforms = (gl, uniforms, state, sources, fieldUnit, windUnit) => {
  const seconds = state.animate ? sources.seconds : 0
  if (uniforms.time) gl.uniform1f(uniforms.time, seconds)
  // Real weather tracks west to east. Slow enough that a lesson never sees it move, fast enough
  // that the planet is not visibly frozen across a long scene.
  if (uniforms.drift) gl.uniform1f(uniforms.drift, seconds * state.driftRate)
  if (uniforms.fieldAmount) gl.uniform1f(uniforms.fieldAmount, sources.field?.ready ? 1 : 0)
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
}

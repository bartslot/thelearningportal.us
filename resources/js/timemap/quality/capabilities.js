/**
 * capabilities.js — what this device can actually do, read once, cheaply.
 *
 * Two rules shape everything here.
 *
 * **No sniffing.** The renderer string is captured for telemetry and marked advisory. It is not an
 * input to any decision: GPU strings are inconsistent, spoofed, and wrong about the thing you care
 * about. `tiers.js` decides from numbers and from a measured frame time.
 *
 * **Nothing throws.** A driver can throw out of `getParameter` on a lost context, and an exception
 * here would take down the whole map on the device least able to afford it. Every read is guarded
 * and failures land in `profile.errors` for whoever is looking.
 *
 * The value that matters most is `maxTextureSize`. A texture above it does not degrade — it fails
 * to bind, draws black, and raises no error, which on good hardware is invisible to everyone
 * reviewing the work. `fitTexture` exists so no layer ever asks for one.
 */

/** GL enum values, spelled out so this module needs no live context to be tested. */
export const GL_PARAMS = {
  MAX_TEXTURE_SIZE: 0x0d33,
  MAX_RENDERBUFFER_SIZE: 0x84e8,
  MAX_TEXTURE_IMAGE_UNITS: 0x8872,
  MAX_VARYING_VECTORS: 0x8dfc,
  MAX_ARRAY_TEXTURE_LAYERS: 0x88ff,
  MAX_TEXTURE_MAX_ANISOTROPY_EXT: 0x84ff,
  UNMASKED_RENDERER_WEBGL: 0x9246,
  UNMASKED_VENDOR_WEBGL: 0x9245,
}

/** Cache per context, so a second caller costs nothing. Weak, so a lost context is collectable. */
const cache = new WeakMap()

const guarded = (errors, label, read, fallback) => {
  try {
    const value = read()
    return value === undefined || value === null ? fallback : value
  } catch (error) {
    errors.push({ label, message: String(error?.message || error) })
    return fallback
  }
}

const readExtensions = (gl, errors) => {
  const has = (name) => guarded(errors, `getExtension(${name})`, () => !!gl.getExtension(name), false)
  const anisotropyExt = guarded(errors, 'anisotropy', () => gl.getExtension('EXT_texture_filter_anisotropic'), null)
  const anisotropy = anisotropyExt
    ? guarded(errors, 'maxAnisotropy', () => gl.getParameter(GL_PARAMS.MAX_TEXTURE_MAX_ANISOTROPY_EXT) || 1, 1)
    : 1

  return {
    floatTextures: has('OES_texture_float') || has('EXT_color_buffer_float'),
    halfFloatTextures: has('OES_texture_half_float') || has('EXT_color_buffer_half_float'),
    colorBufferFloat: has('EXT_color_buffer_float'),
    drawBuffers: has('WEBGL_draw_buffers') || typeof gl.drawBuffers === 'function',
    anisotropy,
  }
}

const readRenderer = (gl, errors) => {
  const ext = guarded(errors, 'debug_renderer_info', () => gl.getExtension('WEBGL_debug_renderer_info'), null)
  if (!ext) return null
  return guarded(errors, 'UNMASKED_RENDERER_WEBGL', () => gl.getParameter(GL_PARAMS.UNMASKED_RENDERER_WEBGL) || null, null)
}

/** An empty-but-shaped profile, so callers never branch on missing fields. */
const emptyProfile = (errors) => ({
  webglVersion: 0,
  maxTextureSize: 0,
  maxRenderbufferSize: 0,
  maxTextureUnits: 0,
  maxArrayTextureLayers: 0,
  textureArrays: false,
  extensions: {},
  deviceMemoryGb: undefined,
  cpuThreads: undefined,
  dpr: 1,
  viewport: { width: 0, height: 0 },
  reducedMotion: false,
  saveData: false,
  effectiveType: undefined,
  probe: null,
  renderer: null,
  rendererIsAdvisory: true,
  hidden: false,
  measurable: true,
  errors,
})

/**
 * Whether a timing measurement taken here would mean anything.
 *
 * A hidden page gets no animation frames at all and has its timers clamped to roughly one a second,
 * and a zero-area viewport gives a canvas nothing to draw into. Neither errors. A probe run in that
 * state returns a real-looking number produced by an environment that cannot produce one, and
 * without this flag nothing downstream could tell it apart from a measurement of a real device.
 *
 * It says nothing about the *static* values — a driver's texture limit is a fact whether or not
 * anything is on screen, and it is the load-bearing check.
 */
const canMeasure = (documentRef, viewport) => {
  if (documentRef?.hidden === true) return false
  return viewport.width > 0 && viewport.height > 0
}

/**
 * Read a capability profile.
 *
 * @param {object}  opts
 * @param {WebGLRenderingContext|WebGL2RenderingContext|null} opts.gl
 * @param {object} [opts.navigator]  injected for tests; defaults to the global
 * @param {object} [opts.window]     injected for tests; defaults to the global
 * @returns {object} a plain, serialisable profile — pass it straight to `selectTier`
 */
export const readCapabilities = ({ gl, navigator: nav, window: win, document: doc } = {}) => {
  if (gl && cache.has(gl)) return cache.get(gl)

  const errors = []
  const navigatorRef = nav ?? (typeof navigator !== 'undefined' ? navigator : {})
  const windowRef = win ?? (typeof window !== 'undefined' ? window : {})
  const documentRef = doc ?? (typeof document !== 'undefined' ? document : null)
  const viewport = { width: windowRef.innerWidth || 0, height: windowRef.innerHeight || 0 }

  const environment = {
    // `deviceMemory` stays undefined where the browser does not implement it (Safari). Coercing
    // "unknown" to a number would put every iPad on the tier meant for 2 GB Android phones.
    deviceMemoryGb: typeof navigatorRef.deviceMemory === 'number' ? navigatorRef.deviceMemory : undefined,
    cpuThreads: typeof navigatorRef.hardwareConcurrency === 'number' ? navigatorRef.hardwareConcurrency : undefined,
    dpr: typeof windowRef.devicePixelRatio === 'number' ? windowRef.devicePixelRatio : 1,
    viewport,
    hidden: documentRef?.hidden === true,
    measurable: canMeasure(documentRef, viewport),
    reducedMotion: guarded(errors, 'matchMedia', () => !!windowRef.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches, false),
    saveData: !!navigatorRef.connection?.saveData,
    effectiveType: navigatorRef.connection?.effectiveType,
  }

  if (!gl) {
    const profile = { ...emptyProfile(errors), ...environment }
    return profile
  }

  const webglVersion = typeof gl.texStorage3D === 'function' ? 2 : 1
  const maxTextureSize = guarded(errors, 'MAX_TEXTURE_SIZE', () => gl.getParameter(GL_PARAMS.MAX_TEXTURE_SIZE) || 0, 0)

  const profile = {
    ...emptyProfile(errors),
    ...environment,
    webglVersion,
    maxTextureSize,
    maxRenderbufferSize: guarded(errors, 'MAX_RENDERBUFFER_SIZE', () => gl.getParameter(GL_PARAMS.MAX_RENDERBUFFER_SIZE) || 0, 0),
    maxTextureUnits: guarded(errors, 'MAX_TEXTURE_IMAGE_UNITS', () => gl.getParameter(GL_PARAMS.MAX_TEXTURE_IMAGE_UNITS) || 0, 0),
    maxArrayTextureLayers: webglVersion === 2
      ? guarded(errors, 'MAX_ARRAY_TEXTURE_LAYERS', () => gl.getParameter(GL_PARAMS.MAX_ARRAY_TEXTURE_LAYERS) || 0, 0)
      : 0,
    // The same predicate the tile pyramid uses. `pyramid.supported` stays authoritative at
    // construction time; this is the advance signal so a plan can be made before one exists.
    textureArrays: webglVersion === 2,
    extensions: readExtensions(gl, errors),
    renderer: readRenderer(gl, errors),
    rendererIsAdvisory: true,
  }

  cache.set(gl, profile)
  return profile
}

const floorPowerOfTwo = (n) => (n < 1 ? 0 : 2 ** Math.floor(Math.log2(n)))

/**
 * Clamp a desired texture dimension to something this device will actually bind.
 *
 * Clamping lands on a power of two rather than on the raw ceiling: a driver reporting a
 * non-power-of-two limit is rare but legal, and a partly-sized texture is worse than a smaller
 * whole one.
 *
 * @returns {{ size: number, clamped: boolean, requested: number }}
 */
export const fitTexture = (requested, { maxTextureSize }) => {
  const ceiling = maxTextureSize || 0
  if (ceiling <= 0) return { size: 0, clamped: true, requested }
  if (requested <= ceiling) return { size: requested, clamped: false, requested }
  const size = ceiling === floorPowerOfTwo(ceiling) ? ceiling : floorPowerOfTwo(ceiling)
  return { size, clamped: true, requested }
}

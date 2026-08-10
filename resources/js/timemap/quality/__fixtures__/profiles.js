/**
 * Synthetic capability profiles.
 *
 * Tier selection is tested against these, never against whatever machine the suite happens to run
 * on. A test that asserts "this laptop gets `high`" passes on the laptop and tells you nothing about
 * the Chromebook, which is the only device the decision actually matters for.
 *
 * The numbers are the real ones each class of device reports. `deviceMemory` is deliberately
 * `undefined` on the Apple profiles: Safari does not implement it, and a rule that treats "absent"
 * as "small" would put every iPad on the minimal tier.
 */

/** Shape every profile shares, so a fixture only states what makes it interesting. */
const BASE = {
  webglVersion: 2,
  maxTextureSize: 16384,
  maxRenderbufferSize: 16384,
  maxTextureUnits: 16,
  textureArrays: true,
  extensions: {
    floatTextures: true,
    halfFloatTextures: true,
    colorBufferFloat: true,
    anisotropy: 16,
    drawBuffers: true,
  },
  deviceMemoryGb: 8,
  cpuThreads: 8,
  dpr: 2,
  viewport: { width: 1440, height: 900 },
  reducedMotion: false,
  saveData: false,
  probe: null,
  renderer: null,
}

export const makeProfile = (overrides = {}) => ({
  ...BASE,
  ...overrides,
  extensions: { ...BASE.extensions, ...(overrides.extensions || {}) },
})

/** Discrete GPU workstation. Everything on. */
export const DESKTOP_DISCRETE = makeProfile({
  maxTextureSize: 16384,
  deviceMemoryGb: 8,
  cpuThreads: 16,
  probe: { medianMs: 2.1, p95Ms: 3.0, frames: 12, aborted: false },
})

/** Apple silicon laptop. Fast, but reports no deviceMemory at all. */
export const MACBOOK_INTEGRATED = makeProfile({
  maxTextureSize: 16384,
  deviceMemoryGb: undefined,
  cpuThreads: 10,
  probe: { medianMs: 4.4, p95Ms: 6.2, frames: 12, aborted: false },
})

/** iPad. Also no deviceMemory, high DPR, plenty of texture headroom. */
export const IPAD = makeProfile({
  maxTextureSize: 16384,
  deviceMemoryGb: undefined,
  cpuThreads: 8,
  dpr: 2,
  viewport: { width: 1024, height: 768 },
  probe: { medianMs: 7.5, p95Ms: 11.0, frames: 12, aborted: false },
})

/**
 * The target device. Current-generation school Chromebook: WebGL2, but 4096 is the whole texture
 * ceiling, 4 GB of shared memory and four threads.
 */
export const SCHOOL_CHROMEBOOK = makeProfile({
  maxTextureSize: 4096,
  maxRenderbufferSize: 4096,
  deviceMemoryGb: 4,
  cpuThreads: 4,
  dpr: 1,
  viewport: { width: 1366, height: 768 },
  extensions: { colorBufferFloat: false, anisotropy: 4 },
  probe: { medianMs: 14.0, p95Ms: 19.0, frames: 12, aborted: false },
})

/** Older Chromebook that never got WebGL2. No texture arrays, so no tile pyramid. */
export const OLD_CHROMEBOOK_WEBGL1 = makeProfile({
  webglVersion: 1,
  textureArrays: false,
  maxTextureSize: 4096,
  deviceMemoryGb: 2,
  cpuThreads: 4,
  dpr: 1,
  extensions: { floatTextures: false, halfFloatTextures: true, colorBufferFloat: false, anisotropy: 2, drawBuffers: false },
  probe: { medianMs: 26.0, p95Ms: 40.0, frames: 12, aborted: false },
})

/** Cheap Android phone: high DPR over a weak GPU, which is the worst combination there is. */
export const BUDGET_ANDROID = makeProfile({
  maxTextureSize: 4096,
  deviceMemoryGb: 2,
  cpuThreads: 4,
  dpr: 2.75,
  viewport: { width: 412, height: 915 },
  extensions: { colorBufferFloat: false, anisotropy: 4 },
  probe: { medianMs: 33.0, p95Ms: 55.0, frames: 12, aborted: false },
})

/** A phone on a metered connection that has asked for less data. */
export const DATA_SAVER_PHONE = makeProfile({
  maxTextureSize: 8192,
  deviceMemoryGb: 8,
  cpuThreads: 8,
  dpr: 3,
  saveData: true,
  probe: { medianMs: 6.0, p95Ms: 9.0, frames: 12, aborted: false },
})

/**
 * Software rasteriser. Reports capable hardware — 8192 textures, WebGL2, texture arrays — and then
 * takes 180 ms a frame. Only the probe catches this; every static signal says it is fine.
 */
export const SOFTWARE_RENDERER = makeProfile({
  maxTextureSize: 8192,
  deviceMemoryGb: 8,
  cpuThreads: 8,
  probe: { medianMs: 180.0, p95Ms: 240.0, frames: 6, aborted: false },
})

/** No WebGL context at all. */
export const NO_WEBGL = makeProfile({
  webglVersion: 0,
  textureArrays: false,
  maxTextureSize: 0,
  maxRenderbufferSize: 0,
  extensions: {},
  probe: null,
})

/** A machine that is fine but whose user has asked the OS for reduced motion. */
export const REDUCED_MOTION_DESKTOP = makeProfile({
  cpuThreads: 16,
  reducedMotion: true,
  probe: { medianMs: 2.4, p95Ms: 3.2, frames: 12, aborted: false },
})

export const ALL_PROFILES = {
  DESKTOP_DISCRETE,
  MACBOOK_INTEGRATED,
  IPAD,
  SCHOOL_CHROMEBOOK,
  OLD_CHROMEBOOK_WEBGL1,
  BUDGET_ANDROID,
  DATA_SAVER_PHONE,
  SOFTWARE_RENDERER,
  NO_WEBGL,
  REDUCED_MOTION_DESKTOP,
}

/**
 * video-fallback.js — the bottom tier: a pre-rendered loop, for a device that cannot run the
 * shaders at all.
 *
 * The footage already exists. `tools/cloud-capture` drives the real scene frame by frame in
 * headless Chromium and packs the result two ways; what it never had was a playback half. This is
 * that half, and it is deliberately the only part of the quality system that touches the DOM.
 *
 * Three alpha strategies, picked from what the browser says it can decode:
 *
 * - **native** — VP9 with an alpha plane, in WebM. Smallest, needs no shader. Chrome and Firefox,
 *   which covers the Chromebooks. Not Safari, not iOS.
 * - **packed** — colour in the top half of an ordinary H.264 frame, alpha as greyscale in the
 *   bottom half, recombined in a two-line shader. Double the pixels, plays everywhere. This is why
 *   the capture pipeline packs at all.
 * - **none** — an opaque loop over a solid background. The last resort, and the only one that works
 *   with no WebGL whatsoever. Choosing *packed* on a device with no GL would draw the picture with
 *   a greyscale copy of itself stacked underneath, which is a worse failure than no transparency.
 *
 * Nothing is fetched until `start()`. The element is created with `preload="none"` and no `src` at
 * all, so a page that resolves to a higher tier pays nothing for this module's existence.
 */

export const ALPHA_MODES = { NATIVE: 'native', PACKED: 'packed', NONE: 'none' }

/** Preference order. Native first: fewer pixels and no shader. */
const PREFERENCE = [ALPHA_MODES.NATIVE, ALPHA_MODES.PACKED, ALPHA_MODES.NONE]

const defaultBrowser = () => ({
  canPlayType: (type) => {
    if (typeof document === 'undefined') return ''
    const probe = document.createElement('video')
    return probe.canPlayType ? probe.canPlayType(type) : ''
  },
  hasWebgl: () => {
    if (typeof document === 'undefined') return false
    try {
      const canvas = document.createElement('canvas')
      return !!(canvas.getContext('webgl2') || canvas.getContext('webgl'))
    } catch {
      return false
    }
  },
})

/**
 * Choose the best source this browser can actually play.
 *
 * @param {Array<{src:string, type:string, alpha:string}>} sources
 * @param {{canPlayType:(t:string)=>string, hasWebgl:()=>boolean}} [browser]
 * @returns {object|null} the chosen source, or null if none of them will play
 */
export const pickVideoSource = (sources, browser = defaultBrowser()) => {
  if (!Array.isArray(sources) || sources.length === 0) return null
  const playable = sources.filter((source) => !!browser.canPlayType(source.type))

  for (const mode of PREFERENCE) {
    if (mode === ALPHA_MODES.PACKED && !browser.hasWebgl()) continue
    const found = playable.find((source) => source.alpha === mode)
    if (found) return { ...found, alpha: mode }
  }
  return null
}

/**
 * Mount a looping fallback into `container`.
 *
 * @param {object}   opts
 * @param {Element}  opts.container
 * @param {Array}    opts.sources           as `pickVideoSource`
 * @param {object}  [opts.browser]          injected for tests
 * @param {Function}[opts.onUnavailable]    called when nothing here will play
 * @param {string}  [opts.poster]
 */
export const createVideoFallback = ({ container, sources, browser = defaultBrowser(), onUnavailable = null, poster = null }) => {
  const chosen = pickVideoSource(sources, browser)

  const element = container.ownerDocument.createElement('video')
  element.setAttribute('preload', 'none')      // nothing crosses the network until start()
  element.setAttribute('playsinline', '')      // iOS plays inline rather than taking over the screen
  element.setAttribute('loop', '')
  element.setAttribute('aria-hidden', 'true')  // decorative: it replaces a picture, not information
  // Muted is not an option. Autoplay depends on it, and narration is the only thing in this product
  // allowed to make a sound.
  element.muted = true
  element.setAttribute('muted', '')
  if (poster) element.setAttribute('poster', poster)
  element.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block'

  container.appendChild(element)

  let unpack = null
  let started = false

  const start = () => {
    if (started) return
    started = true
    if (!chosen) {
      onUnavailable?.(new Error('quality: no fallback source this browser can play'))
      return
    }

    element.setAttribute('src', chosen.src)
    element.setAttribute('preload', 'auto')

    if (chosen.alpha === ALPHA_MODES.PACKED) {
      // Only a packed source needs the unpacking shader, so only a packed source pays for it.
      import('./video-unpack.js')
        .then(({ createPackedAlphaUnpacker }) => {
          if (!started) return
          unpack = createPackedAlphaUnpacker({ container, video: element })
        })
        .catch((error) => onUnavailable?.(error))
    }

    try {
      const playing = element.play?.()
      if (playing?.catch) playing.catch(() => {})
    } catch {
      // Autoplay refused, or no media stack at all. The poster stands in; nothing to recover.
    }
  }

  return {
    start,
    get available () { return chosen !== null },
    get source () { return chosen },
    get element () { return element },
    dispose () {
      started = false
      unpack?.dispose()
      unpack = null
      element.removeAttribute('src')
      try { element.load?.() } catch { /* jsdom and some embedded browsers have no media stack */ }
      element.remove()
    },
  }
}

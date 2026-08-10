import { describe, it, expect, vi } from 'vitest'
import { pickVideoSource, createVideoFallback, ALPHA_MODES } from '../video-fallback.js'

const SOURCES = [
  { src: '/timemap/fallback/globe.alpha.webm', type: 'video/webm; codecs="vp09.00.10.08"', alpha: 'native' },
  { src: '/timemap/fallback/globe.packed.mp4', type: 'video/mp4; codecs="avc1.42E01E"', alpha: 'packed' },
  { src: '/timemap/fallback/globe.opaque.mp4', type: 'video/mp4; codecs="avc1.42E01E"', alpha: 'none' },
]

/** A browser that can play whatever is in `plays` and nothing else. */
const browser = ({ plays = [], webgl = true } = {}) => ({
  canPlayType: (type) => (plays.some((p) => type.includes(p)) ? 'probably' : ''),
  hasWebgl: () => webgl,
})

describe('pickVideoSource', () => {
  it('prefers native alpha where it plays, because it needs no shader and half the pixels', () => {
    const picked = pickVideoSource(SOURCES, browser({ plays: ['vp09', 'avc1'] }))
    expect(picked.alpha).toBe(ALPHA_MODES.NATIVE)
    expect(picked.src).toContain('.webm')
  })

  it('falls back to the packed mp4 on a browser with no VP9 alpha', () => {
    // Safari and iOS. VP9-with-alpha does not play there, which is why the capture pipeline packs
    // colour over alpha into an ordinary H.264 frame in the first place.
    const picked = pickVideoSource(SOURCES, browser({ plays: ['avc1'] }))
    expect(picked.alpha).toBe(ALPHA_MODES.PACKED)
    expect(picked.src).toContain('.mp4')
  })

  it('will not choose the packed source when there is no WebGL to unpack it with', () => {
    // The packed layout needs a two-line shader to recombine. On a device with no GL at all that
    // would render a picture with a greyscale copy of itself stacked underneath.
    const picked = pickVideoSource(SOURCES, browser({ plays: ['avc1'], webgl: false }))
    expect(picked.alpha).toBe(ALPHA_MODES.NONE)
    expect(picked.src).toContain('opaque')
  })

  it('returns null rather than a broken element when nothing is playable', () => {
    expect(pickVideoSource(SOURCES, browser({ plays: [] }))).toBe(null)
  })

  it('accepts a source list from a host app that ships its own footage', () => {
    const own = [{ src: 'https://cdn.example/loop.mp4', type: 'video/mp4', alpha: 'none' }]
    expect(pickVideoSource(own, browser({ plays: ['mp4'] })).src).toBe('https://cdn.example/loop.mp4')
  })
})

describe('createVideoFallback', () => {
  it('does not fetch anything until it is told to start', () => {
    const container = document.createElement('div')
    const fallback = createVideoFallback({ container, sources: SOURCES, browser: browser({ plays: ['avc1'], webgl: false }) })
    const video = container.querySelector('video')
    expect(video).toBeTruthy()
    expect(video.getAttribute('preload')).toBe('none')
    expect(video.getAttribute('src')).toBe(null)
    expect(video.querySelector('source')).toBe(null)

    fallback.start()
    expect(container.querySelector('video').getAttribute('src')).toContain('opaque')
    fallback.dispose()
  })

  it('is muted, always, and cannot be unmuted through the options', () => {
    const container = document.createElement('div')
    const fallback = createVideoFallback({ container, sources: SOURCES, browser: browser({ plays: ['avc1'], webgl: false }), muted: false })
    const video = container.querySelector('video')
    expect(video.muted).toBe(true)
    expect(video.hasAttribute('playsinline')).toBe(true)
    fallback.dispose()
  })

  it('leaves the container as it found it', () => {
    const container = document.createElement('div')
    container.innerHTML = '<p>existing</p>'
    const fallback = createVideoFallback({ container, sources: SOURCES, browser: browser({ plays: ['avc1'], webgl: false }) })
    fallback.start()
    fallback.dispose()
    expect(container.querySelector('video')).toBe(null)
    expect(container.querySelector('p')).toBeTruthy()
  })

  it('loads the unpacking shader only for a packed source, and only once started', async () => {
    const unpacker = { dispose: vi.fn() }
    const createPackedAlphaUnpacker = vi.fn(() => unpacker)
    vi.doMock('../video-unpack.js', () => ({ createPackedAlphaUnpacker }))

    const container = document.createElement('div')
    const fallback = createVideoFallback({ container, sources: SOURCES, browser: browser({ plays: ['avc1'], webgl: true }) })
    expect(fallback.source.alpha).toBe(ALPHA_MODES.PACKED)
    expect(createPackedAlphaUnpacker).not.toHaveBeenCalled()

    fallback.start()
    await vi.waitFor(() => expect(createPackedAlphaUnpacker).toHaveBeenCalled())

    fallback.dispose()
    expect(unpacker.dispose).toHaveBeenCalled()
    vi.doUnmock('../video-unpack.js')
  })

  it('never loads the unpacking shader for a source that does not need one', async () => {
    const createPackedAlphaUnpacker = vi.fn()
    vi.doMock('../video-unpack.js', () => ({ createPackedAlphaUnpacker }))

    const container = document.createElement('div')
    const fallback = createVideoFallback({ container, sources: SOURCES, browser: browser({ plays: ['vp09'], webgl: true }) })
    fallback.start()
    await Promise.resolve()
    expect(createPackedAlphaUnpacker).not.toHaveBeenCalled()
    fallback.dispose()
    vi.doUnmock('../video-unpack.js')
  })

  it('starting twice does not mount a second copy', () => {
    const container = document.createElement('div')
    const fallback = createVideoFallback({ container, sources: SOURCES, browser: browser({ plays: ['avc1'], webgl: false }) })
    fallback.start()
    fallback.start()
    expect(container.querySelectorAll('video').length).toBe(1)
    fallback.dispose()
  })

  it('shows a poster while the loop is still arriving', () => {
    const container = document.createElement('div')
    const fallback = createVideoFallback({
      container,
      sources: SOURCES,
      browser: browser({ plays: ['avc1'], webgl: false }),
      poster: '/timemap/fallback/globe.jpg',
    })
    expect(container.querySelector('video').getAttribute('poster')).toBe('/timemap/fallback/globe.jpg')
    fallback.dispose()
  })

  it('reports that it could not start rather than sitting on a blank rectangle', () => {
    const container = document.createElement('div')
    const onFail = vi.fn()
    const fallback = createVideoFallback({ container, sources: SOURCES, browser: browser({ plays: [] }), onUnavailable: onFail })
    fallback.start()
    expect(onFail).toHaveBeenCalled()
    expect(fallback.available).toBe(false)
    fallback.dispose()
  })
})

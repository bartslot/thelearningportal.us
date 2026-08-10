import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { createPackedAlphaUnpacker } from '../video-unpack.js'
import { createFakeGl } from '../__fixtures__/fake-gl.js'

/** Drive rAF by hand: jsdom's own would run on a timer and make every assertion a race. */
let pending = []
const flush = () => { const due = pending; pending = []; due.forEach((fn) => fn(0)) }

beforeEach(() => {
  pending = []
  globalThis.requestAnimationFrame = vi.fn((fn) => { pending.push(fn); return pending.length })
  globalThis.cancelAnimationFrame = vi.fn()
})
afterEach(() => { vi.restoreAllMocks() })

/** A container whose canvas hands back a fake context instead of a real one. */
const mount = (glOptions = {}, videoState = {}) => {
  const gl = createFakeGl(glOptions)
  const container = document.createElement('div')
  const video = document.createElement('video')
  Object.defineProperties(video, {
    readyState: { value: videoState.readyState ?? 4, configurable: true },
    videoWidth: { value: videoState.videoWidth ?? 1280, configurable: true },
    videoHeight: { value: videoState.videoHeight ?? 1440, configurable: true },
  })
  container.appendChild(video)

  const realCreate = document.createElement.bind(document)
  vi.spyOn(document, 'createElement').mockImplementation((tag) => {
    const element = realCreate(tag)
    if (tag === 'canvas') element.getContext = () => gl
    return element
  })

  return { gl, container, video }
}

describe('createPackedAlphaUnpacker', () => {
  it('renders the picture at half the packed frame height', () => {
    // Colour on top, alpha beneath: a 1280×1440 packed frame carries a 1280×720 picture.
    const { gl, container, video } = mount()
    const unpacker = createPackedAlphaUnpacker({ container, video })
    flush()
    expect(unpacker.canvas.width).toBe(1280)
    expect(unpacker.canvas.height).toBe(720)
    expect(gl.drawArrays).toHaveBeenCalled()
    unpacker.dispose()
  })

  it('waits for the video to have data instead of uploading an empty frame', () => {
    const { gl, container, video } = mount({}, { readyState: 0 })
    const unpacker = createPackedAlphaUnpacker({ container, video })
    flush()
    expect(gl.texImage2D).not.toHaveBeenCalled()
    expect(gl.drawArrays).not.toHaveBeenCalled()
    unpacker.dispose()
  })

  it('hides the video and shows the unpacked canvas instead', () => {
    const { container, video } = mount()
    const unpacker = createPackedAlphaUnpacker({ container, video })
    expect(video.style.display).toBe('none')
    expect(container.querySelector('canvas')).toBe(unpacker.canvas)
    unpacker.dispose()
  })

  it('gives back every GL object and the canvas when disposed', () => {
    const { gl, container, video } = mount()
    const unpacker = createPackedAlphaUnpacker({ container, video })
    flush()
    unpacker.dispose()
    expect(gl.leaked()).toEqual([])
    expect(container.querySelector('canvas')).toBe(null)
    expect(video.style.display).toBe('')
  })

  it('stops drawing after disposal rather than running against deleted objects', () => {
    const { gl, container, video } = mount()
    const unpacker = createPackedAlphaUnpacker({ container, video })
    flush()
    const drawn = gl.drawArrays.mock.calls.length
    unpacker.dispose()
    flush()
    expect(gl.drawArrays.mock.calls.length).toBe(drawn)
  })

  it('says which shader failed rather than drawing nothing and no reason', () => {
    const { container, video } = mount({ compiles: false })
    expect(() => createPackedAlphaUnpacker({ container, video })).toThrow(/compile/)
  })

  it('says so when the program will not link', () => {
    const { container, video } = mount({ links: false })
    expect(() => createPackedAlphaUnpacker({ container, video })).toThrow(/link/)
  })
})

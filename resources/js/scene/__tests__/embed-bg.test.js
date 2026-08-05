import { describe, it, expect, afterEach } from 'vitest'
import { mountEmbedBg, embedBgSignature } from '../embed-bg.js'

const VIDEO = { kind: 'video', src: 'https://www.youtube.com/embed/abc?autoplay=1', fit: 'cover' }

const host = () => {
  const el = document.createElement('div')
  el.style.position = 'relative'
  document.body.appendChild(el)
  return el
}

afterEach(() => { document.body.innerHTML = '' })

describe('mountEmbedBg', () => {
  it('mounts a full-bleed iframe for a video background', () => {
    const el = host()
    const bg = mountEmbedBg(el, VIDEO)

    const frame = el.querySelector('iframe')
    expect(frame).not.toBeNull()
    expect(frame.src).toBe(VIDEO.src)
    expect(frame.title).toBe('Video background')
    expect(bg.el.style.position).toBe('absolute')
  })

  it('titles a Sketchfab embed as a 3D background and lets it fill the stage', () => {
    const el = host()
    mountEmbedBg(el, { kind: 'sketchfab', src: 'https://sketchfab.com/models/x/embed' })

    const frame = el.querySelector('iframe')
    expect(frame.title).toBe('3D background')
    expect(frame.style.width).toBe('100%')
    expect(frame.style.height).toBe('100%')
  })

  it("letterboxes a 'fit' video instead of overflowing it", () => {
    const el = host()
    mountEmbedBg(el, { ...VIDEO, fit: 'fit' })

    const frame = el.querySelector('iframe')
    expect(frame.style.width).toBe('100%')
    expect(frame.style.transform).toBe('')
  })

  it('stacks at the z-index the caller asks for', () => {
    const el = host()
    const bg = mountEmbedBg(el, VIDEO, { zIndex: 2 })

    expect(bg.el.style.zIndex).toBe('2')
  })

  it('returns null when there is nothing to embed', () => {
    expect(mountEmbedBg(host(), null)).toBeNull()
    expect(mountEmbedBg(host(), { kind: 'video' })).toBeNull()
    expect(document.querySelector('iframe')).toBeNull()
  })

  it('removes the iframe on destroy', () => {
    const el = host()
    mountEmbedBg(el, VIDEO).destroy()

    expect(el.querySelector('iframe')).toBeNull()
  })
})

describe('embedBgSignature', () => {
  it('is stable for the same embed, so a poll re-render never restarts the video', () => {
    expect(embedBgSignature({ ...VIDEO })).toBe(embedBgSignature({ ...VIDEO }))
  })

  it('changes when the source or the fit changes', () => {
    expect(embedBgSignature({ ...VIDEO, src: 'https://vimeo.com/1' })).not.toBe(embedBgSignature(VIDEO))
    expect(embedBgSignature({ ...VIDEO, fit: 'fit' })).not.toBe(embedBgSignature(VIDEO))
  })
})

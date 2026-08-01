import { describe, it, expect } from 'vitest'
import { sameOriginMediaUrl } from '../media-url.js'

const LOCAL = 'http://localhost:8000'
const LIVE = 'https://history.thelearningportal.us'

describe('sameOriginMediaUrl', () => {
  // Why the rewrite exists at all: the page is served from localhost while a stored URL says
  // 127.0.0.1, and the origin mismatch blocks audio playback.
  it('re-homes our own media between loopback spellings', () => {
    expect(sameOriginMediaUrl('http://127.0.0.1:8000/storage/lessons/1/a.mp3', LOCAL))
      .toBe('http://localhost:8000/storage/lessons/1/a.mp3')
  })

  it('keeps the path, query and fragment when it re-homes', () => {
    expect(sameOriginMediaUrl('http://127.0.0.1:8000/storage/a.webp?v=42#x', LOCAL))
      .toBe('http://localhost:8000/storage/a.webp?v=42#x')
  })

  /**
   * The regression this guards: rewriting a Cloudinary cover produced
   * http://localhost:8000/<cloud>/image/upload/… and a 404 on every lesson page.
   */
  it('never rewrites a CDN url onto our own origin', () => {
    const cdn = 'https://res.cloudinary.com/ezl6t3yg/image/upload/f_webp,q_60,c_limit,w_480/v1785451145/lessons/31/cover.webp'

    expect(sameOriginMediaUrl(cdn, LOCAL)).toBe(cdn)
    expect(sameOriginMediaUrl(cdn, LIVE)).toBe(cdn)
  })

  it('leaves any other third-party host alone', () => {
    const commons = 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Painting.jpg'

    expect(sameOriginMediaUrl(commons, LOCAL)).toBe(commons)
  })

  /** A lesson served from the real domain must never have its media pointed at a laptop. */
  it('does not drag production media onto a loopback host', () => {
    const local = 'http://localhost:8000/storage/lessons/1/a.mp3'

    expect(sameOriginMediaUrl(local, LIVE)).toBe(local)
  })

  it('passes same-origin and relative urls through untouched', () => {
    expect(sameOriginMediaUrl('http://localhost:8000/storage/a.mp3', LOCAL)).toBe('http://localhost:8000/storage/a.mp3')
    expect(sameOriginMediaUrl('/storage/a.mp3', LOCAL)).toBe('/storage/a.mp3')
  })

  it('is safe on empty and non-string input', () => {
    expect(sameOriginMediaUrl(null, LOCAL)).toBeNull()
    expect(sameOriginMediaUrl(undefined, LOCAL)).toBeUndefined()
    expect(sameOriginMediaUrl('', LOCAL)).toBe('')
  })
})

import { describe, it, expect } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { buildStarVertices, starColour } from '../starfield.js'

/**
 * The bright star catalogue, and what the layer makes of it.
 *
 * The shipped file is checked as well as the code that reads it, because a catalogue that silently
 * loses its brightest stars, or arrives with declinations where right ascensions should be, gives
 * a sky that still looks like a sky.
 */

describe('star colour from surface temperature', () => {
  it('makes cool stars orange and hot stars blue', () => {
    // Betelgeuse is about 3,600 K and visibly orange; Rigel is about 12,000 K and visibly blue.
    // Getting this backwards is the single most recognisable error a star field can contain.
    const cool = starColour(3600)
    const hot = starColour(12000)
    expect(cool[0]).toBeGreaterThan(cool[2])
    expect(hot[2]).toBeGreaterThan(hot[0])
  })

  it('makes a sun-like star very nearly white', () => {
    const sun = starColour(5800)
    expect(Math.max(...sun) - Math.min(...sun)).toBeLessThan(0.35)
  })

  it('never returns a colour that would darken a star', () => {
    for (const kelvin of [1200, 3000, 5800, 9000, 20000, 40000]) {
      const rgb = starColour(kelvin)
      expect(Math.max(...rgb)).toBeCloseTo(1, 6)
      rgb.forEach((channel) => {
        expect(channel).toBeGreaterThanOrEqual(0)
        expect(channel).toBeLessThanOrEqual(1)
      })
    }
  })

  it('survives nonsense without producing NaN, which draws as nothing at all', () => {
    for (const kelvin of [0, -1, NaN, Infinity]) {
      starColour(kelvin).forEach((channel) => expect(Number.isFinite(channel)).toBe(true))
    }
  })
})

describe('turning the catalogue into vertices', () => {
  const catalogue = (rows) => {
    const data = new Float32Array(rows.length * 4)
    rows.forEach((row, i) => data.set(row, i * 4))
    return data.buffer
  }

  it('keeps right ascension and declination exactly where they were', () => {
    const { vertices, count } = buildStarVertices(catalogue([[101.287, -16.716, -1.46, 9940]]))
    expect(count).toBe(1)
    expect(vertices[0]).toBeCloseTo(101.287, 3)
    expect(vertices[1]).toBeCloseTo(-16.716, 3)
  })

  it('puts the brightest star first in brightness, not last', () => {
    // Magnitude is upside down: smaller is brighter. A sky built by someone who forgot that has
    // Sirius as its faintest star, and still looks like a sky.
    const { vertices } = buildStarVertices(catalogue([
      [0, 0, -1.46, 9940],   // Sirius
      [10, 10, 6.4, 5800],   // just visible on a good night
    ]))
    expect(vertices[2]).toBeGreaterThan(vertices[8])
  })

  it('compresses the range so the faint majority is still there', () => {
    // In raw flux Sirius is about 1,600 times the faintest star here, which drawn literally is one
    // white dot and nine thousand invisible ones.
    const { vertices } = buildStarVertices(catalogue([[0, 0, -1.46, 9940], [10, 10, 6.4, 5800]]))
    const ratio = vertices[2] / vertices[8]
    expect(ratio).toBeGreaterThan(3)
    expect(ratio).toBeLessThan(40)
  })

  it('drops stars fainter than the limit it was given', () => {
    const rows = [[0, 0, 2.0, 5800], [1, 1, 5.0, 5800], [2, 2, 6.9, 5800]]
    expect(buildStarVertices(catalogue(rows), { limitMagnitude: 4.5 }).count).toBe(1)
    expect(buildStarVertices(catalogue(rows), { limitMagnitude: 7 }).count).toBe(3)
  })

  it('lays out six floats a star, which is what the attribute pointers assume', () => {
    const { vertices, count } = buildStarVertices(catalogue([[0, 0, 1, 5800], [1, 1, 2, 5800]]))
    expect(vertices.length).toBe(count * 6)
  })
})

describe('the catalogue file that actually ships', () => {
  const path = resolve(process.cwd(), 'public/data/sky/bright-stars.bin')

  it('is there, because the sky is a download and downloads go missing', () => {
    expect(existsSync(path)).toBe(true)
  })

  it('holds the whole naked-eye sky and knows where Sirius is', () => {
    const file = readFileSync(path)
    const { vertices, count } = buildStarVertices(file.buffer.slice(file.byteOffset, file.byteOffset + file.byteLength))
    // The Bright Star Catalogue is complete to about magnitude 6.5 — every star a person can see.
    expect(count).toBeGreaterThan(8000)

    // The brightest entry must be Sirius, at 6h45m / -16°43'. If the two columns were ever swapped
    // this lands in Ursa Minor instead, and every constellation is somewhere else.
    let brightest = 0
    for (let i = 1; i < count; i++) if (vertices[i * 6 + 2] > vertices[brightest * 6 + 2]) brightest = i
    expect(vertices[brightest * 6]).toBeCloseTo(101.29, 1)
    expect(vertices[brightest * 6 + 1]).toBeCloseTo(-16.72, 1)
  })

  it('has every star on the sky and none off it', () => {
    const file = readFileSync(path)
    const { vertices, count } = buildStarVertices(file.buffer.slice(file.byteOffset, file.byteOffset + file.byteLength))
    for (let i = 0; i < count; i++) {
      expect(vertices[i * 6]).toBeGreaterThanOrEqual(0)
      expect(vertices[i * 6]).toBeLessThan(360)
      expect(Math.abs(vertices[i * 6 + 1])).toBeLessThanOrEqual(90)
    }
  })

  it('is spread over the whole sky rather than bunched in one hemisphere', () => {
    // A parsing error in the declination sign puts the entire catalogue in the north. The real sky
    // is close to even, with slightly more listed in the north because that is where the
    // observatories were.
    const file = readFileSync(path)
    const { vertices, count } = buildStarVertices(file.buffer.slice(file.byteOffset, file.byteOffset + file.byteLength))
    let north = 0
    for (let i = 0; i < count; i++) if (vertices[i * 6 + 1] > 0) north++
    expect(north / count).toBeGreaterThan(0.4)
    expect(north / count).toBeLessThan(0.6)
  })
})

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import {
  gmstDegrees,
  j2000ToPlanet,
  planetToJ2000,
  skyFrameMatrix,
  panoramaUV,
  SKY_EPOCH_LIMIT_YEARS,
} from '../celestial.js'

/**
 * The sky's frame, checked against things that are known independently of this code.
 *
 * Every failure mode here is silent. A sky rotated the wrong way still looks like a sky. A sky
 * mirrored east for west still looks like a sky, still parallaxes correctly, and still raises no
 * error — that exact bug shipped in this file's predecessor and was caught only by someone who
 * knows the constellations. So none of these tests assert that the code does what the code says;
 * they assert against the real sky.
 */

const DEG = Math.PI / 180
const angleBetween = (a, b) => Math.acos(Math.min(1, Math.max(-1, a[0] * b[0] + a[1] * b[1] + a[2] * b[2]))) / DEG
const NORTH = [0, 1, 0]
/** A right ascension and declination as a unit vector, in whatever frame they were quoted in. */
const vec = (ra, dec) => [
  Math.cos(dec * DEG) * Math.cos(ra * DEG),
  Math.sin(dec * DEG),
  Math.cos(dec * DEG) * Math.sin(ra * DEG),
]
/**
 * Compared component by component, not by the angle between them: the matrix is float32, and
 * `acos` near one amplifies its last bit into a fifth of an arcminute. That is a property of the
 * measuring instrument, not of the sky.
 */
const expectSameDirection = (actual, expected) =>
  actual.forEach((v, i) => expect(v).toBeCloseTo(expected[i], 6))

describe('sidereal time', () => {
  it('puts the Greenwich meridian where the almanac says at the J2000 epoch', () => {
    // GMST at 2000 January 1, 12h UT is 18h 41m 50.5s = 280.46 degrees. This is the number every
    // sidereal formula is anchored to, so if it is wrong nothing downstream can be right.
    expect(gmstDegrees(new Date(Date.UTC(2000, 0, 1, 12)))).toBeCloseTo(280.46, 1)
  })

  it('advances by a sidereal day, not a solar one', () => {
    // The stars come back four minutes early every day. Over a year that is one extra turn, and a
    // sky that uses the solar day instead drifts a full 360 degrees across twelve months while
    // looking perfectly plausible on any single date.
    const start = new Date(Date.UTC(2026, 5, 1, 0))
    const solarDayLater = new Date(start.getTime() + 86400000)
    const drift = ((gmstDegrees(solarDayLater) - gmstDegrees(start)) % 360 + 360) % 360
    expect(drift).toBeCloseTo(0.9856, 2)
  })
})

describe('placing a star on the turning earth', () => {
  const date = new Date(Date.UTC(2026, 7, 10, 12))

  it('puts a star on the Greenwich meridian when its right ascension equals sidereal time', () => {
    // The definition of sidereal time, restated as geometry: longitude = right ascension - GMST.
    const dir = j2000ToPlanet(gmstDegrees(date), 0, date)
    const lng = Math.atan2(dir[2], dir[0]) / DEG
    // Precession moves the J2000 grid a little relative to the pole of date; a couple of tenths of
    // a degree over a quarter century is the expected size of the disagreement.
    expect(Math.abs(lng)).toBeLessThan(0.5)
  })

  it('puts the celestial pole at the top of the world', () => {
    const pole = j2000ToPlanet(0, 90, new Date(Date.UTC(2000, 0, 1, 12)))
    expect(angleBetween(pole, NORTH)).toBeLessThan(0.01)
  })

  it('turns the sky once a sidereal day and not once a year', () => {
    const noon = j2000ToPlanet(90, 0, date)
    const sixHoursLater = j2000ToPlanet(90, 0, new Date(date.getTime() + 6 * 3600 * 1000))
    // A quarter turn, near enough: six hours of earth rotation is 90.25 degrees of sidereal angle.
    expect(angleBetween(noon, sixHoursLater)).toBeGreaterThan(89)
    expect(angleBetween(noon, sixHoursLater)).toBeLessThan(92)
  })

  it('round-trips a direction back to the coordinates it came from', () => {
    for (const [ra, dec] of [[0, 0], [123.4, -45.6], [359.9, 89], [180, -89]]) {
      const back = planetToJ2000(j2000ToPlanet(ra, dec, date), date)
      expect(back.dec).toBeCloseTo(dec, 4)
      expect(Math.abs(((back.ra - ra + 540) % 360) - 180)).toBeLessThan(1e-3)
    }
  })
})

describe('precession — the reason a history map cannot use a fixed sky', () => {
  /**
   * The earth's axis swings round a 26,000-year circle, so "north" on the sky is not where it was.
   * Over the span this map covers that is not a subtlety: it changes which star the pole star is.
   */
  /** Where the pole of a given date sits on the fixed J2000 grid the catalogue is quoted in. */
  const poleOfDateInJ2000 = (year) => {
    const { ra, dec } = planetToJ2000(NORTH, new Date(Date.UTC(year, 0, 1)))
    return vec(ra, dec)
  }

  it('has Polaris as the pole star now, and closest around 2100', () => {
    const POLARIS = vec(37.9529, 89.2641)
    const now = angleBetween(POLARIS, poleOfDateInJ2000(2000))
    expect(now).toBeGreaterThan(0.5)
    expect(now).toBeLessThan(0.9)
    expect(angleBetween(POLARIS, poleOfDateInJ2000(2100))).toBeLessThan(now)
  })

  it('has Thuban as the pole star when the pyramids were built', () => {
    // Alpha Draconis was within a fraction of a degree of the pole around 2700 BC, which is why the
    // descending passage of the Great Pyramid points at it. With the sign of precession flipped
    // this test puts Thuban sixty degrees away instead of on the pole.
    const THUBAN = vec(211.0973, 64.3758)
    expect(angleBetween(THUBAN, poleOfDateInJ2000(-2700))).toBeLessThan(3)
  })

  it('says out loud how far it can be trusted', () => {
    // The series is a cubic fitted near J2000. It is not valid for the Pleistocene and the constant
    // is here so nobody has to read the paper to find that out.
    expect(SKY_EPOCH_LIMIT_YEARS).toBeGreaterThan(2000)
    expect(SKY_EPOCH_LIMIT_YEARS).toBeLessThan(20000)
  })
})

describe('the panorama lookup, against the panorama', () => {
  /**
   * The one test that a mirrored sky cannot pass.
   *
   * A 128x64 luminance thumbnail of the shipped panorama is committed beside these tests, so this
   * can check the frame against real pixels rather than against its own arithmetic. The galactic
   * centre is the brightest thing in the sky and its coordinates are known: if the lookup is
   * mirrored, flipped, or rotated, the brightest patch is not where this says it is.
   */
  const W = 128, H = 64
  const luma = readFileSync(resolve(process.cwd(), 'resources/js/timemap/__tests__/fixtures/milkyway-luma.bin'))
  const sampleAt = (ra, dec) => {
    const [u, v] = panoramaUV(ra, dec)
    const x = Math.min(W - 1, Math.max(0, Math.floor((((u % 1) + 1) % 1) * W)))
    const y = Math.min(H - 1, Math.max(0, Math.floor(v * H)))
    return luma[y * W + x]
  }

  const GALACTIC_CENTRE = [266.405, -28.936]
  const GALACTIC_ANTICENTRE = [86.405, 28.936]
  const NORTH_GALACTIC_POLE = [192.859, 27.128]

  it('finds the galactic centre where the galactic centre is', () => {
    expect(sampleAt(...GALACTIC_CENTRE)).toBeGreaterThan(3 * sampleAt(...NORTH_GALACTIC_POLE))
  })

  it('is not the mirror of itself — the anticentre is the dim end', () => {
    // The band alone cannot catch a double flip, because reflecting right ascension AND declination
    // maps the galactic equator nearly onto itself. The centre against the anticentre can.
    expect(sampleAt(...GALACTIC_CENTRE)).toBeGreaterThan(2 * sampleAt(...GALACTIC_ANTICENTRE))
  })

  it('lines the band up with the galactic equator across the whole sky', () => {
    const NGP = { ra: 192.85948 * DEG, dec: 27.12825 * DEG }
    const galacticLatitude = (ra, dec) => Math.asin(
      Math.sin(dec * DEG) * Math.sin(NGP.dec) + Math.cos(dec * DEG) * Math.cos(NGP.dec) * Math.cos(ra * DEG - NGP.ra),
    ) / DEG

    let band = 0, bandCount = 0, poles = 0, poleCount = 0
    for (let i = 0; i < 4000; i++) {
      const ra = (i * 137.508) % 360
      const dec = Math.asin(2 * ((i * 0.6180339887) % 1) - 1) / DEG
      const b = Math.abs(galacticLatitude(ra, dec))
      if (b < 5) { band += sampleAt(ra, dec); bandCount++ }
      else if (b > 60) { poles += sampleAt(ra, dec); poleCount++ }
    }
    expect(band / bandCount).toBeGreaterThan(4 * (poles / poleCount))
  })
})

describe('the frame handed to the shader', () => {
  const date = new Date(Date.UTC(1492, 9, 12, 5))

  it('is the same rotation the scalar functions apply, as a mat3', () => {
    // The shader gets a matrix and the tests above check functions. They have to be the same thing,
    // or the sky in the browser is not the sky these tests passed on.
    const m = skyFrameMatrix(date)
    expect(m).toHaveLength(9)
    for (const dir of [[1, 0, 0], [0, 1, 0], [0, 0, 1], [0.5, 0.5, Math.SQRT1_2]]) {
      const length = Math.hypot(...dir)
      const unit = dir.map((v) => v / length)
      // Column-major, as GLSL reads a mat3: result = M * v.
      const mapped = [0, 1, 2].map((row) => m[row] * unit[0] + m[3 + row] * unit[1] + m[6 + row] * unit[2])
      const expected = planetToJ2000(unit, date)
      expectSameDirection(mapped, vec(expected.ra, expected.dec))
    }
  })

  it('is a rotation, so the sky is not stretched or turned inside out', () => {
    const m = skyFrameMatrix(date)
    const col = (i) => [m[i * 3], m[i * 3 + 1], m[i * 3 + 2]]
    for (let i = 0; i < 3; i++) expect(Math.hypot(...col(i))).toBeCloseTo(1, 6)
    const [a, b, c] = [col(0), col(1), col(2)]
    // A right-handed frame has determinant +1. Minus one is a mirror, which is the bug this whole
    // file exists to make impossible.
    const determinant = a[0] * (b[1] * c[2] - b[2] * c[1])
      - a[1] * (b[0] * c[2] - b[2] * c[0])
      + a[2] * (b[0] * c[1] - b[1] * c[0])
    expect(determinant).toBeCloseTo(1, 6)
  })

  it('transposes to the star placement, so the two can never disagree', () => {
    // The panorama is sampled with M and the catalogue stars are placed with its transpose, in the
    // shader, from one uniform. Two matrices would be two things to keep in step.
    const m = skyFrameMatrix(date)
    const star = j2000ToPlanet(101.287, -16.716, date)      // Sirius
    const roundTrip = [0, 1, 2].map((row) => m[row] * star[0] + m[3 + row] * star[1] + m[6 + row] * star[2])
    expectSameDirection(roundTrip, vec(101.287, -16.716))
  })
})

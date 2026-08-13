import { describe, it, expect } from 'vitest'
import {
  solarVisibleFraction,
  solarAngularRadius,
  eclipseAt,
  eclipseIsPossible,
  deepestEclipse,
  MOON_RADIUS_EARTHS,
} from '../eclipse.js'
import { sunDirection } from '../sun.js'
import { moonVector } from '../moon.js'
import { planetSpacePosition } from '../planet-mesh.js'

/**
 * An eclipse is the one piece of sky geometry a history lesson can put a DATE on, so it is worth
 * computing rather than faking. The maths is a circle covering a circle — the visible fraction of
 * the sun's disc — and every interesting case is a degenerate one: the moon entirely inside the
 * sun (annular), entirely covering it (total), or exactly grazing.
 */

describe('how much of the sun is left', () => {
  const SUN = 0.004652     // radians, the sun's angular RADIUS from earth: about 16 arcmin

  it('leaves the sun whole when the moon is nowhere near it', () => {
    expect(solarVisibleFraction(SUN, 0.0045, 0.5)).toBe(1)
  })

  it('leaves it whole at the exact moment of grazing', () => {
    expect(solarVisibleFraction(SUN, 0.0045, SUN + 0.0045)).toBeCloseTo(1, 6)
  })

  it('blacks it out when a larger moon sits on top of it', () => {
    // The moon at perigee is wider than the sun. This is totality.
    expect(solarVisibleFraction(SUN, 0.0050, 0)).toBe(0)
  })

  it('leaves a ring when a smaller moon sits on top of it', () => {
    // Apogee: the moon is too small to cover the disc, and an annular eclipse leaves a ring of
    // sun all the way round. The fraction left is exactly the area ratio.
    const moon = 0.0042
    expect(solarVisibleFraction(SUN, moon, 0)).toBeCloseTo(1 - (moon / SUN) ** 2, 9)
  })

  it('matches the closed form for two equal discs half a radius apart', () => {
    // Two circles of radius r whose centres are r apart overlap by 1.2284 r², so 39.10% is hidden.
    // A worked case with an exact answer, which is what keeps the segment algebra honest.
    expect(solarVisibleFraction(SUN, SUN, SUN)).toBeCloseTo(0.6090, 4)
  })

  it('falls away as the moon closes in, and never leaves 0..1', () => {
    let previous = 1
    for (let step = 20; step >= 0; step--) {
      const fraction = solarVisibleFraction(SUN, SUN * 1.05, (SUN * 2.05 * step) / 20)
      expect(fraction).toBeGreaterThanOrEqual(0)
      expect(fraction).toBeLessThanOrEqual(1)
      expect(fraction).toBeLessThanOrEqual(previous + 1e-12)
      previous = fraction
    }
    expect(previous).toBe(0)
  })
})

describe('the sun\'s own size', () => {
  it('is about sixteen arcminutes of radius, all year', () => {
    for (const month of [0, 3, 6, 9]) {
      const radius = solarAngularRadius(new Date(Date.UTC(2026, month, 15)))
      const arcmin = radius * 180 * 60 / Math.PI
      expect(arcmin).toBeGreaterThan(15.7)
      expect(arcmin).toBeLessThan(16.4)
    }
  })

  it('is largest in January, when earth is closest to the sun', () => {
    // Perihelion is around 3 January. This is the term that decides whether a given eclipse is
    // total or annular, so it is not decoration.
    const january = solarAngularRadius(new Date(Date.UTC(2026, 0, 3)))
    const july = solarAngularRadius(new Date(Date.UTC(2026, 6, 5)))
    expect(january).toBeGreaterThan(july)
    expect(january / july).toBeCloseTo(1.034, 2)
  })
})

describe('an eclipse seen from a particular place', () => {
  const date = new Date(Date.UTC(2026, 7, 12, 18, 0))
  const sun = sunDirection(date)
  const moon = moonVector(date)

  it('is unobstructed where the moon is not in the way', () => {
    // The antipode of wherever the moon is: the moon is below the horizon there, let alone in
    // front of the sun.
    const away = moon.map((c) => -c / Math.hypot(...moon))
    expect(eclipseAt(away, sun, moon, solarAngularRadius(date))).toBe(1)
  })

  it('depends on WHERE you stand — that is the whole reason a path is narrow', () => {
    /**
     * The moon is only sixty earth radii away, so its direction genuinely differs from one side of
     * the earth to the other. That parallax is why totality is a hundred-kilometre track rather
     * than a hemisphere. Compute the moon's direction once, globally, and the umbra swells to
     * cover half the planet — which still looks like an eclipse, and is wrong by a continent.
     */
    const samples = []
    for (let lat = -85; lat <= 85; lat += 5) {
      for (let lng = -180; lng < 180; lng += 5) {
        const point = planetSpacePosition(lng, lat, 0)
        samples.push(eclipseAt(point, sun, moon, solarAngularRadius(date)))
      }
    }
    const darkest = Math.min(...samples)
    const brightest = Math.max(...samples)
    expect(brightest).toBe(1)
    expect(darkest).toBeLessThan(0.5)
  })
})

describe('finding the eclipse on a real date', () => {
  /**
   * 12 August 2026 is a real total solar eclipse — Greenland, Iceland, northern Spain. Being able
   * to name a date and have the shadow appear where it actually was is the reason this is worth
   * computing at all: for a history lesson an eclipse is a dated event, not decoration.
   *
   * The tolerances are loose on purpose. sun.js is good to about 0.01 degrees and moon.js to a few
   * tenths, and the moon's disc is only 0.26 degrees across, so the umbra's exact track is beyond
   * what this ephemeris can promise. That an eclipse happens, is deep, and is in the right part of
   * the world is well inside it.
   */
  it('finds a deep eclipse on 12 August 2026', () => {
    const { fraction } = deepestEclipse(new Date(Date.UTC(2026, 7, 12, 17, 46)))
    expect(fraction).toBeLessThan(0.1)
  })

  it('puts it over the north Atlantic, not somewhere else entirely', () => {
    // Greenland, Iceland, then Spain at sunset. With the subsolar point near 89 degrees west at
    // this hour, that whole track is late afternoon and evening.
    const { lng, lat } = deepestEclipse(new Date(Date.UTC(2026, 7, 12, 17, 46)))
    expect(lat).toBeGreaterThan(30)
    expect(lng).toBeGreaterThan(-70)
    expect(lng).toBeLessThan(20)
  })

  it('never reports an eclipse for somebody standing in the dark', () => {
    /**
     * The moon at new moon lies roughly in the sun's direction and is sixty earth radii out, so
     * from a point on the NIGHT side it is still very nearly in front of the sun. The bare
     * geometry says "eclipse"; the earth says "you cannot see either of them". The first run of
     * this put the August 2026 totality over central Asia, at midnight, and it looked entirely
     * plausible until you checked the local time.
     */
    const date = new Date(Date.UTC(2026, 7, 12, 17, 46))
    const { lng, lat } = deepestEclipse(date)
    const sun = sunDirection(date)
    const point = planetSpacePosition(lng, lat, 0)
    const sunAboveHorizon = point[0] * sun[0] + point[1] * sun[1] + point[2] * sun[2]
    expect(sunAboveHorizon).toBeGreaterThan(0)
  })

  it('finds totality on four eclipses that actually happened', () => {
    /**
     * The strongest test in this file, because it is ABSOLUTE. Every other assertion about the
     * moon is relative — the perigee range, the drift rate, the synodic month — and a moon that is
     * consistently in the wrong place passes all of them. That is exactly how moon.js carried a
     * twenty-degree epoch error for as long as it did.
     *
     * A date is not relative. Four historical eclipses, each of which either goes total here or
     * does not:
     *
     *   1919-05-29  Eddington's expedition, the one that measured starlight bending round the sun
     *   1999-08-11  the last European totality most people alive remember
     *   2024-04-08  Mexico, Texas, the Great Lakes
     *   2026-08-12  Greenland, Iceland, Spain at sunset
     *
     * The place is checked only to a hemisphere. The exact track is a hundred kilometres wide and
     * this ephemeris promises a tenth of a degree, which is several times that — and the quoted
     * times are approximate too. What is being pinned here is that totality happens, on the day,
     * on the right side of the planet.
     */
    const eclipses = [
      { when: '1919-05-29T13:08:00Z', lngRange: [-40, 20], latRange: [-20, 20] },
      { when: '1999-08-11T11:03:00Z', lngRange: [-10, 50], latRange: [30, 65] },
      { when: '2024-04-08T18:17:00Z', lngRange: [-120, -60], latRange: [10, 50] },
      { when: '2026-08-12T17:46:00Z', lngRange: [-70, 20], latRange: [30, 80] },
    ]
    for (const { when, lngRange, latRange } of eclipses) {
      const { fraction, lng, lat } = deepestEclipse(new Date(when))
      expect(fraction, `${when} should reach totality`).toBeLessThan(0.05)
      expect(lng, `${when} longitude`).toBeGreaterThanOrEqual(lngRange[0])
      expect(lng, `${when} longitude`).toBeLessThanOrEqual(lngRange[1])
      expect(lat, `${when} latitude`).toBeGreaterThanOrEqual(latRange[0])
      expect(lat, `${when} latitude`).toBeLessThanOrEqual(latRange[1])
    }
  })

  it('finds nothing at all on an ordinary day', () => {
    // A fortnight later the moon is nowhere near the ecliptic node and no part of earth is shaded.
    expect(deepestEclipse(new Date(Date.UTC(2026, 7, 26, 12))).fraction).toBe(1)
  })

  it('says in advance whether it is worth looking, so the shader can skip the trigonometry', () => {
    // Eclipses are rare and this runs per fragment. The cheap geocentric test has to be
    // conservative: it may say "possible" on a near miss, but never "impossible" during one.
    expect(eclipseIsPossible(new Date(Date.UTC(2026, 7, 12, 17, 46)))).toBe(true)
    expect(eclipseIsPossible(new Date(Date.UTC(2026, 7, 26, 12)))).toBe(false)
  })

  it('never rules out an eclipse that is actually happening', () => {
    // The guard is the one thing that can silently delete the feature: a false "impossible" is a
    // missing eclipse with nothing to show why. Swept across the whole eclipse day.
    for (let minutes = 0; minutes < 24 * 60; minutes += 20) {
      const date = new Date(Date.UTC(2026, 7, 12, 0, minutes))
      if (deepestEclipse(date).fraction < 1) {
        expect(eclipseIsPossible(date)).toBe(true)
      }
    }
  })
})

describe('the moon\'s size as the shader is told it', () => {
  it('is the real ratio to earth\'s radius', () => {
    expect(MOON_RADIUS_EARTHS).toBeCloseTo(1737.4 / 6371.0088, 5)
  })
})

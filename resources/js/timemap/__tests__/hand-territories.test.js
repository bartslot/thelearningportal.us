import { describe, expect, it } from 'vitest'
import {
  convexHull,
  handTerritories,
  handTerritorySpec,
  provenanceFor,
  softExtent,
  territoryById,
} from '../hand-territories.js'

/**
 * Two different things are under test here and they fail in different ways.
 *
 * The GEOMETRY tests are ordinary: a ring must close, a shape must contain its own spine, a radius
 * must mean kilometres. Those catch code.
 *
 * The DATA tests are the ones that matter more, and they exist because this dataset's failure mode
 * is not a crash — it is a border on a school map with nobody able to say where it came from. So
 * every territory must carry graded sources, must be drawn from the highest grade it has, and must
 * say what the sources it rejected claimed instead. A shape without that paper trail cannot be
 * checked by the next person and will be re-litigated from scratch.
 *
 * They are also the tests that fail when a HUMAN adds a territory carelessly, which is the actual
 * risk. Nothing here can be satisfied by writing more code.
 */

const KM_PER_DEGREE_LAT = 111.32

/** Great-circle-ish distance in km, good enough at these scales to check a radius. */
const distanceKm = ([lon1, lat1], [lon2, lat2]) => {
  const meanLat = ((lat1 + lat2) / 2) * (Math.PI / 180)
  const dx = (lon2 - lon1) * KM_PER_DEGREE_LAT * Math.cos(meanLat)
  const dy = (lat2 - lat1) * KM_PER_DEGREE_LAT
  return Math.hypot(dx, dy)
}

/** Ray casting, so "does the shape actually contain the line it was drawn from" is answerable. */
const ringContains = (ring, [x, y]) => {
  let inside = false
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i]
    const [xj, yj] = ring[j]
    if ((yi > y) !== (yj > y) && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside
  }
  return inside
}

describe('convexHull', () => {
  it('returns the corners of a square and drops the interior points', () => {
    const hull = convexHull([[0, 0], [1, 0], [1, 1], [0, 1], [0.5, 0.5], [0.4, 0.6]])

    expect(hull).toHaveLength(4)
    expect(hull.map((p) => p.join(','))).toEqual(
      expect.arrayContaining(['0,0', '1,0', '1,1', '0,1']),
    )
  })

  it('drops points that lie ON an edge, not just inside', () => {
    // A collinear point is the classic monotone-chain off-by-one: keep it and the ring grows a
    // redundant vertex every time a disc lines up with its neighbour, which is most of them.
    const hull = convexHull([[0, 0], [2, 0], [1, 0], [2, 2], [0, 2]])

    expect(hull).toHaveLength(4)
  })
})

describe('softExtent', () => {
  const spine = [[10, 52], [11, 52]]

  it('closes the ring', () => {
    const ring = softExtent(spine, 50)

    // An unclosed ring renders as nothing at all, with no error anywhere on the way.
    expect(ring[0]).toEqual(ring[ring.length - 1])
    expect(ring.length).toBeGreaterThan(8)
  })

  it('contains every point of the spine it was drawn from', () => {
    const ring = softExtent(spine, 50)

    for (const point of spine) expect(ringContains(ring, point)).toBe(true)
  })

  it('reaches the stated radius in kilometres, not in degrees', () => {
    // THE absolute check. A radius applied in degrees rather than kilometres still produces a
    // plausible blob in the right place, still contains the spine, and is out by a factor of a
    // hundred. Every relative assertion above passes on that bug.
    const ring = softExtent([[10, 52]], 100)
    const distances = ring.map((p) => distanceKm([10, 52], p))

    expect(Math.min(...distances)).toBeGreaterThan(95)
    expect(Math.max(...distances)).toBeLessThan(105)
  })

  it('stretches along the spine rather than ballooning around it', () => {
    const ring = softExtent([[10, 52], [13, 52]], 40)
    const lons = ring.map(([lon]) => lon)
    const lats = ring.map(([, lat]) => lat)

    const widthKm = distanceKm([Math.min(...lons), 52], [Math.max(...lons), 52])
    const heightKm = (Math.max(...lats) - Math.min(...lats)) * KM_PER_DEGREE_LAT

    // ~205 km wide (3 degrees of longitude at 52N plus two radii) against ~80 km tall.
    expect(widthKm).toBeGreaterThan(heightKm * 2)
  })

  it('is convex, so the outline never claims a detail the evidence has not got', () => {
    const ring = softExtent([[10, 52], [11, 52.4], [12, 52]], 60)
    const open = ring.slice(0, -1)

    for (let i = 0; i < open.length; i++) {
      const [ax, ay] = open[i]
      const [bx, by] = open[(i + 1) % open.length]
      const [cx, cy] = open[(i + 2) % open.length]
      const cross = (bx - ax) * (cy - ay) - (by - ay) * (cx - ax)
      expect(cross).toBeGreaterThanOrEqual(-1e-9)
    }
  })
})

describe('the hand-authored territory set', () => {
  const territories = handTerritorySpec.territories

  it('renders as a FeatureCollection of closed polygons', () => {
    const collection = handTerritories()

    expect(collection.type).toBe('FeatureCollection')
    expect(collection.features).toHaveLength(territories.length)

    for (const feature of collection.features) {
      const ring = feature.geometry.coordinates[0]
      expect(feature.geometry.type).toBe('Polygon')
      expect(ring[0]).toEqual(ring[ring.length - 1])
    }
  })

  it('speaks Cliopatria\'s property names, so nothing downstream needs a special case', () => {
    // This is what makes "a lesson cannot tell the difference" true rather than aspirational: the
    // year filter, the colour hash, the label pass and the click handler all read these five keys.
    for (const feature of handTerritories().features) {
      expect(Object.keys(feature.properties)).toEqual(
        expect.arrayContaining(['Name', 'Wikidata', 'FromYear', 'ToYear', 'Type']),
      )
      expect(feature.properties.Type).toBe('POLITY')
      expect(feature.properties.Wikidata).toMatch(/^Q\d+$/)
      expect(feature.properties.hand).toBe(true)
    }
  })

  it('covers the two lessons this layer exists for', () => {
    // Teutoburg Forest, 9 CE, and the Marcomannic Wars, 166-180 CE. Cliopatria's earliest Germanic
    // polity is the Goths at 30 CE, so before this layer both years had nothing to shade.
    const alive = (year) => territories.filter((t) => t.from <= year && t.to >= year).map((t) => t.name)

    expect(alive(9)).toEqual(expect.arrayContaining(['Cherusci', 'Chatti', 'Suebi']))
    expect(alive(170)).toEqual(expect.arrayContaining(['Marcomanni', 'Quadi']))
  })

  it('gives every territory a lifespan that runs forwards', () => {
    for (const t of territories) {
      expect(Number.isInteger(t.from), `${t.name} from`).toBe(true)
      expect(Number.isInteger(t.to), `${t.name} to`).toBe(true)
      expect(t.to, `${t.name} ends before it starts`).toBeGreaterThan(t.from)
    }
  })

  it('has a unique id and a unique Wikidata item per territory', () => {
    const ids = territories.map((t) => t.id)
    const qids = territories.map((t) => t.qid)

    expect(new Set(ids).size).toBe(ids.length)
    expect(new Set(qids).size).toBe(qids.length)
  })
})

describe('provenance', () => {
  const territories = handTerritorySpec.territories

  it('records at least three graded sources for every territory', () => {
    for (const t of territories) {
      const { sources } = t.provenance
      expect(sources.length, `${t.name} has fewer than three sources`).toBeGreaterThanOrEqual(3)

      for (const source of sources) {
        expect(source.title, `${t.name}: a source with no title`).toBeTruthy()
        expect(source.url, `${t.name}: ${source.title} has no url`).toMatch(/^https?:\/\//)
        expect(['A', 'B', 'C', 'D'], `${t.name}: ${source.title} grade`).toContain(source.grade)
      }
    }
  })

  it('never lets an F onto the map', () => {
    // Not a weak source. Not a source. AI-generated, uncredited, or traced from fiction.
    for (const t of territories) {
      for (const source of t.provenance.sources) expect(source.grade).not.toBe('F')
    }
  })

  it('draws every territory from the highest grade it holds', () => {
    // The rule that stops a shape being traced from the prettiest map rather than the best one.
    for (const t of territories) {
      const chosen = t.provenance.sources.find((s) => s.key === t.provenance.chosen_source)
      expect(chosen, `${t.name}: chosen_source names no source in the list`).toBeTruthy()

      const best = t.provenance.sources.map((s) => s.grade).sort()[0]
      expect(chosen.grade, `${t.name} was drawn from a ${chosen.grade} while a ${best} was available`).toBe(best)
    }
  })

  it('writes down what the rejected sources said instead', () => {
    // The disagreement IS the finding. Without it, "which source, and what did the others say?" —
    // the first question a teacher's report raises — has no answer and the work is redone.
    for (const t of territories) {
      const { disagreements, sources } = t.provenance
      expect(disagreements.length, `${t.name} records no disagreement at all`).toBeGreaterThan(0)

      for (const d of disagreements) {
        expect(sources.map((s) => s.key), `${t.name}: disagreement cites unknown source ${d.source}`).toContain(d.source)
        expect(d.says.length, `${t.name}: an empty disagreement`).toBeGreaterThan(20)
      }
    }
  })

  it('states a confidence, a method and who drew it', () => {
    for (const t of territories) {
      expect(['high', 'medium', 'low'], `${t.name} confidence`).toContain(t.provenance.confidence)
      expect(t.provenance.method.length, `${t.name} method`).toBeGreaterThan(20)
      expect(t.provenance.drawn_by, `${t.name} drawn_by`).toBeTruthy()
      expect(t.provenance.summary.length, `${t.name} needs a plain-language summary`).toBeGreaterThan(20)
    }
  })

  it('says so out loud when the Suebi shape is a scholarly guess', () => {
    // Not a generic check. "Suebi" was an umbrella for peoples spread over half of Germania —
    // Caesar meets them on the Rhine, 500 km from where we draw them. Any single shape is wrong,
    // and a teacher choosing between two lessons deserves to be told which border is firm.
    const suebi = territoryById('suebi-elbe')

    expect(suebi.provenance.confidence).toBe('low')
    expect(suebi.provenance.summary.toLowerCase()).toContain('caution')
  })

  it('answers provenanceFor by id, and refuses ids that are not ours', () => {
    expect(provenanceFor('cherusci').chosen_source).toBe('barrington-awmc')
    expect(provenanceFor('Q17167')).toBeNull()
  })
})

/**
 * hand-territories.js — the territories we drew ourselves, and the shape of what we actually know.
 *
 * Cliopatria is good and it is incomplete. It has no Cherusci, no Chatti, no Marcomanni and no
 * Quadi at all, and its earliest Germanic polity is the Goths at 30 CE — so a lesson on Teutoburg
 * Forest in 9 CE, or on the Marcomannic Wars, has nothing to shade. This layer is the gap-filler.
 *
 * It is a SEPARATE source from Cliopatria on purpose, and that purpose runs both ways: an upstream
 * Cliopatria refresh can never silently overwrite hand-drawn work, and hand-drawn work is never
 * mistaken for theirs. Everything downstream — fill, border, label, hover, click, the year slider —
 * treats it as an ordinary territory, because a lesson has no business knowing the difference.
 *
 *
 * WHY THE DATA IS A LINE AND A RADIUS, AND NOT A POLYGON
 *
 * The best source for a Roman-era people is the Barrington Atlas, and the Barrington Atlas does not
 * draw a border for one. It prints the NAME ACROSS the region — a label, running along the axis the
 * cartographer judged the people to occupy. Pleiades and the Ancient World Mapping Center publish
 * that label as georeferenced linework, which is the single most authoritative geometry that exists
 * for these peoples, and it is a line rather than an area.
 *
 * So the file stores the line, and this module derives the area by ONE stated rule. Storing a
 * polygon instead would launder a label placement into a frontier nobody ever claimed, and by the
 * time anyone came back to check, the derivation would be gone and only the frontier would remain.
 *
 * The rule is: the convex hull of discs of `radius_km` centred on every point of the spine. For a
 * short spine that is a circle; for a long one, a lozenge along it.
 *
 *
 * THE SHAPE LOOKS COMPUTED, AND THAT IS THE POINT
 *
 * The obvious next move is a wobble — some deterministic noise on the outline so it reads as
 * hand-drawn rather than as a pill. Deliberately not done. A wobbly outline says "we know this
 * shape"; a circle says "somewhere around here, we are not claiming to know how far". The second
 * one is true. A hard edge, however handsome, tells a pupil we know something we do not.
 *
 * The renderer carries the same message: these territories draw with a blurred, dashed border, and
 * a low-confidence one draws softer still. See HAND_LINE_* in index.js.
 */

import spec from '../../../database/data/hand-territories.json'

/** Metres per degree of latitude — near enough constant, and the only constant this needs. */
const KM_PER_DEGREE_LAT = 111.32

/**
 * Points sampled around each disc. 64 is far past what the eye can resolve at this scale, and the
 * whole set is five shapes — there is nothing to save by being clever here.
 */
const DISC_SAMPLES = 64

const toRadians = (degrees) => (degrees * Math.PI) / 180

/**
 * Local flat frame, so "a radius in kilometres" is a real circle rather than an ellipse.
 *
 * An equirectangular projection about the spine's own mean latitude. Over a few hundred kilometres
 * at European latitudes the distortion is well under a kilometre, which is an order of magnitude
 * finer than anything this data claims.
 */
const localFrame = (spine) => {
  const meanLat = spine.reduce((sum, [, lat]) => sum + lat, 0) / spine.length
  const kmPerDegreeLon = KM_PER_DEGREE_LAT * Math.cos(toRadians(meanLat))
  return {
    forward: ([lon, lat]) => [lon * kmPerDegreeLon, lat * KM_PER_DEGREE_LAT],
    inverse: ([x, y]) => [x / kmPerDegreeLon, y / KM_PER_DEGREE_LAT],
  }
}

/** Andrew's monotone chain. Returns the hull counter-clockwise, without the closing repeat. */
export const convexHull = (points) => {
  if (points.length < 3) return [...points]
  const sorted = [...points].sort((a, b) => (a[0] - b[0]) || (a[1] - b[1]))
  const cross = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0])

  const half = (input) => {
    const out = []
    for (const p of input) {
      while (out.length >= 2 && cross(out[out.length - 2], out[out.length - 1], p) <= 0) out.pop()
      out.push(p)
    }
    out.pop()
    return out
  }

  return [...half(sorted), ...half([...sorted].reverse())]
}

/**
 * The soft extent around a spine: the convex hull of discs of `radiusKm` on every spine point.
 *
 * Returned as a CLOSED linear ring (first point repeated last), because an unclosed ring is the
 * classic way to get a polygon that renders as nothing at all with no error anywhere.
 *
 * @param {Array<[number, number]>} spine  lng/lat, the source's own label linework
 * @param {number} radiusKm
 * @returns {Array<[number, number]>} a closed ring in lng/lat
 */
export const softExtent = (spine, radiusKm) => {
  const { forward, inverse } = localFrame(spine)
  const cloud = []

  for (const point of spine) {
    const [x, y] = forward(point)
    for (let i = 0; i < DISC_SAMPLES; i++) {
      const angle = (i / DISC_SAMPLES) * 2 * Math.PI
      cloud.push([x + radiusKm * Math.cos(angle), y + radiusKm * Math.sin(angle)])
    }
  }

  const ring = convexHull(cloud).map(inverse)
  return ring.length ? [...ring, ring[0]] : ring
}

/**
 * The properties a territory needs to be treated as one.
 *
 * `Name` / `Wikidata` / `FromYear` / `ToYear` / `Type` are spelled exactly as Cliopatria spells
 * them, because every filter, label, colour hash and click path on this map already reads those
 * keys. Matching the vocabulary is what makes "renders as an ordinary territory" true rather than
 * aspirational — none of those code paths needed a branch for this layer.
 *
 * `hand` and `confidence` are the additions, and they exist to be honest rather than to be clever:
 * one opens the provenance panel, the other softens the border.
 *
 * The provenance itself is deliberately NOT in here. MapLibre flattens nested feature properties to
 * strings on the way back out of queryRenderedFeatures, so a provenance object would arrive at the
 * click handler as JSON in a string — parseable, and quietly lossy the first time a field contains
 * something unexpected. It is looked up by id from the spec instead; see provenanceFor.
 */
const featureFor = (territory) => ({
  type: 'Feature',
  id: territory.id,
  properties: {
    id: territory.id,
    Name: territory.name,
    Wikidata: territory.qid,
    FromYear: territory.from,
    ToYear: territory.to,
    Type: 'POLITY',
    hand: true,
    confidence: territory.provenance.confidence,
  },
  geometry: {
    type: 'Polygon',
    coordinates: [softExtent(territory.shape.spine, territory.shape.radius_km)],
  },
})

/** Rough area of a closed ring, in square degrees. Only ever compared against another one. */
const ringArea = (ring) => {
  let twiceArea = 0
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    twiceArea += ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1]
  }
  return Math.abs(twiceArea / 2)
}

/**
 * Every hand-authored territory, as a FeatureCollection ready for a GeoJSON source.
 *
 * BIGGEST FIRST, and that ordering is load-bearing rather than tidy. MapLibre draws a GeoJSON
 * layer's features in source order, and these shapes genuinely overlap: "Suebi" was an umbrella
 * that CONTAINED the Marcomanni and the Quadi, so its shape sits over both of them. Authored order
 * would have put the umbrella on top and buried the specific peoples underneath the vaguest one.
 *
 * Sorting by area is not a guess about intent — a containing category is always the larger shape.
 */
export const handTerritories = () => ({
  type: 'FeatureCollection',
  features: spec.territories
    .map(featureFor)
    .sort((a, b) => ringArea(b.geometry.coordinates[0]) - ringArea(a.geometry.coordinates[0])),
})

/** id → the authored record, provenance and all. */
export const territoryById = (id) => spec.territories.find((t) => t.id === id) ?? null

/** The paper trail for one territory, or null if it is not one of ours. */
export const provenanceFor = (id) => territoryById(id)?.provenance ?? null

/** The raw authored spec, for tests and for the dev settings panel. */
export const handTerritorySpec = spec

export default handTerritories

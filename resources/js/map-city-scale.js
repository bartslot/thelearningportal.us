/**
 * map-city-scale.js — which cities the atlas shows first, and how big it writes them.
 *
 * Natural Earth ranks every place by importance (`scalerank`: 0 for a world city, 7 for a market
 * town), and the tileset carries that rank. The map used to ignore it: no sort key, so when two
 * labels collided MapLibre kept whichever it happened to reach first in the tile, and one flat size
 * ramp for all of them. On a lesson about Java that put Surakarta (rank 4, 555k) and Bandar Lampung
 * on the map while Jakarta (rank 0, 9.1M) was collided away — the class saw a map of Indonesia with
 * no Jakarta on it.
 *
 * So: rank decides who survives a collision, and rank decides how large the name is written. Both
 * from one place, because a city that wins the space and is then written in the same hand as its
 * neighbours has only solved half the problem.
 */

/** Ranks 0–1 are world cities, 2–3 are national capitals and big regional centres, the rest local. */
export const MAJOR_RANK = 1
export const REGIONAL_RANK = 3

/** The rank as MapLibre reads it — missing/odd values sort last rather than first. */
const rank = ['to-number', ['coalesce', ['get', 'scalerank'], 99]]

/**
 * Collision priority. MapLibre places low sort keys first, and a symbol already placed cannot be
 * pushed out — so this is literally "Jakarta before Surakarta".
 */
export const cityPriority = () => rank

/**
 * Text size by importance, at each end of the zoom range.
 *
 * Three tiers rather than a continuous scale: a map reads as a hierarchy when the steps are
 * obvious, and 8 sizes of nearly-the-same is just noise.
 *
 * @param {[number, number, number]} small  [major, regional, local] size when zoomed out
 * @param {[number, number, number]} large  the same three, zoomed in
 */
export const cityTextSize = (small = [12, 10, 8.5], large = [17, 14, 11.5]) => [
  'interpolate', ['linear'], ['zoom'],
  2, ['case', ['<=', rank, MAJOR_RANK], small[0], ['<=', rank, REGIONAL_RANK], small[1], small[2]],
  6, ['case', ['<=', rank, MAJOR_RANK], large[0], ['<=', rank, REGIONAL_RANK], large[1], large[2]],
]

/** Dot radius by the same three tiers, so the marker agrees with the name beside it. */
export const cityDotRadius = () => [
  'interpolate', ['linear'], ['zoom'],
  2, ['case', ['<=', rank, MAJOR_RANK], 2.6, ['<=', rank, REGIONAL_RANK], 1.8, 1.3],
  6, ['case', ['<=', rank, MAJOR_RANK], 5, ['<=', rank, REGIONAL_RANK], 3.4, 2.4],
]

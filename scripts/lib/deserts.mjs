/**
 * deserts.mjs — rough polygons for the world's major hot/sand deserts. Used at build time to (a)
 * keep forest/scrub scatter OUT of deserts and (b) place sand-dune marks INSIDE them. Coarse by
 * design — terrain hinting, not survey data. Add/adjust rings as needed.
 */
export const DESERTS = {
  Sahara:        [[-13, 27], [-6, 31], [10, 32], [22, 31], [31, 29], [37, 24], [34, 18], [24, 15.5], [8, 15], [-4, 16], [-12, 21]],
  Arabian:       [[35, 31], [42, 31], [49, 29], [56, 23], [55, 18], [48, 15], [42, 15], [37, 19], [34, 25]],
  Iranian_Thar:  [[57, 34], [66, 33], [72, 29], [75, 26], [70, 23], [60, 25], [55, 29]],
  Central_Asia:  [[78, 43], [92, 46], [108, 45], [113, 40], [103, 37], [86, 37], [77, 40]], // Gobi + Taklamakan
  Australian:    [[118, -20], [132, -19], [145, -24], [142, -31], [130, -32], [120, -29], [115, -25]],
  Kalahari_Namib: [[12, -19], [24, -19], [25, -28], [19, -31], [13, -27], [11, -23]],
  N_America_SW:  [[-119, 40], [-110, 39], [-104, 33], [-107, 27], [-114, 28], [-120, 34]],
}

// ray-casting point-in-polygon (lng,lat); rings are [lng,lat]
function inRing (lng, lat, ring) {
  let inside = false
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i], [xj, yj] = ring[j]
    if (((yi > lat) !== (yj > lat)) && (lng < ((xj - xi) * (lat - yi)) / (yj - yi) + xi)) inside = !inside
  }
  return inside
}

export function inDesert (lng, lat) {
  for (const ring of Object.values(DESERTS)) if (inRing(lng, lat, ring)) return true
  return false
}

/**
 * tile-prefetch.js — warm the tiles a voyage is about to sail over, before it sails over them.
 *
 * A voyage is the one map in the app where we know the future: the route is fixed, the camera is on
 * rails behind the ship, and the student cannot pan away. So there is no reason to discover the
 * imagery tile by tile as the ship arrives at it — which is what produces the grey pop-in mid-ocean.
 *
 * The trick is not a MapLibre API (there isn't one for prefetching): it is the HTTP cache. Fetching
 * a tile URL puts the response in the browser cache, and when MapLibre asks for the same URL a
 * moment later the request is served locally. So all we have to compute is WHICH urls, and the
 * answer is: the tiles under the route corridor, at the zooms the tour actually uses.
 *
 *   const stop = prefetchRouteTiles({ route, zooms: [3, 5], templates: [...], onDone })
 *   stop()   // abandon the rest — the scene changed
 */

/** Web-Mercator tile x/y for a coordinate at zoom `z` (the standard slippy-map formula). */
export function tileAt (lng, lat, z) {
  const n = 2 ** z
  const φ = Math.max(-85.05112878, Math.min(85.05112878, lat)) * Math.PI / 180
  // Clamping the latitude is not enough: 85.05112878° IS the edge of the Mercator square, so
  // rounding can land a hair past it and produce y = -1 or y = n — tiles that do not exist.
  const y = Math.floor(((1 - Math.log(Math.tan(φ) + 1 / Math.cos(φ)) / Math.PI) / 2) * n)
  return {
    x: Math.floor(((lng + 180) / 360) * n),
    y: Math.max(0, Math.min(n - 1, y)),
    z,
  }
}

/**
 * Every tile within `pad` tiles of the route, at each zoom — the corridor the camera will fly down.
 *
 * `pad` is in tiles, not kilometres, on purpose: what matters is how much lies beside the ship ON
 * SCREEN, and a tile is the same size on screen at every zoom. 1 covers the ship's own tile plus a
 * ring around it, which is about what a 16:9 stage shows at the tour's follow zoom.
 *
 * @param {Array<[number, number]>} route  the sailed line, [lng, lat] pairs
 * @param {{ zooms?: number[], pad?: number, max?: number }} [opts]
 * @returns {Array<{x: number, y: number, z: number}>} deduped, nearest-the-start first
 */
export function corridorTiles (route, { zooms = [4, 5], pad = 1, max = 1200 } = {}) {
  const seen = new Set()
  const out = []
  const points = densify(
    (route || []).filter((p) => Array.isArray(p) && Number.isFinite(p[0]) && Number.isFinite(p[1])),
    Math.max(...zooms, 0),
  )

  // Route order is sailing order, so tiles come out in the order they will be needed: if the cap
  // cuts the list short, what survives is the start of the voyage rather than a random scatter.
  for (const [lng, lat] of points) {
    for (const z of zooms) {
      const t = tileAt(lng, lat, z)
      const n = 2 ** z
      for (let dx = -pad; dx <= pad; dx++) {
        for (let dy = -pad; dy <= pad; dy++) {
          const x = t.x + dx
          const y = t.y + dy
          if (y < 0 || y >= n) continue                 // off the top/bottom of the world
          const wrapped = ((x % n) + n) % n             // ...but longitude wraps, so Pacific routes work
          const key = `${z}/${wrapped}/${y}`
          if (seen.has(key)) continue
          seen.add(key)
          out.push({ x: wrapped, y, z })
          if (out.length >= max) return out
        }
      }
    }
  }

  return out
}

/**
 * Walk the route in steps small enough that it cannot skip a tile.
 *
 * A voyage's waypoints are landfalls and course changes — often a thousand kilometres apart. Taking
 * the tiles at those points alone warms the two ends of a crossing and nothing in between, which is
 * precisely the open ocean the class spends the crossing looking at. Stepping at half a tile of the
 * finest zoom guarantees every tile the line passes through is visited.
 *
 * @param {Array<[number, number]>} points
 * @param {number} maxZoom  the finest zoom that will be prefetched
 * @returns {Array<[number, number]>}
 */
export function densify (points, maxZoom) {
  if (points.length < 2) return points
  const stepDeg = 360 / (2 ** Math.max(0, maxZoom)) / 2   // half a tile of longitude at that zoom
  const out = [points[0]]

  for (let i = 1; i < points.length; i++) {
    const [ax, ay] = points[i - 1]
    const [bx, by] = points[i]
    // Longitude is compared in the SHORTEST direction, so a Pacific leg crossing 180° is stepped
    // across the antimeridian rather than the long way round the globe.
    let dx = bx - ax
    if (dx > 180) dx -= 360
    if (dx < -180) dx += 360
    const steps = Math.max(1, Math.ceil(Math.max(Math.abs(dx), Math.abs(by - ay)) / stepDeg))
    for (let s = 1; s <= steps; s++) out.push([ax + (dx * s) / steps, ay + ((by - ay) * s) / steps])
  }

  return out
}

/** Fill {z}/{x}/{y} in a tile template. GIBS orders its path {z}/{y}/{x}, hence the named slots. */
export function tileUrl (template, { x, y, z }) {
  return template.replace('{z}', String(z)).replace('{x}', String(x)).replace('{y}', String(y))
}

/**
 * Warm the corridor in the background.
 *
 * Deliberately unhurried: `concurrency` requests at a time, so the prefetch never competes with the
 * tiles MapLibre is asking for RIGHT NOW to draw the current frame. Failures are ignored — a tile
 * that will not prefetch is simply a tile that loads normally later, which is the behaviour we had.
 *
 * @returns {() => void} call to abandon the remaining fetches
 */
export function prefetchTiles (urls, { concurrency = 4, fetcher = fetch } = {}) {
  const controller = typeof AbortController === 'function' ? new AbortController() : null
  let i = 0
  let stopped = false

  const pump = async () => {
    while (!stopped && i < urls.length) {
      const url = urls[i++]
      try {
        await fetcher(url, { mode: 'cors', credentials: 'omit', signal: controller?.signal })
      } catch (_) { /* offline, CORS, 404 — the tile just loads the slow way later */ }
    }
  }

  for (let n = 0; n < Math.max(1, concurrency); n++) pump()

  return () => { stopped = true; try { controller?.abort() } catch (_) { /* already gone */ } }
}

/**
 * The whole job: route + templates → warmed cache.
 *
 * @param {{ route: Array<[number, number]>, templates: string[], zooms?: number[], pad?: number, max?: number, concurrency?: number, fetcher?: Function }} opts
 * @returns {() => void} stop
 */
export function prefetchRouteTiles ({ route, templates, zooms, pad, max, concurrency, fetcher } = {}) {
  const tiles = corridorTiles(route, { zooms, pad, max })
  const urls = []
  for (const t of tiles) {
    for (const template of (templates || [])) {
      if (typeof template === 'string' && template) urls.push(tileUrl(template, t))
    }
  }

  return prefetchTiles(urls, { concurrency, fetcher })
}

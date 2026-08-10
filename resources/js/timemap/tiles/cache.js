/**
 * cache.js — never fetch the same thing twice.
 *
 * Google Earth's speed is not mostly clever rendering. It is that the tile you are looking at has
 * usually already been fetched, decoded and kept. So this is architecture, not an optimisation,
 * and it is three separate mechanisms that get confused with each other:
 *
 *  - AN LRU IN MEMORY, holding decoded tiles under a stated byte budget. This is what makes panning
 *    back the way you came instant.
 *  - A PERSISTENT STORE behind it, so a reload does not start from nothing. The Cache API, keyed by
 *    tile URL. Undecoded bytes: decoded tiles are several times larger and cannot be serialised.
 *  - IN-FLIGHT DE-DUPLICATION, which is the one people leave out. Selection runs every frame, so a
 *    tile that takes 300 ms to arrive is asked for on every frame in between. Without this the map
 *    issues eighteen identical requests for it and decodes eighteen copies of the answer.
 *
 * Everything external is injected — the fetcher, the store, the decoder. That is what lets the
 * whole thing be tested without a network, and it is also what lets the DEM, the land mask and a
 * future cloud source share one cache rather than growing one each.
 */

/**
 * The persistent store contract: `match(key)` returns an ArrayBuffer or null, `put(key, buffer)`
 * writes one. Both may reject; the cache treats a broken store as a missing one.
 */

/** The real store: the Cache API, which survives a reload and is evicted by the browser, not us. */
export const cacheApiStore = (cacheName = 'tm-tiles-v1') => {
  let opening = null
  const open = () => {
    // Deliberately lazy. `caches` is absent in some private-browsing modes and undefined in tests,
    // and touching it at module scope would take the whole map down with it.
    if (!opening) opening = globalThis.caches?.open(cacheName) ?? Promise.reject(new Error('no Cache API'))
    return opening
  }
  return {
    async match(key) {
      const response = await (await open()).match(key)
      return response ? await response.arrayBuffer() : null
    },
    async put(key, buffer) {
      await (await open()).put(key, new Response(buffer))
    },
  }
}

/** A store that forgets on reload. The fallback when the Cache API is unavailable, and the test double. */
export const memoryStore = () => {
  const entries = new Map()
  return {
    async match(key) { return entries.get(key) ?? null },
    async put(key, buffer) { entries.set(key, buffer) },
  }
}

/**
 * @param {object} opts
 * @param {number}   [opts.budgetBytes]  ceiling on DECODED bytes held in memory
 * @param {object}   [opts.store]        persistent store; a broken one costs a fetch, never the layer
 * @param {Function} [opts.fetch]        (url) => Promise<ArrayBuffer>
 * @param {Function} opts.decode         (buffer, tile) => Promise<{data, bytes}>
 * @param {Function} opts.urlFor         (tile) => string
 * @param {Function} [opts.dispose]      (entry) => void, called on eviction and on clear
 */
export const createTileCache = ({
  budgetBytes = 96 << 20,
  store = memoryStore(),
  fetch: fetchBytes = defaultFetch,
  decode,
  urlFor,
  dispose = null,
} = {}) => {
  // Insertion order IS the LRU order: a hit deletes and re-inserts, which moves the key to the end.
  const resident = new Map()
  const inFlight = new Map()
  let pinned = new Set()
  let bytes = 0

  const stats = {
    requests: 0, memoryHits: 0, storeHits: 0, networkFetches: 0,
    evictions: 0, failures: 0,
  }

  const evictToBudget = () => {
    for (const [key, entry] of resident) {
      if (bytes <= budgetBytes) return
      // A tile the current frame is drawing with must not have its slot recycled underneath it.
      if (pinned.has(key)) continue
      resident.delete(key)
      bytes -= entry.bytes
      stats.evictions++
      dispose?.(entry)
    }
  }

  const admit = (key, entry) => {
    resident.set(key, entry)
    bytes += entry.bytes
    evictToBudget()
  }

  /**
   * Read through: memory, then the store, then the network.
   *
   * Resolves to null rather than rejecting when a tile cannot be had. This runs inside a render
   * loop; an unhandled rejection there is a broken frame, and there is always something sensible
   * to draw without this tile — its parent.
   */
  const get = async (tile) => {
    const key = tile.key
    stats.requests++

    const hit = resident.get(key)
    if (hit) {
      resident.delete(key)
      resident.set(key, hit)
      stats.memoryHits++
      return hit.data
    }

    const flying = inFlight.get(key)
    if (flying) return flying

    const url = urlFor(tile)
    const load = (async () => {
      try {
        let buffer = await store.match(url).catch(() => null)
        if (buffer) {
          stats.storeHits++
        } else {
          buffer = await fetchBytes(url)
          stats.networkFetches++
          // Persisting must not be able to fail the tile. A full disk costs the next reload's
          // head start, nothing more.
          store.put(url, buffer.slice ? buffer.slice(0) : buffer).catch(() => {})
        }
        const { data, bytes: size } = await decode(buffer, tile)
        admit(key, { key, data, bytes: size })
        return data
      } catch {
        // Not remembered. A tile that failed because the network blinked must be retried the next
        // time it is asked for, or one bad moment leaves a permanent hole in the map.
        stats.failures++
        return null
      } finally {
        inFlight.delete(key)
      }
    })()

    inFlight.set(key, load)
    return load
  }

  return {
    get,

    /** Already in memory? Selection uses this to decide whether to draw a parent instead. */
    has: (key) => resident.has(key),

    /**
     * The decoded tile if it is already here, without awaiting.
     *
     * This is the path that restores a tile the GPU dropped but memory kept — the atlas is far
     * smaller than the cache, so panning away and back evicts slots long before it evicts decoded
     * tiles. It counts as a request and a hit, or the reported hit rate would be blind to the one
     * route that most often avoids the network. A miss counts nothing, because the caller goes on
     * to `get()` which counts it once.
     */
    peek(key) {
      const entry = resident.get(key)
      if (!entry) return null
      resident.delete(key)
      resident.set(key, entry)
      stats.requests++
      stats.memoryHits++
      return entry.data
    },

    /**
     * The tiles this frame is drawing with. They are exempt from eviction until the next pin
     * replaces the set — immutably, so a caller holding the previous set still has it.
     */
    pin(keys) { pinned = new Set(keys) },

    stats: () => ({
      ...stats,
      bytes,
      budgetBytes,
      residentTiles: resident.size,
      inFlight: inFlight.size,
      hitRate: stats.requests === 0 ? 0 : (stats.memoryHits + stats.storeHits) / stats.requests,
    }),

    clear() {
      resident.forEach((entry) => dispose?.(entry))
      resident.clear()
      inFlight.clear()
      pinned = new Set()
      bytes = 0
    },
  }
}

const defaultFetch = async (url) => {
  const response = await globalThis.fetch(url, { mode: 'cors' })
  if (!response.ok) throw new Error(`tile ${url}: ${response.status}`)
  return response.arrayBuffer()
}

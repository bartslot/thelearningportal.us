import { describe, it, expect, vi, afterEach } from 'vitest'
import { cacheApiStore, createTileCache, memoryStore } from '../cache.js'

/**
 * "Never fetch the same thing twice" is most of what makes a tiled map feel fast, so these are not
 * performance tests — they are correctness tests for the thing the speed rests on.
 *
 * Nothing here touches the network. The fetcher and the persistent store are both injected, which
 * is the only reason a cache can be tested at all.
 */

const tileBytes = (key) => new TextEncoder().encode(`tile:${key}`).buffer

/**
 * A fetcher that counts calls and can be told to fail or to hang.
 *
 * `release` has to work whether it is called before or after the fetch actually starts — the cache
 * awaits the persistent store first, so the fetch begins a microtask later than the caller does,
 * and a stub that only resolves an already-registered promise deadlocks on that ordering.
 */
const fetcherStub = () => {
  const pending = new Map()
  const released = new Set()
  const fetcher = vi.fn(async (url) => {
    if (fetcher.failOn.has(url)) throw new Error(`network: ${url}`)
    if (fetcher.hangOn.has(url) && !released.has(url)) {
      return new Promise((resolve) => pending.set(url, () => resolve(tileBytes(url))))
    }
    return tileBytes(url)
  })
  fetcher.failOn = new Set()
  fetcher.hangOn = new Set()
  fetcher.release = (url) => {
    released.add(url)
    pending.get(url)?.()
    pending.delete(url)
  }
  return fetcher
}

const cacheWith = (over = {}) => {
  const fetcher = over.fetcher ?? fetcherStub()
  const store = over.store ?? memoryStore()
  const cache = createTileCache({
    budgetBytes: 400,
    store,
    fetch: fetcher,
    // Every tile weighs 100 bytes, so budgets are easy to reason about in these tests.
    decode: async (buffer, tile) => ({ data: { key: tile.key, buffer }, bytes: 100 }),
    urlFor: (tile) => tile.key,
    ...over,
  })
  return { cache, fetcher, store }
}

const tile = (key) => ({ key, z: 0, x: 0, y: 0 })

describe('not fetching the same thing twice', () => {
  it('serves a repeat request from memory', async () => {
    const { cache, fetcher } = cacheWith()
    await cache.get(tile('a'))
    await cache.get(tile('a'))
    expect(fetcher).toHaveBeenCalledTimes(1)
    expect(cache.stats().memoryHits).toBe(1)
  })

  it('collapses concurrent requests for one tile into a single fetch', async () => {
    /**
     * The failure this prevents is not subtle once you see it: selection runs every frame, so a
     * tile that takes 300 ms to arrive gets asked for on all eighteen frames in between. Without
     * this the map issues eighteen identical requests and decodes eighteen copies.
     */
    const { cache, fetcher } = cacheWith()
    fetcher.hangOn.add('a')
    const all = Promise.all([cache.get(tile('a')), cache.get(tile('a')), cache.get(tile('a'))])
    fetcher.release('a')
    const results = await all
    expect(fetcher).toHaveBeenCalledTimes(1)
    // All three callers get the same object, not three decodes of the same bytes.
    expect(results[0]).toBe(results[1])
    expect(results[1]).toBe(results[2])
  })

  it('survives a reload by reading the persistent store instead of the network', async () => {
    const store = memoryStore()
    const first = cacheWith({ store })
    await first.cache.get(tile('a'))
    expect(first.fetcher).toHaveBeenCalledTimes(1)

    // A new cache over the same store is what a page reload looks like.
    const second = cacheWith({ store })
    await second.cache.get(tile('a'))
    expect(second.fetcher).not.toHaveBeenCalled()
    expect(second.cache.stats().storeHits).toBe(1)
  })

  it('counts a peek that lands, so the hit rate is not blind to the atlas-restore path', async () => {
    const { cache } = cacheWith()
    await cache.get(tile('a'))
    expect(cache.peek('a')).not.toBeNull()
    expect(cache.stats().memoryHits).toBe(1)
    // A peek that misses costs nothing: the caller goes on to get(), which counts it once.
    expect(cache.peek('nope')).toBeNull()
    expect(cache.stats().requests).toBe(2)
  })

  it('reports a hit rate, so the claim can be measured', async () => {
    const { cache } = cacheWith()
    await cache.get(tile('a'))
    await cache.get(tile('a'))
    await cache.get(tile('a'))
    const stats = cache.stats()
    expect(stats.networkFetches).toBe(1)
    expect(stats.requests).toBe(3)
    expect(stats.hitRate).toBeCloseTo(2 / 3, 6)
  })
})

describe('the memory budget', () => {
  it('evicts the least recently used tile once the budget is spent', async () => {
    const { cache, fetcher } = cacheWith({ budgetBytes: 300 })
    await cache.get(tile('a'))
    await cache.get(tile('b'))
    await cache.get(tile('c'))
    await cache.get(tile('a'))          // 'a' is now the most recent, 'b' the least
    await cache.get(tile('d'))          // over budget: 'b' goes

    expect(cache.has('a')).toBe(true)
    expect(cache.has('b')).toBe(false)
    expect(cache.stats().evictions).toBe(1)

    fetcher.mockClear()
    await cache.get(tile('b'))
    expect(fetcher).toHaveBeenCalledTimes(0)   // evicted from memory, still in the store
    expect(cache.stats().storeHits).toBe(1)
  })

  it('never evicts a tile the current frame is drawing with', async () => {
    /**
     * Eviction under a live draw is the classic tiled-renderer crash: the slot is recycled, the
     * shader samples it, and a mountain briefly wears an ocean. Pinned tiles are off limits.
     */
    const { cache } = cacheWith({ budgetBytes: 300 })
    await cache.get(tile('a'))
    await cache.get(tile('b'))
    cache.pin(['a', 'b'])
    await cache.get(tile('c'))
    await cache.get(tile('d'))

    expect(cache.has('a')).toBe(true)
    expect(cache.has('b')).toBe(true)
  })

  it('lets go again once the pin moves on', async () => {
    const { cache } = cacheWith({ budgetBytes: 300 })
    await cache.get(tile('a'))
    cache.pin(['a'])
    await cache.get(tile('b'))
    await cache.get(tile('c'))
    expect(cache.has('a')).toBe(true)

    cache.pin(['b', 'c'])
    await cache.get(tile('d'))
    expect(cache.has('a')).toBe(false)
  })

  it('stays under budget, and says how much it is holding', async () => {
    const { cache } = cacheWith({ budgetBytes: 300 })
    for (const key of ['a', 'b', 'c', 'd', 'e']) await cache.get(tile(key))
    expect(cache.stats().bytes).toBeLessThanOrEqual(300)
    expect(cache.stats().residentTiles).toBe(3)
  })
})

describe('when a tile does not arrive', () => {
  it('reports the failure without throwing into the render loop', async () => {
    const { cache, fetcher } = cacheWith()
    fetcher.failOn.add('a')
    await expect(cache.get(tile('a'))).resolves.toBeNull()
    expect(cache.stats().failures).toBe(1)
  })

  it('will try again later rather than remembering the failure forever', async () => {
    const { cache, fetcher } = cacheWith()
    fetcher.failOn.add('a')
    await cache.get(tile('a'))
    fetcher.failOn.delete('a')
    await expect(cache.get(tile('a'))).resolves.not.toBeNull()
  })

  it('does not write a failed fetch into the persistent store', async () => {
    const store = memoryStore()
    const { cache, fetcher } = cacheWith({ store })
    fetcher.failOn.add('a')
    await cache.get(tile('a'))
    expect(await store.match('a')).toBeNull()
  })

  it('keeps working when the persistent store itself is unavailable', async () => {
    // Private browsing, a full disk, a blocked Cache API. Persistence is a speed-up, never a
    // dependency — losing it must cost a fetch, not the layer.
    const brokenStore = {
      match: async () => { throw new Error('no storage') },
      put: async () => { throw new Error('no storage') },
    }
    const { cache, fetcher } = cacheWith({ store: brokenStore })
    await expect(cache.get(tile('a'))).resolves.not.toBeNull()
    expect(fetcher).toHaveBeenCalledTimes(1)
  })
})

describe('the Cache API store, which is what actually survives a reload', () => {
  afterEach(() => { delete globalThis.caches })

  /** The slice of the Cache API this uses: open, match, put. */
  const fakeCaches = () => {
    const entries = new Map()
    const opened = []
    globalThis.caches = {
      open: vi.fn(async (name) => {
        opened.push(name)
        return {
          match: async (key) => entries.get(key) ?? undefined,
          put: async (key, response) => entries.set(key, response),
        }
      }),
    }
    return { entries, opened }
  }

  it('writes a tile and reads it back as bytes', async () => {
    fakeCaches()
    const store = cacheApiStore('tm-test')
    await store.put('https://example.test/1/2/3.png', new TextEncoder().encode('hello').buffer)
    const back = await store.match('https://example.test/1/2/3.png')
    expect(new TextDecoder().decode(back)).toBe('hello')
  })

  it('reports a miss as null rather than as an undefined response', async () => {
    fakeCaches()
    expect(await cacheApiStore('tm-test').match('https://example.test/nope.png')).toBeNull()
  })

  it('opens the cache once and keeps it, not once per tile', async () => {
    const { opened } = fakeCaches()
    const store = cacheApiStore('tm-test')
    await store.match('a')
    await store.match('b')
    await store.match('c')
    expect(opened).toEqual(['tm-test'])
  })

  it('rejects, rather than throwing at import time, where there is no Cache API', async () => {
    // Private browsing has no `caches`. Touching it at module scope would take the map down with
    // it, so the store is built lazily and simply fails its first read.
    delete globalThis.caches
    await expect(cacheApiStore('tm-test').match('a')).rejects.toThrow()
  })
})

describe('clearing up', () => {
  it('drops everything and hands each tile to the disposer', async () => {
    const disposed = []
    const { cache } = cacheWith({ dispose: (entry) => disposed.push(entry.data.key) })
    await cache.get(tile('a'))
    await cache.get(tile('b'))
    cache.clear()
    expect(disposed.sort()).toEqual(['a', 'b'])
    expect(cache.stats().bytes).toBe(0)
    expect(cache.has('a')).toBe(false)
  })

  it('hands evicted tiles to the disposer too, so GL objects are freed', async () => {
    const disposed = []
    const { cache } = cacheWith({ budgetBytes: 200, dispose: (entry) => disposed.push(entry.data.key) })
    await cache.get(tile('a'))
    await cache.get(tile('b'))
    await cache.get(tile('c'))
    expect(disposed).toEqual(['a'])
  })
})

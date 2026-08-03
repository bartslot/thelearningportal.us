import { describe, it, expect, vi } from 'vitest'
import { tileAt, corridorTiles, densify, tileUrl, prefetchTiles, prefetchRouteTiles } from '../tile-prefetch.js'

describe('tileAt', () => {
  it('puts 0,0 at the middle of the world', () => {
    expect(tileAt(0, 0, 1)).toEqual({ x: 1, y: 1, z: 1 })
  })

  it('clamps the poles instead of running off the tile grid', () => {
    const n = 2 ** 4
    for (const lat of [90, -90, 89.9, -89.9]) {
      const t = tileAt(0, lat, 4)
      expect(t.y).toBeGreaterThanOrEqual(0)
      expect(t.y).toBeLessThan(n)
    }
  })
})

describe('corridorTiles', () => {
  const route = [[0, 0], [10, 0], [20, 0]]

  it('covers the ship\'s tile and a ring around it', () => {
    const tiles = corridorTiles([[0, 0]], { zooms: [4], pad: 1 })

    expect(tiles).toHaveLength(9)   // 3×3 around the point
    expect(tiles).toContainEqual(tileAt(0, 0, 4))
  })

  it('returns each tile once, however often the route crosses it', () => {
    const dense = corridorTiles([[0, 0], [0.01, 0.01], [0.02, 0]], { zooms: [4], pad: 1 })
    const keys = dense.map((t) => `${t.z}/${t.x}/${t.y}`)

    expect(new Set(keys).size).toBe(keys.length)
  })

  it('covers every zoom the tour will use', () => {
    const tiles = corridorTiles(route, { zooms: [3, 5], pad: 0 })

    expect(new Set(tiles.map((t) => t.z))).toEqual(new Set([3, 5]))
  })

  /** A Pacific crossing runs past 180°; its tiles are on the other edge of the grid, not off it. */
  it('wraps around the antimeridian rather than falling off the world', () => {
    const tiles = corridorTiles([[179.9, -20]], { zooms: [3], pad: 1 })
    const n = 2 ** 3

    expect(tiles.every((t) => t.x >= 0 && t.x < n)).toBe(true)
    expect(tiles.some((t) => t.x === 0)).toBe(true)   // the wrap actually happened
  })

  it('drops tiles off the top and bottom of the world', () => {
    const tiles = corridorTiles([[0, 85]], { zooms: [1], pad: 1 })

    expect(tiles.every((t) => t.y >= 0 && t.y < 2)).toBe(true)
  })

  /** The cap keeps the START of the voyage, because that is what is needed first. */
  it('stops at the cap, keeping the earliest tiles', () => {
    const long = Array.from({ length: 200 }, (_, i) => [i * 0.5, 0])
    const tiles = corridorTiles(long, { zooms: [5], pad: 1, max: 20 })

    expect(tiles).toHaveLength(20)
    // Not the exact centre tile — the corridor emits the ring around each point — but every tile
    // kept must sit beside the START of the route, not somewhere out along it.
    const start = tileAt(0, 0, 5)
    expect(Math.abs(tiles[0].x - start.x)).toBeLessThanOrEqual(1)
    expect(Math.abs(tiles[0].y - start.y)).toBeLessThanOrEqual(1)
  })

  it('ignores malformed coordinates instead of fetching NaN tiles', () => {
    const tiles = corridorTiles([[NaN, 0], ['x', 'y'], null, [0, 0]], { zooms: [4], pad: 0 })

    expect(tiles).toEqual([tileAt(0, 0, 4)])
  })
})

describe('densify', () => {
  /**
   * The regression this guards: prefetching the raw waypoints warmed the two ends of a crossing and
   * nothing between them — so an ocean leg still loaded tile by tile, which is the whole problem.
   */
  it('fills in the ocean between two far-apart landfalls', () => {
    const sparse = [[0, 0], [60, 0]]

    const walked = densify(sparse, 5)

    expect(walked[0]).toEqual([0, 0])
    expect(walked[walked.length - 1]).toEqual([60, 0])
    // No step may exceed half a tile at that zoom, which is what makes skipping a tile impossible.
    const halfTile = 360 / 2 ** 5 / 2
    const gaps = walked.slice(1).map((p, i) => Math.abs(p[0] - walked[i][0]))
    expect(Math.max(...gaps)).toBeLessThanOrEqual(halfTile + 1e-9)
  })

  it('steps across the antimeridian the short way, not around the world', () => {
    const walked = densify([[179, -20], [-179, -20]], 4)

    // 2° apart across the date line: a handful of steps, not hundreds going the long way.
    expect(walked.length).toBeLessThan(10)
  })

  it('leaves a single point alone', () => {
    expect(densify([[5, 5]], 5)).toEqual([[5, 5]])
  })

  it('covers every tile the line passes through', () => {
    const tiles = corridorTiles([[0, 0], [30, 0]], { zooms: [4], pad: 0 })
    const xs = tiles.map((t) => t.x).sort((a, b) => a - b)

    // 0°→30°E at zoom 4 spans several tile columns; none may be skipped.
    expect(xs[xs.length - 1] - xs[0] + 1).toBe(new Set(xs).size)
  })
})

describe('tileUrl', () => {
  it('fills the slots by name, so a {z}/{y}/{x} service works too', () => {
    const t = { x: 3, y: 7, z: 5 }

    expect(tileUrl('https://e/{z}/{x}/{y}.png', t)).toBe('https://e/5/3/7.png')
    expect(tileUrl('https://gibs/{z}/{y}/{x}.jpeg', t)).toBe('https://gibs/5/7/3.jpeg')
  })
})

describe('prefetchTiles', () => {
  it('fetches every url', async () => {
    const fetcher = vi.fn().mockResolvedValue({})
    prefetchTiles(['a', 'b', 'c'], { concurrency: 2, fetcher })
    await new Promise((r) => setTimeout(r, 10))

    expect(fetcher.mock.calls.map((c) => c[0]).sort()).toEqual(['a', 'b', 'c'])
  })

  it('keeps going when a tile fails — a missing tile is not an error', async () => {
    const fetcher = vi.fn()
      .mockRejectedValueOnce(new Error('404'))
      .mockResolvedValue({})
    prefetchTiles(['a', 'b'], { concurrency: 1, fetcher })
    await new Promise((r) => setTimeout(r, 10))

    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('abandons the rest when stopped — a scene change must not keep the network busy', async () => {
    const fetcher = vi.fn().mockImplementation(() => new Promise((r) => setTimeout(r, 5)))
    const stop = prefetchTiles(Array.from({ length: 50 }, (_, i) => `t${i}`), { concurrency: 1, fetcher })
    await new Promise((r) => setTimeout(r, 8))
    stop()
    const afterStop = fetcher.mock.calls.length
    await new Promise((r) => setTimeout(r, 25))

    expect(fetcher.mock.calls.length).toBeLessThanOrEqual(afterStop + 1)
  })
})

describe('prefetchRouteTiles', () => {
  it('warms every source for every tile in the corridor', async () => {
    const fetcher = vi.fn().mockResolvedValue({})
    prefetchRouteTiles({
      route: [[0, 0]],
      templates: ['https://imagery/{z}/{y}/{x}.jpeg', 'https://dem/{z}/{x}/{y}.png'],
      zooms: [4],
      pad: 0,
      fetcher,
    })
    await new Promise((r) => setTimeout(r, 10))

    const urls = fetcher.mock.calls.map((c) => c[0])
    expect(urls).toHaveLength(2)
    expect(urls.some((u) => u.startsWith('https://imagery/'))).toBe(true)
    expect(urls.some((u) => u.startsWith('https://dem/'))).toBe(true)
  })
})

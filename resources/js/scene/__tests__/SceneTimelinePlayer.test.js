import { describe, it, expect, vi } from 'vitest'
import scenes from '../__fixtures__/scenes.json'
import { SceneTimelinePlayer } from '../SceneTimelinePlayer.js'

function makeStubs() {
  const listeners = []
  return {
    skybox:  { crossfadeTo: vi.fn().mockResolvedValue() },
    overlay: { update: vi.fn() },
    timer:   {
      show: vi.fn(),
      start: vi.fn(() => {
        // Simulate the game finishing immediately for the test.
        setTimeout(() => listeners.forEach(fn => fn()), 0)
      }),
      pause: vi.fn(),
      seek: vi.fn(),
      hide: vi.fn(),
      on: vi.fn((evt, fn) => { if (evt === 'gameend') listeners.push(fn) }),
    },
    avatar:  { setClip: vi.fn(), speak: vi.fn().mockResolvedValue() },
  }
}

describe('SceneTimelinePlayer', () => {
  it('emits scenechange for each scene during playFrom(0)', async () => {
    const stubs = makeStubs()
    const p = new SceneTimelinePlayer({ scenes, ...stubs })

    const changes = []
    p.on('scenechange', s => changes.push(s.id))
    await p.playFrom(0)

    expect(changes).toEqual([1, 2, 3])
  })

  it('triggers timer.show + start when entering a game scene', async () => {
    const stubs = makeStubs()
    const p = new SceneTimelinePlayer({ scenes, ...stubs })

    await p.playFrom(0)
    expect(stubs.timer.show).toHaveBeenCalledWith(expect.objectContaining({ durationSeconds: 5 }))
    expect(stubs.timer.start).toHaveBeenCalled()
  })

  it('seek(time) lands on the right scene index + offset', () => {
    const stubs = makeStubs()
    const p = new SceneTimelinePlayer({ scenes, ...stubs })

    const { index, offset } = p.locate(12)
    expect(index).toBe(1)
    expect(offset).toBe(2)
  })

  it('total duration sums all scenes', () => {
    const stubs = makeStubs()
    const p = new SceneTimelinePlayer({ scenes, ...stubs })
    expect(p.totalSeconds()).toBe(25)
  })
})

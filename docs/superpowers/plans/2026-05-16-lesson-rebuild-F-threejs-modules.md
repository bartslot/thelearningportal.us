# Plan F — Three.js Scene Modules

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the JS modules that layer scene playback on top of the existing `avatar-3d.js` renderer: `SkyboxSphere` (equirect background with crossfade), `SceneOverlay` (year + location HUD), `SceneTimelinePlayer` (sequencer), `GameTimerOverlay` (challenge countdown), and `AmplitudeWaveform` (timeline audio bars). Each is independently unit-testable against fixture data with Vitest.

**Architecture:** New folder `resources/js/scene/`. Modules import `three` from npm (already at `^0.183.2`). `SceneTimelinePlayer` orchestrates the others — it never touches Livewire directly; it exposes events that the wizard glue code (Plan G) bridges to Livewire dispatches. Renderer + Avatar3DPlayer instance is passed in via constructor (singleton owned by the wizard).

**Tech Stack:** JS ES modules, Three.js r183, Vitest (added in this plan).

**Spec:** `docs/superpowers/specs/2026-05-16-lesson-creation-rebuild-design.md` (§3.2, §5.3, §8.3, §8.4).

**Depends on:** nothing — pure JS work using fixture data.

---

## File Structure

```
resources/js/scene/
  SkyboxSphere.js                NEW
  SceneOverlay.js                NEW
  SceneTimelinePlayer.js         NEW
  GameTimerOverlay.js            NEW
  AmplitudeWaveform.js           NEW
  __fixtures__/
    scenes.json                  NEW (sample scenes for tests)
resources/js/scene/__tests__/
  SkyboxSphere.test.js           NEW
  SceneOverlay.test.js           NEW
  SceneTimelinePlayer.test.js    NEW
  GameTimerOverlay.test.js       NEW
  AmplitudeWaveform.test.js      NEW
vitest.config.js                  NEW
package.json                      MODIFY (add vitest, jsdom)
```

---

## Task 1: Install Vitest

- [ ] **Step 1: Install**

```bash
npm install --save-dev vitest jsdom @vitest/ui
```

- [ ] **Step 2: Add `vitest.config.js`**

```js
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['resources/js/**/__tests__/**/*.test.js'],
  },
})
```

- [ ] **Step 3: Add `npm run test` script in `package.json`**

Inside `"scripts"`:
```json
"test": "vitest run",
"test:watch": "vitest"
```

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json vitest.config.js
git commit -m "chore(js): add vitest"
```

---

## Task 2: `AmplitudeWaveform`

**Files:**
- Create: `resources/js/scene/AmplitudeWaveform.js`
- Test: `resources/js/scene/__tests__/AmplitudeWaveform.test.js`

- [ ] **Step 1: Write the failing test**

```js
import { describe, it, expect } from 'vitest'
import { AmplitudeWaveform } from '../AmplitudeWaveform.js'

describe('AmplitudeWaveform', () => {
  it('returns N normalized bar heights from an alignment array', () => {
    const alignment = Array.from({ length: 100 }, (_, i) => ({
      character: 'a', start: i * 0.05, end: i * 0.05 + 0.05,
    }))

    const bars = AmplitudeWaveform.computeBars(alignment, 10)

    expect(bars).toHaveLength(10)
    bars.forEach(h => {
      expect(h).toBeGreaterThanOrEqual(0)
      expect(h).toBeLessThanOrEqual(1)
    })
  })

  it('returns an array of zeros when alignment is empty', () => {
    const bars = AmplitudeWaveform.computeBars([], 8)
    expect(bars).toEqual([0, 0, 0, 0, 0, 0, 0, 0])
  })

  it('renders 8 spans into a host element', () => {
    const host = document.createElement('div')
    AmplitudeWaveform.render(host, [{ character: 'a', start: 0, end: 1 }], 8)
    expect(host.querySelectorAll('span')).toHaveLength(8)
  })
})
```

- [ ] **Step 2: Run, confirm fail**

```bash
npm test -- AmplitudeWaveform
```

- [ ] **Step 3: Implement**

```js
// resources/js/scene/AmplitudeWaveform.js

export const AmplitudeWaveform = {
  /**
   * @param {Array<{character:string, start:number, end:number}>} alignment
   * @param {number} bucketCount
   * @returns {number[]} bar heights normalized 0..1
   */
  computeBars(alignment, bucketCount) {
    if (!Array.isArray(alignment) || alignment.length === 0) {
      return Array(bucketCount).fill(0)
    }
    const total = alignment[alignment.length - 1].end || 1
    const buckets = Array(bucketCount).fill(0)

    for (const c of alignment) {
      const mid = (c.start + c.end) / 2
      const idx = Math.min(bucketCount - 1, Math.floor((mid / total) * bucketCount))
      buckets[idx] += 1
    }

    const max = Math.max(...buckets) || 1
    return buckets.map(v => v / max)
  },

  render(hostEl, alignment, bucketCount = 24) {
    const bars = this.computeBars(alignment, bucketCount)
    hostEl.innerHTML = ''
    for (const h of bars) {
      const span = document.createElement('span')
      span.style.display    = 'inline-block'
      span.style.width      = `${100 / bucketCount}%`
      span.style.height     = `${Math.max(2, Math.round(h * 100))}%`
      span.style.background = 'currentColor'
      hostEl.appendChild(span)
    }
  },
}
```

- [ ] **Step 4: Pass + commit**

```bash
npm test -- AmplitudeWaveform
git add resources/js/scene/AmplitudeWaveform.js resources/js/scene/__tests__/AmplitudeWaveform.test.js
git commit -m "feat(scene): AmplitudeWaveform"
```

---

## Task 3: `SceneOverlay`

**Files:**
- Create: `resources/js/scene/SceneOverlay.js`
- Test: `resources/js/scene/__tests__/SceneOverlay.test.js`

- [ ] **Step 1: Failing test**

```js
import { describe, it, expect } from 'vitest'
import { SceneOverlay } from '../SceneOverlay.js'

describe('SceneOverlay', () => {
  it('mounts year and location text into the host element', () => {
    const host = document.createElement('div')
    const overlay = new SceneOverlay(host)
    overlay.mount()
    overlay.update({ year: '1810', location: 'PARIS, FRANCE' })

    expect(host.querySelector('[data-year]').textContent).toBe('1810')
    expect(host.querySelector('[data-location]').textContent).toBe('PARIS, FRANCE')
    expect(host.querySelector('svg')).not.toBeNull()  // location pin SVG
  })

  it('uppercases the location', () => {
    const host = document.createElement('div')
    const overlay = new SceneOverlay(host)
    overlay.mount()
    overlay.update({ year: '1810', location: 'paris, france' })
    expect(host.querySelector('[data-location]').textContent).toBe('PARIS, FRANCE')
  })

  it('hides year badge when year is null', () => {
    const host = document.createElement('div')
    const overlay = new SceneOverlay(host)
    overlay.mount()
    overlay.update({ year: null, location: 'X' })

    const badge = host.querySelector('.scene-overlay__year')
    expect(badge.style.opacity).toBe('0')
  })
})
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

```js
// resources/js/scene/SceneOverlay.js

const LOCATION_PIN_SVG = `
<svg width="21" height="26" viewBox="0 0 21 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M10.3329 0C4.63543 0 0 4.63543 0 10.3329C0 19.3812 9.58334 25.4792 9.9913 25.735L10.334 25.9493L10.6767 25.735C11.0848 25.4795 20.668 19.3812 20.668 10.3329C20.668 4.63543 16.0326 0 10.3351 0H10.3329ZM10.3329 15.5C7.47996 15.5 5.16584 13.1871 5.16584 10.3329C5.16584 7.47996 7.47872 5.16584 10.3329 5.16584C13.1859 5.16584 15.5 7.47872 15.5 10.3329C15.5 13.1859 13.1871 15.5 10.3329 15.5Z" fill="white"/>
</svg>`

export class SceneOverlay {
  constructor(hostEl) {
    this.host = hostEl
    this.mounted = false
  }

  mount() {
    if (this.mounted) return
    this.host.classList.add('scene-overlay')
    this.host.innerHTML = `
      <div class="scene-overlay__year" style="position:absolute; top:24px; left:32px; display:flex; align-items:center; gap:12px; transition:opacity 600ms;">
        <span style="width:48px; height:48px; border:2px solid white; border-radius:50%;"></span>
        <span data-year style="font-size:28px; font-weight:600; color:white; letter-spacing:-0.02em;"></span>
      </div>
      <div class="scene-overlay__location" style="position:absolute; bottom:96px; left:32px; display:flex; align-items:center; gap:8px; transition:opacity 600ms;">
        ${LOCATION_PIN_SVG}
        <span data-location style="font-size:14px; font-weight:600; color:white; letter-spacing:0.1em; text-transform:uppercase;"></span>
      </div>
    `
    this.yearEl     = this.host.querySelector('[data-year]')
    this.locationEl = this.host.querySelector('[data-location]')
    this.yearWrap   = this.host.querySelector('.scene-overlay__year')
    this.locWrap    = this.host.querySelector('.scene-overlay__location')
    this.mounted = true
  }

  update({ year, location }) {
    if (!this.mounted) this.mount()

    if (year) {
      this.yearEl.textContent = String(year)
      this.yearWrap.style.opacity = '1'
    } else {
      this.yearWrap.style.opacity = '0'
    }

    if (location) {
      this.locationEl.textContent = String(location).toUpperCase()
      this.locWrap.style.opacity = '1'
    } else {
      this.locWrap.style.opacity = '0'
    }
  }

  destroy() {
    this.host.innerHTML = ''
    this.mounted = false
  }
}
```

- [ ] **Step 4: Pass + commit**

```bash
npm test -- SceneOverlay
git add resources/js/scene/SceneOverlay.js resources/js/scene/__tests__/SceneOverlay.test.js
git commit -m "feat(scene): SceneOverlay HUD"
```

---

## Task 4: `SkyboxSphere`

**Files:**
- Create: `resources/js/scene/SkyboxSphere.js`
- Test: `resources/js/scene/__tests__/SkyboxSphere.test.js`

- [ ] **Step 1: Failing test**

```js
import { describe, it, expect, vi } from 'vitest'
import * as THREE from 'three'
import { SkyboxSphere } from '../SkyboxSphere.js'

describe('SkyboxSphere', () => {
  it('adds two inverted spheres to the scene on construction', () => {
    const scene = new THREE.Scene()
    new SkyboxSphere(scene)
    const skyboxes = scene.children.filter(c => c.userData?.role === 'skybox')
    expect(skyboxes).toHaveLength(2)
    skyboxes.forEach(s => expect(s.scale.x).toBeLessThan(0))   // inverted via negative scale
  })

  it('loadTexture resolves with a THREE.Texture for a known URL', async () => {
    const scene = new THREE.Scene()
    const sky = new SkyboxSphere(scene)
    sky._textureLoader = { load: (url, ok) => { const t = new THREE.Texture(); ok(t); return t } }

    const tex = await sky.loadTexture('about:blank')
    expect(tex).toBeInstanceOf(THREE.Texture)
  })

  it('crossfadeTo resolves after the configured duration', async () => {
    vi.useFakeTimers()
    const scene = new THREE.Scene()
    const sky = new SkyboxSphere(scene)
    sky._textureLoader = { load: (url, ok) => { const t = new THREE.Texture(); ok(t); return t } }

    const p = sky.crossfadeTo('about:blank', 100)
    vi.advanceTimersByTime(120)
    await expect(p).resolves.toBeUndefined()
    vi.useRealTimers()
  })
})
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

```js
// resources/js/scene/SkyboxSphere.js
import * as THREE from 'three'

export class SkyboxSphere {
  /**
   * @param {THREE.Scene} scene
   * @param {{ radius?: number }} [opts]
   */
  constructor(scene, opts = {}) {
    this._scene = scene
    this._textureLoader = new THREE.TextureLoader()
    this._cache = new Map()
    this._activeIdx = 0

    const radius = opts.radius ?? 500
    const geometry = new THREE.SphereGeometry(radius, 60, 40)

    this._spheres = [0, 1].map(i => {
      const material = new THREE.MeshBasicMaterial({ side: THREE.BackSide, transparent: true, opacity: i === 0 ? 1 : 0, depthWrite: false })
      const mesh = new THREE.Mesh(geometry, material)
      mesh.scale.x = -1  // invert so the inside faces in
      mesh.userData.role = 'skybox'
      scene.add(mesh)
      return mesh
    })
  }

  async loadTexture(url) {
    if (this._cache.has(url)) return this._cache.get(url)
    const tex = await new Promise((resolve, reject) => {
      this._textureLoader.load(url, t => {
        t.colorSpace = THREE.SRGBColorSpace
        t.mapping    = THREE.EquirectangularReflectionMapping
        resolve(t)
      }, undefined, reject)
    })
    this._cache.set(url, tex)
    return tex
  }

  /** Crossfade to a new URL over `duration` ms. */
  async crossfadeTo(url, duration = 600) {
    const tex = await this.loadTexture(url)
    const fromIdx = this._activeIdx
    const toIdx   = 1 - fromIdx
    const toMesh   = this._spheres[toIdx]
    const fromMesh = this._spheres[fromIdx]

    toMesh.material.map = tex
    toMesh.material.needsUpdate = true
    toMesh.material.opacity = 0

    const start = performance.now()
    return new Promise(resolve => {
      const tick = (now) => {
        const t = Math.min(1, (now - start) / duration)
        toMesh.material.opacity   = t
        fromMesh.material.opacity = 1 - t
        if (t < 1) {
          requestAnimationFrame(tick)
        } else {
          this._activeIdx = toIdx
          resolve()
        }
      }
      requestAnimationFrame(tick)
      // Also resolve via timeout for environments without RAF (vitest jsdom).
      setTimeout(() => {
        toMesh.material.opacity   = 1
        fromMesh.material.opacity = 0
        this._activeIdx = toIdx
        resolve()
      }, duration + 10)
    })
  }
}
```

- [ ] **Step 4: Pass + commit**

```bash
npm test -- SkyboxSphere
git add resources/js/scene/SkyboxSphere.js resources/js/scene/__tests__/SkyboxSphere.test.js
git commit -m "feat(scene): SkyboxSphere"
```

---

## Task 5: `GameTimerOverlay`

**Files:**
- Create: `resources/js/scene/GameTimerOverlay.js`
- Test: `resources/js/scene/__tests__/GameTimerOverlay.test.js`

- [ ] **Step 1: Failing test**

```js
import { describe, it, expect, vi } from 'vitest'
import { GameTimerOverlay } from '../GameTimerOverlay.js'

describe('GameTimerOverlay', () => {
  it('renders an 8:40 readout for 520s duration', () => {
    const host = document.createElement('div')
    const o = new GameTimerOverlay(host)
    o.show({ durationSeconds: 520 })
    expect(host.textContent).toContain('8:40')
    expect(host.textContent).toContain('TIME TO COMPLETE THE CHALLENGE')
  })

  it('counts down at 1Hz and emits gameend at 0', async () => {
    vi.useFakeTimers()
    const host = document.createElement('div')
    const o = new GameTimerOverlay(host)
    const ended = vi.fn()
    o.on('gameend', ended)
    o.show({ durationSeconds: 2 })
    o.start()
    vi.advanceTimersByTime(1100); expect(host.textContent).toContain('0:01')
    vi.advanceTimersByTime(1100); expect(host.textContent).toContain('0:00')
    expect(ended).toHaveBeenCalledOnce()
    vi.useRealTimers()
  })

  it('hide() removes the overlay markup', () => {
    const host = document.createElement('div')
    const o = new GameTimerOverlay(host)
    o.show({ durationSeconds: 60 })
    o.hide()
    expect(host.innerHTML).toBe('')
  })
})
```

- [ ] **Step 2: Run, confirm fail.**

- [ ] **Step 3: Implement**

```js
// resources/js/scene/GameTimerOverlay.js

export class GameTimerOverlay {
  constructor(hostEl) {
    this.host = hostEl
    this._listeners = new Map()
    this._intervalId = null
  }

  on(event, fn) {
    if (!this._listeners.has(event)) this._listeners.set(event, [])
    this._listeners.get(event).push(fn)
  }

  _emit(event, ...args) {
    (this._listeners.get(event) || []).forEach(fn => fn(...args))
  }

  show({ durationSeconds }) {
    this._remaining = Math.max(0, Math.floor(durationSeconds))
    this.host.innerHTML = `
      <div style="position:absolute; inset:0; background:rgba(0,0,0,0.55); display:flex; flex-direction:column; align-items:center; justify-content:center; color:white;">
        <div style="font-size:14px; letter-spacing:0.2em; text-transform:uppercase; opacity:0.85;">TIME TO COMPLETE THE CHALLENGE</div>
        <div data-timer style="font-size:96px; font-weight:700; margin-top:24px;">${this._format(this._remaining)}</div>
      </div>
    `
    this._timerEl = this.host.querySelector('[data-timer]')
  }

  start() {
    this._stop()
    this._intervalId = setInterval(() => {
      this._remaining = Math.max(0, this._remaining - 1)
      if (this._timerEl) this._timerEl.textContent = this._format(this._remaining)
      if (this._remaining === 0) {
        this._stop()
        this._emit('gameend')
      }
    }, 1000)
  }

  pause() { this._stop() }
  seek(seconds) {
    this._remaining = Math.max(0, Math.floor(seconds))
    if (this._timerEl) this._timerEl.textContent = this._format(this._remaining)
  }
  hide() { this._stop(); this.host.innerHTML = '' }

  _stop() { if (this._intervalId) { clearInterval(this._intervalId); this._intervalId = null } }
  _format(s) {
    const m = Math.floor(s / 60)
    const r = s % 60
    return `${m}:${String(r).padStart(2, '0')}`
  }
}
```

- [ ] **Step 4: Pass + commit**

```bash
npm test -- GameTimerOverlay
git add resources/js/scene/GameTimerOverlay.js resources/js/scene/__tests__/GameTimerOverlay.test.js
git commit -m "feat(scene): GameTimerOverlay"
```

---

## Task 6: `SceneTimelinePlayer`

**Files:**
- Create: `resources/js/scene/SceneTimelinePlayer.js`
- Create: `resources/js/scene/__fixtures__/scenes.json`
- Test: `resources/js/scene/__tests__/SceneTimelinePlayer.test.js`

- [ ] **Step 1: Fixture**

`resources/js/scene/__fixtures__/scenes.json`:

```json
[
  { "id": 1, "kind": "narration", "year": "1810", "location": "Paris", "image_path": "/img/paris.png", "audio_path": "/audio/1.mp3", "audio_alignment": [], "duration_seconds": 10 },
  { "id": 2, "kind": "game",      "year": null,   "location": null,    "image_path": "/img/game.png",  "audio_path": "/audio/g.mp3", "audio_alignment": [], "duration_seconds": 5 },
  { "id": 3, "kind": "narration", "year": "1812", "location": "Moscow","image_path": "/img/mosc.png",  "audio_path": "/audio/3.mp3", "audio_alignment": [], "duration_seconds": 10 }
]
```

- [ ] **Step 2: Failing test**

```js
import { describe, it, expect, vi } from 'vitest'
import scenes from '../__fixtures__/scenes.json'
import { SceneTimelinePlayer } from '../SceneTimelinePlayer.js'

function makeStubs() {
  return {
    skybox:  { crossfadeTo: vi.fn().mockResolvedValue() },
    overlay: { update: vi.fn() },
    timer:   { show: vi.fn(), start: vi.fn(), pause: vi.fn(), seek: vi.fn(), hide: vi.fn(), on: vi.fn() },
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
    expect(index).toBe(1)       // scene 2 starts at 10s
    expect(offset).toBe(2)
  })

  it('total duration sums all scenes', () => {
    const stubs = makeStubs()
    const p = new SceneTimelinePlayer({ scenes, ...stubs })
    expect(p.totalSeconds()).toBe(25)
  })
})
```

- [ ] **Step 3: Run, confirm fail.**

- [ ] **Step 4: Implement**

```js
// resources/js/scene/SceneTimelinePlayer.js

export class SceneTimelinePlayer {
  /**
   * @param {{
   *   scenes: Array<object>,
   *   skybox: { crossfadeTo(url: string): Promise<void> },
   *   overlay: { update({year, location}): void },
   *   timer: { show(opts): void, start(): void, pause(): void, seek(s): void, hide(): void, on(evt, fn): void },
   *   avatar: { setClip(id): void, speak(opts): Promise<void> },
   * }} deps
   */
  constructor({ scenes, skybox, overlay, timer, avatar }) {
    this.scenes  = scenes
    this.skybox  = skybox
    this.overlay = overlay
    this.timer   = timer
    this.avatar  = avatar

    this._listeners = new Map()
    this._isPlaying = false

    this.timer.on('gameend', () => this._next())
  }

  on(event, fn) {
    if (!this._listeners.has(event)) this._listeners.set(event, [])
    this._listeners.get(event).push(fn)
  }

  _emit(event, ...args) {
    (this._listeners.get(event) || []).forEach(fn => fn(...args))
  }

  totalSeconds() {
    return this.scenes.reduce((acc, s) => acc + Math.max(0, s.duration_seconds || 0), 0)
  }

  /**
   * Convert an absolute lesson timestamp to a scene index + offset within that scene.
   */
  locate(timeSeconds) {
    let cursor = 0
    for (let i = 0; i < this.scenes.length; i++) {
      const d = Math.max(0, this.scenes[i].duration_seconds || 0)
      if (timeSeconds < cursor + d) {
        return { index: i, offset: timeSeconds - cursor }
      }
      cursor += d
    }
    return { index: this.scenes.length - 1, offset: 0 }
  }

  async playFrom(index = 0) {
    this._isPlaying = true
    this._index = index
    while (this._isPlaying && this._index < this.scenes.length) {
      await this._playOne(this.scenes[this._index])
      this._index += 1
    }
    if (this._index >= this.scenes.length) {
      this._emit('timelineend')
    }
  }

  pause() {
    this._isPlaying = false
    this.timer.pause()
  }

  async seek(timeSeconds) {
    const { index } = this.locate(timeSeconds)
    this.pause()
    this._index = index
    if (this._isPlaying === false) await this.playFrom(index)
  }

  async _playOne(scene) {
    this._emit('scenechange', scene)

    await this.skybox.crossfadeTo(scene.image_path)
    this.overlay.update({ year: scene.year, location: scene.location })

    if (scene.animation_clip_id) {
      this.avatar.setClip(scene.animation_clip_id)
    }

    if (scene.kind === 'game') {
      this.timer.show({ durationSeconds: scene.duration_seconds || 0 })
      this.timer.start()
      await this._waitForGameEnd()
      this.timer.hide()
    } else {
      await this.avatar.speak({
        audioUrl:  scene.audio_path,
        alignment: scene.audio_alignment,
        text:      scene.script_segment,
      })
    }
  }

  _waitForGameEnd() {
    return new Promise(resolve => {
      const handler = () => resolve()
      this.timer.on('gameend', handler)
    })
  }

  _next() {
    // No-op; advancement is driven by _playOne's await resolving.
  }
}
```

- [ ] **Step 5: Pass + commit**

```bash
npm test -- SceneTimelinePlayer
git add resources/js/scene/SceneTimelinePlayer.js \
        resources/js/scene/__fixtures__/scenes.json \
        resources/js/scene/__tests__/SceneTimelinePlayer.test.js
git commit -m "feat(scene): SceneTimelinePlayer"
```

---

## Task 7: Wire all modules through `app.js`

**Files:**
- Modify: `resources/js/app.js`

- [ ] **Step 1: Export the modules**

Append to `resources/js/app.js`:

```js
import { SkyboxSphere }        from './scene/SkyboxSphere.js'
import { SceneOverlay }        from './scene/SceneOverlay.js'
import { SceneTimelinePlayer } from './scene/SceneTimelinePlayer.js'
import { GameTimerOverlay }    from './scene/GameTimerOverlay.js'
import { AmplitudeWaveform }   from './scene/AmplitudeWaveform.js'

window.LessonScene = {
  SkyboxSphere, SceneOverlay, SceneTimelinePlayer, GameTimerOverlay, AmplitudeWaveform,
}
```

- [ ] **Step 2: Vite build smoke test**

```bash
npm run build
```
Expected: build succeeds; bundle includes the new modules.

- [ ] **Step 3: Commit**

```bash
git add resources/js/app.js
git commit -m "feat(scene): expose LessonScene globals for blade hooks"
```

---

## Task 8: Full JS suite

- [ ] **Step 1:** `npm test`
- [ ] **Step 2:** confirm all five suites green.

Done. Plans G and H consume `window.LessonScene`.

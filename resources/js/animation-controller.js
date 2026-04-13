/**
 * animation-controller.js
 *
 * Manages avatar body animation using Three.js AnimationMixer.
 * Reads the controller JSON from /api/v1/avatars/{id}/controller:
 *   { "idle": ["1","2"], "presenting": ["3"], "greeting": ["4"] }
 *
 * Usage:
 *   const ac = new AnimationController(mixer, controllerJson);
 *   await ac.loadClips(clipUrls);  // { '1': '/path/1.fbx', ... }
 *   ac.trigger('presenting');       // start loop
 *   ac.trigger('greeting');         // play once, auto-return to presenting
 *   ac.update(deltaTime);           // call every frame
 */

import * as THREE  from 'three'
import { FBXLoader } from 'three/examples/jsm/loaders/FBXLoader.js'

export class AnimationController {
  #mixer
  #clips        = {}
  #controller
  #currentCat   = 'idle'
  #currentAction= null
  #lastClip     = {}

  constructor (mixer, controllerJson) {
    this.#mixer      = mixer
    this.#controller = controllerJson ?? {}
  }

  async loadClips (clipUrls) {
    const loader = new FBXLoader()

    await Promise.all(
      Object.entries(clipUrls).map(async ([id, url]) => {
        try {
          const fbx = await loader.loadAsync(url)
          if (fbx.animations.length > 0) {
            this.#clips[id] = fbx.animations[0]
          } else {
            console.warn(`[AnimationController] No animations in FBX "${id}"`)
          }
        } catch (err) {
          console.warn(`[AnimationController] Failed to load clip "${id}":`, err.message)
        }
      })
    )
  }

  trigger (category, fadeIn = 0.3) {
    const pool = this.#controller[category] ?? []
    if (pool.length === 0) return

    const id   = this.#pick(category, pool)
    const clip = this.#clips[id]
    if (!clip) return

    const isLooping = category === 'idle' || category === 'presenting'
    const action    = this.#mixer.clipAction(clip)

    action.setLoop(isLooping ? THREE.LoopRepeat : THREE.LoopOnce, isLooping ? Infinity : 1)
    action.clampWhenFinished = !isLooping
    action.reset()

    if (this.#currentAction && this.#currentAction !== action) {
      this.#currentAction.crossFadeTo(action, fadeIn, false)
    }

    action.play()

    const prevCat       = this.#currentCat
    this.#currentAction = action
    this.#currentCat    = category

    if (!isLooping) {
      const onFinished = (ev) => {
        if (ev.action !== action) return
        this.#mixer.removeEventListener('finished', onFinished)
        this.trigger(prevCat === category ? 'idle' : prevCat, 0.3)
      }
      this.#mixer.addEventListener('finished', onFinished)
    }
  }

  stop (fadeOut = 0.3) {
    this.trigger('idle', fadeOut)
  }

  update (delta) {
    this.#mixer.update(delta)
  }

  #pick (category, pool) {
    if (pool.length === 1) { this.#lastClip[category] = pool[0]; return pool[0] }
    const last      = this.#lastClip[category]
    const available = pool.filter(id => id !== last)
    const source    = available.length > 0 ? available : pool
    const id        = source[Math.floor(Math.random() * source.length)]
    this.#lastClip[category] = id
    return id
  }
}

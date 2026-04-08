/**
 * avatar-3d.js — Three.js 3D avatar player driven by Azure blend shapes
 *
 * Usage:
 *   const player = new Avatar3DPlayer(canvasEl, { characterUrl, morphMapUrl, frameBackground })
 *   await player.init()
 *   await player.loadPreview(audioUrl, blendShapesUrl)
 *   player.play()
 */
import * as THREE from 'three'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'

export class Avatar3DPlayer {
  #scene
  #camera
  #renderer
  #mesh          // SkinnedMesh with morph targets
  #morphMap = {} // azureName → morphTargetIndex
  #frames   = [] // [{ t: float, w: Float32Array(55) }]
  #audio    = null
  #playing  = false
  #rafId    = null
  #blinkTimer = 0
  #blinkNext  = 3000 // ms until next blink
  #clock    = new THREE.Clock()

  constructor (canvasEl, {
    characterUrl  = null,
    morphMapUrl   = null,
    frameBackground = '#0f172a',
  } = {}) {
    this.canvasEl       = canvasEl
    this.characterUrl   = characterUrl
    this.morphMapUrl    = morphMapUrl
    this.frameBackground = frameBackground
  }

  async init () {
    const w = this.canvasEl.clientWidth  || 400
    const h = this.canvasEl.clientHeight || 500

    // Renderer
    this.#renderer = new THREE.WebGLRenderer({ canvas: this.canvasEl, antialias: true, alpha: true })
    this.#renderer.setSize(w, h)
    this.#renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    this.#renderer.setClearColor(new THREE.Color(this.frameBackground), 1)

    // Scene
    this.#scene = new THREE.Scene()

    // Camera — bust shot
    this.#camera = new THREE.PerspectiveCamera(45, w / h, 0.1, 100)
    this.#camera.position.set(0, 1.6, 2.2)
    this.#camera.lookAt(0, 1.6, 0)

    // Lights
    this.#scene.add(new THREE.AmbientLight(0xffffff, 0.6))
    const dirLight = new THREE.DirectionalLight(0xffffff, 0.8)
    dirLight.position.set(1, 2, 2)
    this.#scene.add(dirLight)

    // Load character if URL provided
    if (this.characterUrl) {
      await this.#loadCharacter(this.characterUrl, this.morphMapUrl)
    }

    // Start loop
    this.#loop()
  }

  async #loadCharacter (gltfUrl, morphMapUrl) {
    const loader = new GLTFLoader()
    const gltf   = await loader.loadAsync(gltfUrl)
    this.#scene.add(gltf.scene)

    // Find the mesh with morph targets
    gltf.scene.traverse((node) => {
      if (node.isMesh && node.morphTargetDictionary && !this.#mesh) {
        this.#mesh = node
      }
    })

    if (!this.#mesh) {
      console.warn('[Avatar3D] No mesh with morph targets found in GLB')
      return
    }

    // Load morph map
    if (morphMapUrl) {
      try {
        const resp = await fetch(morphMapUrl)
        const map  = await resp.json()
        // map: { "jawOpen": 17, "eyeBlinkLeft": 0, ... }
        // We need to invert: azurePosition → morphTargetIndex
        const dict = this.#mesh.morphTargetDictionary
        for (const [azureName, azureIdx] of Object.entries(map)) {
          if (azureName in dict) {
            this.#morphMap[azureIdx] = dict[azureName]
          }
        }
      } catch (e) {
        console.warn('[Avatar3D] Could not load morph-map.json:', e)
      }
    }
  }

  async loadPreview (audioUrl, blendShapesUrl) {
    // Load blend shapes
    try {
      const resp = await fetch(blendShapesUrl)
      const data = await resp.json()
      this.#frames = data.frames.map(f => ({ t: f.t, w: f.w }))
    } catch (e) {
      console.warn('[Avatar3D] Could not load blendshapes.json:', e)
      this.#frames = []
    }

    // Load audio
    if (this.#audio) {
      this.#audio.pause()
      this.#audio = null
    }
    this.#audio = new Audio(audioUrl)
    this.#audio.addEventListener('ended', () => { this.#playing = false })
  }

  play () {
    if (!this.#audio) return
    this.#audio.currentTime = 0
    this.#audio.play()
    this.#playing = true
  }

  pause () {
    this.#audio?.pause()
    this.#playing = false
  }

  #loop () {
    this.#rafId = requestAnimationFrame((ts) => this.#loop(ts))
    const delta = this.#clock.getDelta() * 1000 // ms

    if (this.#playing && this.#mesh && this.#frames.length > 0) {
      this.#applyBlendShapes(this.#audio.currentTime)
    }

    this.#idlePass(delta)
    this.#renderer.render(this.#scene, this.#camera)
  }

  #applyBlendShapes (t) {
    const frames = this.#frames
    // Binary search for current frame
    let lo = 0, hi = frames.length - 1, idx = 0
    while (lo <= hi) {
      const mid = (lo + hi) >> 1
      if (frames[mid].t <= t) { idx = mid; lo = mid + 1 }
      else hi = mid - 1
    }

    const f0 = frames[idx]
    const f1 = frames[Math.min(idx + 1, frames.length - 1)]
    const alpha = f1.t > f0.t ? (t - f0.t) / (f1.t - f0.t) : 0

    const influences = this.#mesh.morphTargetInfluences
    for (const [azureIdx, morphIdx] of Object.entries(this.#morphMap)) {
      const w0 = f0.w[azureIdx] ?? 0
      const w1 = f1.w[azureIdx] ?? 0
      influences[morphIdx] = w0 + (w1 - w0) * alpha
    }
  }

  #idlePass (deltaMs) {
    if (!this.#mesh) return
    const t = performance.now() / 1000

    // Breathing — gentle Y scale oscillation
    const breathe = 1 + Math.sin(t * 0.8) * 0.012
    this.#mesh.scale.y = breathe

    // Eye blink
    this.#blinkTimer += deltaMs
    if (this.#blinkTimer >= this.#blinkNext) {
      this.#blinkTimer = 0
      this.#blinkNext  = 2000 + Math.random() * 3000
      this.#triggerBlink()
    }
  }

  #triggerBlink () {
    const dict = this.#mesh?.morphTargetDictionary
    if (!dict) return
    const lIdx = dict['eyeBlinkLeft']
    const rIdx = dict['eyeBlinkRight']
    if (lIdx === undefined || rIdx === undefined) return

    const infl = this.#mesh.morphTargetInfluences
    infl[lIdx] = 1; infl[rIdx] = 1
    setTimeout(() => { infl[lIdx] = 0; infl[rIdx] = 0 }, 120)
  }

  destroy () {
    if (this.#rafId) cancelAnimationFrame(this.#rafId)
    this.#audio?.pause()
    this.#renderer?.dispose()
    this.#scene?.traverse((obj) => {
      if (obj.geometry) obj.geometry.dispose()
      if (obj.material) {
        if (Array.isArray(obj.material)) obj.material.forEach(m => m.dispose())
        else obj.material.dispose()
      }
    })
  }
}

// ── Livewire / Alpine event glue ────────────────────────────────────────────

window.addEventListener('avatar3d:previewReady', (ev) => {
  const { audioUrl, blendShapesUrl } = ev.detail
  window._avatar3d?.loadPreview(audioUrl, blendShapesUrl)
    .then(() => console.log('[Avatar3D] Preview loaded'))
})

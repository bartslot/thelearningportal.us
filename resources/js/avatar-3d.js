/**
 * avatar-3d.js — Three.js 3D avatar player driven by Azure 3D blend shapes
 *
 * Compatible with Ready Player Me / MPFB GLBs that include ARKit morph targets.
 * No morph-map.json required — shape names are matched directly from the GLB.
 *
 * Usage:
 *   const player = new Avatar3DPlayer(canvasEl, { characterUrl, frameBackground })
 *   await player.init()
 *   await player.loadPreview(audioUrl, blendShapesUrl)
 *   player.play()
 */
import * as THREE from 'three'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'

/**
 * Azure 3D blend shape names in their official index order (indices 0–54).
 * https://learn.microsoft.com/azure/ai-services/speech-service/how-to-speech-synthesis-viseme?tabs=3dblendshapes
 * The last three (headRoll, leftEyeRoll, rightEyeRoll) may not exist in all GLBs and are skipped gracefully.
 */
const AZURE_BLEND_SHAPES = [
  'eyeBlinkLeft',    'eyeLookDownLeft',  'eyeLookInLeft',    'eyeLookOutLeft',   // 0–3
  'eyeLookUpLeft',   'eyeSquintLeft',    'eyeWideLeft',                          // 4–6
  'eyeBlinkRight',   'eyeLookDownRight', 'eyeLookInRight',   'eyeLookOutRight',  // 7–10
  'eyeLookUpRight',  'eyeSquintRight',   'eyeWideRight',                         // 11–13
  'jawForward',      'jawLeft',          'jawOpen',          'jawRight',         // 14–17
  'mouthClose',      'mouthDimpleLeft',  'mouthDimpleRight',                     // 18–20
  'mouthFrownLeft',  'mouthFrownRight',  'mouthFunnel',                          // 21–23
  'mouthLeft',       'mouthLowerDownLeft', 'mouthLowerDownRight',                // 24–26
  'mouthPressLeft',  'mouthPressRight',  'mouthPucker',      'mouthRight',       // 27–30
  'mouthRollLower',  'mouthRollUpper',   'mouthShrugLower',  'mouthShrugUpper',  // 31–34
  'mouthSmileLeft',  'mouthSmileRight',  'mouthStretchLeft', 'mouthStretchRight',// 35–38
  'mouthUpperUpLeft','mouthUpperUpRight',                                        // 39–40
  'browDownLeft',    'browDownRight',    'browInnerUp',                          // 41–43
  'browOuterUpLeft', 'browOuterUpRight',                                         // 44–45
  'cheekPuff',       'cheekSquintLeft',  'cheekSquintRight',                     // 46–48
  'noseSneerLeft',   'noseSneerRight',                                           // 49–50
  'tongueOut',                                                                   // 51
  'headRoll',        'leftEyeRoll',      'rightEyeRoll',                         // 52–54 (optional)
]

export class Avatar3DPlayer {
  #scene
  #camera
  #renderer
  #meshes   = [] // all SkinnedMeshes with morph targets (head, teeth, eyes…)
  #morphMap = {} // azureIndex → morphTargetIndex (same dict for all meshes)
  #frames   = [] // [{ t: float, w: number[] }]
  #audio    = null
  #playing  = false
  #rafId    = null
  #blinkTimer = 0
  #blinkNext  = 3000 // ms until next blink
  #clock    = new THREE.Clock()

  constructor (canvasEl, {
    characterUrl    = null,
    frameBackground = '#0f172a',
  } = {}) {
    this.canvasEl        = canvasEl
    this.characterUrl    = characterUrl
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
    this.#renderer.outputColorSpace = THREE.SRGBColorSpace

    // Scene
    this.#scene = new THREE.Scene()

    // Camera — bust shot framing for a ~1.7 m character
    this.#camera = new THREE.PerspectiveCamera(38, w / h, 0.01, 100)
    this.#camera.position.set(0, 1.55, 0.75)
    this.#camera.lookAt(0, 1.58, 0)

    // Lights
    this.#scene.add(new THREE.AmbientLight(0xffffff, 1.2))
    const key = new THREE.DirectionalLight(0xffeedd, 1.6)
    key.position.set(1.5, 2.5, 2)
    this.#scene.add(key)
    const fill = new THREE.DirectionalLight(0xddeeff, 0.6)
    fill.position.set(-2, 1, 1)
    this.#scene.add(fill)

    if (this.characterUrl) {
      await this.#loadCharacter(this.characterUrl)
    }

    this.#loop()
  }

  async #loadCharacter (gltfUrl) {
    const loader = new GLTFLoader()
    const gltf   = await loader.loadAsync(gltfUrl)
    this.#scene.add(gltf.scene)

    // Collect every mesh that has morph targets
    this.#meshes = []
    gltf.scene.traverse((node) => {
      if (node.isMesh && node.morphTargetDictionary) {
        this.#meshes.push(node)
      }
    })

    if (this.#meshes.length === 0) {
      console.warn('[Avatar3D] No meshes with morph targets found in GLB')
      return
    }

    // Build azureIndex → morphTargetIndex using the first mesh's dictionary.
    // All meshes share the same morph target names, so one lookup table covers all.
    const dict = this.#meshes[0].morphTargetDictionary
    this.#morphMap = {}
    AZURE_BLEND_SHAPES.forEach((name, azureIdx) => {
      if (name in dict) {
        this.#morphMap[azureIdx] = dict[name]
      }
    })

    const mapped = Object.keys(this.#morphMap).length
    console.log(`[Avatar3D] Loaded ${this.#meshes.length} mesh(es), auto-mapped ${mapped}/55 Azure blend shapes`)
  }

  async loadPreview (audioUrl, blendShapesUrl) {
    // Fetch blend shapes
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
    this.#rafId = requestAnimationFrame(() => this.#loop())
    const delta = this.#clock.getDelta() * 1000 // ms

    if (this.#playing && this.#meshes.length > 0 && this.#frames.length > 0) {
      this.#applyBlendShapes(this.#audio.currentTime)
    }

    this.#idlePass(delta)
    this.#renderer.render(this.#scene, this.#camera)
  }

  #applyBlendShapes (t) {
    const frames = this.#frames

    // Binary search — find the last frame with f.t <= t
    let lo = 0, hi = frames.length - 1, idx = 0
    while (lo <= hi) {
      const mid = (lo + hi) >> 1
      if (frames[mid].t <= t) { idx = mid; lo = mid + 1 }
      else hi = mid - 1
    }

    const f0    = frames[idx]
    const f1    = frames[Math.min(idx + 1, frames.length - 1)]
    const alpha = f1.t > f0.t ? Math.min(1, (t - f0.t) / (f1.t - f0.t)) : 0

    // Apply interpolated weights to every mesh
    for (const [azureIdxStr, morphIdx] of Object.entries(this.#morphMap)) {
      const ai = Number(azureIdxStr)
      const w  = (f0.w[ai] ?? 0) + ((f1.w[ai] ?? 0) - (f0.w[ai] ?? 0)) * alpha
      for (const mesh of this.#meshes) {
        mesh.morphTargetInfluences[morphIdx] = w
      }
    }
  }

  #idlePass (deltaMs) {
    if (this.#meshes.length === 0) return
    const t = performance.now() / 1000

    // Subtle breathing — scale the whole head group very slightly
    const breathe = 1 + Math.sin(t * 0.8) * 0.008
    for (const mesh of this.#meshes) {
      mesh.scale.y = breathe
    }

    // Random eye blink
    this.#blinkTimer += deltaMs
    if (this.#blinkTimer >= this.#blinkNext) {
      this.#blinkTimer = 0
      this.#blinkNext  = 2500 + Math.random() * 3000
      this.#triggerBlink()
    }
  }

  #triggerBlink () {
    if (this.#meshes.length === 0) return
    const dict = this.#meshes[0].morphTargetDictionary
    const lIdx = dict['eyeBlinkLeft']
    const rIdx = dict['eyeBlinkRight']
    if (lIdx === undefined || rIdx === undefined) return

    for (const mesh of this.#meshes) {
      mesh.morphTargetInfluences[lIdx] = 1
      mesh.morphTargetInfluences[rIdx] = 1
    }
    setTimeout(() => {
      for (const mesh of this.#meshes) {
        mesh.morphTargetInfluences[lIdx] = 0
        mesh.morphTargetInfluences[rIdx] = 0
      }
    }, 120)
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

// ── Global exposure (used by Alpine on avatar-lab page) ─────────────────────
window.Avatar3DPlayer = Avatar3DPlayer

// ── Livewire / Alpine event glue ────────────────────────────────────────────
window.addEventListener('avatar3d:previewReady', (ev) => {
  const { audioUrl, blendShapesUrl } = ev.detail
  window._avatar3d?.loadPreview(audioUrl, blendShapesUrl)
    .then(() => console.log('[Avatar3D] Preview loaded — ready to play'))
})

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
 */
const AZURE_BLEND_SHAPES = [
  'eyeBlinkLeft',     'eyeLookDownLeft',  'eyeLookInLeft',    'eyeLookOutLeft',   // 0–3
  'eyeLookUpLeft',    'eyeSquintLeft',    'eyeWideLeft',                          // 4–6
  'eyeBlinkRight',    'eyeLookDownRight', 'eyeLookInRight',   'eyeLookOutRight',  // 7–10
  'eyeLookUpRight',   'eyeSquintRight',   'eyeWideRight',                         // 11–13
  'jawForward',       'jawLeft',          'jawOpen',          'jawRight',         // 14–17
  'mouthClose',       'mouthDimpleLeft',  'mouthDimpleRight',                     // 18–20
  'mouthFrownLeft',   'mouthFrownRight',  'mouthFunnel',                          // 21–23
  'mouthLeft',        'mouthLowerDownLeft', 'mouthLowerDownRight',                // 24–26
  'mouthPressLeft',   'mouthPressRight',  'mouthPucker',      'mouthRight',       // 27–30
  'mouthRollLower',   'mouthRollUpper',   'mouthShrugLower',  'mouthShrugUpper',  // 31–34
  'mouthSmileLeft',   'mouthSmileRight',  'mouthStretchLeft', 'mouthStretchRight',// 35–38
  'mouthUpperUpLeft', 'mouthUpperUpRight',                                        // 39–40
  'browDownLeft',     'browDownRight',    'browInnerUp',                          // 41–43
  'browOuterUpLeft',  'browOuterUpRight',                                         // 44–45
  'cheekPuff',        'cheekSquintLeft',  'cheekSquintRight',                     // 46–48
  'noseSneerLeft',    'noseSneerRight',                                           // 49–50
  'tongueOut',                                                                    // 51
  'headRoll',         'leftEyeRoll',      'rightEyeRoll',                        // 52–54 (optional)
]

export class Avatar3DPlayer {
  constructor (canvasEl, { characterUrl = null, frameBackground = '#0f172a' } = {}) {
    this.canvasEl        = canvasEl
    this.characterUrl    = characterUrl
    this.frameBackground = frameBackground

    // Internal state — underscore prefix instead of # to survive HMR re-evaluation
    this._scene      = null
    this._camera     = null
    this._renderer   = null
    this._meshes     = []
    this._morphMap   = {}   // azureIndex → morphTargetIndex
    this._frames     = []   // [{ t: number, w: number[] }]
    this._audio      = null
    this._playing    = false
    this._rafId      = null
    this._blinkTimer = 0
    this._blinkNext  = 3000
    this._clock      = new THREE.Clock()
  }

  async init () {
    const w = this.canvasEl.clientWidth  || 400
    const h = this.canvasEl.clientHeight || 500

    this._renderer = new THREE.WebGLRenderer({ canvas: this.canvasEl, antialias: true, alpha: true })
    this._renderer.setSize(w, h)
    this._renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    this._renderer.setClearColor(new THREE.Color(this.frameBackground), 1)
    this._renderer.outputColorSpace = THREE.SRGBColorSpace

    this._scene  = new THREE.Scene()

    this._camera = new THREE.PerspectiveCamera(38, w / h, 0.01, 100)
    this._camera.position.set(0, 1.55, 0.75)
    this._camera.lookAt(0, 1.58, 0)

    this._scene.add(new THREE.AmbientLight(0xffffff, 1.2))
    const key  = new THREE.DirectionalLight(0xffeedd, 1.6)
    key.position.set(1.5, 2.5, 2)
    this._scene.add(key)
    const fill = new THREE.DirectionalLight(0xddeeff, 0.6)
    fill.position.set(-2, 1, 1)
    this._scene.add(fill)

    if (this.characterUrl) {
      await this._loadCharacter(this.characterUrl)
    }

    this._loop()
  }

  async _loadCharacter (gltfUrl) {
    const loader = new GLTFLoader()
    const gltf   = await loader.loadAsync(gltfUrl)
    this._scene.add(gltf.scene)

    this._meshes = []
    gltf.scene.traverse((node) => {
      if (node.isMesh && node.morphTargetDictionary) {
        this._meshes.push(node)
      }
    })

    if (this._meshes.length === 0) {
      console.warn('[Avatar3D] No meshes with morph targets found in GLB')
      return
    }

    // Auto-map Azure blend shape indices → GLB morphTargetDictionary indices by name
    const dict       = this._meshes[0].morphTargetDictionary
    this._morphMap   = {}
    AZURE_BLEND_SHAPES.forEach((name, azureIdx) => {
      if (name in dict) this._morphMap[azureIdx] = dict[name]
    })

    console.log(`[Avatar3D] Loaded ${this._meshes.length} mesh(es), mapped ${Object.keys(this._morphMap).length}/55 blend shapes`)
  }

  async loadPreview (audioUrl, blendShapesUrl) {
    try {
      const resp    = await fetch(blendShapesUrl)
      const data    = await resp.json()
      this._frames  = data.frames.map(f => ({ t: f.t, w: f.w }))
    } catch (e) {
      console.warn('[Avatar3D] Could not load blendshapes.json:', e)
      this._frames = []
    }

    if (this._audio) { this._audio.pause(); this._audio = null }
    this._audio = new Audio(audioUrl)
    this._audio.addEventListener('ended', () => { this._playing = false })
  }

  play () {
    if (!this._audio) return
    this._audio.currentTime = 0
    this._audio.play()
    this._playing = true
  }

  pause () {
    this._audio?.pause()
    this._playing = false
  }

  _loop () {
    this._rafId = requestAnimationFrame(() => this._loop())
    const delta = this._clock.getDelta() * 1000

    if (this._playing && this._meshes.length > 0 && this._frames.length > 0) {
      this._applyBlendShapes(this._audio.currentTime)
    }

    this._idlePass(delta)
    this._renderer.render(this._scene, this._camera)
  }

  _applyBlendShapes (t) {
    const frames = this._frames
    let lo = 0, hi = frames.length - 1, idx = 0
    while (lo <= hi) {
      const mid = (lo + hi) >> 1
      if (frames[mid].t <= t) { idx = mid; lo = mid + 1 }
      else hi = mid - 1
    }

    const f0    = frames[idx]
    const f1    = frames[Math.min(idx + 1, frames.length - 1)]
    const alpha = f1.t > f0.t ? Math.min(1, (t - f0.t) / (f1.t - f0.t)) : 0

    for (const [azureIdxStr, morphIdx] of Object.entries(this._morphMap)) {
      const ai = Number(azureIdxStr)
      const w  = (f0.w[ai] ?? 0) + ((f1.w[ai] ?? 0) - (f0.w[ai] ?? 0)) * alpha
      for (const mesh of this._meshes) {
        mesh.morphTargetInfluences[morphIdx] = w
      }
    }
  }

  _idlePass (deltaMs) {
    if (this._meshes.length === 0) return
    const t       = performance.now() / 1000
    const breathe = 1 + Math.sin(t * 0.8) * 0.008
    for (const mesh of this._meshes) mesh.scale.y = breathe

    this._blinkTimer += deltaMs
    if (this._blinkTimer >= this._blinkNext) {
      this._blinkTimer = 0
      this._blinkNext  = 2500 + Math.random() * 3000
      this._triggerBlink()
    }
  }

  _triggerBlink () {
    if (this._meshes.length === 0) return
    const dict = this._meshes[0].morphTargetDictionary
    const lIdx = dict['eyeBlinkLeft']
    const rIdx = dict['eyeBlinkRight']
    if (lIdx === undefined || rIdx === undefined) return

    for (const mesh of this._meshes) {
      mesh.morphTargetInfluences[lIdx] = 1
      mesh.morphTargetInfluences[rIdx] = 1
    }
    setTimeout(() => {
      for (const mesh of this._meshes) {
        mesh.morphTargetInfluences[lIdx] = 0
        mesh.morphTargetInfluences[rIdx] = 0
      }
    }, 120)
  }

  destroy () {
    if (this._rafId) cancelAnimationFrame(this._rafId)
    this._audio?.pause()
    this._renderer?.dispose()
    this._scene?.traverse((obj) => {
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

// ── HMR: clean up old player instance when this module is reloaded in dev ───
if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    window._avatar3d?.destroy()
    window._avatar3d      = null
    window.Avatar3DPlayer = null
  })
}

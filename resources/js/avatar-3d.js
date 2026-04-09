/**
 * avatar-3d.js — Three.js 3D avatar player driven by Azure 3D blend shapes
 *
 * Compatible with Ready Player Me / MPFB GLBs that include ARKit morph targets.
 * No morph-map.json required — shape names are matched directly from the GLB.
 *
 * Presentation modes:
 *   'fullscreen' — camera pulls back to show waist-up, fills the container
 *   'framed'     — tight bust/portrait crop, rounded CSS mask applied by parent
 *
 * Camera:
 *   Auto-rotates (cinematic orbit) while locked. OrbitControls active while free.
 *   Fullscreen defaults to locked+autoRotate; framed always stays locked (no controls).
 *   toggleLock()   — fullscreen only; returns new boolean; auto-rotate tracks lock state
 *   resetCamera()  — snaps camera back to preset and re-locks
 *
 * Poses (bone-level):
 *   applyPose(name)  — smoothly transitions skeleton to a preset pose
 *   Built-in presets: 'tpose' | 'relaxed' | 'presenting' | 'crossed' | 'leaning' | 'sitting'
 *   Rest quaternions stored at load time; offsets are in each bone's LOCAL space (post-multiply).
 *   Values tuned for the Ready Player Me / MPFB Mixamo rig.
 *
 * Usage:
 *   const player = new Avatar3DPlayer(canvasEl, { characterUrl, frameBackground, presentationMode })
 *   await player.init()                    // loads GLB, applies 'relaxed' pose
 *   player.setMode('fullscreen')
 *   player.resize(w, h)
 *   player.applyPose('sitting')
 *   const isNowLocked = player.toggleLock()
 *   player.resetCamera()
 *   await player.loadPreview(audioUrl, blendShapesUrl)
 *   player.play()
 */
import * as THREE from 'three'
import { GLTFLoader }    from 'three/examples/jsm/loaders/GLTFLoader.js'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'

const DEG = Math.PI / 180

/** Azure 3D blend shape names in official index order (0–54). */
const AZURE_BLEND_SHAPES = [
  'eyeBlinkLeft',     'eyeLookDownLeft',  'eyeLookInLeft',    'eyeLookOutLeft',
  'eyeLookUpLeft',    'eyeSquintLeft',    'eyeWideLeft',
  'eyeBlinkRight',    'eyeLookDownRight', 'eyeLookInRight',   'eyeLookOutRight',
  'eyeLookUpRight',   'eyeSquintRight',   'eyeWideRight',
  'jawForward',       'jawLeft',          'jawOpen',          'jawRight',
  'mouthClose',       'mouthDimpleLeft',  'mouthDimpleRight',
  'mouthFrownLeft',   'mouthFrownRight',  'mouthFunnel',
  'mouthLeft',        'mouthLowerDownLeft', 'mouthLowerDownRight',
  'mouthPressLeft',   'mouthPressRight',  'mouthPucker',      'mouthRight',
  'mouthRollLower',   'mouthRollUpper',   'mouthShrugLower',  'mouthShrugUpper',
  'mouthSmileLeft',   'mouthSmileRight',  'mouthStretchLeft', 'mouthStretchRight',
  'mouthUpperUpLeft', 'mouthUpperUpRight',
  'browDownLeft',     'browDownRight',    'browInnerUp',
  'browOuterUpLeft',  'browOuterUpRight',
  'cheekPuff',        'cheekSquintLeft',  'cheekSquintRight',
  'noseSneerLeft',    'noseSneerRight',
  'tongueOut',
  'headRoll',         'leftEyeRoll',      'rightEyeRoll',
]

/**
 * Camera presets. Target Y = 1.649 (world-space eye level measured from the
 * skeleton hierarchy: Hips→Spine→…→LeftEye in the bundled RPM GLB).
 */
const CAMERA_PRESETS = {
  fullscreen: { fov: 52, pos: [0, 1.10, 2.4],  target: [0, 1.649, 0] },
  framed:     { fov: 38, pos: [0, 1.60, 0.78], target: [0, 1.649, 0] },
}

/**
 * Pose presets — per-bone LOCAL-space Euler offsets [rx, ry, rz] in degrees,
 * post-multiplied onto each bone's stored rest quaternion.
 *
 * Rig: Ready Player Me / MPFB with Mixamo bone names (character faces +Z after scene flip).
 *
 * Derived bone axis → world-space mapping (post scene Ry=180°):
 *   LeftArm/ForeArm:   local +X→world+Z(fwd)  +Y→world-X(left)  +Z→world-Y(down)
 *   RightArm/ForeArm:  local +X→world-Z(back)  +Y→world+X(right) +Z→world-Y(down)
 *   LeftUpLeg/Leg:     local +X→world+X         +Y→world-Y(down)  +Z→world-Z(back)
 *   RightUpLeg/Leg:    local +X→world+X         +Y→world-Y(down)  +Z→world-Z(back)
 *   Spine/Hips:        local +X→world-X         +Y→world+Y(up)    +Z→world-Z(back)
 *
 * Key rotation rules (right-hand rule around each local axis):
 *   Arms:   Rx+ → arm swings DOWN  (rotating +Y toward +Z = world -Y)
 *           Rx- → arm raises UP
 *           Rz- on LeftArm  → arm tilts forward (+Z world)
 *           Rz+ on RightArm → arm tilts forward (+Z world, mirrored)
 *   Legs:   UpLeg Rx- → hip flexion  (thigh forward, knee rises)
 *           Leg   Rx+ → knee bend    (shin swings back/down)
 *   Spine:  Rx- → lean forward       (top of spine toward +Z)
 *
 * "_hipsY" is a special key: vertical offset (metres) applied to Hips.position.y
 * so that a sitting character doesn't float.
 */
const POSES = {

  tpose: {},   // T-pose = all bones at rest quaternion, no offsets

  relaxed: {
    // Rx+ brings arms down; tiny Rz tilts hands slightly forward (natural hang)
    LeftArm:      [ +75,   0,  -5],
    RightArm:     [ +75,   0,  +5],
    LeftForeArm:  [  +5,  +5,   0],
    RightForeArm: [  +5,  -5,   0],
    Spine1:       [  +3,   0,   0],
  },

  presenting: {
    // Left arm hangs naturally; right arm raised forward (Rx- raises, Ry tilts)
    LeftArm:      [ +75,   0,  -5],
    LeftForeArm:  [  +5,  +5,   0],
    RightArm:     [ -35, -15,  +8],
    RightForeArm: [ -20,   0,   0],
    Spine2:       [  -5,   0,   0],   // slight lean into the gesture
    Neck:         [  -3,   0,   0],
  },

  leaning: {
    // Spine Rx- = lean forward (top of spine toward +Z = camera/front)
    Hips:         [  -6,   0,   0],
    Spine:        [  -9,   0,   0],
    Spine1:       [  -7,   0,   0],
    Spine2:       [  -5,   0,   0],
    LeftArm:      [ +70,   0,  -5],
    RightArm:     [ +70,   0,  +5],
  },

  sitting: {
    // Hips lowered; UpLeg Rx+ = hip flexion (thigh forward after rest-Q flip);
    // Leg Rx- = knee bend (shin swings down to vertical)
    _hipsY:       -0.44,
    Hips:         [  +3,   0,   0],
    LeftUpLeg:    [ +78,   0,   0],
    RightUpLeg:   [ +78,   0,   0],
    LeftLeg:      [ -82,   0,   0],
    RightLeg:     [ -82,   0,   0],
    LeftArm:      [ +75,   0,  -5],
    RightArm:     [ +75,   0,  +5],
    LeftForeArm:  [  +5,  +5,   0],
    RightForeArm: [  +5,  -5,   0],
  },
}

// Smooth ease-in-out cubic (Hermite)
function easeInOut (t) {
  return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2
}

export class Avatar3DPlayer {
  constructor (canvasEl, {
    characterUrl     = null,
    frameBackground  = '#0f172a',
    presentationMode = 'framed',
  } = {}) {
    this.canvasEl         = canvasEl
    this.characterUrl     = characterUrl
    this.frameBackground  = frameBackground
    this.presentationMode = presentationMode

    this._scene      = null
    this._camera     = null
    this._renderer   = null
    this._controls   = null
    this._locked     = true     // starts locked in all modes
    this._meshes     = []
    this._morphMap   = {}

    // Skeleton
    this._boneMap    = {}       // name → THREE.Bone
    this._boneRestQ  = {}       // name → Quaternion (bind-pose snapshot)
    this._boneRestY  = 0        // Hips rest position.y

    // Pose interpolation
    this._currentPose  = 'relaxed'
    this._poseFrom     = {}     // bone name → Quaternion snapshot at pose start
    this._poseTo       = {}     // bone name → Quaternion target
    this._hipFromY     = 0
    this._hipToY       = 0
    this._poseLerpT    = 1.0    // 0 = start, 1 = done
    this._poseDuration = 0.5    // seconds

    // Playback
    this._frames     = []
    this._audio      = null
    this._playing    = false
    this._rafId      = null

    // Idle
    this._blinkTimer = 0
    this._blinkNext  = 3000
    this._clock      = new THREE.Clock()
    this._resizeObs  = null
  }

  // ── Initialisation ─────────────────────────────────────────────────────────

  async init () {
    const w = this.canvasEl.clientWidth  || this.canvasEl.parentElement?.clientWidth  || 400
    const h = this.canvasEl.clientHeight || this.canvasEl.parentElement?.clientHeight || 500

    this._renderer = new THREE.WebGLRenderer({ canvas: this.canvasEl, antialias: true, alpha: false })
    this._renderer.setSize(w, h)
    this._renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    this._renderer.setClearColor(new THREE.Color(this.frameBackground), 1)
    this._renderer.outputColorSpace = THREE.SRGBColorSpace

    this._scene = new THREE.Scene()

    const preset = CAMERA_PRESETS[this.presentationMode] ?? CAMERA_PRESETS.framed
    this._camera = new THREE.PerspectiveCamera(preset.fov, w / h, 0.01, 100)
    this._camera.position.set(...preset.pos)

    // Three-point lighting
    this._scene.add(new THREE.AmbientLight(0xffffff, 1.2))
    const key = new THREE.DirectionalLight(0xffeedd, 1.6)
    key.position.set(1.5, 2.5, 2)
    this._scene.add(key)
    const fill = new THREE.DirectionalLight(0xddeeff, 0.6)
    fill.position.set(-2, 1, 1)
    this._scene.add(fill)

    // Orbit controls
    this._controls = new OrbitControls(this._camera, this._renderer.domElement)
    this._controls.enableDamping   = true
    this._controls.dampingFactor   = 0.08
    this._controls.enablePan       = false
    this._controls.minDistance     = 0.2
    this._controls.maxDistance     = 6.0
    this._controls.minPolarAngle   = 0.05
    this._controls.maxPolarAngle   = Math.PI * 0.80
    this._controls.autoRotateSpeed = 0.6   // slow cinematic orbit
    this._controls.target.set(...preset.target)
    this._controls.update()
    // Lock + auto-rotate on by default in all modes
    this._locked              = true
    this._controls.enabled    = false
    this._controls.autoRotate = true

    if (this.characterUrl) await this._loadCharacter(this.characterUrl)

    // Auto-resize
    if (typeof ResizeObserver !== 'undefined') {
      this._resizeObs = new ResizeObserver(() => {
        const el = this.canvasEl
        if (el.clientWidth > 0 && el.clientHeight > 0) this.resize(el.clientWidth, el.clientHeight)
      })
      this._resizeObs.observe(this.canvasEl)
    }

    this._loop()
  }

  // ── Camera ─────────────────────────────────────────────────────────────────

  setMode (mode) {
    this.presentationMode = mode
    const preset = CAMERA_PRESETS[mode] ?? CAMERA_PRESETS.framed
    if (this._camera) {
      this._camera.fov = preset.fov
      this._camera.position.set(...preset.pos)
      this._camera.updateProjectionMatrix()
    }
    if (this._controls) {
      this._controls.target.set(...preset.target)
      this._controls.update()
    }
    // Re-lock (both modes reset to locked on switch)
    this._locked              = true
    this._controls.enabled    = false
    this._controls.autoRotate = true
  }

  /**
   * Fullscreen only: toggle between locked (auto-rotate) and free orbit.
   * Returns new locked state so Alpine can mirror it.
   */
  toggleLock () {
    if (this.presentationMode === 'framed') return true
    this._locked = !this._locked
    if (this._locked) {
      this._controls.enabled    = false
      this._controls.autoRotate = true
    } else {
      this._controls.enabled    = true
      this._controls.autoRotate = false
    }
    return this._locked
  }

  /** Snap camera back to preset, re-lock, and resume auto-rotate. */
  resetCamera () {
    const preset = CAMERA_PRESETS[this.presentationMode] ?? CAMERA_PRESETS.framed
    if (this._camera) {
      this._camera.position.set(...preset.pos)
      this._camera.fov = preset.fov
      this._camera.updateProjectionMatrix()
    }
    if (this._controls) {
      this._controls.target.set(...preset.target)
      this._controls.update()
    }
    this._locked              = true
    this._controls.enabled    = false
    this._controls.autoRotate = true
  }

  resize (w, h) {
    if (!this._renderer || w === 0 || h === 0) return
    this._renderer.setSize(w, h, false)
    if (this._camera) {
      this._camera.aspect = w / h
      this._camera.updateProjectionMatrix()
    }
  }

  setBackground (hex) {
    this.frameBackground = hex
    this._renderer?.setClearColor(new THREE.Color(hex), 1)
  }

  // ── Pose system ────────────────────────────────────────────────────────────

  /**
   * Smoothly transition the skeleton to a named pose.
   * @param {string} poseName — key from the POSES constant
   */
  applyPose (poseName) {
    if (!POSES[poseName] || poseName === this._currentPose) return

    const nextPose = POSES[poseName]

    // Collect all bones affected by either the current or next pose
    const affected = new Set([
      ...Object.keys(POSES[this._currentPose] || {}).filter(k => !k.startsWith('_')),
      ...Object.keys(nextPose).filter(k => !k.startsWith('_')),
    ])

    // Snapshot current quaternions as "from"
    this._poseFrom = {}
    for (const name of affected) {
      const bone = this._boneMap[name]
      if (bone) this._poseFrom[name] = bone.quaternion.clone()
    }

    // Compute target quaternions for "to"
    this._poseTo = {}
    for (const name of affected) {
      const bone = this._boneMap[name]
      const restQ = this._boneRestQ[name]
      if (!bone || !restQ) continue

      const [rx, ry, rz] = nextPose[name] ?? [0, 0, 0]
      const offset = new THREE.Quaternion()
      offset.setFromEuler(new THREE.Euler(rx * DEG, ry * DEG, rz * DEG, 'XYZ'))
      // Post-multiply: rotate in the bone's own local space
      this._poseTo[name] = restQ.clone().multiply(offset)
    }

    // Hips vertical offset (sitting)
    const hipsRestY      = this._boneRestY
    this._hipFromY       = this._boneMap['Hips']?.position.y ?? hipsRestY
    this._hipToY         = hipsRestY + (nextPose._hipsY ?? 0)

    this._currentPose = poseName
    this._poseLerpT   = 0
  }

  // ── GLB loading ────────────────────────────────────────────────────────────

  async _loadCharacter (gltfUrl) {
    const loader = new GLTFLoader()
    const gltf   = await loader.loadAsync(gltfUrl)

    // RPM / Blender GLBs export facing -Z; rotate 180° so the face looks toward
    // the camera (which sits at +Z) and auto-rotate opens on the front.
    gltf.scene.rotation.y = Math.PI

    this._scene.add(gltf.scene)

    // Collect morph-target meshes
    this._meshes = []
    gltf.scene.traverse((node) => {
      if (node.isMesh && node.morphTargetDictionary) this._meshes.push(node)
    })

    if (this._meshes.length > 0) {
      const dict = this._meshes[0].morphTargetDictionary
      this._morphMap = {}
      AZURE_BLEND_SHAPES.forEach((name, i) => {
        if (name in dict) this._morphMap[i] = dict[name]
      })
      console.log(`[Avatar3D] ${this._meshes.length} mesh(es), ${Object.keys(this._morphMap).length}/55 blend shapes mapped`)
    }

    // Collect bones and store rest quaternions
    this._boneMap   = {}
    this._boneRestQ = {}
    gltf.scene.traverse((node) => {
      if (node.isBone || node.type === 'Bone') {
        this._boneMap[node.name]   = node
        this._boneRestQ[node.name] = node.quaternion.clone()
      }
    })
    const hips = this._boneMap['Hips']
    this._boneRestY = hips?.position.y ?? 0

    console.log(`[Avatar3D] ${Object.keys(this._boneMap).length} bones captured`)

    // Immediately apply default relaxed pose (no transition — character spawns posed)
    this._applyPoseInstant('relaxed')
    this._currentPose = 'relaxed'
    this._poseLerpT   = 1.0
  }

  /** Apply a pose instantly (no interpolation) — used on first load. */
  _applyPoseInstant (poseName) {
    const pose = POSES[poseName]
    if (!pose) return
    for (const [name, val] of Object.entries(pose)) {
      if (name.startsWith('_')) continue
      const bone = this._boneMap[name]
      const restQ = this._boneRestQ[name]
      if (!bone || !restQ) continue
      const [rx, ry, rz] = val
      const offset = new THREE.Quaternion()
      offset.setFromEuler(new THREE.Euler(rx * DEG, ry * DEG, rz * DEG, 'XYZ'))
      bone.quaternion.copy(restQ).multiply(offset)
    }
    const hips = this._boneMap['Hips']
    if (hips) hips.position.y = this._boneRestY + (pose._hipsY ?? 0)
  }

  // ── Preview playback ───────────────────────────────────────────────────────

  async loadPreview (audioUrl, blendShapesUrl) {
    try {
      const data   = await (await fetch(blendShapesUrl)).json()
      this._frames = data.frames.map(f => ({ t: f.t, w: f.w }))
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

  // ── Render loop ────────────────────────────────────────────────────────────

  _loop () {
    this._rafId = requestAnimationFrame(() => this._loop())
    const delta = this._clock.getDelta()

    if (this._playing && this._meshes.length > 0 && this._frames.length > 0) {
      this._applyBlendShapes(this._audio.currentTime)
    }

    this._updatePoseLerp(delta)
    this._idlePass(delta * 1000)
    this._controls?.update()
    this._renderer?.render(this._scene, this._camera)
  }

  _updatePoseLerp (deltaSec) {
    if (this._poseLerpT >= 1.0) return
    this._poseLerpT = Math.min(1.0, this._poseLerpT + deltaSec / this._poseDuration)
    const t = easeInOut(this._poseLerpT)

    for (const [name, toQ] of Object.entries(this._poseTo)) {
      const bone  = this._boneMap[name]
      const fromQ = this._poseFrom[name] ?? this._boneRestQ[name]
      if (!bone || !fromQ) continue
      bone.quaternion.slerpQuaternions(fromQ, toQ, t)
    }

    const hips = this._boneMap['Hips']
    if (hips && this._hipFromY !== this._hipToY) {
      hips.position.y = this._hipFromY + (this._hipToY - this._hipFromY) * t
    }
  }

  _applyBlendShapes (t) {
    const frames = this._frames
    let lo = 0, hi = frames.length - 1, idx = 0
    while (lo <= hi) {
      const mid = (lo + hi) >> 1
      if (frames[mid].t <= t) { idx = mid; lo = mid + 1 }
      else hi = mid - 1
    }
    const f0 = frames[idx]
    const f1 = frames[Math.min(idx + 1, frames.length - 1)]
    const a  = f1.t > f0.t ? Math.min(1, (t - f0.t) / (f1.t - f0.t)) : 0
    for (const [si, mi] of Object.entries(this._morphMap)) {
      const w = (f0.w[si] ?? 0) + ((f1.w[si] ?? 0) - (f0.w[si] ?? 0)) * a
      for (const mesh of this._meshes) mesh.morphTargetInfluences[mi] = w
    }
  }

  _idlePass (deltaMs) {
    if (!this._meshes.length) return
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
    const dict = this._meshes[0]?.morphTargetDictionary
    if (!dict) return
    const l = dict['eyeBlinkLeft'], r = dict['eyeBlinkRight']
    if (l === undefined || r === undefined) return
    for (const m of this._meshes) { m.morphTargetInfluences[l] = 1; m.morphTargetInfluences[r] = 1 }
    setTimeout(() => {
      for (const m of this._meshes) { m.morphTargetInfluences[l] = 0; m.morphTargetInfluences[r] = 0 }
    }, 120)
  }

  // ── Cleanup ────────────────────────────────────────────────────────────────

  destroy () {
    this._resizeObs?.disconnect()
    if (this._rafId) cancelAnimationFrame(this._rafId)
    this._audio?.pause()
    this._controls?.dispose()
    this._renderer?.dispose()
    this._scene?.traverse((obj) => {
      obj.geometry?.dispose()
      if (obj.material) {
        const mats = Array.isArray(obj.material) ? obj.material : [obj.material]
        mats.forEach(m => m.dispose())
      }
    })
  }
}

// ── Global exposure ──────────────────────────────────────────────────────────
window.Avatar3DPlayer = Avatar3DPlayer

// ── Livewire event → load preview ────────────────────────────────────────────
window.addEventListener('avatar3d:previewReady', (ev) => {
  const { audioUrl, blendShapesUrl } = ev.detail
  window._avatar3d?.loadPreview(audioUrl, blendShapesUrl)
    .then(() => console.log('[Avatar3D] Preview ready'))
})

// ── HMR cleanup ───────────────────────────────────────────────────────────────
if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    window._avatar3d?.destroy()
    window._avatar3d = window.Avatar3DPlayer = null
  })
}

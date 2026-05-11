/**
 * avatar-3d.js — Three.js 3D avatar player driven by Azure ARKit blend shapes
 *
 * Compatible with Ready Player Me / MPFB GLBs that include ARKit morph targets.
 * No morph-map.json required — shape names are matched directly from the GLB.
 *
 * Camera:
 *   Always interactive. OrbitControls enabled from the start; user can freely
 *   orbit, zoom, and inspect the character.
 *
 * Poses (bone-level):
 *   applyPose(name)  — smoothly transitions skeleton to a preset pose
 *   Built-in presets: 'tpose' | 'relaxed' | 'presenting' | 'leaning' | 'sitting'
 *   Rest quaternions stored at load time; offsets are in each bone's LOCAL space.
 *   Values tuned for the Ready Player Me / MPFB Mixamo rig.
 *
 * Usage:
 *   const player = new Avatar3DPlayer(canvasEl, { characterUrl })
 *   await player.init()                    // loads GLB, applies 'relaxed' pose
 *   player.applyPose('sitting')
 *   await player.loadPreview(audioUrl, blendShapesUrl)
 *   player.play()
 */
import * as THREE from 'three'
import { GLTFLoader }    from 'three/examples/jsm/loaders/GLTFLoader.js'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
import { FBXLoader }     from 'three/examples/jsm/loaders/FBXLoader.js'

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
 * Camera preset. Target Y = 0.9 (mid-body) centres the full character in frame.
 * Camera sits at Z=2.0 for a tighter full-body shot.
 */
const CAMERA_PRESET = { fov: 52, pos: [0, 0.9, 2.0], target: [0, 0.9, 0] }

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
    characterUrl = null,
  } = {}) {
    this.canvasEl     = canvasEl
    this.characterUrl = characterUrl

    this._scene    = null
    this._camera   = null
    this._renderer = null
    this._controls = null
    this._meshes   = []
    this._morphMap = {}

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

    // Animation clip (applied to the character rig)
    this._characterRoot      = null   // gltf.scene reference stored after character load
    this._animMixer          = null   // THREE.AnimationMixer
    this._animAction         = null   // current THREE.AnimationAction
    this._animDuration       = 0      // clip duration in seconds
    this._animPlaying        = false  // is animation currently playing
    this._fbxLoader          = new FBXLoader()
    this._bodyAction         = null
    this._animSpeed          = 1.0   // playback speed multiplier (0.25 – 2.0)
    this._animExpressiveness = 1.0   // blend weight (0.0 – 1.0)

    // Idle
    this._blinkTimer = 0
    this._blinkNext  = 3000
    this._clock      = new THREE.Clock()
    this._resizeObs  = null

    // Gaze — character looks at viewer face zones (left eye / right eye / mouth)
    this._gazeTimer   = 800 + Math.random() * 1200  // ms until first gaze shift
    this._gazeTarget  = new THREE.Vector3(0, 0.9, 2.0)
    this._gazeYaw     = 0   // current smoothed yaw   (radians, char-local)
    this._gazePitch   = 0   // current smoothed pitch (radians, char-local)
    this._neckBaseQ   = null  // neck quaternion snapshot taken after mixer, before gaze

    // Viseme playback (amplitude-driven via Web Audio)
    this._visemeTimings  = []
    this._audioCtx       = null
    this._analyser       = null
    this._analyserBuf    = null

    // Camera lerp
    this._camLerpT  = 1.0
    this._camFrom   = null
    this._camTarget = null
  }

  // ── Initialisation ─────────────────────────────────────────────────────────

  async init () {
    const w = this.canvasEl.clientWidth  || this.canvasEl.parentElement?.clientWidth  || 400
    const h = this.canvasEl.clientHeight || this.canvasEl.parentElement?.clientHeight || 500

    this._renderer = new THREE.WebGLRenderer({ canvas: this.canvasEl, antialias: true, alpha: false })
    this._renderer.setSize(w, h)
    this._renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    this._renderer.outputColorSpace = THREE.SRGBColorSpace

    this._scene = new THREE.Scene()

    // ── Gradient background ──────────────────────────────────────────────────
    const bgCanvas  = document.createElement('canvas')
    bgCanvas.width  = 2
    bgCanvas.height = 256
    const bgCtx = bgCanvas.getContext('2d')
    const grad  = bgCtx.createLinearGradient(0, 0, 0, 256)
    grad.addColorStop(0,   '#1a1a3a')   // dark indigo at top
    grad.addColorStop(0.5, '#0f172a')   // slate-900 mid
    grad.addColorStop(1,   '#060610')   // near-black at bottom
    bgCtx.fillStyle = grad
    bgCtx.fillRect(0, 0, 2, 256)
    this._scene.background = new THREE.CanvasTexture(bgCanvas)

    // ── Fog ───────────────────────────────────────────────────────────────────
    this._scene.fog = new THREE.Fog(0x0f172a, 5, 14)

    // ── Ground grid ───────────────────────────────────────────────────────────
    const grid = new THREE.GridHelper(14, 28, 0x1e2040, 0x141428)
    grid.position.y = 0
    this._scene.add(grid)

    // ── Camera ────────────────────────────────────────────────────────────────
    this._camera = new THREE.PerspectiveCamera(CAMERA_PRESET.fov, w / h, 0.01, 100)
    this._camera.position.set(...CAMERA_PRESET.pos)

    // ── Three-point lighting ──────────────────────────────────────────────────
    this._scene.add(new THREE.AmbientLight(0xffffff, 1.2))
    const key = new THREE.DirectionalLight(0xffeedd, 1.6)
    key.position.set(1.5, 2.5, 2)
    this._scene.add(key)
    const fill = new THREE.DirectionalLight(0xddeeff, 0.6)
    fill.position.set(-2, 1, 1)
    this._scene.add(fill)

    // ── Orbit controls (always interactive) ──────────────────────────────────
    this._controls = new OrbitControls(this._camera, this._renderer.domElement)
    this._controls.enableDamping  = true
    this._controls.dampingFactor  = 0.08
    this._controls.enablePan      = false
    this._controls.minDistance    = 0.5
    this._controls.maxDistance    = 6.0
    this._controls.minPolarAngle  = 0.05
    this._controls.maxPolarAngle  = Math.PI * 0.80
    this._controls.target.set(...CAMERA_PRESET.target)
    this._controls.update()

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

  /** Snap camera back to the default position. */
  resetCamera () {
    if (this._camera) {
      this._camera.position.set(...CAMERA_PRESET.pos)
      this._camera.fov = CAMERA_PRESET.fov
      this._camera.updateProjectionMatrix()
    }
    if (this._controls) {
      this._controls.target.set(...CAMERA_PRESET.target)
      this._controls.update()
    }
  }

  /** Smooth camera dolly to head close-up. */
  zoomToHead () {
    this._camTarget = { pos: new THREE.Vector3(0, 1.62, 0.55), look: new THREE.Vector3(0, 1.62, 0), fov: 28 }
    this._camLerpT  = 0
    this._camFrom   = {
      pos:  this._camera.position.clone(),
      look: this._controls ? this._controls.target.clone() : new THREE.Vector3(0, 0.9, 0),
      fov:  this._camera.fov,
    }
  }

  /** Smooth camera dolly back to full-body. */
  zoomToBody () {
    this._camTarget = { pos: new THREE.Vector3(...CAMERA_PRESET.pos), look: new THREE.Vector3(...CAMERA_PRESET.target), fov: CAMERA_PRESET.fov }
    this._camLerpT  = 0
    this._camFrom   = {
      pos:  this._camera.position.clone(),
      look: this._controls ? this._controls.target.clone() : new THREE.Vector3(0, 0.9, 0),
      fov:  this._camera.fov,
    }
  }

  /**
   * Speak audio with amplitude-driven jaw animation via Web Audio AnalyserNode.
   * Word timings are ignored — real-time RMS from the audio drives jawOpen.
   */
  speakWithVisemes (audioUrl) {
    // Tear down previous audio
    if (this._audio) { this._audio.pause(); this._audio = null }
    if (this._audioCtx) { this._audioCtx.close(); this._audioCtx = null }
    this._analyser    = null
    this._analyserBuf = null
    this._frames      = []
    this._visemeTimings = []

    const audio = new Audio(audioUrl)
    this._audio = audio

    audio.addEventListener('ended', () => {
      this._playing = false
      this._clearMouthShapes()
    })

    this._playing = false
    this.zoomToHead()

    // Slight delay so zoom starts before audio plays
    setTimeout(() => {
      // Set up Web Audio analyser for real-time amplitude
      try {
        const Ctx      = window.AudioContext || window.webkitAudioContext
        const ctx      = new Ctx()
        const source   = ctx.createMediaElementSource(audio)
        const analyser = ctx.createAnalyser()
        analyser.fftSize              = 256
        analyser.smoothingTimeConstant = 0.6
        source.connect(analyser)
        analyser.connect(ctx.destination)
        this._audioCtx    = ctx
        this._analyser    = analyser
        this._analyserBuf = new Uint8Array(analyser.frequencyBinCount)
        // Resume context (may be suspended if no prior gesture)
        ctx.resume().catch(() => {})
      } catch (e) {
        console.warn('[Avatar3D] Web Audio setup failed, jaw animation disabled:', e)
      }

      audio.play().catch(e => console.warn('[Avatar3D] audio.play() failed:', e))
      this._playing = true
    }, 600)
  }

  _clearMouthShapes () {
    if (!this._meshes.length) return
    const dict = this._meshes[0].morphTargetDictionary
    if (!dict) return
    for (const name of ['jawOpen', 'mouthFunnel', 'mouthPucker', 'mouthSmileLeft', 'mouthSmileRight', 'mouthRollLower']) {
      const i = dict[name]
      if (i !== undefined) for (const m of this._meshes) m.morphTargetInfluences[i] = 0
    }
  }

  /** Drive jawOpen from real-time audio RMS via Web Audio AnalyserNode. */
  _applyVisemes () {
    if (!this._meshes.length || !this._analyser) return
    const dict = this._meshes[0].morphTargetDictionary
    if (!dict) return

    const jawIdx    = dict['jawOpen']
    const funnelIdx = dict['mouthFunnel']
    if (jawIdx === undefined) return

    // Sample frequency-domain data and compute RMS of speech band (100-3000 Hz)
    this._analyser.getByteFrequencyData(this._analyserBuf)
    const buf        = this._analyserBuf
    const sampleRate = this._audioCtx?.sampleRate ?? 44100
    const binHz      = sampleRate / (this._analyser.fftSize)
    const loIdx      = Math.floor(100  / binHz)
    const hiIdx      = Math.min(buf.length - 1, Math.floor(3000 / binHz))
    let sum = 0
    for (let i = loIdx; i <= hiIdx; i++) sum += buf[i] * buf[i]
    const rms = Math.sqrt(sum / Math.max(1, hiIdx - loIdx + 1))

    // Map RMS (0-255 scale) → jaw target (0-0.7)
    const targetJaw    = Math.min(0.7,  (rms / 255) * 2.2)
    const targetFunnel = Math.min(0.35, (rms / 255) * 1.1)

    // Smooth lerp — fast open, slower close
    const lerpSpeed = targetJaw > (this._meshes[0].morphTargetInfluences[jawIdx] || 0) ? 0.35 : 0.18

    for (const m of this._meshes) {
      const cur = m.morphTargetInfluences[jawIdx] || 0
      m.morphTargetInfluences[jawIdx] = cur + (targetJaw - cur) * lerpSpeed
      if (funnelIdx !== undefined) {
        const curF = m.morphTargetInfluences[funnelIdx] || 0
        m.morphTargetInfluences[funnelIdx] = curF + (targetFunnel - curF) * lerpSpeed
      }
    }
  }

  resize (w, h) {
    if (!this._renderer || w === 0 || h === 0) return
    this._renderer.setSize(w, h, false)
    if (this._camera) {
      this._camera.aspect = w / h
      this._camera.updateProjectionMatrix()
    }
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

    // Remove previous character if swapping
    if (this._characterRoot) {
      this._scene.remove(this._characterRoot)
      this._animMixer?.stopAllAction()
      this._animMixer = null
      this._bodyAction = null
    }

    gltf.scene.rotation.y = 0
    this._characterRoot = gltf.scene
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

    // Use the GLB's baked rest pose (A-pose from Blender export) — no offset applied.
    this._currentPose = 'tpose'
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

  // ── Azure blend shape speech ───────────────────────────────────────────────

  /**
   * Synthesise text via Azure Speech SDK (browser-side) to get per-frame blend shapes,
   * then play the provided audioUrl (e.g. Pocket TTS cloned voice) with those blend
   * shapes time-scaled to match the actual audio duration.
   *
   * Requires window.SpeechSDK (microsoft-cognitiveservices-speech-sdk CDN bundle).
   */
  async speakWithAzureBlendShapes (audioUrl, text, azureKey, azureRegion) {
    if (!window.SpeechSDK) {
      console.warn('[Avatar3D] Azure Speech SDK not loaded — falling back to amplitude visemes')
      this.speakWithVisemes(audioUrl)
      return
    }

    const sdk = window.SpeechSDK

    // Build SSML with FacialExpression viseme tag
    const escaped = text
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    const ssml = `<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="http://www.w3.org/2001/mstts" xml:lang="en-US">
  <voice name="en-US-GuyNeural">
    <mstts:viseme type="FacialExpression"/>
    ${escaped}
  </voice>
</speak>`

    const config = sdk.SpeechConfig.fromSubscription(azureKey, azureRegion)
    config.speechSynthesisOutputFormat = sdk.SpeechSynthesisOutputFormat.Raw22050Hz16BitMonoPcm

    // Collect blend shape frames from viseme events
    const framesBuffer = {}
    const framesOrdered = []

    const synthesizer = new sdk.SpeechSynthesizer(config, null)

    synthesizer.visemeReceived = (s, e) => {
      try {
        if (!e.animation) return
        const anim       = JSON.parse(e.animation)
        const frameIndex = anim.FrameIndex
        const shapes     = anim.BlendShapes   // array of arrays (multiple frames per event)

        if (frameIndex === framesOrdered.length) {
          framesOrdered.push(...shapes)
          let next = framesOrdered.length
          while (framesBuffer[next]) {
            const buffered = framesBuffer[next]
            framesOrdered.push(...buffered)
            delete framesBuffer[next]
            next = framesOrdered.length
          }
        } else if (frameIndex > framesOrdered.length) {
          framesBuffer[frameIndex] = shapes
        }
      } catch (err) {
        console.warn('[Avatar3D] viseme parse error:', err)
      }
    }

    // Perform synthesis — audio discarded, we only need the viseme frames
    const azureDone = new Promise((resolve, reject) => {
      synthesizer.speakSsmlAsync(ssml,
        (result) => {
          synthesizer.close()
          if (result.reason === sdk.ResultReason.SynthesizingAudioCompleted) {
            resolve()
          } else {
            reject(new Error(`Azure TTS failed: ${result.reason}`))
          }
        },
        (err) => { synthesizer.close(); reject(err) }
      )
    })

    // Load the Pocket TTS audio element to get its duration in parallel
    const audioDuration = new Promise((resolve) => {
      const a = new Audio(audioUrl)
      a.addEventListener('loadedmetadata', () => resolve(a.duration))
      a.addEventListener('error', () => resolve(0))
    })

    try {
      const [, pocketSecs] = await Promise.all([azureDone, audioDuration])

      if (!framesOrdered.length) {
        console.warn('[Avatar3D] Azure returned 0 viseme frames — check SSML / API key')
        this.speakWithVisemes(audioUrl)
        return
      }

      const FPS         = 60
      const azureSecs   = framesOrdered.length / FPS
      const timeScale   = pocketSecs > 0.1 && azureSecs > 0.1 ? pocketSecs / azureSecs : 1

      // Build _frames in the format expected by _applyBlendShapes: [{t, w:[52 floats]}]
      this._frames = framesOrdered.map((weights, i) => ({
        t: (i / FPS) * timeScale,
        w: weights,
      }))

      console.log(`[Avatar3D] Azure: ${framesOrdered.length} frames, azure=${azureSecs.toFixed(2)}s, pocket=${pocketSecs.toFixed(2)}s, scale=${timeScale.toFixed(3)}`)

      // Play the Pocket TTS audio with Azure blend shapes driving the face
      if (this._audio) { this._audio.pause(); this._audio = null }
      if (this._audioCtx) { this._audioCtx.close(); this._audioCtx = null }
      this._visemeTimings = []

      const audio = new Audio(audioUrl)
      this._audio = audio
      audio.addEventListener('ended', () => {
        this._playing = false
        this._clearMouthShapes()
      })

      this._playing = false
      this.zoomToHead()
      setTimeout(() => {
        audio.play().catch(e => console.warn('[Avatar3D] play() failed:', e))
        this._playing = true
      }, 600)

    } catch (err) {
      console.warn('[Avatar3D] Azure blend shapes failed, falling back:', err)
      this.speakWithVisemes(audioUrl)
    }
  }

  // ── Animation clip ─────────────────────────────────────────────────────────

  /**
   * Load a GLB animation file and retarget it onto the loaded character rig.
   * Loops forever until replaced or destroyed.
   */
  async loadAnimation (glbUrl) {
    if (this._animMixer) { this._animMixer.stopAllAction(); this._animMixer = null }
    this._animAction   = null
    this._animDuration = 0
    this._animPlaying  = false

    if (!this._characterRoot) {
      console.warn('[Avatar3D] loadAnimation: character not loaded yet')
      return
    }

    const gltf = await new GLTFLoader().loadAsync(glbUrl)
    if (gltf.animations.length === 0) {
      console.warn('[Avatar3D] loadAnimation: no animations in', glbUrl)
      return
    }

    const clip         = gltf.animations[0]
    this._animMixer    = new THREE.AnimationMixer(this._characterRoot)
    this._animAction   = this._animMixer.clipAction(clip)
    this._animDuration = clip.duration
    this._animAction.setLoop(THREE.LoopRepeat)
    this._animAction.clampWhenFinished = false
    this._animAction.play()
    this._animPlaying  = true
  }

  /** Toggle animation play / pause. */
  toggleAnimation () {
    if (!this._animMixer) return
    if (this._animPlaying) {
      this._animMixer.timeScale = 0
      this._animPlaying = false
    } else {
      this._animMixer.timeScale = 1
      this._animPlaying = true
    }
  }

  /** Seek to a normalised position (0–1) in the animation clip. */
  seekAnimation (progress) {
    if (!this._animAction || !this._animDuration) return
    this._animAction.time = Math.max(0, Math.min(this._animDuration, progress * this._animDuration))
    this._animMixer.update(0) // force a frame update while paused
  }

  /** Normalised playhead position (0–1). */
  get animProgress () {
    if (!this._animAction || !this._animDuration) return 0
    return this._animAction.time / this._animDuration
  }

  /** True while the animation is running. */
  get animIsPlaying () { return this._animPlaying }

  /** Duration of the loaded clip in seconds. */
  get animDuration () { return this._animDuration }

  // ── Render loop ────────────────────────────────────────────────────────────

  _loop () {
    this._rafId = requestAnimationFrame(() => this._loop())
    const delta = this._clock.getDelta()

    if (this._playing && this._meshes.length > 0) {
      if (this._frames.length > 0) {
        this._applyBlendShapes(this._audio.currentTime)
      } else {
        this._applyVisemes()
      }
    }

    this._animMixer?.update(delta)
    // Snapshot neck base AFTER mixer runs.
    // When mixer is active it resets the bone each frame → snapshot its output.
    // When no mixer is running the bone retains our previous gaze modification →
    // use the rest quaternion instead, so gaze never compounds onto itself.
    const _nb = this._boneMap['Neck'] || this._boneMap['Head']
    if (_nb) {
      this._neckBaseQ = this._animMixer
        ? _nb.quaternion.clone()
        : (this._boneRestQ['Neck'] ?? this._boneRestQ['Head'] ?? new THREE.Quaternion())
    }
    this._gazePass(delta * 1000)    // after mixer so it stacks on top of animation
    this._updatePoseLerp(delta)
    this._updateCameraLerp(delta)
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

  _updateCameraLerp (deltaSec) {
    if (this._camLerpT >= 1.0 || !this._camFrom || !this._camTarget) return
    this._camLerpT = Math.min(1.0, this._camLerpT + deltaSec / 0.7)
    const t = easeInOut(this._camLerpT)
    this._camera.position.lerpVectors(this._camFrom.pos, this._camTarget.pos, t)
    this._camera.fov = this._camFrom.fov + (this._camTarget.fov - this._camFrom.fov) * t
    this._camera.updateProjectionMatrix()
    if (this._controls) {
      this._controls.target.lerpVectors(this._camFrom.look, this._camTarget.look, t)
      this._controls.update()
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

  // ── Gaze system ────────────────────────────────────────────────────────────

  /**
   * Gaze zones relative to camera position (world units).
   * Simulates natural eye-contact: character glances at viewer's left eye,
   * right eye, or mouth — mirrored from the viewer's POV.
   */
  static get GAZE_ZONES () {
    return [
      { x: -0.055, y:  0.04 },   // viewer's left eye
      { x:  0.055, y:  0.04 },   // viewer's right eye
      { x:  0.000, y: -0.09 },   // viewer's mouth
    ]
  }

  _gazePass (deltaMs) {
    this._gazeTimer -= deltaMs

    if (this._gazeTimer <= 0) {
      const camPos = this._camera.position
      const r = () => (Math.random() - 0.5)

      if (Math.random() < 0.78) {
        // Look at one of the three face zones
        const zone = Avatar3DPlayer.GAZE_ZONES[Math.floor(Math.random() * 3)]
        this._gazeTarget.set(
          camPos.x + zone.x + r() * 0.02,
          camPos.y + zone.y + r() * 0.02,
          camPos.z,
        )
        this._gazeTimer = 1200 + Math.random() * 2200
      } else {
        // Brief look-away (natural break in eye contact)
        this._gazeTarget.set(
          camPos.x + r() * 0.7,
          camPos.y + r() * 0.25,
          camPos.z - 0.3,
        )
        this._gazeTimer = 400 + Math.random() * 800
      }
    }

    // ── Compute desired yaw / pitch toward gaze target ──────────────────────
    const neckBone = this._boneMap['Neck'] || this._boneMap['Head']
    if (!neckBone) return

    const neckWorld = new THREE.Vector3()
    neckBone.getWorldPosition(neckWorld)

    const toTarget = new THREE.Vector3().subVectors(this._gazeTarget, neckWorld)
    const dist     = toTarget.length()
    if (dist < 0.01) return
    toTarget.divideScalar(dist)

    // Character faces +Z; yaw = atan2(x, z) gives 0 when looking straight ahead.
    const targetYaw   = Math.atan2(toTarget.x, toTarget.z)
    const targetPitch = Math.asin(Math.max(-1, Math.min(1, toTarget.y)))

    const MAX_YAW   = 28 * DEG
    const MAX_PITCH = 18 * DEG
    const clampedYaw   = Math.max(-MAX_YAW,   Math.min(MAX_YAW,   targetYaw))
    const clampedPitch = Math.max(-MAX_PITCH, Math.min(MAX_PITCH, targetPitch))

    // Smooth follow — natural head-turn speed
    const k = Math.min(1, deltaMs * 0.0025)
    this._gazeYaw   += (clampedYaw   - this._gazeYaw)   * k
    this._gazePitch += (clampedPitch - this._gazePitch) * k

    // ── Apply to Neck bone relative to the pre-gaze snapshot ───────────────
    // Using the snapshot (not current bone quaternion) prevents the rotation
    // from compounding each frame when no AnimationMixer is running.
    const gazeQ = new THREE.Quaternion()
    gazeQ.setFromEuler(new THREE.Euler(this._gazePitch, this._gazeYaw, 0, 'YXZ'))
    const baseQ = this._neckBaseQ || this._boneRestQ['Neck'] || this._boneRestQ['Head'] || new THREE.Quaternion()
    neckBone.quaternion.copy(baseQ).multiply(gazeQ)

    // ── Drive eye look morph targets with per-eye parallax ──────────────────
    if (!this._meshes.length) return
    const dict = this._meshes[0].morphTargetDictionary
    if (!dict) return

    // Normalise angles (-1 … 1)
    const hN = this._gazeYaw   / MAX_YAW    // + = char looking right
    const vN = this._gazePitch / MAX_PITCH  // + = char looking up

    // Left eye aims slightly further left; right eye slightly further right
    const PARALLAX = 0.08
    const hL = hN - PARALLAX   // left  eye horizontal
    const hR = hN + PARALLAX   // right eye horizontal

    const setM = (name, val) => {
      const i = dict[name]
      if (i !== undefined)
        for (const m of this._meshes)
          m.morphTargetInfluences[i] = Math.max(0, Math.min(1, val * 0.55))
    }

    // ARKit convention: "In" = toward nose, "Out" = away from nose
    // char looking right (+hN): left eye goes OUT, right eye goes IN
    setM('eyeLookOutLeft',   hL > 0 ?  hL : 0)
    setM('eyeLookInLeft',    hL < 0 ? -hL : 0)
    setM('eyeLookInRight',   hR > 0 ?  hR : 0)
    setM('eyeLookOutRight',  hR < 0 ? -hR : 0)
    // Vertical (same both eyes)
    setM('eyeLookUpLeft',    vN > 0 ?  vN : 0)
    setM('eyeLookDownLeft',  vN < 0 ? -vN : 0)
    setM('eyeLookUpRight',   vN > 0 ?  vN : 0)
    setM('eyeLookDownRight', vN < 0 ? -vN : 0)
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

  // ── Body animation (FBX from Mixamo) ──────────────────────────────────────

  async loadBodyAnimation (fbxUrl) {
    if (!this._characterRoot) return

    // Create mixer lazily — _loadCharacter() doesn't create one
    if (!this._animMixer) {
      this._animMixer = new THREE.AnimationMixer(this._characterRoot)
    }

    const fbx  = await new Promise((res, rej) => this._fbxLoader.load(fbxUrl, res, undefined, rej))
    const clip = fbx.animations?.[0]
    if (!clip) { console.warn('[Avatar3D] FBX has no animation clips:', fbxUrl); return }

    // ── Retarget: strip "mixamorig" + optional digit prefix ──
    // Mixamo exports as "mixamorig1Hips.quaternion" → RPM needs "Hips.quaternion"
    clip.tracks.forEach(track => {
      track.name = track.name.replace(/^mixamorig\d*/g, '')
    })

    // Drop ALL position tracks — Mixamo bakes foot IK + uses cm scale (100× GLB metres).
    // Position tracks slide the feet and shoot the character off-screen.
    clip.tracks = clip.tracks.filter(track => !track.name.endsWith('.position'))

    // Drop Hips quaternion — bakes global root rotation which spins the skeleton.
    clip.tracks = clip.tracks.filter(track => track.name !== 'Hips.quaternion')

    // Drop lower-body quaternion tracks — Mixamo bakes weight-shifting micro-movement
    // into UpLeg/Leg/Foot/Toe bones. Without foot IK (Unity/Unreal apply this
    // automatically) Three.js plays the raw FK chain and feet lift off the floor.
    // Locking the leg chain in bind pose gives stable planted feet; the upper body
    // (spine, chest, arms, neck) still animates fully.
    const LOWER_BODY = new Set([
      'LeftUpLeg', 'RightUpLeg',
      'LeftLeg',   'RightLeg',
      'LeftFoot',  'RightFoot',
      'LeftToeBase', 'RightToeBase',
    ])
    clip.tracks = clip.tracks.filter(track => {
      const bone = track.name.split('.')[0]
      return !LOWER_BODY.has(bone)
    })

    console.log(`[Avatar3D] Loading body animation: ${fbxUrl} (${clip.tracks.length} tracks)`)

    if (this._bodyAction) {
      this._bodyAction.fadeOut(0.3)
    }

    const action = this._animMixer.clipAction(clip, this._characterRoot)
    action.reset().fadeIn(0.3).play()
    action.setEffectiveWeight(this._animExpressiveness)
    this._animMixer.timeScale = this._animSpeed
    this._bodyAction = action
  }

  // ── Animation tweaks ──────────────────────────────────────────────────────

  /**
   * Set animation playback speed.
   * @param {number} speed — multiplier, e.g. 0.5 = half speed, 2.0 = double speed
   */
  setAnimationSpeed (speed) {
    this._animSpeed = speed
    if (this._animMixer) this._animMixer.timeScale = speed
  }

  /**
   * Set animation expressiveness — scales the influence (weight) of the current action.
   * 0 = frozen in bind pose, 1 = full animation, values > 1 exaggerate.
   * @param {number} weight — 0.0 to 1.5
   */
  setAnimationExpressiveness (weight) {
    this._animExpressiveness = weight
    if (this._bodyAction) this._bodyAction.setEffectiveWeight(weight)
  }

  // ── Cleanup ────────────────────────────────────────────────────────────────

  destroy () {
    this._resizeObs?.disconnect()
    if (this._rafId) cancelAnimationFrame(this._rafId)
    this._audio?.pause()
    this._audioCtx?.close()
    this._audioCtx = null
    if (this._animMixer) { this._animMixer.stopAllAction(); this._animMixer = null }
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

// ── Auto-init ─────────────────────────────────────────────────────────────────
// Initialise (or re-initialise) the player whenever the canvas is present.
// Called on first script load AND after every Livewire DOM morph so that
// selecting an avatar (which renders the canvas) always spins up the player.
function autoInit () {
  const canvas = document.getElementById('avatar-lab-canvas')
  if (!canvas) return
  const characterUrl = canvas.dataset.characterUrl
  if (!characterUrl) return
  // Already running for this same character — nothing to do.
  if (window._avatar3d && window._avatar3d._characterUrl === characterUrl) return
  window._avatar3d?.destroy()
  window._avatar3d = new Avatar3DPlayer(canvas, { characterUrl })
  window._avatar3d._characterUrl = characterUrl
  window._avatar3d.init().catch(err => console.error('[Avatar3D] init failed:', err))
}

autoInit()

// Wait for canvas to appear in DOM (after Livewire morphs it in), then resolve.
function waitForCanvas (timeout = 3000) {
  return new Promise((resolve, reject) => {
    const el = document.getElementById('avatar-lab-canvas')
    if (el) { resolve(el); return }
    const obs = new MutationObserver(() => {
      const el = document.getElementById('avatar-lab-canvas')
      if (el) { obs.disconnect(); resolve(el) }
    })
    obs.observe(document.body, { childList: true, subtree: true })
    setTimeout(() => { obs.disconnect(); reject(new Error('canvas timeout')) }, timeout)
  })
}

// Fired by selectAvatar() — load (or swap) the character GLB.
// Canvas may not exist yet if this is the first avatar selection, so wait for it.
window.addEventListener('avatar3d:load', async (ev) => {
  const { characterUrl } = ev.detail

  if (window._avatar3d && window._avatar3d._characterUrl === characterUrl) return

  const canvas = await waitForCanvas().catch(() => null)
  if (!canvas) { console.error('[Avatar3D] canvas never appeared'); return }

  if (!window._avatar3d) {
    window._avatar3d = new Avatar3DPlayer(canvas, { characterUrl })
    window._avatar3d._characterUrl = characterUrl
    await window._avatar3d.init().catch(err => console.error('[Avatar3D] init failed:', err))
  } else {
    // Player already running — just swap the character, keep the scene/renderer alive.
    window._avatar3d._characterUrl = characterUrl
    await window._avatar3d._loadCharacter(characterUrl).catch(err => console.error('[Avatar3D] character load failed:', err))
  }
})

// ── Livewire event → load preview ────────────────────────────────────────────
window.addEventListener('avatar3d:previewReady', (ev) => {
  const { audioUrl, blendShapesUrl } = ev.detail
  window._avatar3d?.loadPreview(audioUrl, blendShapesUrl)
    .then(() => console.log('[Avatar3D] Preview ready'))
})

// ── Body animation preview (Avatar Lab) ──────────────────────────────────────
window.addEventListener('preview-clip', (ev) => {
  if (!ev.detail.fbxUrl) return
  window._avatar3d?.loadBodyAnimation(ev.detail.fbxUrl)
    .catch(err => console.error('[Avatar3D] Failed to load FBX:', err))
})

// ── Narration speak (Avatar Lab) — Azure blend shapes + Pocket TTS audio ─────
window.addEventListener('avatar3d:speak', async (ev) => {
  const { audioUrl, text } = ev.detail
  const player = window._avatar3d
  if (!player) return

  const canvas = document.getElementById('avatar-lab-canvas')
  const azureKey    = canvas?.dataset.azureKey    ?? ''
  const azureRegion = canvas?.dataset.azureRegion ?? 'eastus'

  if (azureKey && text && window.SpeechSDK) {
    await player.speakWithAzureBlendShapes(audioUrl, text, azureKey, azureRegion)
  } else {
    // Fallback: play audio only (amplitude-driven jaw via analyser)
    player.speakWithVisemes(audioUrl)
  }
})

// ── HMR cleanup ───────────────────────────────────────────────────────────────
if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    window._avatar3d?.destroy()
    window._avatar3d = window.Avatar3DPlayer = null
  })
}

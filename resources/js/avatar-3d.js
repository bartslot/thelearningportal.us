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
import { SparkRenderer, SplatMesh } from '@sparkjsdev/spark'

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
 * Oculus viseme names (RPM export with morphTargets=Oculus) in order.
 * Used to drive lip sync from ElevenLabs character-level alignment.
 * ElevenLabs returns character timings; we map phoneme → viseme weight set.
 */
const OCULUS_VISEMES = [
  'viseme_sil', 'viseme_PP', 'viseme_FF', 'viseme_TH', 'viseme_DD',
  'viseme_kk',  'viseme_CH', 'viseme_SS', 'viseme_nn', 'viseme_RR',
  'viseme_aa',  'viseme_E',  'viseme_I',  'viseme_O',  'viseme_U',
]

/**
 * English phoneme → Oculus viseme index mapping.
 * Covers the IPA-ish phonemes ElevenLabs alignment chars approximate.
 * Multiple visemes can fire at once (blend weights).
 */
const PHONEME_TO_VISEME = {
  // Bilabials → PP
  'p': 'viseme_PP', 'b': 'viseme_PP', 'm': 'viseme_PP',
  // Labiodentals → FF
  'f': 'viseme_FF', 'v': 'viseme_FF',
  // Dentals → TH
  'θ': 'viseme_TH', 'ð': 'viseme_TH',
  // Alveolars → DD
  't': 'viseme_DD', 'd': 'viseme_DD',
  // Velars → kk
  'k': 'viseme_kk', 'g': 'viseme_kk',
  // Postalveolars → CH
  'ʃ': 'viseme_CH', 'ʒ': 'viseme_CH', 'tʃ': 'viseme_CH', 'dʒ': 'viseme_CH',
  'ch': 'viseme_CH', 'sh': 'viseme_CH',
  // Sibilants → SS
  's': 'viseme_SS', 'z': 'viseme_SS',
  // Nasals → nn
  'n': 'viseme_nn', 'ŋ': 'viseme_nn', 'ng': 'viseme_nn',
  // Liquids/rhotics → RR
  'r': 'viseme_RR', 'l': 'viseme_RR',
  // Vowels — broad mapping from letter to viseme
  'a': 'viseme_aa', 'ɑ': 'viseme_aa', 'æ': 'viseme_aa',
  'e': 'viseme_E',  'ɛ': 'viseme_E',  'eɪ': 'viseme_E',
  'i': 'viseme_I',  'ɪ': 'viseme_I',
  'o': 'viseme_O',  'ɔ': 'viseme_O',  'oʊ': 'viseme_O',
  'u': 'viseme_U',  'ʊ': 'viseme_U',
  // Silence / space
  ' ': 'viseme_sil', '': 'viseme_sil',
}

/** Map a single character from ElevenLabs alignment to an Oculus viseme name. */
function charToViseme (char) {
  const c = char.toLowerCase()
  return PHONEME_TO_VISEME[c] ?? (
    'aeiou'.includes(c) ? 'viseme_aa' :
    'bcdfghjklmnpqrstvwxyz'.includes(c) ? 'viseme_DD' :
    'viseme_sil'
  )
}

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

// ── Skybox shaders ────────────────────────────────────────────────────────────
const SKYBOX_VERT = /* glsl */`
  varying vec2 vUv;
  void main() {
    vUv = vec2(1.0 - uv.x, uv.y);
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  }
`

const SKYBOX_FRAG = /* glsl */`
  uniform sampler2D uTexBase;
  uniform sampler2D uTexLayer;
  uniform float     uBlend;
  uniform vec4      uNoiseColor;
  uniform float     uOpacity;
  uniform vec3      uBgColor;
  uniform bool      uHasLayer;
  varying vec2      vUv;

  float hash(vec2 p) {
    p = fract(p * vec2(234.34, 435.345));
    p += dot(p, p + 34.23);
    return fract(p.x * p.y);
  }
  float vnoise(vec2 p) {
    vec2 i = floor(p), f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);
    return mix(mix(hash(i), hash(i+vec2(1,0)), u.x),
               mix(hash(i+vec2(0,1)), hash(i+vec2(1,1)), u.x), u.y);
  }
  float fbm(vec2 p) {
    float v = 0.0, a = 0.5;
    mat2 rot = mat2(0.8, 0.6, -0.6, 0.8);
    for (int i = 0; i < 4; i++) { v += a * vnoise(p); p = rot * p * 2.0 + 1.7; a *= 0.5; }
    return v;
  }

  void main() {
    vec4 base = texture2D(uTexBase, vUv);
    if (!uHasLayer || uBlend <= 0.001) { gl_FragColor = vec4(mix(uBgColor, base.rgb, uOpacity), 1.0); return; }

    vec4 layer = texture2D(uTexLayer, vUv);
    float n     = fbm(vUv * 5.0);
    float edge  = 0.06;
    float mask  = 1.0 - smoothstep(uBlend - edge, uBlend + edge, n);

    // Tinted noise band visible only mid-transition
    float inTrans = smoothstep(0.0, 0.12, uBlend) * smoothstep(1.0, 0.88, uBlend);
    float band    = max(0.0, 1.0 - abs(n - uBlend) / (edge * 1.5)) * inTrans;

    vec4 blended  = mix(base, layer, mask);
    blended.rgb   = mix(blended.rgb, uNoiseColor.rgb, uNoiseColor.a * band * 0.55);
    gl_FragColor  = vec4(mix(uBgColor, blended.rgb, uOpacity), 1.0);
  }
`

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
    this._meshes        = []
    this._morphMap      = {}
    this._visemeMap     = {}
    this._elevenTimeline = null

    // Skeleton
    this._boneMap    = {}       // name → THREE.Bone
    this._boneRestQ  = {}       // name → Quaternion (bind-pose snapshot)
    this._boneRestY  = 0        // Hips rest position.y
    this._headZoomY  = 1.62     // updated per-model after bounding box fit
    this._shadowPlane      = null
    this._lastSolidBg      = '#f0f0f0'
    // Skybox sphere + layers
    this._skyboxSphere     = null
    this._skyboxBaseTex    = null
    this._skyboxLayers     = []       // [{ url, blur, texture }]
    this._currentLayerIdx  = 0
    this._skyboxBlend      = 0.0
    this._skyboxNoiseColor = new THREE.Vector4(1, 1, 1, 1)
    this._skyboxOpacity    = 1.0
    this._transPhase       = 'base-hold'   // 'base-hold'|'to-layer'|'layer-hold'|'to-base'
    this._transTimer       = 0.0
    this._skyboxHoldTime   = 10.0          // seconds each image is displayed
    this._skyboxFadeTime   = 2.0           // seconds for noise dissolve transition
    this._skyboxUrl        = null
    this._skyboxBlur       = 0.5

    // Accessories
    this._glassesMeshes = []    // Wolf3D_Glasses nodes, toggled via showGlasses()

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
    this._renderer.setSize(w, h, false)   // false = don't override CSS (keeps w-full h-full responsive)
    this._renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    this._renderer.outputColorSpace = THREE.SRGBColorSpace
    this._renderer.shadowMap.enabled = true
    this._renderer.shadowMap.type    = THREE.PCFSoftShadowMap

    this._scene = new THREE.Scene()
    this._scene.background = new THREE.Color('#f0f0f0')

    // ── Camera ────────────────────────────────────────────────────────────────
    this._camera = new THREE.PerspectiveCamera(CAMERA_PRESET.fov, w / h, 0.01, 100)
    this._camera.position.set(...CAMERA_PRESET.pos)

    // ── model-viewer-style lighting ───────────────────────────────────────────
    // Bright hemisphere: sky (warm white) / ground (cool grey) — simulates IBL
    const hemi = new THREE.HemisphereLight(0xffffff, 0xc8c8c8, 1.8)
    this._scene.add(hemi)

    // Key light: soft warm from upper-front-left (casts shadows)
    const key = new THREE.DirectionalLight(0xfff5e8, 1.4)
    key.position.set(-2, 6, 3)
    key.castShadow                       = true
    key.shadow.mapSize.width             = 2048
    key.shadow.mapSize.height            = 2048
    key.shadow.camera.near               = 0.1
    key.shadow.camera.far                = 30
    key.shadow.camera.left               = -5
    key.shadow.camera.right              = 5
    key.shadow.camera.top                = 8
    key.shadow.camera.bottom             = -4
    key.shadow.bias                      = -0.0005
    key.shadow.radius                    = 3      // PCFSoft softness
    this._scene.add(key)
    this._keyLight = key

    // Fill light: subtle cool from opposite side, no shadows
    const fill = new THREE.DirectionalLight(0xe8f0ff, 0.5)
    fill.position.set(2, 2, -1)
    this._scene.add(fill)

    // Rim light: back-light lifts the silhouette from background
    const rim = new THREE.DirectionalLight(0xffffff, 0.3)
    rim.position.set(0, 1.5, -3)
    this._scene.add(rim)
    this._rimLight = rim

    // ── Contact shadow plane (receives shadows, invisible otherwise) ──────────
    const shadowGeo = new THREE.PlaneGeometry(4, 4)
    const shadowMat = new THREE.ShadowMaterial({ opacity: 0.18, transparent: true })
    const shadowPlane = new THREE.Mesh(shadowGeo, shadowMat)
    shadowPlane.rotation.x = -Math.PI / 2
    shadowPlane.position.y = 0
    shadowPlane.receiveShadow = true
    this._scene.add(shadowPlane)
    this._shadowPlane = shadowPlane

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

    // Auto-resize — observe the parent container (canvas has no CSS size of its own;
    // layout dimensions come from the parent's w-full/h-full constraints).
    if (typeof ResizeObserver !== 'undefined') {
      const resizeTarget = this.canvasEl.parentElement ?? this.canvasEl
      this._resizeObs = new ResizeObserver(() => {
        const w = resizeTarget.clientWidth
        const h = resizeTarget.clientHeight
        if (w > 0 && h > 0) this.resize(w, h)
      })
      this._resizeObs.observe(resizeTarget)
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
    this._zoomBiasLock = performance.now() + 1500
  }

  /** Smooth camera dolly to head close-up (physical move only, FOV unchanged). */
  zoomToHead () {
    const headY = this._headZoomY ?? 1.62
    const currentFov = this._camera.fov
    this._camTarget = { pos: new THREE.Vector3(0, headY, 1.1), look: new THREE.Vector3(0, headY, 0), fov: currentFov }
    this._camLerpT  = 0
    this._zoomBiasLock = performance.now() + 1500  // block bias drift for 1.5s after dolly lands
    this._camFrom   = {
      pos:  this._camera.position.clone(),
      look: this._controls ? this._controls.target.clone() : new THREE.Vector3(0, 0.9, 0),
      fov:  currentFov,
    }
  }

  /** Smooth camera dolly back to full-body. */
  zoomToBody () {
    this._camTarget = { pos: new THREE.Vector3(...CAMERA_PRESET.pos), look: new THREE.Vector3(...CAMERA_PRESET.target), fov: CAMERA_PRESET.fov }
    this._camLerpT  = 0
    this._zoomBiasLock = performance.now() + 1500
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
  speakWithVisemes (audioUrl, { zoom = true, delay = 600 } = {}) {
    // Tear down previous audio
    if (this._audio) { this._audio.pause(); this._audio = null }
    if (this._audioCtx) { this._audioCtx.close(); this._audioCtx = null }
    this._analyser    = null
    this._analyserBuf = null
    this._frames      = []
    this._visemeTimings = []

    const audio = new Audio()
    audio.crossOrigin = 'anonymous'
    audio.src = audioUrl
    this._audio = audio

    audio.addEventListener('ended', () => {
      this._playing = false
      this._clearMouthShapes()
      window.dispatchEvent(new CustomEvent('avatar3d:speakend'))
    })

    this._playing = false
    if (zoom) this.zoomToHead()

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
    const mesh = this._richestMesh
    if (!mesh) return
    const dict = mesh.morphTargetDictionary
    if (!dict) return
    const toClear = [
      'jawOpen', 'mouthFunnel', 'mouthPucker', 'mouthSmileLeft', 'mouthSmileRight',
      'mouthRollLower', 'mouthSmile', ...OCULUS_VISEMES,
    ]
    for (const name of toClear) {
      const i = dict[name]
      if (i !== undefined && i < mesh.morphTargetInfluences.length) mesh.morphTargetInfluences[i] = 0
    }
  }

  /**
   * Drive Oculus visemes from ElevenLabs character-level alignment data.
   * alignment: array of {character, start_time, end_time} from /with-timestamps.
   * audioUrl: pre-generated audio to play simultaneously.
   */
  speakWithElevenLabsAlignment (audioUrl, alignment, { zoom = true, delay = 600 } = {}) {
    if (!this._meshes.length) {
      this.speakWithVisemes(audioUrl, { zoom, delay })
      return
    }

    const hasVisemes = Object.keys(this._visemeMap).filter(k => k.startsWith('viseme_')).length > 0
    if (!hasVisemes) {
      console.warn('[Avatar3D] No Oculus visemes mapped — amplitude fallback')
      this.speakWithVisemes(audioUrl, { zoom, delay })
      return
    }

    // Build per-viseme keyframe tracks.
    // Peak weight 0.75 — matches the feel of the debug viseme buttons (0.8) at full
    // ARKit morph intensity without deforming the mesh.
    const PEAK = 0.75
    const byViseme = {}
    for (const entry of alignment) {
      const viseme = charToViseme(entry.character)
      const t0 = entry.start_time
      const t1 = entry.end_time
      const dur = t1 - t0
      if (!byViseme[viseme]) byViseme[viseme] = []
      byViseme[viseme].push({ t: t0,             w: 0 })
      byViseme[viseme].push({ t: t0 + dur * 0.1, w: PEAK })
      byViseme[viseme].push({ t: t1 - dur * 0.1, w: PEAK })
      byViseme[viseme].push({ t: t1,             w: 0 })
    }
    // Sort each track by time
    for (const v of Object.keys(byViseme)) byViseme[v].sort((a, b) => a.t - b.t)

    if (this._audio) { this._audio.pause(); this._audio = null }
    if (this._audioCtx) { this._audioCtx.close(); this._audioCtx = null }
    this._visemeTimings = []
    this._frames = []
    this._elevenTimeline = byViseme

    const audio = new Audio()
    audio.crossOrigin = 'anonymous'
    audio.src = audioUrl
    this._audio = audio
    audio.addEventListener('ended', () => {
      this._playing = false
      this._elevenTimeline = null
      this._clearMouthShapes()
      window.dispatchEvent(new CustomEvent('avatar3d:speakend'))
    })

    this._playing = false
    if (zoom) this.zoomToHead()
    setTimeout(() => {
      audio.play().catch(e => console.warn('[Avatar3D] play() failed:', e))
      this._playing = true
    }, delay)

    console.log(`[Avatar3D] ElevenLabs alignment: ${alignment.length} chars, ${Object.keys(byViseme).length} active visemes`)
  }

  _applyElevenLabsVisemes () {
    if (!this._elevenTimeline || !this._audio || !this._meshes.length) return
    const t = this._audio.currentTime
    const vm = this._visemeMap

    // For each viseme, linearly interpolate between the two bracketing keyframes.
    // This avoids the windowed-max approach that could show multiple visemes at peak simultaneously.
    const weights = {}
    for (const [viseme, track] of Object.entries(this._elevenTimeline)) {
      let w = 0
      for (let i = 0; i < track.length - 1; i++) {
        const a = track[i], b = track[i + 1]
        if (t >= a.t && t <= b.t) {
          const frac = (t - a.t) / (b.t - a.t)
          w = a.w + frac * (b.w - a.w)
          break
        }
      }
      if (w > 0) weights[viseme] = w
    }

    // Apply with smooth lerp — only on the richest mesh (face mesh that owns all viseme morphs)
    const faceMesh = this._richestMesh
    if (!faceMesh) return
    for (const viseme of OCULUS_VISEMES) {
      const mi = vm[viseme]
      if (mi === undefined || mi >= faceMesh.morphTargetInfluences.length) continue
      const target = weights[viseme] ?? 0
      const cur = faceMesh.morphTargetInfluences[mi] || 0
      faceMesh.morphTargetInfluences[mi] = cur + (target - cur) * 0.3
    }
  }

  /** Drive jawOpen from real-time audio RMS via Web Audio AnalyserNode. */
  _applyVisemes () {
    if (!this._meshes.length || !this._analyser) return
    const refMesh = this._richestMesh ?? this._meshes[0]
    const dict = refMesh.morphTargetDictionary
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

    // Smooth lerp — fast open, slower close — only on richest mesh (face mesh)
    const lerpSpeed = targetJaw > (refMesh.morphTargetInfluences[jawIdx] || 0) ? 0.35 : 0.18
    if (jawIdx < refMesh.morphTargetInfluences.length) {
      const cur = refMesh.morphTargetInfluences[jawIdx] || 0
      refMesh.morphTargetInfluences[jawIdx] = cur + (targetJaw - cur) * lerpSpeed
    }
    if (funnelIdx !== undefined && funnelIdx < refMesh.morphTargetInfluences.length) {
      const curF = refMesh.morphTargetInfluences[funnelIdx] || 0
      refMesh.morphTargetInfluences[funnelIdx] = curF + (targetFunnel - curF) * lerpSpeed
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

  setSceneBackground (hex) {
    if (!this._scene) return
    this._clearSkyboxInternal()
    this._lastSolidBg = hex
    this._scene.background = new THREE.Color(hex)
    if (this._rimLight) {
      const c = new THREE.Color(hex)
      const lum = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b
      this._rimLight.intensity = lum < 0.25 ? 1.2 : 0.3
    }
  }

  // ── Skybox sphere + layer system ──────────────────────────────────────────

  _clearSkyboxInternal () {
    if (this._skyboxSphere) {
      this._scene?.remove(this._skyboxSphere)
      this._skyboxSphere.geometry.dispose()
      this._skyboxSphere.material.dispose()
      this._skyboxSphere = null
    }
    this._skyboxBaseTex?.dispose(); this._skyboxBaseTex = null
    for (const l of this._skyboxLayers) l.texture?.dispose()
    this._skyboxLayers    = []
    this._currentLayerIdx = 0
    this._transPhase      = 'base-hold'
    this._transTimer      = 0.0
    this._skyboxUrl       = null
    this._scene && (this._scene.background = null)
  }

  _loadSkyboxTexture (url, blur) {
    return new Promise((resolve, reject) => {
      const img = new Image()
      img.onload = () => {
        const MAX_W = 2048
        const scale = Math.min(1, MAX_W / img.naturalWidth)
        const w   = Math.round(img.naturalWidth  * scale)
        const h   = Math.round(img.naturalHeight * scale)
        const pad = Math.max(1, Math.ceil(blur * 3))

        // Tile 3× horizontally so blur samples the wrap at left/right edges
        const wide = document.createElement('canvas')
        wide.width = w * 3; wide.height = h
        const wCtx = wide.getContext('2d')
        wCtx.drawImage(img, 0,   0, w, h)
        wCtx.drawImage(img, w,   0, w, h)
        wCtx.drawImage(img, w*2, 0, w, h)

        const canvas = document.createElement('canvas')
        canvas.width = w; canvas.height = h
        const ctx = canvas.getContext('2d')
        ctx.filter = `blur(${blur}px)`
        ctx.drawImage(wide, w - pad, -pad, w + pad*2, h + pad*2, -pad, -pad, w + pad*2, h + pad*2)

        resolve(new THREE.CanvasTexture(canvas))
      }
      img.onerror = reject
      img.src = url
    })
  }

  _createSkyboxSphere () {
    const geo = new THREE.SphereGeometry(50, 64, 40)
    const mat = new THREE.ShaderMaterial({
      vertexShader:   SKYBOX_VERT,
      fragmentShader: SKYBOX_FRAG,
      uniforms: {
        uTexBase:    { value: this._skyboxBaseTex },
        uTexLayer:   { value: this._skyboxBaseTex },
        uBlend:      { value: 0.0 },
        uNoiseColor: { value: this._skyboxNoiseColor },
        uOpacity:    { value: this._skyboxOpacity },
        uBgColor:    { value: new THREE.Color(this._lastSolidBg ?? '#f0f0f0') },
        uHasLayer:   { value: false },
      },
      side:       THREE.BackSide,
      depthTest:  false,
      depthWrite: false,
    })
    const mesh = new THREE.Mesh(geo, mat)
    mesh.renderOrder = -1000
    this._scene.add(mesh)
    this._skyboxSphere = mesh
  }

  _updateSkyboxUniforms () {
    if (!this._skyboxSphere) return
    const u = this._skyboxSphere.material.uniforms
    const hasLayer = this._skyboxLayers.length > 0
    u.uHasLayer.value = hasLayer
    if (hasLayer) u.uTexLayer.value = this._skyboxLayers[this._currentLayerIdx % this._skyboxLayers.length].texture
    u.uBlend.value = this._skyboxBlend
  }

  async setSkyboxFromUrl (url, blur = this._skyboxBlur) {
    if (!this._scene) return
    this._skyboxUrl  = url
    this._skyboxBlur = blur
    const tex = await this._loadSkyboxTexture(url, blur).catch(e => { console.warn('[Avatar3D] skybox load failed:', e) })
    if (!tex) return
    this._skyboxBaseTex?.dispose()
    this._skyboxBaseTex = tex
    if (!this._skyboxSphere) {
      this._scene.background = null
      this._createSkyboxSphere()
    } else {
      this._skyboxSphere.material.uniforms.uTexBase.value = tex
    }
    this._transPhase  = 'base-hold'
    this._transTimer  = 0.0
    this._skyboxBlend = 0.0
    this._updateSkyboxUniforms()
    if (this._rimLight) this._rimLight.intensity = 0.5
  }

  async setSkyboxBlur (blur) {
    this._skyboxBlur = blur
    if (this._skyboxUrl) {
      const tex = await this._loadSkyboxTexture(this._skyboxUrl, blur).catch(() => null)
      if (tex && this._skyboxSphere) {
        this._skyboxBaseTex?.dispose()
        this._skyboxBaseTex = tex
        this._skyboxSphere.material.uniforms.uTexBase.value = tex
      }
    }
    // Re-process all layers with new blur too
    for (const layer of this._skyboxLayers) {
      const tex = await this._loadSkyboxTexture(layer.url, blur).catch(() => null)
      if (tex) { layer.texture?.dispose(); layer.texture = tex }
    }
    this._updateSkyboxUniforms()
  }

  async addSkyboxLayer (url, slot) {
    if (!this._scene) return -1
    const tex = await this._loadSkyboxTexture(url, this._skyboxBlur).catch(e => { console.warn('[Avatar3D] layer load failed:', e) })
    if (!tex) return -1
    const wasEmpty = this._skyboxLayers.length === 0
    if (typeof slot === 'number' && slot >= 0 && slot < this._skyboxLayers.length) {
      this._skyboxLayers[slot].texture?.dispose()
      this._skyboxLayers[slot] = { url, texture: tex }
    } else {
      this._skyboxLayers.push({ url, texture: tex })
    }
    if (wasEmpty) {
      this._transPhase = 'base-hold'
      this._transTimer = 0.0
      this._skyboxBlend = 0.0
    }
    this._updateSkyboxUniforms()
    return typeof slot === 'number' && slot < this._skyboxLayers.length ? slot : this._skyboxLayers.length - 1
  }

  removeSkyboxLayer (index) {
    if (index < 0 || index >= this._skyboxLayers.length) return
    this._skyboxLayers[index].texture?.dispose()
    this._skyboxLayers.splice(index, 1)
    this._currentLayerIdx = Math.min(this._currentLayerIdx, Math.max(0, this._skyboxLayers.length - 1))
    this._transPhase = 'base-hold'; this._transTimer = 0.0; this._skyboxBlend = 0.0
    this._updateSkyboxUniforms()
  }

  setNoiseColor (hex, alpha = 1.0) {
    const c = new THREE.Color(hex)
    this._skyboxNoiseColor = new THREE.Vector4(c.r, c.g, c.b, Math.max(0, Math.min(1, alpha)))
    if (this._skyboxSphere) this._skyboxSphere.material.uniforms.uNoiseColor.value = this._skyboxNoiseColor
  }

  setSkyboxOpacity (opacity) {
    this._skyboxOpacity = Math.max(0, Math.min(1, opacity))
    if (this._skyboxSphere) this._skyboxSphere.material.uniforms.uOpacity.value = this._skyboxOpacity
  }

  clearSkybox () {
    this._clearSkyboxInternal()
    if (this._scene) this._scene.background = new THREE.Color(this._lastSolidBg ?? '#f0f0f0')
    if (this._rimLight) {
      const c = new THREE.Color(this._lastSolidBg ?? '#f0f0f0')
      const lum = 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b
      this._rimLight.intensity = lum < 0.25 ? 1.2 : 0.3
    }
  }

  async rebuildSkybox (urls, blur) {
    if (!urls || !urls.length) { this.clearSkybox(); return }
    this._clearSkyboxInternal()
    await this.setSkyboxFromUrl(urls[0], blur ?? this._skyboxBlur)
    for (const url of urls.slice(1)) {
      await this.addSkyboxLayer(url)
    }
  }

  setTransitionTimes (hold, fade) {
    this._skyboxHoldTime = Math.max(0.5, hold)
    this._skyboxFadeTime = Math.max(0.1, fade)
  }

  _updateSkyboxTransition (delta) {
    if (!this._skyboxSphere || !this._skyboxLayers.length) return
    const HOLD = this._skyboxHoldTime
    const TRANS = this._skyboxFadeTime
    this._transTimer += Math.min(delta, 0.1)  // cap delta so tab-switch spikes don't skip phases
    switch (this._transPhase) {
      case 'base-hold':
        this._skyboxBlend = 0.0
        if (this._transTimer >= HOLD) { this._transTimer = 0; this._transPhase = 'to-layer' }
        break
      case 'to-layer':
        this._skyboxBlend = Math.min(1, this._transTimer / TRANS)
        if (this._transTimer >= TRANS) { this._transTimer = 0; this._transPhase = 'layer-hold'; this._skyboxBlend = 1.0 }
        break
      case 'layer-hold':
        this._skyboxBlend = 1.0
        if (this._transTimer >= HOLD) { this._transTimer = 0; this._transPhase = 'to-base' }
        break
      case 'to-base':
        this._skyboxBlend = 1.0 - Math.min(1, this._transTimer / TRANS)
        if (this._transTimer >= TRANS) {
          this._transTimer = 0; this._transPhase = 'base-hold'; this._skyboxBlend = 0.0
          this._currentLayerIdx = (this._currentLayerIdx + 1) % this._skyboxLayers.length
          this._updateSkyboxUniforms()
        }
        break
    }
    if (this._skyboxSphere) this._skyboxSphere.material.uniforms.uBlend.value = this._skyboxBlend
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
    window.dispatchEvent(new CustomEvent('avatar3d:loadstart'))

    // Prime the browser HTTP cache before GLTFLoader requests the same URL.
    // If the browser already cached it (preload hint or prior visit), this resolves instantly.
    try { await fetch(gltfUrl, { method: 'GET', cache: 'force-cache' }) } catch (_) {}

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
    gltf.scene.scale.setScalar(1)   // ensure clean scale before bounding-box fit
    this._characterRoot = gltf.scene
    this._scene.add(gltf.scene)

    // Collect morph-target meshes, glasses meshes, and enable shadow casting
    this._meshes        = []
    this._glassesMeshes = []
    gltf.scene.traverse((node) => {
      if (node.isMesh) {
        node.castShadow    = true
        node.receiveShadow = false
        if (node.morphTargetDictionary) this._meshes.push(node)
        if (/glasses/i.test(node.name)) this._glassesMeshes.push(node)
      }
    })

    if (this._meshes.length > 0) {
      // Zero all morph influences immediately to prevent a one-frame flicker
      // where the animation mixer hasn't yet driven the mesh to its rest state.
      for (const mesh of this._meshes) {
        if (mesh.morphTargetInfluences) {
          mesh.morphTargetInfluences.fill(0)
        }
      }

      // Prefer face/head meshes over eye/teeth meshes when morph counts are equal.
      // After arkit transfer all face-related meshes have 72 morphs — tie-break by name priority.
      const FACE_PRIORITY = ['Wolf3D_Head', 'Wolf3D_Skin', 'Wolf3D_Avatar', 'Head', 'Face']
      const facePriority = (m) => {
        const idx = FACE_PRIORITY.findIndex(n => m.name === n)
        return idx === -1 ? FACE_PRIORITY.length : idx
      }
      const richestMesh = this._meshes.reduce((best, m) => {
        const mc = Object.keys(m.morphTargetDictionary ?? {}).length
        const bc = Object.keys(best.morphTargetDictionary ?? {}).length
        if (mc !== bc) return mc > bc ? m : best
        return facePriority(m) < facePriority(best) ? m : best
      })
      const dict = richestMesh.morphTargetDictionary ?? {}

      // Azure ARKit map (index → morph index) for speakWithAzureBlendShapes
      this._morphMap = {}
      AZURE_BLEND_SHAPES.forEach((name, i) => {
        if (name in dict) this._morphMap[i] = dict[name]
      })
      // Oculus viseme map (viseme name → morph index) for speakWithElevenLabsAlignment
      this._visemeMap = {}
      OCULUS_VISEMES.forEach(name => {
        if (name in dict) this._visemeMap[name] = dict[name]
      })
      // Also map ARKit jaw/mouth names (amplitude fallback + debug panel)
      for (const name of ['jawOpen', 'mouthFunnel', 'mouthSmile', 'mouthSmileLeft', 'mouthSmileRight',
        'mouthPucker', 'mouthRollLower', 'mouthRollUpper', 'mouthShrugUpper']) {
        if (name in dict) this._visemeMap[name] = dict[name]
      }
      // Store reference to the richest mesh for per-frame morph application
      this._richestMesh = richestMesh
      const hasOculus = Object.keys(this._visemeMap).filter(k => k.startsWith('viseme_')).length
      console.log(`[Avatar3D] ${this._meshes.length} mesh(es), ${Object.keys(this._morphMap).length}/55 Azure shapes, ${hasOculus}/15 Oculus visemes mapped (from '${richestMesh.name}')`)
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

    // Auto-fit camera to the actual model bounds so misscaled/miscentred GLBs frame correctly.
    this._fitCameraToCharacter()

    window.dispatchEvent(new CustomEvent('avatar3d:loadend'))
  }

  /** Compute model bounding box and reposition camera + controls to frame the full body. */
  _fitCameraToCharacter () {
    if (!this._characterRoot) return

    const box    = new THREE.Box3().setFromObject(this._characterRoot)
    const size   = new THREE.Vector3()
    const center = new THREE.Vector3()
    box.getSize(size)
    box.getCenter(center)

    const height   = size.y
    const midY     = center.y
    // Keep the character grounded — if the model root is above the grid, shift scene down.
    const groundY  = box.min.y
    if (Math.abs(groundY) > 0.05) {
      this._characterRoot.position.y -= groundY
      // Recompute after grounding
      box.setFromObject(this._characterRoot)
      box.getCenter(center)
      box.getSize(size)
    }

    // Keep shadow plane flush with model feet
    if (this._shadowPlane) this._shadowPlane.position.y = box.min.y + 0.001

    const targetY  = center.y                           // orbit pivot at model midpoint
    const camDist  = Math.max(1.4, size.y * 1.15)       // pull back proportionally to height
    const camY     = center.y                            // camera level with model centre

    // Update CAMERA_PRESET values for resetCamera / zoomToBody to use
    CAMERA_PRESET.pos    = [0, camY, camDist]
    CAMERA_PRESET.target = [0, targetY, 0]

    if (this._camera) {
      this._camera.position.set(0, camY, camDist)
      this._camera.fov = 52
      this._camera.updateProjectionMatrix()
    }
    if (this._controls) {
      this._controls.target.set(0, targetY, 0)
      this._controls.update()
    }

    // Adjust head zoom to model scale
    const headY = box.max.y - size.y * 0.08
    this._headZoomY = headY
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
    config.speechSynthesisOutputFormat = sdk.SpeechSynthesisOutputFormat.Audio24Khz96KBitRateMonoMp3

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
    let azureAudioData = null
    const azureDone = new Promise((resolve, reject) => {
      synthesizer.speakSsmlAsync(ssml,
        (result) => {
          synthesizer.close()
          if (result.reason === sdk.ResultReason.SynthesizingAudioCompleted) {
            azureAudioData = result.audioData  // MP3 bytes from Azure
            resolve()
          } else {
            reject(new Error(`Azure TTS failed: ${result.reason}`))
          }
        },
        (err) => { synthesizer.close(); reject(err) }
      )
    })

    try {
      await azureDone

      if (!framesOrdered.length) {
        console.warn('[Avatar3D] Azure returned 0 viseme frames — check SSML / API key')
        this.speakWithVisemes(audioUrl)
        return
      }

      const FPS       = 60
      const azureSecs = framesOrdered.length / FPS

      // Use Azure's own audio — no timeScale needed, timing is exact
      this._frames = framesOrdered.map((weights, i) => ({
        t: i / FPS,
        w: weights,
      }))

      console.log(`[Avatar3D] Azure: ${framesOrdered.length} frames, ${azureSecs.toFixed(2)}s, using Azure audio (no timeScale)`)

      if (this._audio) { this._audio.pause(); this._audio = null }
      if (this._audioCtx) { this._audioCtx.close(); this._audioCtx = null }
      this._visemeTimings = []

      // Play Azure's own MP3 — timing matches blend shapes exactly
      const blob  = new Blob([azureAudioData], { type: 'audio/mpeg' })
      const blobUrl = URL.createObjectURL(blob)
      const audio = new Audio(blobUrl)
      audio.addEventListener('ended', () => URL.revokeObjectURL(blobUrl))
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
   * Load a GLB animation file onto the loaded character rig.
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

    const clip = this._prepareClip(gltf.animations[0])
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
      } else if (this._elevenTimeline) {
        this._applyElevenLabsVisemes()
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
    if (!this._worldMode) {
      this._updateCameraLerp(delta)
      this._updateZoomTargetBias()
    } else {
      this._updateWorldCamera()
    }
    this._idlePass(delta * 1000)
    this._updateSkyboxTransition(delta)
    this._controls?.update()
    if (this._sparkRenderer) {
      this._sparkRenderer.render(this._scene, this._camera)
    } else {
      this._renderer?.render(this._scene, this._camera)
    }
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

  /**
   * As the user zooms in, shift the orbit target upward in two stages:
   *   dist >= 1.5m : body centre → chest (upper-body framing)
   *   dist <  1.5m : chest      → eyes  (face close-up framing)
   */
  _updateZoomTargetBias () {
    if (this._camLerpT < 1.0 || !this._controls || !this._characterRoot) return
    if (this._zoomBiasLock && performance.now() < this._zoomBiasLock) return
    const camDist = this._camera.position.distanceTo(this._controls.target)
    const minDist = this._controls.minDistance   // 0.5
    const maxDist = this._controls.maxDistance   // 6.0
    const bodyY  = CAMERA_PRESET.target[1]
    const headY  = this._headZoomY ?? bodyY * 1.8
    const chestY = headY * 0.78
    const eyeY   = headY * 0.96

    let desiredY
    if (camDist >= 1.5) {
      const t = Math.max(0, Math.min(1, (camDist - 1.5) / (maxDist - 1.5)))
      desiredY = chestY + (bodyY - chestY) * t   // far → body, close → chest
    } else {
      const t = Math.max(0, Math.min(1, (camDist - minDist) / (1.5 - minDist)))
      desiredY = eyeY + (chestY - eyeY) * t      // very close → eyes, 1.5m → chest
    }

    this._controls.target.y += (desiredY - this._controls.target.y) * 0.08
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
    const faceMesh = this._richestMesh
    if (!faceMesh) return
    for (const [si, mi] of Object.entries(this._morphMap)) {
      const w = (f0.w[si] ?? 0) + ((f1.w[si] ?? 0) - (f0.w[si] ?? 0)) * a
      if (mi < faceMesh.morphTargetInfluences.length) {
        faceMesh.morphTargetInfluences[mi] = w
      }
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
    // Negate pitch: RPM Neck local +X points down the spine, so positive RX tilts head DOWN.
    // Camera above head → toTarget.y > 0 → positive pitch → must negate to tilt UP.
    gazeQ.setFromEuler(new THREE.Euler(-this._gazePitch, this._gazeYaw, 0, 'YXZ'))
    const baseQ = this._neckBaseQ || this._boneRestQ['Neck'] || this._boneRestQ['Head'] || new THREE.Quaternion()
    neckBone.quaternion.copy(baseQ).multiply(gazeQ)

    // ── Drive eye look morph targets with per-eye parallax ──────────────────
    if (!this._meshes.length) return
    const dict = (this._richestMesh ?? this._meshes[0]).morphTargetDictionary
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
    // Vertical: vN > 0 = looking up in world, which after pitch negation means eyes up
    setM('eyeLookUpLeft',    vN > 0 ?  vN : 0)
    setM('eyeLookDownLeft',  vN < 0 ? -vN : 0)
    setM('eyeLookUpRight',   vN > 0 ?  vN : 0)
    setM('eyeLookDownRight', vN < 0 ? -vN : 0)
  }

  _idlePass (deltaMs) {
    if (!this._meshes.length) return
    // Uniform scale breathing on the character root.
    // Non-uniform scale (Y only) on a SkinnedMesh ancestor breaks Three.js skinning:
    // bindMatrixInverse is baked at load time assuming uniform scale=1, so a parent
    // with scale(1, 1.006, 1) introduces shearing in the skinning shader — visible
    // as a glitch on multi-mesh RPM avatars (Leo, Benjamin, Jack, Henry etc.).
    // Uniform scale is handled correctly: it reduces to a scalar in the normal matrix.
    if (this._characterRoot) {
      const t       = performance.now() / 1000
      const breathe = 1 + Math.sin(t * 0.8) * 0.004   // subtle — uniform all axes
      const base    = (this._worldCharScale ?? 1) * (this._charScaleMult ?? 1)
      this._characterRoot.scale.setScalar(base * breathe)
    }

    this._blinkTimer += deltaMs
    if (this._blinkTimer >= this._blinkNext) {
      this._blinkTimer = 0
      this._blinkNext  = 2500 + Math.random() * 3000
      this._triggerBlink()
    }
  }

  /**
   * Fire a single viseme for debug purposes.
   * @param {string} visemeName — one of OCULUS_VISEMES or 'jawOpen' etc.
   * @param {number} weight     — 0.0 to 1.0 (default 0.8)
   * @param {number} holdMs     — how long to hold before releasing (default 300ms)
   */
  debugViseme (visemeName, weight = 0.8, holdMs = 300) {
    const mesh = this._richestMesh
    if (!mesh) { console.warn('[Avatar3D] debugViseme: no mesh loaded yet'); return }
    const mi = this._visemeMap[visemeName]
    if (mi === undefined) {
      console.warn(`[Avatar3D] debugViseme: '${visemeName}' not in visemeMap`)
      return
    }
    if (mi < mesh.morphTargetInfluences.length) {
      mesh.morphTargetInfluences[mi] = weight
      console.log(`[Avatar3D] debugViseme: '${visemeName}' idx=${mi} weight=${weight} on '${mesh.name}'`)
    }
    setTimeout(() => {
      if (mi < mesh.morphTargetInfluences.length) mesh.morphTargetInfluences[mi] = 0
    }, holdMs)
  }

  /** Show or hide the glasses/sunglasses mesh(es) on this avatar. */
  showGlasses (visible) {
    for (const m of this._glassesMeshes) m.visible = visible
  }

  /** True if this avatar has a glasses mesh. */
  get hasGlasses () { return this._glassesMeshes.length > 0 }

  _triggerBlink () {
    const dict = (this._richestMesh ?? this._meshes[0])?.morphTargetDictionary
    if (!dict) return
    const l = dict['eyeBlinkLeft'], r = dict['eyeBlinkRight']
    if (l === undefined || r === undefined) return
    for (const m of this._meshes) { m.morphTargetInfluences[l] = 1; m.morphTargetInfluences[r] = 1 }
    setTimeout(() => {
      for (const m of this._meshes) { m.morphTargetInfluences[l] = 0; m.morphTargetInfluences[r] = 0 }
    }, 120)
  }

  // ── Animation clip preparation ────────────────────────────────────────────

  /**
   * Strip tracks that corrupt RPM rigs when applied as absolute local quaternions.
   * Both GLB and FBX animation data from the RPM/Mixamo pipeline store identical
   * absolute quaternion values — no retargeting or bind-pose compensation needed.
   * We only need to drop tracks that physically cannot work on the RPM character:
   *   - position  : Mixamo bakes cm-scale root motion + foot IK offsets
   *   - scale     : all (1,1,1) but Three.js still applies them; safe to drop
   *   - Hips quat : bakes a large forward tilt (~47°x) that pitches the whole skeleton
   *   - lower body: without foot IK the leg chain produces floating feet
   */
  _prepareClip (clip) {
    const DROP_QUAT = new Set([
      // Root / lower body — Hips encodes ~47° forward tilt; legs need foot IK to ground
      'Hips',
      'LeftUpLeg', 'RightUpLeg',
      'LeftLeg',   'RightLeg',
      'LeftFoot',  'RightFoot',
      'LeftToeBase', 'RightToeBase',
      // Base spine — encodes forward lean that is uncompensated without Hips; Spine1/2 kept for breathing
      'Spine',
      // Fingers — Mixamo T-pose finger rotations don't match RPM A-pose bind, causing deformation
      'LeftHand',  'RightHand',
      'LeftHandThumb1',  'LeftHandThumb2',  'LeftHandThumb3',
      'RightHandThumb1', 'RightHandThumb2', 'RightHandThumb3',
      'LeftHandIndex1',  'LeftHandIndex2',  'LeftHandIndex3',
      'RightHandIndex1', 'RightHandIndex2', 'RightHandIndex3',
      'LeftHandMiddle1', 'LeftHandMiddle2', 'LeftHandMiddle3',
      'RightHandMiddle1','RightHandMiddle2','RightHandMiddle3',
      'LeftHandRing1',   'LeftHandRing2',   'LeftHandRing3',
      'RightHandRing1',  'RightHandRing2',  'RightHandRing3',
      'LeftHandPinky1',  'LeftHandPinky2',  'LeftHandPinky3',
      'RightHandPinky1', 'RightHandPinky2', 'RightHandPinky3',
    ])
    clip.tracks = clip.tracks.filter(track => {
      if (track.name.endsWith('.position')) return false
      if (track.name.endsWith('.scale'))    return false
      if (track.name.endsWith('.quaternion') && DROP_QUAT.has(track.name.split('.')[0])) return false
      return true
    })
    return clip
  }

  // ── Body animation (FBX or GLB) ──────────────────────────────────────────

  async loadBodyAnimation (url) {
    if (!this._characterRoot) return

    if (!this._animMixer) {
      this._animMixer = new THREE.AnimationMixer(this._characterRoot)
    }

    let clip
    if (url.toLowerCase().endsWith('.glb') || url.toLowerCase().endsWith('.gltf')) {
      const gltf = await new GLTFLoader().loadAsync(url)
      clip = gltf.animations?.[0]
      if (!clip) { console.warn('[Avatar3D] GLB has no animation clips:', url); return }
    } else {
      const fbx = await new Promise((res, rej) => this._fbxLoader.load(url, res, undefined, rej))
      clip = fbx.animations?.[0]
      if (!clip) { console.warn('[Avatar3D] FBX has no animation clips:', url); return }
      // Strip "mixamorig" + optional digit prefix from Mixamo FBX exports
      clip.tracks.forEach(track => {
        track.name = track.name.replace(/^mixamorig\d*/g, '')
      })
    }

    this._prepareClip(clip)

    console.log(`[Avatar3D] Body animation loaded: ${url} (${clip.tracks.length} tracks)`)

    if (this._bodyAction) this._bodyAction.fadeOut(0.3)

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

  // ── WorldLabs (SPZ Gaussian splat + GLB ground) ────────────────────────────

  async mountWorldLabs ({ spzUrl, glbUrl, panoUrl, semantics = {} }) {
    // Cancel any in-flight mount so concurrent calls don't stack two worlds.
    const mountId = (this._mountWorldId = (this._mountWorldId ?? 0) + 1)
    const stale = () => this._mountWorldId !== mountId

    this.dismountWorldLabs()
    this._worldMode = true
    // Disable damping so the camera doesn't coast after user input
    if (this._controls) this._controls.enableDamping = false
    // Stop any in-flight camera lerp (zoom animations aimed at avatar portrait framing)
    this._camLerpT = 1.0

    if (this._shadowPlane) this._shadowPlane.visible = false

    const {
      groundPlaneOffset = 0,
      flipY             = true,
      metricScaleFactor = 1,
    } = semantics

    const rotX = flipY ? Math.PI : 0

    // ── Pano as equirectangular background / IBL ──────────────────────────
    if (panoUrl) {
      const tex = await new Promise((res, rej) =>
        new THREE.TextureLoader().load(panoUrl, t => {
          t.mapping   = THREE.EquirectangularReflectionMapping
          t.colorSpace = THREE.SRGBColorSpace
          res(t)
        }, undefined, rej)
      )
      if (stale()) return null
      this._scene.environment        = tex
      this._scene.background         = tex
      this._scene.backgroundBlurriness = 0
      if (this._skyboxSphere) this._skyboxSphere.visible = false
      this._worldPanoTex = tex
    }

    // ── GLB collider mesh — derive actual street Y, then shadow-catch ────────
    let streetY = groundPlaneOffset  // fallback if no GLB
    if (glbUrl) {
      const gltf = await new Promise((res, rej) =>
        new GLTFLoader().load(glbUrl, res, undefined, rej)
      ).catch(() => null)

      if (stale()) return null
      if (gltf) {
        const ground = gltf.scene
        const shadowMat = new THREE.ShadowMaterial({ opacity: 0.4, transparent: true, depthWrite: false })
        ground.traverse(child => {
          if (child.isMesh) {
            child.material      = shadowMat
            child.receiveShadow = true
            child.raycast       = () => {}
            // Must render AFTER the SparkRenderer (renderOrder 0) so the shadow
            // darkening composites on top of the splat instead of being buried under it.
            // Without this, Three.js depth-sorts both transparent objects by camera
            // distance, and the order flips with camera angle — hiding the shadow.
            child.renderOrder   = 1
          }
        })
        ground.rotation.x = rotX
        ground.position.y = groundPlaneOffset
        ground.scale.setScalar(metricScaleFactor)
        this._scene.add(ground)
        this._worldGlbMesh = ground

        // Compute the real street surface Y from the GLB bounding box.
        // The GLB is loaded with rotation.x = Math.PI (flipY), which inverts the Y
        // axis: the original top surface (street, highest local Y) maps to the
        // smallest world Y after the flip. So min.y = walkable street level.
        ground.updateMatrixWorld(true)
        const glbBox = new THREE.Box3().setFromObject(ground)
        streetY = glbBox.min.y
      }
    }

    // ── Scale character to 1.5 m real-world height × default fine multiplier ──
    const WORLD_TARGET_HEIGHT  = 1.5
    const WORLD_CHAR_SCALE_MULT = 0.53   // user-confirmed: fits through doors correctly
    this._charScaleMult = WORLD_CHAR_SCALE_MULT
    if (this._characterRoot) {
      const box = new THREE.Box3().setFromObject(this._characterRoot)
      const naturalHeight = box.getSize(new THREE.Vector3()).y
      if (naturalHeight > 0.1) {
        const s = WORLD_TARGET_HEIGHT / naturalHeight
        this._worldCharScale = s
        this._characterRoot.scale.setScalar(s * WORLD_CHAR_SCALE_MULT)
      }
    }

    // ── Lift the WORLD so the street surface sits at Y=0 ─────────────────
    // Character stays at position.y = 0 where lights/shadows are tuned.
    // worldYAdjust = -streetY brings the cobblestone surface up to Y=0.
    // WORLD_DEFAULT_FINE_OFFSET: user-confirmed tuning to align street precisely.
    const WORLD_DEFAULT_FINE_OFFSET = -0.64
    const worldYAdjust = -streetY + WORLD_DEFAULT_FINE_OFFSET
    this._worldStreetY = streetY
    this._worldYAdjust = worldYAdjust

    if (this._worldGlbMesh) {
      this._worldGlbMesh.position.y = groundPlaneOffset + worldYAdjust
    }

    if (stale()) return null

    // ── SPZ Gaussian splat via @sparkjsdev/spark ──────────────────────────
    // SparkRenderer extends THREE.Mesh — it IS a scene object. SplatMesh must
    // be added as a child of SparkRenderer (not directly to the scene) so that
    // SparkRenderer.render() / onBeforeRender can discover and process it.
    if (spzUrl) {
      this._sparkRenderer = new SparkRenderer({ renderer: this._renderer, enableLod: true })
      const splat = new SplatMesh({ url: spzUrl })
      splat.rotation.x = rotX
      splat.position.y = groundPlaneOffset
      splat.scale.setScalar(metricScaleFactor)
      this._sparkRenderer.add(splat)   // child of SparkRenderer, not scene
      // Lift the SparkRenderer so the splat cobblestone also sits at Y=0
      this._sparkRenderer.position.y = worldYAdjust
      this._scene.add(this._sparkRenderer)
      this._worldSplatMesh = splat
    }

    return { streetY }
  }

  _getHeadWorldPos () {
    const head = this._boneMap?.['Head'] ?? this._boneMap?.['head']
    if (head) {
      const wp = new THREE.Vector3()
      head.getWorldPosition(wp)
      return wp
    }
    // Fallback: use character root Y + approx head height
    const root = this._characterRoot
    if (root) return new THREE.Vector3(0, root.position.y + 0.75, 0)
    return new THREE.Vector3(0, 0.75, 0)
  }

  _updateWorldCamera () {
    if (!this._controls || !this._camera) return


    // Pin orbit target to head bone so camera always revolves around the head
    const headPos = this._getHeadWorldPos()
    this._controls.target.copy(headPos)

    // Dynamically clamp maxPolarAngle so OrbitControls itself never lets the camera
    // dip below the floor. Clamping camera.position.y after the fact doesn't stick
    // because controls.update() recalculates from internal spherical state each frame.
    //
    // In OrbitControls spherical coords: camera.position.y = target.y + r * cos(phi)
    // We need camera.y >= FLOOR  →  cos(phi) >= (FLOOR - target.y) / r
    //                              →  phi <= acos((FLOOR - target.y) / r)
    const FLOOR = 0.5
    const r     = this._camera.position.distanceTo(headPos)
    if (r > 0.01) {
      const cosMax = (FLOOR - headPos.y) / r
      // clamp cosMax to [-1,1] so acos is always valid
      this._controls.maxPolarAngle = Math.acos(Math.max(-1, Math.min(1, cosMax)))
    }

    // Fake fog on the splat: Spark's custom renderer ignores Three.js scene fog,
    // so we simulate it by blending recolor toward a haze tint at distance.
    if (this._worldSplatMesh) {
      const FOG_START  = 4    // metres — fog begins
      const FOG_END    = 18   // metres — full haze
      const fogT = THREE.MathUtils.clamp((r - FOG_START) / (FOG_END - FOG_START), 0, 1)
      // recolor multiplies each splat's RGB — lerp white→haze tint
      const hazeR = 0.85, hazeG = 0.88, hazeB = 0.92
      this._worldSplatMesh.recolor.setRGB(
        1 - fogT * (1 - hazeR),
        1 - fogT * (1 - hazeG),
        1 - fogT * (1 - hazeB),
      )
      // Also fade opacity so far splats dissolve into background
      this._worldSplatMesh.opacity = THREE.MathUtils.clamp(1 - fogT * 0.5, 0.5, 1)
      this._worldSplatMesh.generatorDirty = true
    }

    // Collision: raycast from head toward camera; pull camera in if world GLB is hit
    if (this._worldGlbMesh) {
      const dir = new THREE.Vector3().subVectors(this._camera.position, headPos)
      const dist = dir.length()
      if (dist > 0.01) {
        const ray = new THREE.Raycaster(headPos, dir.clone().divideScalar(dist), 0.1, dist)
        const hits = ray.intersectObject(this._worldGlbMesh, true)
        if (hits.length > 0) {
          // Pull camera to just in front of the first hit surface
          const safe = headPos.clone().addScaledVector(dir.divideScalar(dist), hits[0].distance - 0.15)
          this._camera.position.copy(safe)
        }
      }
    }
  }

  setCharScale (scale) {
    this._charScaleMult = scale
    if (!this._characterRoot) return
    const base = this._worldCharScale ?? 1
    this._characterRoot.scale.setScalar(base * scale)
  }

  setWorldScale (scale) {
    if (this._worldGlbMesh)   this._worldGlbMesh.scale.setScalar(scale)
    if (this._sparkRenderer)  this._sparkRenderer.scale.setScalar(scale)
    this._worldScale = scale
  }

  dismountWorldLabs () {
    if (this._worldPanoTex) {
      if (this._scene.environment === this._worldPanoTex) this._scene.environment = null
      if (this._scene.background  === this._worldPanoTex) this._scene.background  = null
      this._worldPanoTex.dispose()
      this._worldPanoTex = null
      if (this._skyboxSphere) this._skyboxSphere.visible = true
    }
    if (this._worldGlbMesh) {
      this._scene.remove(this._worldGlbMesh)
      this._worldGlbMesh = null
    }

    if (this._sparkRenderer) {
      // SparkRenderer is the scene parent of SplatMesh — removing it also removes the splat.
      this._scene.remove(this._sparkRenderer)
      this._sparkRenderer.dispose?.()
      this._sparkRenderer = null
      this._worldSplatMesh = null
    }
    // Restore character position and scale for non-world scene views.
    if (this._characterRoot) {
      if (this._worldStreetY != null) this._characterRoot.position.y = 0
      if (this._worldCharScale != null) this._characterRoot.scale.setScalar(1)
    }
    if (this._shadowPlane) this._shadowPlane.visible = true
    this._worldStreetY   = null
    this._worldCharScale = null
    this._charScaleMult  = null
    this._worldMode = false
    if (this._controls) {
      this._controls.enableDamping = true
      this._controls.dampingFactor = 0.08
    }
    if (this._keyLight) {
      this._keyLight.target.position.set(0, 0, 0)
      this._keyLight.target.updateMatrixWorld()
      this._keyLight.shadow.camera.updateProjectionMatrix()
    }
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
    this._skyboxBaseTex?.dispose()
    this._skyboxBaseTex = null
    for (const layer of this._skyboxLayers) layer.texture?.dispose()
    this._skyboxLayers = []
    if (this._skyboxSphere) {
      this._scene?.remove(this._skyboxSphere)
      this._skyboxSphere.geometry.dispose()
      this._skyboxSphere.material.dispose()
      this._skyboxSphere = null
    }
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
  // Always init the player (scene + renderer), even if no character yet.
  if (window._avatar3d && window._avatar3d._characterUrl === (characterUrl || null)) return
  window._avatar3d?.destroy()
  window._avatar3d = new Avatar3DPlayer(canvas, { characterUrl: characterUrl || null })
  window._avatar3d._characterUrl = characterUrl || null
  window._avatar3d.init().catch(err => console.error('[Avatar3D] init failed:', err))
}

autoInit()

// Background-prefetch all other avatar GLBs into the HTTP cache once the page is idle.
// Uses requestIdleCallback so it never competes with the initial load or render loop.
function scheduleGlbPrefetch () {
  const canvas = document.getElementById('avatar-lab-canvas')
  if (!canvas) return
  const urls = (canvas.dataset.prefetchUrls || '').split(',').filter(Boolean)
  if (!urls.length) return

  const currentUrl = canvas.dataset.characterUrl || ''
  const others     = urls.filter(u => u !== currentUrl)
  if (!others.length) return

  const prefetch = (list, idx) => {
    if (idx >= list.length) return
    const run = () => {
      fetch(list[idx], { method: 'GET', cache: 'force-cache' })
        .catch(() => {})
        .finally(() => {
          const idle = window.requestIdleCallback || ((cb) => setTimeout(cb, 300))
          idle(() => prefetch(list, idx + 1))
        })
    }
    const idle = window.requestIdleCallback || ((cb) => setTimeout(cb, 300))
    idle(run, { timeout: 5000 })
  }

  // Start after a short delay so initial avatar load gets full bandwidth first
  setTimeout(() => prefetch(others, 0), 2000)
}

scheduleGlbPrefetch()

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

  // Synchronously clear characterRoot BEFORE any await so that preview-clip events
  // dispatched in the same Livewire response fire after this and correctly queue
  // via _pendingAnimUrl (instead of calling loadBodyAnimation on the old character).
  const p = window._avatar3d
  if (p?._characterRoot) {
    p._scene?.remove(p._characterRoot)
    p._animMixer?.stopAllAction()
    p._animMixer = null
    p._bodyAction = null
    p._characterRoot = null
  }

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

// ── Glasses toggle ────────────────────────────────────────────────────────────
window.addEventListener('avatar3d:showGlasses', (ev) => {
  window._avatar3d?.showGlasses(ev.detail.visible)
})

// After a character loads, broadcast whether it has glasses so the UI can show/hide the toggle.
// Also play any animation that arrived before the character finished loading.
window.addEventListener('avatar3d:loadend', () => {
  const hasGlasses = window._avatar3d?.hasGlasses ?? false
  window.dispatchEvent(new CustomEvent('avatar3d:glassesAvailable', { detail: { hasGlasses } }))

  if (window._pendingAnimUrl) {
    console.log('[Avatar3D] loadend: consuming _pendingAnimUrl', window._pendingAnimUrl)
    window._avatar3d?.loadBodyAnimation(window._pendingAnimUrl)
    window._pendingAnimUrl = null
  } else {
    console.log('[Avatar3D] loadend: no _pendingAnimUrl queued')
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
  const url = ev.detail.fbxUrl || ev.detail.glbUrl
  if (!url) return
  // If character not loaded yet, queue it — loadend will pick it up
  if (!window._avatar3d?._characterRoot) {
    console.log('[Avatar3D] preview-clip: _characterRoot null, queuing', url)
    window._pendingAnimUrl = url
    return
  }
  console.log('[Avatar3D] preview-clip: _characterRoot exists, loading immediately', url)
  window._avatar3d?.loadBodyAnimation(url)
    .catch(err => console.error('[Avatar3D] Failed to load animation:', err))
})

// ── Narration speak (Avatar Lab) ──────────────────────────────────────────────
async function _handleSpeak (ev) {
  const { audioUrl, text, alignment, preview = false } = ev.detail
  const player = window._avatar3d
  if (!player) return

  // Defer until character is loaded (meshes available for viseme mapping)
  if (!player._meshes.length) {
    window.addEventListener('avatar3d:loadend', () => _handleSpeak(ev), { once: true })
    return
  }

  // preview=true: no camera zoom, no delay (voice card previews)
  const opts = preview ? { zoom: false, delay: 0 } : { zoom: true, delay: 600 }

  // Best path: ElevenLabs character alignment → Oculus visemes
  console.log('[Avatar3D] avatar3d:speak received — alignment entries:', alignment?.length ?? 0, 'preview:', preview)
  if (alignment && alignment.length > 0) {
    player.speakWithElevenLabsAlignment(audioUrl, alignment, opts)
    return
  }

  // No alignment — amplitude-driven jaw
  console.log('[Avatar3D] No ElevenLabs alignment — using amplitude jaw fallback')
  player.speakWithVisemes(audioUrl, opts)
}
window.addEventListener('avatar3d:speak', _handleSpeak)

window.addEventListener('avatar3d:setBg', (ev) => {
  window._avatar3d?.setSceneBackground(ev.detail.hex)
})

window.addEventListener('avatar3d:setskybox', (ev) => {
  window._avatar3d?.setSkyboxFromUrl(ev.detail.url, ev.detail.blur ?? 0.5)
})

window.addEventListener('avatar3d:setskyboxblur', (ev) => {
  window._avatar3d?.setSkyboxBlur(ev.detail.blur)
})

window.addEventListener('avatar3d:clearskybox', () => {
  window._avatar3d?.clearSkybox()
})

window.addEventListener('avatar3d:addskyboxlayer', async (ev) => {
  await window._avatar3d?.addSkyboxLayer(ev.detail.url, ev.detail.slot)
})

window.addEventListener('avatar3d:removeskyboxlayer', (ev) => {
  window._avatar3d?.removeSkyboxLayer(ev.detail.index)
})

window.addEventListener('avatar3d:setnoisecolor', (ev) => {
  window._avatar3d?.setNoiseColor(ev.detail.hex, ev.detail.alpha ?? 1.0)
})

window.addEventListener('avatar3d:setskyboxopacity', (ev) => {
  window._avatar3d?.setSkyboxOpacity(ev.detail.opacity)
})

window.addEventListener('avatar3d:settransitiontimes', (ev) => {
  window._avatar3d?.setTransitionTimes(ev.detail.hold, ev.detail.fade)
})

window.addEventListener('avatar3d:rebuildskybox', async (ev) => {
  await window._avatar3d?.rebuildSkybox(ev.detail.urls, ev.detail.blur)
})

// ── Skybox panel Alpine component ────────────────────────────────────────────
// Single ordered image list: index 0 = skybox base, 1..n = transition layers.
// Drag to reorder rebuilds the skybox with new ordering.
// Blobs persist in IndexedDB; settings in localStorage.
window.skyboxPanel = function () {
  return {
    skyboxImages:     [],   // [{ objectUrl, idbKey }]  index 0 = base
    skyboxBlur:       0.5,
    skyboxOpacity:    1.0,
    skyboxGrain:      0.06,
    skyboxGrainColor: '#1a1a1a',
    skyboxNoiseColor: '#ffffff',
    skyboxNoiseAlpha: 1.0,
    skyboxHoldTime:   10,
    skyboxFadeTime:   2,
    _db:              null,
    _nextId:          0,
    _dragSrcIdx:      null,
    _dragOverIdx:     null,

    async init () {
      this._db = await this._openDb()
      await this._restore()
    },

    // ── IndexedDB helpers ──────────────────────────────────────────────────

    _openDb () {
      return new Promise((resolve, reject) => {
        const req = indexedDB.open('avatar-lab-skybox', 1)
        req.onupgradeneeded = e => { e.target.result.createObjectStore('images') }
        req.onsuccess = e => resolve(e.target.result)
        req.onerror   = e => reject(e.target.error)
      })
    },

    _idbGet (key) {
      return new Promise((resolve, reject) => {
        const req = this._db.transaction('images', 'readonly').objectStore('images').get(key)
        req.onsuccess = e => resolve(e.target.result ?? null)
        req.onerror   = e => reject(e.target.error)
      })
    },

    _idbPut (key, value) {
      return new Promise((resolve, reject) => {
        const req = this._db.transaction('images', 'readwrite').objectStore('images').put(value, key)
        req.onsuccess = () => resolve()
        req.onerror   = e => reject(e.target.error)
      })
    },

    _idbDel (key) {
      return new Promise((resolve, reject) => {
        const req = this._db.transaction('images', 'readwrite').objectStore('images').delete(key)
        req.onsuccess = () => resolve()
        req.onerror   = e => reject(e.target.error)
      })
    },

    // ── Persistence ────────────────────────────────────────────────────────

    _saveSettings () {
      localStorage.setItem('avatar-lab-skybox-v1', JSON.stringify({
        blur:       this.skyboxBlur,
        opacity:    this.skyboxOpacity,
        grain:      this.skyboxGrain,
        grainColor: this.skyboxGrainColor,
        noiseColor: this.skyboxNoiseColor,
        noiseAlpha: this.skyboxNoiseAlpha,
        holdTime:   Number(this.skyboxHoldTime),
        fadeTime:   Number(this.skyboxFadeTime),
        imageKeys:  this.skyboxImages.map(img => img.idbKey),
        nextId:     this._nextId,
      }))
    },

    _waitForScene () {
      if (window._avatar3d?._scene) return Promise.resolve()
      return new Promise(resolve => {
        const tick = () => window._avatar3d?._scene ? resolve() : requestAnimationFrame(tick)
        requestAnimationFrame(tick)
      })
    },

    async _restore () {
      let s
      try { s = JSON.parse(localStorage.getItem('avatar-lab-skybox-v1') ?? 'null') } catch { s = null }
      if (!s) return

      this.skyboxBlur       = s.blur       ?? this.skyboxBlur
      this.skyboxOpacity    = s.opacity    ?? this.skyboxOpacity
      this.skyboxGrain      = s.grain      ?? this.skyboxGrain
      this.skyboxGrainColor = s.grainColor ?? this.skyboxGrainColor
      this.skyboxNoiseColor = s.noiseColor ?? this.skyboxNoiseColor
      this.skyboxNoiseAlpha = s.noiseAlpha ?? this.skyboxNoiseAlpha
      this.skyboxHoldTime   = s.holdTime   ?? this.skyboxHoldTime
      this.skyboxFadeTime   = s.fadeTime   ?? this.skyboxFadeTime
      // Support old format (hasBase + layerKeys) and new format (imageKeys)
      const keys = s.imageKeys ?? [
        ...(s.hasBase ? ['base'] : []),
        ...(s.layerKeys ?? []),
      ]
      this._nextId = s.nextId ?? s.nextLayerId ?? 0

      await this._waitForScene()

      const urls = []
      for (const key of keys) {
        const blob = await this._idbGet(key).catch(() => null)
        if (!blob) continue
        const url = URL.createObjectURL(blob)
        this.skyboxImages.push({ objectUrl: url, idbKey: key })
        urls.push(url)
      }

      if (urls.length) {
        window.dispatchEvent(new CustomEvent('avatar3d:rebuildskybox', {
          detail: { urls, blur: Number(this.skyboxBlur) },
        }))
        window.dispatchEvent(new CustomEvent('avatar3d:setskyboxopacity', {
          detail: { opacity: Number(this.skyboxOpacity) },
        }))
        if (urls.length > 1) {
          window.dispatchEvent(new CustomEvent('avatar3d:settransitiontimes', {
            detail: { hold: Number(this.skyboxHoldTime), fade: Number(this.skyboxFadeTime) },
          }))
          window.dispatchEvent(new CustomEvent('avatar3d:setnoisecolor', {
            detail: { hex: this.skyboxNoiseColor, alpha: Number(this.skyboxNoiseAlpha) },
          }))
        }
      }
    },

    // ── Core rebuild ───────────────────────────────────────────────────────

    _dispatchRebuild () {
      const urls = this.skyboxImages.map(img => img.objectUrl)
      window.dispatchEvent(new CustomEvent('avatar3d:rebuildskybox', {
        detail: { urls, blur: Number(this.skyboxBlur) },
      }))
    },

    // ── Actions ────────────────────────────────────────────────────────────

    async onImagesAdd (fileList) {
      for (const file of Array.from(fileList)) {
        const key = 'img-' + this._nextId++
        const url = URL.createObjectURL(file)
        this.skyboxImages.push({ objectUrl: url, idbKey: key })
        await this._idbPut(key, file)
      }
      this._dispatchRebuild()
      this._saveSettings()
    },

    async onImageRemove (i) {
      const img = this.skyboxImages[i]
      if (img.objectUrl) URL.revokeObjectURL(img.objectUrl)
      await this._idbDel(img.idbKey).catch(() => {})
      this.skyboxImages.splice(i, 1)
      this._dispatchRebuild()
      this._saveSettings()
    },

    async clearAll () {
      for (const img of this.skyboxImages) {
        if (img.objectUrl) URL.revokeObjectURL(img.objectUrl)
        await this._idbDel(img.idbKey).catch(() => {})
      }
      this.skyboxImages = []
      this._nextId = 0
      window.dispatchEvent(new CustomEvent('avatar3d:clearskybox'))
      this._saveSettings()
    },

    // ── Drag to reorder ────────────────────────────────────────────────────

    dragStart (i) {
      this._dragSrcIdx = i
    },

    dragOver (i) {
      if (this._dragSrcIdx !== null && i !== this._dragSrcIdx) this._dragOverIdx = i
    },

    async drop (i) {
      this._dragOverIdx = null
      if (this._dragSrcIdx === null || this._dragSrcIdx === i) { this._dragSrcIdx = null; return }
      const [item] = this.skyboxImages.splice(this._dragSrcIdx, 1)
      this.skyboxImages.splice(i, 0, item)
      this._dragSrcIdx = null
      this._dispatchRebuild()
      this._saveSettings()
    },

    dragEnd () {
      this._dragSrcIdx = null
      this._dragOverIdx = null
    },
  }
}

// ── HMR cleanup ───────────────────────────────────────────────────────────────
if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    window._avatar3d?.destroy()
    window._avatar3d = window.Avatar3DPlayer = null
  })
}

import * as THREE from 'three'
import { Avatar3DPlayer } from '../avatar-3d.js'

/**
 * Push every alignment entry earlier by VISEME_LEAD_SECONDS. The avatar player
 * builds keyframes whose peak lands at the START of each phoneme; on screen the
 * morph-target update happens a frame or two after audio.currentTime advances,
 * so visemes visibly lag audio. Leading the alignment compensates.
 */
const VISEME_LEAD_SECONDS = 0.08
function shiftAlignment(alignment) {
    if (!Array.isArray(alignment)) return []
    return alignment.map(c => ({
        character:  c.character,
        start_time: Math.max(0, (c.start_time ?? 0) - VISEME_LEAD_SECONDS),
        end_time:   Math.max(0, (c.end_time   ?? 0) - VISEME_LEAD_SECONDS),
    }))
}

/**
 * Mount the 3D stage for Step 3 / Step 4. Reuses Avatar3DPlayer's built-in skybox
 * shader pipeline (player.setSkyboxFromUrl) instead of stacking a second sphere.
 */
export async function mountWizardScene({ canvasEl, overlayEl, timerEl, scenes, characterUrl }) {
    const Scene = window.LessonScene
    if (!Scene) {
        console.warn('[wizard-bridge] window.LessonScene not loaded')
        return null
    }
    if (!canvasEl) {
        console.warn('[wizard-bridge] missing canvas element')
        return null
    }

    // Idempotency: Alpine init can re-fire under some Livewire morph paths. Return
    // the cached bridge so we don't stack additional scene:load / scene:play
    // listeners (which would cause double audio playback, etc).
    //
    // Claim the slot SYNCHRONOUSLY before any await so two concurrent inits
    // don't both pass the check.
    if (canvasEl.__lessonBridge || canvasEl.__lessonBridgeMounting) {
        return canvasEl.__lessonBridge || null
    }
    canvasEl.__lessonBridgeMounting = true

    // Subscribe to scene:load BEFORE the slow avatar init so we don't miss the
    // initial dispatch that fires during Livewire hydration. Buffer the last
    // payload and apply it as soon as the player is ready.
    let pendingScene  = null
    let playerReady   = false
    let activePlayer  = null
    let overlay       = null
    let timer         = null
    let quizOverlay   = null
    let baseTerritory = ''   // lesson-level territory title (fallback when a scene has no override)
    let baseFlag      = ''
    let readonlyTextLayer = null
    // Tracks a tab click that hasn't been confirmed by the DB yet.
    // Prevents wire:poll from snapping Three.js back to the old view.
    let _pendingView  = null

    // Pre-warmed audio elements per URL — keeps the browser's HTTP cache hot AND
    // primes the media pipeline so audio.play() starts instantly when the teacher
    // hits ▶ (no ~1s decode gap that would freeze the visemes).
    const audioWarmCache = new Map()
    const preloadAudio = (url) => {
        if (!url || audioWarmCache.has(url)) return
        try {
            const a = new Audio()
            a.preload     = 'auto'
            a.crossOrigin = 'anonymous'
            a.src         = url
            audioWarmCache.set(url, a)
        } catch {}
    }

    const applyScene = async (payload) => {
        if (!payload) return
        const dbView = payload.sceneView === 'slideshow' ? 'slideshow'
                     : payload.sceneView === 'world'     ? 'world'
                     : 'skybox'
        // Once DB confirms the clicked tab, clear the pending guard.
        if (_pendingView && _pendingView === dbView) _pendingView = null

        // While a tab click hasn't been confirmed by the DB yet, skip ALL Three.js
        // work — the lesson:scene:view handler already applied the correct view with
        // the correct payload. Running applyScene with a stale payload causes the
        // wrong branch to execute with the wrong image URL, creating extra texture loads.
        if (playerReady && !_pendingView) {
            const view = dbView
            try {
                if (view === 'slideshow') {
                    applySlideshowCameraMode(activePlayer)
                    const firstShot = (payload.shots && payload.shots.length) ? payload.shots[0] : null
                    await applySlideshowBackground(payload.imageUrl, payload.sceneId ?? 0, payload.duration ?? 10, {
                        kbAnimated: payload.kbAnimated,
                        kbDirection: payload.kbDirection,
                        backgroundColor: payload.backgroundColor,
                        focus: payload.focus ?? payload.config?.background_focus,
                        layers: firstShot?.layers ?? null,
                        parallax: !!payload.parallax,
                        clipartOnTop: !!payload.config?.clipart_on_top,
                        slideshowMode: payload.slideshowMode || (payload.parallax ? 'parallax' : 'standard'),
                        sceneId: payload.sceneId ?? 0,
                    })
                } else if (view === 'world') {
                    destroyWizardLayers()
                    applyWorldCameraMode(activePlayer)
                    if (payload.worldPanoUrl && payload.worldLabsStatus === 'ready') {
                        hideWorldWaitingState(activePlayer._scene)
                        const result = await activePlayer.mountWorldLabs({
                            panoUrl:   payload.worldPanoUrl,
                            spzUrl:    payload.worldSpzUrl,
                            glbUrl:    payload.worldGlbUrl,
                            semantics: payload.worldSemantics ?? {},
                        })
                        repositionWorldCamera(activePlayer)
                        applyWorldSettings(activePlayer, payload)
                        window.dispatchEvent(new CustomEvent('world:mounted', { detail: payload }))
                        _lastMountedWorldStatus = payload.sceneId + ':ready'
                    } else {
                        activePlayer.dismountWorldLabs()
                        _lastMountedWorldStatus = null
                        applyWorldWaitingState()
                    }
                } else if (payload.imageUrl) {
                    destroyWizardLayers()
                    restoreDefaultCameraMode(activePlayer)
                    await applySkyboxView(payload.imageUrl, payload.skyboxBlur ?? 0)
                }
            } catch (err) {
                console.warn('[wizard-bridge] background load failed', err)
            }
        }
        if (playerReady && dbView === 'skybox' && typeof payload.skyboxOpacity === 'number' && typeof activePlayer.setSkyboxOpacity === 'function') {
            try { activePlayer.setSkyboxOpacity(payload.skyboxOpacity) } catch {}
        }
        if (playerReady && dbView === 'skybox' && typeof payload.backgroundColor === 'string') {
            applyBackgroundColor(payload.backgroundColor)
        }
        if (playerReady && payload.animationClipUrl && typeof activePlayer.loadAnimation === 'function') {
            try { await activePlayer.loadAnimation(payload.animationClipUrl) }
            catch (err) { console.warn('[wizard-bridge] loadAnimation failed', err) }
        }
        if (payload.audioUrl) preloadAudio(payload.audioUrl)
        // Per-scene identity: a title override (else the lesson territory) and a hide flag.
        overlay?.setTerritory({ title: payload.identityTitle || baseTerritory, flagUrl: baseFlag })
        overlay?.update({
          sceneId: payload.sceneId,
          year: payload.year,
          location: payload.location,
          hidden: payload.hideIdentity,
        })
        // The challenge countdown belongs to the STUDENT run, not the editor — a frozen
        // "0:00" in Configure only confuses teachers. Keep it hidden here.
        timer?.hide()

        // Selecting a quiz scene in Configure previews its questions right on the canvas
        // (navigable, answerable). Any other selection clears the overlay.
        if (payload.kind === 'game' && payload.gameType === 'quiz' && payload.quizQuestions?.length) {
            quizOverlay?.show({ questions: payload.quizQuestions })
        } else {
            quizOverlay?.hide()
        }

        // Preview playback: render teacher text annotations read-only. (Configure uses its
        // own EDITABLE layer wired in the blade — that path never sets textsReadonly.)
        if (payload.textsReadonly !== undefined) {
            if (!readonlyTextLayer && canvasEl?.parentElement && Scene.TextOverlayLayer) {
                const host = document.createElement('div')
                host.style.cssText = 'position:absolute; inset:0; z-index:7; pointer-events:none;'
                canvasEl.parentElement.appendChild(host)
                readonlyTextLayer = new Scene.TextOverlayLayer(host, { editable: false })
            }
            readonlyTextLayer?.setTexts(payload.textsReadonly)
        }
    }

    // Live preview while the teacher drags a slider — bypasses the Livewire
    // round-trip for instant feedback. The matching wire:change on the slider
    // persists the final value to the DB.
    window.addEventListener('lesson:world:character-y', e => {
        if (!playerReady || !activePlayer?._worldMode) return
        // Slider fine-tunes world Y: positive offset lifts the world (raises street surface).
        // Character stays at Y=0 where lights/shadows are tuned.
        const offset = Number(e.detail?.offset ?? 0)
        const base   = activePlayer._worldYAdjust ?? 0
        if (activePlayer._worldGlbMesh) {
            activePlayer._worldGlbMesh.position.y = base + offset
        }
        if (activePlayer._sparkRenderer) {
            activePlayer._sparkRenderer.position.y = base + offset
        }
    })
    window.addEventListener('lesson:world:scale', e => {
        if (!playerReady || !activePlayer?._worldMode) return
        activePlayer.setWorldScale(Number(e.detail?.scale ?? 1))
    })
    window.addEventListener('lesson:world:char-scale', e => {
        if (!playerReady || !activePlayer?._worldMode) return
        activePlayer.setCharScale(Number(e.detail?.scale ?? 1))
    })
    window.addEventListener('lesson:skybox:blur', e => {
        if (playerReady && typeof activePlayer.setSkyboxBlur === 'function') {
            try { activePlayer.setSkyboxBlur(Number(e.detail?.blur ?? 0)) } catch {}
        }
    })
    window.addEventListener('lesson:skybox:opacity', e => {
        if (playerReady && typeof activePlayer.setSkyboxOpacity === 'function') {
            try { activePlayer.setSkyboxOpacity(Number(e.detail?.opacity ?? 1)) } catch {}
        }
    })
    window.addEventListener('lesson:skybox:bgcolor', e => {
        applyBackgroundColor(String(e.detail?.color ?? '#000000'))
    })

    // Immediate scene-view tab switch — camera + background mode without waiting
    // for the next Livewire poll to call applyScene.
    window.addEventListener('lesson:scene:view', async e => {
        if (!playerReady) return
        const { view, imageUrl, sceneId, duration } = e.detail ?? {}
        _pendingView = view ?? null
        if (view === 'slideshow') {
            applySlideshowCameraMode(activePlayer)
            const firstShot = (e.detail?.shots && e.detail.shots.length) ? e.detail.shots[0] : null
            await applySlideshowBackground(imageUrl, sceneId ?? 0, duration ?? 10, {
                kbAnimated: e.detail?.kbAnimated,
                kbDirection: e.detail?.kbDirection,
                backgroundColor: e.detail?.backgroundColor,
                focus: e.detail?.focus ?? e.detail?.config?.background_focus,
                layers: firstShot?.layers ?? null,
                parallax: !!e.detail?.parallax,
                clipartOnTop: !!e.detail?.config?.clipart_on_top,
                sceneId: sceneId ?? 0,
            })
        } else if (view === 'skybox') {
            // Skybox sphere is already loaded — just restore camera + make it visible.
            destroyWizardLayers()
            kenBurnsState = null
            if (activePlayer._skyboxSphere) activePlayer._skyboxSphere.visible = true
            activePlayer?.dismountWorldLabs?.()
            hideWorldWaitingState(activePlayer?._scene)
            restoreDefaultCameraMode(activePlayer)
        } else if (view === 'world') {
            destroyWizardLayers()
            applyWorldCameraMode(activePlayer)
        }
    })

    // Update the scene clear color + the skybox shader's bg uniform without
    // clearing the skybox sphere (Avatar3DPlayer.setSceneBackground destroys it).
    function applyBackgroundColor(hex) {
        if (!playerReady || !activePlayer?._scene) return
        try {
            const color = new THREE.Color(hex)
            activePlayer._scene.background = color
            activePlayer._lastSolidBg      = hex
            if (activePlayer._skyboxSphere?.material?.uniforms?.uBgColor) {
                activePlayer._skyboxSphere.material.uniforms.uBgColor.value.set(hex)
            }
        } catch (err) {
            console.warn('[wizard-bridge] bg color failed', err)
        }
    }

    async function applySkyboxView(url, blur) {
        hideWorldWaitingState(activePlayer?._scene)
        activePlayer?.dismountWorldLabs?.()
        _lastMountedWorldStatus = null
        if (activePlayer._skyboxSphere) activePlayer._skyboxSphere.visible = true
        // Pause Ken Burns animation when leaving slideshow.
        kenBurnsState = null
        // Restore orbit + rotate when coming back from slideshow or world
        restoreDefaultCameraMode(activePlayer)
        await activePlayer.setSkyboxFromUrl(url, blur)
    }

    // World mode — waiting state: white animated noise dissolve over a dotted grid.
    // Uses the skybox sphere but swaps in a procedural ShaderMaterial so no texture
    // needs to load. The noise gradually animates to signal activity.
    let _worldWaitMat = null
    let _worldWaitRaf = null
    function applyWorldWaitingState() {
        if (!activePlayer) return
        const scene3 = activePlayer._scene
        if (!scene3) return

        // Hide the regular skybox sphere so our material takes over cleanly.
        if (activePlayer._skyboxSphere) activePlayer._skyboxSphere.visible = false
        scene3.background = null

        // Reuse or create the waiting mesh
        if (!scene3.__worldWaitMesh) {
            const geo = new THREE.SphereGeometry(490, 48, 32)
            const mat = new THREE.ShaderMaterial({
                side: THREE.BackSide,
                depthWrite: false,
                uniforms: { uTime: { value: 0 } },
                vertexShader: /* glsl */`
                    varying vec2 vUv;
                    void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }
                `,
                fragmentShader: /* glsl */`
                    uniform float uTime;
                    varying vec2  vUv;

                    float hash(vec2 p) {
                        p = fract(p * vec2(234.34, 435.345));
                        p += dot(p, p + 34.23);
                        return fract(p.x * p.y);
                    }
                    float vnoise(vec2 p) {
                        vec2 i = floor(p), f = fract(p);
                        vec2 u = f*f*(3.0-2.0*f);
                        return mix(mix(hash(i),hash(i+vec2(1,0)),u.x),
                                   mix(hash(i+vec2(0,1)),hash(i+vec2(1,1)),u.x),u.y);
                    }
                    float fbm(vec2 p) {
                        float v=0.0,a=0.5;
                        mat2 rot=mat2(0.8,0.6,-0.6,0.8);
                        for(int i=0;i<4;i++){v+=a*vnoise(p);p=rot*p*2.0+1.7;a*=0.5;}
                        return v;
                    }

                    void main() {
                        // Dotted grid
                        vec2 grid = fract(vUv * vec2(48.0, 24.0));
                        float dot = 1.0 - smoothstep(0.06, 0.10, length(grid - 0.5));
                        float gridAlpha = dot * 0.18;

                        // Animated white noise mask
                        float n = fbm(vUv * 6.0 + uTime * 0.12);
                        float noise = smoothstep(0.35, 0.65, n);

                        float brightness = mix(gridAlpha, 1.0, noise * 0.85);
                        gl_FragColor = vec4(vec3(brightness), 1.0);
                    }
                `,
            })
            _worldWaitMat = mat
            const mesh = new THREE.Mesh(geo, mat)
            mesh.scale.x = -1
            mesh.renderOrder = -1
            scene3.__worldWaitMesh = mesh
            scene3.add(mesh)
        }
        scene3.__worldWaitMesh.visible = true

        // Animate uTime
        if (_worldWaitRaf) cancelAnimationFrame(_worldWaitRaf)
        const tick = () => {
            if (!scene3.__worldWaitMesh?.visible) return
            _worldWaitMat.uniforms.uTime.value = performance.now() * 0.001
            _worldWaitRaf = requestAnimationFrame(tick)
        }
        tick()
    }

    function hideWorldWaitingState(scene3) {
        if (scene3?.__worldWaitMesh) scene3.__worldWaitMesh.visible = false
        if (_worldWaitRaf) { cancelAnimationFrame(_worldWaitRaf); _worldWaitRaf = null }
    }

    // Slideshow mode: hide the inverted-sphere skybox and put the image directly
    // on the scene's clear color so it renders as a flat 2D backdrop, or use ParallaxScene
    // for layered shots (E3c).
    const slideshowTextureCache = new Map()
    let currentBgFocus = 'center'   // 'top' anchors portrait backgrounds so faces survive the crop
    async function applySlideshowBackground(url, sceneId = 0, durationSec = 10, motion = {}) {
        currentBgFocus = motion.focus || 'center'
        // Layered shot (E3c): render via ParallaxScene when the shot carries layers.
        if (Array.isArray(motion.layers) && motion.layers.length) {
            const didShow = await showLayeredSlideshowShot(motion)
            if (didShow) return
            // Fall through to flat if layered show fails
        }
        // Flat scene: make sure a previous scene's layer host isn't covering the canvas.
        destroyWizardLayers()

        // No image (deleted, or a blank/map scene): show the scene's solid backdrop instead of
        // whatever texture happened to be on the canvas before. Also avoids handing the loader
        // a null url — THREE would fetch the literal string "null" and log a stray 404.
        if (!url) {
            kenBurnsState = null
            if (activePlayer._skyboxSphere) activePlayer._skyboxSphere.visible = false
            activePlayer._scene.background = new THREE.Color(motion.backgroundColor || '#0f172a')
            return
        }
        try {
            let tex = slideshowTextureCache.get(url)
            if (!tex) {
                tex = await new Promise((resolve, reject) => {
                    new THREE.TextureLoader().load(url, t => {
                        t.colorSpace        = THREE.SRGBColorSpace
                        t.mapping           = THREE.UVMapping
                        t.matrixAutoUpdate  = true
                        // Clamp (not wrap) so a top-anchored crop can never bleed the image's
                        // opposite edge in if an offset+repeat overshoots [0,1].
                        t.wrapS = t.wrapT   = THREE.ClampToEdgeWrapping
                        resolve(t)
                    }, undefined, reject)
                })
                slideshowTextureCache.set(url, tex)
            }
            // Hide sphere only after texture is ready — avoids black flash.
            if (activePlayer._skyboxSphere) activePlayer._skyboxSphere.visible = false
            activePlayer._scene.background = tex

            if (motion.kbAnimated === false) {
                // Motion off: freeze at a clean cover fit.
                kenBurnsState = null
                const cv = coverScale(tex)
                tex.repeat.set(cv.rX, cv.rY)
                tex.offset.set(cv.oX, cv.oY)
            } else {
                startKenBurns(tex, sceneId, Math.max(4, durationSec || 10), motion.kbDirection || null)
            }
        } catch (err) {
            console.warn('[wizard-bridge] slideshow texture load failed', err)
        }
    }

    // ── Ken Burns ──────────────────────────────────────────────────────────────
    // Each variant: start/end UV offset + start/end "repeat" (visible portion).
    // repeat < 1 = zoomed in; repeat = 1.0 fills the texture. Image always covers.
    const KEN_BURNS_VARIANTS = [
        { from: { ox: 0.00, oy: 0.00, r: 0.91 }, to: { ox: 0.09, oy: 0.09, r: 1.00 } }, // TL → BR, zoom out
        { from: { ox: 0.09, oy: 0.00, r: 0.91 }, to: { ox: 0.00, oy: 0.09, r: 1.00 } }, // TR → BL
        { from: { ox: 0.09, oy: 0.09, r: 0.91 }, to: { ox: 0.00, oy: 0.00, r: 1.00 } }, // BR → TL
        { from: { ox: 0.00, oy: 0.09, r: 0.91 }, to: { ox: 0.09, oy: 0.00, r: 1.00 } }, // BL → TR
        { from: { ox: 0.045, oy: 0.045, r: 0.91 }, to: { ox: 0.045, oy: 0.045, r: 1.00 } }, // centered zoom
        { from: { ox: 0.00, oy: 0.045, r: 0.91 }, to: { ox: 0.09, oy: 0.045, r: 0.91 } },  // pan L→R, no zoom
    ]
    const KEN_BURNS_PAUSE_MS = 2000
    let kenBurnsState = null
    // Teacher-selectable moves (scenes.kb_direction) — mirrors the player's KB_NAMED set.
    const KEN_BURNS_NAMED = {
        left_right: { from: { ox: 0.00, oy: 0.045, r: 0.91 }, to: { ox: 0.09, oy: 0.045, r: 0.91 } },
        right_left: { from: { ox: 0.09, oy: 0.045, r: 0.91 }, to: { ox: 0.00, oy: 0.045, r: 0.91 } },
        zoom_in:    { from: { ox: 0.045, oy: 0.045, r: 1.00 }, to: { ox: 0.045, oy: 0.045, r: 0.88 } },
        zoom_out:   { from: { ox: 0.045, oy: 0.045, r: 0.88 }, to: { ox: 0.045, oy: 0.045, r: 1.00 } },
    }

    function startKenBurns(tex, sceneId, durationSec, direction = null) {
        const idx = ((Number(sceneId) || 0) % KEN_BURNS_VARIANTS.length + KEN_BURNS_VARIANTS.length) % KEN_BURNS_VARIANTS.length
        kenBurnsState = {
            tex,
            variant:    KEN_BURNS_NAMED[direction] ?? KEN_BURNS_VARIANTS[idx],
            startedAt:  performance.now(),
            durationMs: durationSec * 1000,
        }
    }
    // Compute "cover" UV scale so the background image fills the viewport without
    // stretching regardless of how the window is resized.
    function coverScale(tex) {
        const img = tex.image
        const imgW = img?.naturalWidth  || img?.width  || 1
        const imgH = img?.naturalHeight || img?.height || 1
        const vpW  = activePlayer?.canvasEl?.clientWidth  || 1
        const vpH  = activePlayer?.canvasEl?.clientHeight || 1
        const imgAspect = imgW / imgH
        const vpAspect  = vpW  / vpH
        if (vpAspect > imgAspect) {
            // viewport wider — fill by width, crop top/bottom
            const rY = imgAspect / vpAspect
            // Portrait focus: anchor the crop to the TOP (oY = 1-rY) so the face isn't lost;
            // otherwise centre it. (flipY textures: v=1 is the image top.)
            const oY = currentBgFocus === 'top' ? (1 - rY) : (1 - rY) / 2
            return { rX: 1, rY, oX: 0, oY }
        } else {
            // viewport taller — fill by height, crop left/right
            const rX = vpAspect / imgAspect
            return { rX, rY: 1, oX: (1 - rX) / 2, oY: 0 }
        }
    }

    function tickKenBurns(now) {
        if (!kenBurnsState) return
        const { tex, variant, startedAt, durationMs } = kenBurnsState
        // Ping-pong cycle: from → to (pan), pause, to → from (pan back), pause.
        const cycleDuration = 2 * durationMs + 2 * KEN_BURNS_PAUSE_MS
        const elapsed = (now - startedAt) % cycleDuration

        // Direction value d: 0 = `from` position, 1 = `to` position.
        let d
        if (elapsed < durationMs) {
            d = elapsed / durationMs                                   // forward
        } else if (elapsed < durationMs + KEN_BURNS_PAUSE_MS) {
            d = 1                                                      // hold at `to`
        } else if (elapsed < 2 * durationMs + KEN_BURNS_PAUSE_MS) {
            d = 1 - (elapsed - durationMs - KEN_BURNS_PAUSE_MS) / durationMs  // reverse
        } else {
            d = 0                                                      // hold at `from`
        }

        const eased = d < 0.5 ? 2 * d * d : 1 - Math.pow(-2 * d + 2, 2) / 2
        const ox = variant.from.ox + (variant.to.ox - variant.from.ox) * eased
        const oy = variant.from.oy + (variant.to.oy - variant.from.oy) * eased
        const r  = variant.from.r  + (variant.to.r  - variant.from.r)  * eased
        // Apply cover scaling so the image always fills the viewport at any size.
        const cv = coverScale(tex)
        tex.repeat.set(cv.rX * r, cv.rY * r)
        // Top-anchored portraits pan the vertical drift DOWNWARD only, so it never rises past
        // the top edge (which would wrap the texture and bleed the image's bottom in at the top).
        const oySign = currentBgFocus === 'top' ? -1 : 1
        tex.offset.set(cv.oX + ox * cv.rX, cv.oY + oySign * oy * cv.rY)
    }

    // ── Auto-orbit + handheld vibration ───────────────────────────────────────
    let resumeOrbitTimer = null
    let _worldCameraMode = false
    let _slideshowMode   = false
    function pauseOrbit() {
        if (!activePlayer?._controls) return
        activePlayer._controls.autoRotate = false
        if (resumeOrbitTimer) clearTimeout(resumeOrbitTimer)
    }
    function scheduleOrbitResume() {
        if (!activePlayer?._controls || _worldCameraMode || _slideshowMode) return
        if (resumeOrbitTimer) clearTimeout(resumeOrbitTimer)
        resumeOrbitTimer = setTimeout(() => {
            if (activePlayer?._controls && !_worldCameraMode && !_slideshowMode) activePlayer._controls.autoRotate = true
        }, 2000)
    }
    // ── WASD free-fly for World Scene View ───────────────────────────────────
    const _keys = new Set()
    let   _wasdRaf = null

    function _startWasd(player) {
        if (_wasdRaf) return
        const cam      = player._camera
        const controls = player._controls
        if (!cam || !controls) return

        const _fwd  = new THREE.Vector3()
        const _right= new THREE.Vector3()
        const _move = new THREE.Vector3()
        let   _last = performance.now()

        const tick = () => {
            if (!_worldCameraMode) { _wasdRaf = null; return }
            _wasdRaf = requestAnimationFrame(tick)

            const now = performance.now()
            const dt  = Math.min((now - _last) / 1000, 0.05)
            _last = now

            if (!_keys.size) return

            const speed = _keys.has('ShiftLeft') || _keys.has('ShiftRight') ? 12 : 4

            // forward = look direction projected onto XZ plane
            cam.getWorldDirection(_fwd)
            _fwd.y = 0
            if (_fwd.lengthSq() < 0.001) return
            _fwd.normalize()
            _right.crossVectors(_fwd, cam.up).normalize()

            _move.set(0, 0, 0)
            if (_keys.has('KeyW') || _keys.has('ArrowUp'))    _move.addScaledVector(_fwd,   speed * dt)
            if (_keys.has('KeyS') || _keys.has('ArrowDown'))  _move.addScaledVector(_fwd,  -speed * dt)
            if (_keys.has('KeyA') || _keys.has('ArrowLeft'))  _move.addScaledVector(_right,-speed * dt)
            if (_keys.has('KeyD') || _keys.has('ArrowRight')) _move.addScaledVector(_right, speed * dt)
            if (_keys.has('KeyE') || _keys.has('Space'))      _move.y +=  speed * dt
            if (_keys.has('KeyQ') || _keys.has('KeyC'))       _move.y += -speed * dt

            if (_move.lengthSq() < 1e-8) return
            cam.position.add(_move)
            controls.target.add(_move)
            // Floor clamp — don't let WASD descend below street level + 0.5 m
            if (cam.position.y < 0.5) {
                const dy = 0.5 - cam.position.y
                cam.position.y       = 0.5
                controls.target.y   += dy
            }
            controls.update()
        }
        _wasdRaf = requestAnimationFrame(tick)
    }

    function _stopWasd() {
        if (_wasdRaf) { cancelAnimationFrame(_wasdRaf); _wasdRaf = null }
        _keys.clear()
    }

    const _onKeyDown = e => { if (_worldCameraMode) _keys.add(e.code) }
    const _onKeyUp   = e => _keys.delete(e.code)
    window.addEventListener('keydown', _onKeyDown)
    window.addEventListener('keyup',   _onKeyUp)

    function applyWorldSettings(player, payload) {
        if (!player?._worldMode) return
        const yOffset   = payload.worldYOffset   ?? 0
        const scale     = payload.worldScale      ?? 1
        const charScale = payload.worldCharScale  ?? 0.53
        // Apply Y offset
        const base = player._worldYAdjust ?? 0
        if (player._worldGlbMesh)  player._worldGlbMesh.position.y  = base + yOffset
        if (player._sparkRenderer) player._sparkRenderer.position.y = base + yOffset
        // Apply scales
        player.setWorldScale(scale)
        player.setCharScale(charScale)
    }

    function applyWorldCameraMode(player) {
        if (!player?._controls) return
        _worldCameraMode = true
        _slideshowMode   = false
        if (resumeOrbitTimer) { clearTimeout(resumeOrbitTimer); resumeOrbitTimer = null }
        player._controls.enabled = true
        player._controls.autoRotate      = false
        player._controls.enableRotate    = true
        player._controls.enableZoom      = true
        player._controls.enablePan       = true
        player._controls.mouseButtons    = { LEFT: THREE.MOUSE.ROTATE, MIDDLE: THREE.MOUSE.DOLLY, RIGHT: THREE.MOUSE.PAN }
        player._controls.minDistance     = 0.3
        player._controls.maxDistance     = 20   // allow drone shots; floor enforced via maxPolarAngle
        player._controls.maxPolarAngle   = Math.PI * 0.88   // initial value; overridden each frame

        _startWasd(player)
    }
    // Called after mountWorldLabs resolves. Street is always at Y=0 after world repositioning.
    function repositionWorldCamera(player) {
        if (!player?._camera || !player?._controls) return
        // Start 2 m back, 1.2 m up (eye-level), orbiting around head (~0.75 m)
        player._camera.position.set(0, 1.2, 2)
        player._controls.target.set(0, 0.75, 0)
        player._controls.update()
    }
    function restoreDefaultCameraMode(player) {
        if (!player?._controls) return
        _worldCameraMode = false
        _slideshowMode   = false
        _stopWasd()

        player._controls.enabled         = true
        player._controls.enableRotate    = true
        player._controls.enableDamping   = true
        if (player._renderer?.domElement) {
            player._renderer.domElement.style.pointerEvents = ''
        }
        player._controls.dampingFactor   = 0.08
        player._controls.autoRotate      = true
        player._controls.autoRotateSpeed = 0.4
        player._controls.enableZoom      = false
        player._controls.enablePan       = false
        player._controls.minDistance     = 0.5
        player._controls.maxDistance     = 6.0
        player._controls.maxPolarAngle   = Math.PI * 0.85
    }

    function applySlideshowCameraMode(player) {
        if (!player?._controls || !player?._camera) return
        _worldCameraMode = false
        _slideshowMode   = true
        _stopWasd()
        if (resumeOrbitTimer) { clearTimeout(resumeOrbitTimer); resumeOrbitTimer = null }

        // Fully lock camera — slideshow is a static 2D backdrop, no 3D navigation
        player._controls.autoRotate   = false
        player._controls.enableRotate = false
        player._controls.enableZoom   = false
        player._controls.enablePan    = false
        player._controls.enabled      = false
        // Belt-and-suspenders: block pointer events on the canvas so no drag/scroll
        // reaches OrbitControls even if enabled somehow gets reset.
        if (player._renderer?.domElement) {
            player._renderer.domElement.style.pointerEvents = 'none'
        }
        // Disable damping and zero residual angular velocity so the camera doesn't
        // keep drifting from whatever orbit speed it had before the mode switch.
        player._controls.enableDamping = false
        if (player._controls._sphericalDelta) {
            player._controls._sphericalDelta.theta = 0
            player._controls._sphericalDelta.phi   = 0
        }

        // Position camera directly front-facing at avatar
        player._camera.position.set(0, 1.4, 2.4)
        player._controls.target.set(0, 1.2, 0)
        player._controls.update()
    }

    // Subtle handheld jitter. Apply just before render, undo just after, so
    // OrbitControls' spherical state isn't perturbed. Suppressed in slideshow mode.
    function startHandheldVibration(player) {
        if (!player?._renderer || !player?._camera) return
        const originalRender = player._renderer.render.bind(player._renderer)
        player._renderer.render = function (scene, camera) {
            if (_slideshowMode) { return originalRender(scene, camera) }
            const t = performance.now() / 1000
            const dx = Math.sin(t * 1.7) * 0.0035 + Math.sin(t * 0.7) * 0.0015
            const dy = Math.cos(t * 2.3) * 0.0035 + Math.cos(t * 0.9) * 0.0015
            camera.position.x += dx
            camera.position.y += dy
            try { originalRender(scene, camera) }
            finally {
                camera.position.x -= dx
                camera.position.y -= dy
            }
        }
    }

    // Frame tick for Ken Burns (texture UV animation). Runs alongside the
    // player's own RAF; cheap (just mutates texture matrix).
    function startKenBurnsTick() {
        const loop = () => {
            requestAnimationFrame(loop)
            tickKenBurns(performance.now())
        }
        requestAnimationFrame(loop)
    }

    // Layered scene (wizard E3c preview): renders a multiplane shot via ParallaxScene
    // in a DOM host laid over the Three.js canvas. The wizard has no #background-layer
    // (that element belongs to the student player), so we own our host here.
    let _parallaxMod = null
    let _wizardLayeredScene = null
    let _wizardLayerHost = null
    let _wizardLayeredRaf = 0
    let _bgSig = null   // skip re-fading the background when only unrelated inspector fields change

    // Ken Burns direction → ParallaxScene motion, so the ANIMATED + direction settings drive
    // the background pan/zoom on layered scenes too (not just flat ones). Static when off.
    const kbMotion = (payload) => {
        if (payload.kbAnimated === false) return { panX: 0, panY: 0, zoom: 1 }
        switch (payload.kbDirection) {
            case 'left_right': return { panX: 6, panY: 0, zoom: 1.03 }
            case 'right_left': return { panX: -6, panY: 0, zoom: 1.03 }
            case 'zoom_in':    return { panX: 0, panY: 0, zoom: 1.09 }
            case 'zoom_out':   return { panX: 0, panY: 0, zoom: 0.92 }
            default:           return { panX: -4, panY: 0, zoom: 1.06 }   // Auto (varied pans)
        }
    }
    // Editable clipart overlay (separate from the parallax bg): its own host, own module.
    let _artworkMod = null
    let _artworkOverlay = null
    let _artworkHost = null
    let _artworkSig = null   // skip re-seeding the overlay on identical poll re-renders
    // Drawing mode: ink pen engine that draws each clipart stroke-by-stroke.
    let _inkMod = null
    let _inkEngines = []
    let _inkSig = null

    function wizardLayerHost() {
        if (_wizardLayerHost?.isConnected) return _wizardLayerHost
        const parent = canvasEl.parentElement
        if (!parent) return null
        if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative'
        const host = document.createElement('div')
        host.className = 'wizard-parallax-host'
        // pointer-events:none keeps OrbitControls and canvas clicks working underneath.
        host.style.cssText = 'position:absolute;inset:0;overflow:hidden;z-index:1;pointer-events:none;'
        parent.appendChild(host)
        _wizardLayerHost = host
        return host
    }

    // The editable clipart overlay sits ABOVE the parallax background (z:2) but below the
    // text overlay. Its host is transparent + pointer-events:none; only the layer nodes catch.
    function wizardArtworkHost() {
        if (_artworkHost?.isConnected) return _artworkHost
        const parent = canvasEl.parentElement
        if (!parent) return null
        if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative'
        const host = document.createElement('div')
        host.className = 'wizard-artwork-host'
        host.style.cssText = 'position:absolute;inset:0;overflow:hidden;z-index:2;pointer-events:none;'
        parent.appendChild(host)
        _artworkHost = host
        return host
    }

    function destroyWizardLayers() {
        if (_wizardLayeredRaf) { cancelAnimationFrame(_wizardLayeredRaf); _wizardLayeredRaf = 0 }
        if (_wizardLayeredScene) {
            try { _wizardLayeredScene.destroy() } catch (_) {}
            _wizardLayeredScene = null
        }
        if (_wizardLayerHost) _wizardLayerHost.style.display = 'none'
        _bgSig = null
        if (_artworkOverlay) { try { _artworkOverlay.clear() } catch (_) {} }
        if (_artworkHost) _artworkHost.style.display = 'none'
        _artworkSig = null
        for (const e of _inkEngines) { try { e.destroy() } catch (_) {} }
        _inkEngines = []
        _inkSig = null
    }

    async function showLayeredSlideshowShot(payload) {
        try {
            const { layers, parallax = false } = payload
            if (!Array.isArray(layers) || !layers.length) return false

            // Split: the background plane(s) render as parallax; free clipart (asset_id) is
            // rendered by the editable overlay so teachers can move + scale it directly.
            const bgLayers = layers.filter(l => l.kind === 'cover' || l.asset_id == null)
            const artLayers = layers.filter(l => l.asset_id != null && l.kind !== 'cover')

            if (!_parallaxMod) _parallaxMod = await import('./ParallaxScene.js')
            const host = wizardLayerHost()
            if (!host) return false

            host.style.display = ''
            kenBurnsState = null
            if (activePlayer?._skyboxSphere) activePlayer._skyboxSphere.visible = false

            // ── Background — only rebuild when the bg actually changes. Re-showing on every
            //    scene:load (a caption toggle, a script edit, a 3s poll) re-runs the 900ms fade =
            //    the flicker Bart saw. The motion follows the ANIMATED + Ken Burns direction. ──
            const motion = kbMotion(payload)
            const animated = payload.kbAnimated !== false && (Math.abs(motion.panX) > 0.01 || Math.abs(motion.zoom - 1) > 0.001)
            // Strip the ?v=<updated_at> cache-buster: it changes on EVERY scene save (a caption
            // toggle, a script edit), which would otherwise force a bg re-fade = flicker.
            const stripV = (u) => String(u || '').split('?')[0]
            const bgSig = JSON.stringify([bgLayers.map(l => stripV(l.url)), motion, animated])
            if (bgSig !== _bgSig || !_wizardLayeredScene) {
                _bgSig = bgSig
                if (_wizardLayeredRaf) { cancelAnimationFrame(_wizardLayeredRaf); _wizardLayeredRaf = 0 }
                if (_wizardLayeredScene) { try { _wizardLayeredScene.destroy() } catch (_) {} }
                _wizardLayeredScene = new _parallaxMod.ParallaxScene(host)
                _wizardLayeredScene.show({ layers: bgLayers.length ? bgLayers : layers, motion })
                if (animated) {
                    // Ping-pong so the pan/zoom previews in a loop (playback drives it monotonically).
                    const CYCLE_MS = 12000, start = performance.now()
                    const loop = (now) => {
                        if (!_wizardLayeredScene) return
                        const t = ((now - start) % CYCLE_MS) / CYCLE_MS
                        _wizardLayeredScene.update(t < 0.5 ? t * 2 : (1 - t) * 2)
                        _wizardLayeredRaf = requestAnimationFrame(loop)
                    }
                    _wizardLayeredRaf = requestAnimationFrame(loop)
                } else {
                    _wizardLayeredScene.update(0.5)   // static, centred pose
                }
            }

            const mode = payload.slideshowMode || (parallax ? 'parallax' : 'standard')
            const artHost = wizardArtworkHost()

            if (mode === 'drawing' && artHost && artLayers.length) {
                // Drawing mode: each clipart draws itself stroke-by-stroke (looped preview). Not
                // editable here — teachers position it in Standard/Parallax, Drawing is the effect.
                artHost.style.display = ''
                if (_artworkOverlay) { try { _artworkOverlay.clear() } catch (_) {} }
                if (!_inkMod) _inkMod = await import('./InkDrawEngine.js')
                const sig = JSON.stringify(artLayers.map(l => [l.asset_id, l.x, l.y, l.scale, l.height, String(l.url || '').split('?')[0], l.ink_preset, l.ink_fill, l.draw_time]))
                if (sig !== _inkSig) {
                    _inkSig = sig
                    for (const e of _inkEngines) { try { e.destroy() } catch (_) {} }
                    _inkEngines = artLayers.map((l) => {
                        const eng = new _inkMod.InkDrawEngine(artHost)
                        eng.draw(l.url, { xPct: l.x ?? 50, yPct: l.y ?? 58, scale: l.scale ?? 1, heightPct: l.height ?? 40 }, {
                            loop: true,
                            preset: l.ink_preset || undefined,
                            fillMode: (l.ink_fill && l.ink_fill !== 'auto') ? l.ink_fill : undefined,
                            durationSec: l.draw_time || undefined,
                        })
                        return eng
                    })
                    window.__inkEngines = _inkEngines
                }
            } else if (artHost) {
                // Editable clipart overlay (Standard / Parallax).
                for (const e of _inkEngines) { try { e.destroy() } catch (_) {} }
                _inkEngines = []; _inkSig = null
                artHost.style.display = ''
                if (!_artworkMod) _artworkMod = await import('./ArtworkOverlay.js')
                if (!_artworkOverlay) {
                    _artworkOverlay = new _artworkMod.ArtworkOverlay(artHost, {
                        onChange: (assetId, t) => {
                            window.Livewire?.dispatch('artwork:move', { assetId, x: t.x, y: t.y, scale: t.scale })
                        },
                    })
                    window.__lessonArtworkLayer = _artworkOverlay
                }
                // Clipart can be stacked above the text overlay (teacher drags it there in the object
                // list). The host is cached with z-index:2, so re-apply the flag on every scene load.
                const clipartOnTop = !!payload.clipartOnTop
                _artworkOverlay.setOnTop(clipartOnTop)
                artHost.style.zIndex = clipartOnTop ? '8' : '2'
                // Skip re-seeding on identical poll re-renders (would reset an in-progress edit).
                const sig = JSON.stringify(artLayers.map(l => [l.asset_id, l.x, l.y, l.scale, l.height, String(l.url||"").split("?")[0]]))
                if (sig !== _artworkSig) { _artworkSig = sig; _artworkOverlay.setLayers(artLayers) }
            }
            return true
        } catch (e) {
            console.warn('[wizard-bridge] layered slideshow failed', e)
            destroyWizardLayers()
            return false
        }
    }

    window.Livewire?.on('scene:load', ({ payload }) => {
        pendingScene = payload
        if (playerReady) applyScene(payload)
    })

    // Fired every poll tick while scene_view === 'world'.
    // When the job completes, mount the full WorldLabs scene (SPZ + GLB + pano).
    let _lastMountedWorldStatus = null
    window.Livewire?.on('scene:worldstatus', ({ payload }) => {
        if (!playerReady || !activePlayer) return
        const scene3 = activePlayer._scene
        if (payload.worldLabsStatus === 'ready' && payload.worldPanoUrl) {
            // Only remount if we haven't already (avoids re-loading every 3s poll)
            if (_lastMountedWorldStatus !== payload.sceneId + ':ready') {
                _lastMountedWorldStatus = payload.sceneId + ':ready'
                hideWorldWaitingState(scene3)
                applyWorldCameraMode(activePlayer)
                activePlayer.mountWorldLabs({
                    panoUrl:   payload.worldPanoUrl,
                    spzUrl:    payload.worldSpzUrl,
                    glbUrl:    payload.worldGlbUrl,
                    semantics: payload.worldSemantics ?? {},
                }).then(() => {
                    repositionWorldCamera(activePlayer)
                    applyWorldSettings(activePlayer, payload)
                    window.dispatchEvent(new CustomEvent('world:mounted', { detail: payload }))
                }).catch(err => console.warn('[wizard-bridge] mountWorldLabs failed', err))
            }
        } else if (payload.worldLabsStatus === 'failed') {
            hideWorldWaitingState(scene3)
            _lastMountedWorldStatus = null
        }
        // While generating: waiting state stays — no action needed
    })

    // Manual Play button from the inspector: lip-sync with the scene's alignment.
    window.Livewire?.on('scene:play', ({ payload }) => {
        if (!playerReady || !payload?.audioUrl) return
        try {
            activePlayer.speakWithElevenLabsAlignment(
                payload.audioUrl,
                shiftAlignment(payload.alignment || []),
                { zoom: false, delay: 0 },
            )
        } catch (err) {
            console.warn('[wizard-bridge] speak failed', err)
        }
    })

    // Re-use an existing player on the same canvas if we already mounted one.
    let player = canvasEl.__lessonPlayer
    if (!player) {
        player = new Avatar3DPlayer(canvasEl, { characterUrl: characterUrl || null })
        canvasEl.__lessonPlayer = player
        try {
            await player.init()
        } catch (err) {
            console.error('[wizard-bridge] Avatar3DPlayer init failed', err)
            return null
        }
    }

    // Allow the teacher to orbit and inspect the skybox, but keep zoom + pan off
    // so they can't dolly into the avatar or wander out of the panorama.
    // Auto-orbit slowly around the storyteller; pauses on user input and
    // resumes 2s after the last interaction ends.
    if (player._controls) {
        player._controls.enabled         = true
        player._controls.enableRotate    = true
        player._controls.enableZoom      = false
        player._controls.enablePan       = false
        player._controls.autoRotate      = true
        player._controls.autoRotateSpeed = 0.4
        player._controls.addEventListener('start', pauseOrbit)
        player._controls.addEventListener('end',   scheduleOrbitResume)
    }

    activePlayer = player
    playerReady  = true

    startHandheldVibration(player)
    startKenBurnsTick()
    // Configure = editable identity: inline-edit + an × to hide it, persisted per scene.
    overlay      = new Scene.SceneOverlay(overlayEl, {
      editable: true,
      onChange: (patch) => window.Livewire?.dispatch('sceneIdentityChanged', { patch }),
    })
    overlay.mount()
    // Territory identity (flag + title) from the lesson — the fallback across scenes.
    const rootData = document.getElementById('lesson-canvas-root')?.dataset || {}
    baseTerritory = rootData.territory || ''
    baseFlag = rootData.flag || ''
    overlay.setTerritory({ title: baseTerritory, flagUrl: baseFlag })
    timer        = new Scene.GameTimerOverlay(timerEl)
    quizOverlay  = new Scene.QuizOverlay(timerEl)   // same full-bleed host layer as the timer

    // DB stores asset paths as relative (e.g. "lessons/31/scenes/241/narration.mp3").
    // The sequencer feeds these straight to the avatar player / skybox, where the
    // browser would otherwise resolve them relative to the current URL. Normalise
    // once so every consumer downstream gets site-absolute URLs.
    const toStorage = (p) => (typeof p === 'string' && p.length > 0)
        ? (p.startsWith('/') || p.startsWith('http') ? p : '/storage/' + p)
        : null
    const normalizedScenes = (scenes || []).map(s => ({
        ...s,
        image_path:      toStorage(s.image_path),
        world_pano_path: toStorage(s.world_pano_path),
        audio_path:      toStorage(s.audio_path),
    }))

    // Eagerly warm every scene's narration so Step 4's sequencer + Step 3's Play
    // button hit cached audio on first ▶.
    normalizedScenes.forEach(s => preloadAudio(s.audio_path))

    if (pendingScene) {
        await applyScene(pendingScene)
    } else if (normalizedScenes[0]) {
        // Apply even without an image — an imageless scene must paint its solid brand
        // backdrop instead of leaving the renderer's default (white) showing through.
        await applyScene({
            imageUrl:  normalizedScenes[0].image_path,
            sceneView: normalizedScenes[0].scene_view ?? 'slideshow',
            year:      normalizedScenes[0].year,
            location:  normalizedScenes[0].location,
            kind:      normalizedScenes[0].kind,
            duration:  normalizedScenes[0].duration_seconds,
            backgroundColor: normalizedScenes[0].background_color,
            kbAnimated:  normalizedScenes[0].kb_animated,
            kbDirection: normalizedScenes[0].kb_direction,
            focus: normalizedScenes[0].config?.background_focus,
            // The scene config rides along so first paint honours per-scene flags
            // (background focus, clipart-above-text stacking, …).
            config: normalizedScenes[0].config ?? null,
            // Multiplane layers (E3c) ride along so the first paint is layered too.
            shots: normalizedScenes[0].shots ?? [],
        })
    }

    const adapter = {
        skybox: {
            crossfadeTo: (url, scene) => applyScene({
                imageUrl:   url,
                sceneView:  scene?.scene_view ?? 'slideshow',
                sceneId:    scene?.id,
                duration:   scene?.duration_seconds,
                skyboxBlur: scene?.skybox_blur,
                year:       scene?.year,
                location:   scene?.location,
                kind:       scene?.kind,
                backgroundColor: scene?.background_color,
                kbAnimated:  scene?.kb_animated,
                kbDirection: scene?.kb_direction,
                focus: scene?.config?.background_focus,
                textsReadonly: scene?.config?.texts || [],
            }),
        },
        overlay,
        timer,
        quiz: quizOverlay,
        avatar: {
            setClip: () => { /* now handled by applyScene via animationClipUrl */ },
            // Tracks the resolver for the currently-running speak() so stop() can short-circuit it.
            _resolveSpeak: null,
            speak ({ audioUrl, alignment }) {
                return new Promise(resolve => {
                    if (!audioUrl) return resolve()
                    this._resolveSpeak = resolve
                    try {
                        player.resumeAnimation?.()   // unfreeze body if a previous pause froze it
                        player.speakWithElevenLabsAlignment(audioUrl, shiftAlignment(alignment || []), { zoom: false, delay: 0 })
                    } catch {}
                    const tick = () => {
                        // External stop() already resolved — bail out
                        if (this._resolveSpeak !== resolve) return
                        if (!player._audio || player._audio.ended) {
                            this._resolveSpeak = null
                            return resolve()
                        }
                        setTimeout(tick, 250)
                    }
                    setTimeout(tick, 250)
                })
            },
            stop () {
                try { player.stopSpeech?.() } catch {}
                if (this._resolveSpeak) {
                    const r = this._resolveSpeak
                    this._resolveSpeak = null
                    r()
                }
            },
        },
    }

    const sequencer = new Scene.SceneTimelinePlayer({ scenes: normalizedScenes, ...adapter })

    canvasEl.__lessonBridge = { player, sequencer, overlay, timer }
    canvasEl.__lessonBridgeMounting = false
    return canvasEl.__lessonBridge
}

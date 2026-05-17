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
    let pendingScene = null
    let playerReady  = false
    let activePlayer = null
    let overlay      = null
    let timer        = null

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
        const view = payload.sceneView === 'slideshow' ? 'slideshow' : 'skybox'

        if (playerReady && payload.imageUrl) {
            try {
                if (view === 'slideshow') {
                    await applySlideshowBackground(payload.imageUrl)
                } else {
                    await applySkyboxView(payload.imageUrl, payload.skyboxBlur ?? 0)
                }
            } catch (err) {
                console.warn('[wizard-bridge] background load failed', err)
            }
        }
        if (playerReady && view === 'skybox' && typeof payload.skyboxOpacity === 'number' && typeof activePlayer.setSkyboxOpacity === 'function') {
            try { activePlayer.setSkyboxOpacity(payload.skyboxOpacity) } catch {}
        }
        if (playerReady && view === 'skybox' && typeof payload.backgroundColor === 'string') {
            applyBackgroundColor(payload.backgroundColor)
        }
        if (playerReady && payload.animationClipUrl && typeof activePlayer.loadAnimation === 'function') {
            try { await activePlayer.loadAnimation(payload.animationClipUrl) }
            catch (err) { console.warn('[wizard-bridge] loadAnimation failed', err) }
        }
        if (payload.audioUrl) preloadAudio(payload.audioUrl)
        overlay?.update({ year: payload.year, location: payload.location })
        if (payload.kind === 'game') {
            timer?.show({ durationSeconds: payload.duration || 0 })
        } else {
            timer?.hide()
        }
    }

    // Live preview while the teacher drags a slider — bypasses the Livewire
    // round-trip for instant feedback. The matching wire:change on the slider
    // persists the final value to the DB.
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
        if (activePlayer._skyboxSphere) activePlayer._skyboxSphere.visible = true
        await activePlayer.setSkyboxFromUrl(url, blur)
    }

    // Slideshow mode: hide the inverted-sphere skybox and put the image directly
    // on the scene's clear color so it renders as a flat 2D backdrop.
    const slideshowTextureCache = new Map()
    async function applySlideshowBackground(url) {
        if (activePlayer._skyboxSphere) activePlayer._skyboxSphere.visible = false
        try {
            let tex = slideshowTextureCache.get(url)
            if (!tex) {
                tex = await new Promise((resolve, reject) => {
                    new THREE.TextureLoader().load(url, t => {
                        t.colorSpace = THREE.SRGBColorSpace
                        t.mapping    = THREE.UVMapping
                        resolve(t)
                    }, undefined, reject)
                })
                slideshowTextureCache.set(url, tex)
            }
            activePlayer._scene.background = tex
        } catch (err) {
            console.warn('[wizard-bridge] slideshow texture load failed', err)
        }
    }

    window.Livewire?.on('scene:load', ({ payload }) => {
        pendingScene = payload
        if (playerReady) applyScene(payload)
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
    if (player._controls) {
        player._controls.enabled      = true
        player._controls.enableRotate = true
        player._controls.enableZoom   = false
        player._controls.enablePan    = false
    }

    activePlayer = player
    playerReady  = true
    overlay      = new Scene.SceneOverlay(overlayEl); overlay.mount()
    timer        = new Scene.GameTimerOverlay(timerEl)

    // DB stores asset paths as relative (e.g. "lessons/31/scenes/241/narration.mp3").
    // The sequencer feeds these straight to the avatar player / skybox, where the
    // browser would otherwise resolve them relative to the current URL. Normalise
    // once so every consumer downstream gets site-absolute URLs.
    const toStorage = (p) => (typeof p === 'string' && p.length > 0)
        ? (p.startsWith('/') || p.startsWith('http') ? p : '/storage/' + p)
        : null
    const normalizedScenes = (scenes || []).map(s => ({
        ...s,
        image_path: toStorage(s.image_path),
        audio_path: toStorage(s.audio_path),
    }))

    // Eagerly warm every scene's narration so Step 4's sequencer + Step 3's Play
    // button hit cached audio on first ▶.
    normalizedScenes.forEach(s => preloadAudio(s.audio_path))

    if (pendingScene) {
        await applyScene(pendingScene)
    } else if (normalizedScenes[0]?.image_path) {
        await applyScene({
            imageUrl: normalizedScenes[0].image_path,
            year:     normalizedScenes[0].year,
            location: normalizedScenes[0].location,
            kind:     normalizedScenes[0].kind,
            duration: normalizedScenes[0].duration_seconds,
        })
    }

    const adapter = {
        skybox: { crossfadeTo: (url) => applyScene({ imageUrl: url }) },
        overlay,
        timer,
        avatar: {
            setClip: () => { /* now handled by applyScene via animationClipUrl */ },
            speak: ({ audioUrl, alignment }) => new Promise(resolve => {
                if (!audioUrl) return resolve()
                try {
                    player.speakWithElevenLabsAlignment(audioUrl, shiftAlignment(alignment || []), { zoom: false, delay: 0 })
                } catch {}
                // Best-effort: resolve when the player's audio element ends.
                const tick = () => {
                    if (!player._audio || player._audio.ended) return resolve()
                    setTimeout(tick, 250)
                }
                setTimeout(tick, 250)
            }),
        },
    }

    const sequencer = new Scene.SceneTimelinePlayer({ scenes: normalizedScenes, ...adapter })

    canvasEl.__lessonBridge = { player, sequencer, overlay, timer }
    canvasEl.__lessonBridgeMounting = false
    return canvasEl.__lessonBridge
}

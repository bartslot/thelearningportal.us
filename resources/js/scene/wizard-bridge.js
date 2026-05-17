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

    const applyScene = async (payload) => {
        if (!payload) return
        if (playerReady && payload.imageUrl) {
            try {
                await activePlayer.setSkyboxFromUrl(payload.imageUrl, 0)
            } catch (err) {
                console.warn('[wizard-bridge] skybox load failed', err)
            }
        }
        if (playerReady && payload.animationClipUrl && typeof activePlayer.loadAnimation === 'function') {
            try { await activePlayer.loadAnimation(payload.animationClipUrl) }
            catch (err) { console.warn('[wizard-bridge] loadAnimation failed', err) }
        }
        overlay?.update({ year: payload.year, location: payload.location })
        if (payload.kind === 'game') {
            timer?.show({ durationSeconds: payload.duration || 0 })
        } else {
            timer?.hide()
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

    // Wizard is not an avatar inspector — lock down the orbit/zoom camera so a
    // stray scroll or drag on the canvas doesn't reframe the stage.
    if (player._controls) {
        player._controls.enabled    = false
        player._controls.enableZoom = false
        player._controls.enableRotate = false
    }

    activePlayer = player
    playerReady  = true
    overlay      = new Scene.SceneOverlay(overlayEl); overlay.mount()
    timer        = new Scene.GameTimerOverlay(timerEl)

    if (pendingScene) {
        await applyScene(pendingScene)
    } else if (scenes?.[0]?.image_path) {
        await applyScene({
            imageUrl: '/storage/' + scenes[0].image_path,
            year:     scenes[0].year,
            location: scenes[0].location,
            kind:     scenes[0].kind,
            duration: scenes[0].duration_seconds,
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

    const sequencer = new Scene.SceneTimelinePlayer({ scenes, ...adapter })

    canvasEl.__lessonBridge = { player, sequencer, overlay, timer }
    canvasEl.__lessonBridgeMounting = false
    return canvasEl.__lessonBridge
}

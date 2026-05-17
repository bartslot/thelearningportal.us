import { Avatar3DPlayer } from '../avatar-3d.js'

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
            setClip: (clipId) => {
                if (typeof player.setAnimation === 'function') {
                    player.setAnimation(clipId)
                }
            },
            speak: ({ audioUrl, alignment, text }) => new Promise(resolve => {
                if (typeof player.speak === 'function') {
                    player.speak({ audioUrl, alignment, text }).then(resolve).catch(resolve)
                } else if (audioUrl) {
                    const audio = new Audio(audioUrl)
                    audio.onended = resolve
                    audio.onerror = resolve
                    audio.play().catch(resolve)
                } else {
                    resolve()
                }
            }),
        },
    }

    const sequencer = new Scene.SceneTimelinePlayer({ scenes, ...adapter })

    return { player, sequencer, overlay, timer }
}

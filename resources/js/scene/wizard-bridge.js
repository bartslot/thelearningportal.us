import { Avatar3DPlayer } from '../avatar-3d.js'

/**
 * Mount the 3D stage for Step 3 / Step 4: Avatar3DPlayer on the supplied canvas, a
 * SkyboxSphere added to its scene, and SceneOverlay / GameTimerOverlay HUDs.
 *
 * Returns an object exposing the sequencer + the underlying instances so callers can
 * pause / regenerate / re-mount as needed.
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

    // Re-use an existing player on the same canvas if we already mounted one
    // (Livewire morphs can re-fire alpine:init without destroying the canvas).
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

    const skybox  = new Scene.SkyboxSphere(player._scene)
    const overlay = new Scene.SceneOverlay(overlayEl); overlay.mount()
    const timer   = new Scene.GameTimerOverlay(timerEl)

    const adapter = {
        skybox,
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

    // Livewire → bridge: when a scene is selected in Step 3, swap skybox + HUDs.
    window.Livewire?.on('scene:load', async ({ payload }) => {
        if (payload?.imageUrl) {
            try { await skybox.crossfadeTo(payload.imageUrl, 600) }
            catch (err) { console.warn('[wizard-bridge] skybox load failed', err) }
        }
        overlay.update({ year: payload?.year, location: payload?.location })
        if (payload?.kind === 'game') {
            timer.show({ durationSeconds: payload.duration || 0 })
        } else {
            timer.hide()
        }
    })

    return { player, sequencer, skybox, overlay, timer }
}

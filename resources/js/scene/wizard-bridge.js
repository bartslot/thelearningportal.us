/**
 * Glue between Livewire dispatches and LessonScene modules.
 * Reuses the existing global window.__avatar3d (singleton initialized by avatar-3d.js).
 */
export function mountWizardScene({ canvasEl, overlayEl, timerEl, scenes }) {
    const Scene = window.LessonScene
    if (!Scene) {
        console.warn('[wizard-bridge] window.LessonScene not loaded')
        return null
    }

    const player = window.__avatar3d
    if (!player?.scene) {
        console.warn('[wizard-bridge] Avatar3DPlayer not initialized; aborting.')
        return null
    }

    const skybox  = new Scene.SkyboxSphere(player.scene)
    const overlay = new Scene.SceneOverlay(overlayEl); overlay.mount()
    const timer   = new Scene.GameTimerOverlay(timerEl)

    const adapter = {
        skybox,
        overlay,
        timer,
        avatar: {
            setClip: (clipId) => window.Livewire?.dispatch('avatar3d:setClip', { clipId }),
            speak: ({ audioUrl, alignment, text }) => new Promise(resolve => {
                window.Livewire?.dispatch('avatar3d:speak', { audioUrl, alignment, text })
                const handler = () => { window.removeEventListener('avatar3d:speakend', handler); resolve() }
                window.addEventListener('avatar3d:speakend', handler)
            }),
        },
    }

    const sequencer = new Scene.SceneTimelinePlayer({ scenes, ...adapter })

    // Livewire → bridge
    window.Livewire?.on('scene:load', async ({ payload }) => {
        if (payload?.imageUrl) {
            await skybox.crossfadeTo(payload.imageUrl, 600)
        }
        overlay.update({ year: payload?.year, location: payload?.location })
        if (payload?.kind === 'game') {
            timer.show({ durationSeconds: payload.duration || 0 })
        } else {
            timer.hide()
        }
    })

    return { sequencer, skybox, overlay, timer }
}

/**
 * animation-controller.js
 *
 * Manages avatar body animation using Three.js AnimationMixer.
 * Reads the animation controller JSON (from /api/v1/avatars/{id}/controller)
 * and handles: clip pool selection, crossfades, one-shot gesture/emotion
 * playback with automatic return to previous state, and graceful no-op for
 * any missing/empty slots.
 *
 * Usage:
 *   const ac = new AnimationController(mixer, controllerJson);
 *   await ac.loadClips(clipUrls);   // { '138_11': '/path/138_11.glb', ... }
 *   ac.trigger('walk');             // start walk loop
 *   ac.trigger('gesture');          // play once, auto-return to walk
 *   ac.update(deltaTime);           // call every frame
 */

import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';

export class AnimationController {
    #mixer;
    #clips         = {};   // { clip_id: THREE.AnimationClip }
    #controller;           // parsed controller JSON
    #currentSlot   = 'idle';
    #currentAction = null;
    #lastClipPerSlot  = {};  // { slot: clip_id } — anti-repeat for random mode
    #sequenceIndex    = {};  // { slot: number } — cursor for sequential mode
    #missingSlots     = new Set();

    /**
     * @param {THREE.AnimationMixer} mixer
     * @param {object} controllerJson — parsed controller JSON from the API
     */
    constructor(mixer, controllerJson) {
        this.#mixer      = mixer;
        this.#controller = controllerJson ?? { slots: {}, transitions: {} };
    }

    /**
     * Load all GLB clip files listed in clipUrls.
     * @param {Object.<string, string>} clipUrls — { clip_id: glb_url }
     */
    async loadClips(clipUrls) {
        const loader = new GLTFLoader();

        await Promise.all(
            Object.entries(clipUrls).map(async ([id, url]) => {
                try {
                    const gltf = await loader.loadAsync(url);
                    if (gltf.animations.length > 0) {
                        this.#clips[id] = gltf.animations[0];
                    } else {
                        console.warn(`[AnimationController] No animations in GLB for clip "${id}"`);
                    }
                } catch (err) {
                    console.warn(`[AnimationController] Failed to load clip "${id}":`, err.message);
                }
            })
        );
    }

    /**
     * Trigger a slot. Gracefully no-ops if the slot is unknown, empty, or
     * if the chosen clip failed to load. Fires animation:missingSlot once
     * per missing slot so the lesson UI can show a toast.
     *
     * @param {string} slot — e.g. 'walk', 'gesture', 'emotion_excited'
     * @param {number|null} fadeInDuration — override default crossfade
     */
    trigger(slot, fadeInDuration = null) {
        const transitions = this.#controller.transitions ?? {};

        // Guard 1: slot not defined in controller
        if (!this.#controller.slots?.[slot]) {
            this.#collectMissingSlot(slot);
            return;
        }

        const slotConfig = this.#controller.slots[slot];
        const pool       = slotConfig.clips ?? [];

        // Guard 2: pool is empty
        if (pool.length === 0) {
            this.#collectMissingSlot(slot);
            return;
        }

        const clipId = this.#pickClip(slot);

        // Guard 3: GLB failed to load
        if (!this.#clips[clipId]) {
            this.#collectMissingSlot(slot);
            return;
        }

        const isLooping = slot === 'idle' || slot === 'walk';

        const fadeIn = fadeInDuration ?? (
            slot === 'walk'  ? (transitions.walk_fade_in  ?? 0.2) :
            slot === 'idle'  ? (transitions.idle_fade_in  ?? 0.3) : 0.15
        );

        const clip      = this.#clips[clipId];
        const newAction = this.#mixer.clipAction(clip);

        if (isLooping) {
            newAction.setLoop(THREE.LoopRepeat, Infinity);
            newAction.clampWhenFinished = false;
        } else {
            newAction.setLoop(THREE.LoopOnce, 1);
            newAction.clampWhenFinished = true;
        }

        newAction.reset();

        if (this.#currentAction && this.#currentAction !== newAction) {
            this.#currentAction.crossFadeTo(newAction, fadeIn, false);
        }

        newAction.play();

        const previousSlot   = this.#currentSlot;
        this.#currentAction  = newAction;
        this.#currentSlot    = slot;

        // One-shot animations return to previous slot when finished
        if (!isLooping) {
            const fadeOut = slot.startsWith('emotion_')
                ? (transitions.emotion_fade_out ?? 0.5)
                : (transitions.gesture_fade_out ?? 0.3);

            const onFinished = (event) => {
                if (event.action !== newAction) return;
                this.#mixer.removeEventListener('finished', onFinished);
                // Return to the slot we were in before (fall back to idle)
                const returnSlot = (previousSlot === slot) ? 'idle' : previousSlot;
                this.trigger(returnSlot, fadeOut);
            };

            this.#mixer.addEventListener('finished', onFinished);
        }
    }

    /**
     * Stop the current animation and crossfade back to idle.
     * @param {number|null} fadeOutDuration
     */
    stop(fadeOutDuration = null) {
        const transitions = this.#controller.transitions ?? {};
        this.trigger('idle', fadeOutDuration ?? (transitions.idle_fade_in ?? 0.3));
    }

    /**
     * Must be called every frame with the frame delta time.
     * @param {number} deltaTime — seconds since last frame
     */
    update(deltaTime) {
        this.#mixer.update(deltaTime);
    }

    // ── Private ─────────────────────────────────────────────────────────────

    #pickClip(slot) {
        const slotConfig = this.#controller.slots[slot];
        const clips      = slotConfig.clips;
        const mode       = slotConfig.mode ?? 'random';

        if (clips.length === 1) {
            this.#lastClipPerSlot[slot] = clips[0];
            return clips[0];
        }

        if (mode === 'sequential') {
            const idx    = this.#sequenceIndex[slot] ?? 0;
            const clipId = clips[idx % clips.length];
            this.#sequenceIndex[slot]   = (idx + 1) % clips.length;
            this.#lastClipPerSlot[slot] = clipId;
            return clipId;
        }

        // random — anti-repeat: exclude the last-played clip
        const last      = this.#lastClipPerSlot[slot];
        const available = clips.filter(id => id !== last);
        const pool      = available.length > 0 ? available : clips;
        const clipId    = pool[Math.floor(Math.random() * pool.length)];
        this.#lastClipPerSlot[slot] = clipId;
        return clipId;
    }

    #collectMissingSlot(slot) {
        if (this.#missingSlots.has(slot)) return;
        this.#missingSlots.add(slot);
        window.dispatchEvent(new CustomEvent('animation:missingSlot', {
            detail: { slot },
        }));
    }
}

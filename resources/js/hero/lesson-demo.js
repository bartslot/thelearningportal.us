// Hero storyboard: watch a lesson get made.
//
// Two questions answer themselves, the field of thumbnails accelerates into a wormhole, and the
// finished lesson lands as a card you can play. Show, do not tell — nothing here explains the
// product in words the visitor has to read.
//
// Pacing is the point. Every beat is separated by a real pause (`beat`), because a demo that cuts
// straight from one answer to the next reads as a loading screen rather than as somebody working.
// The timeline's timeScale only ramps once the warp is already under way — ramping it during the
// anticipation compresses the exact beat that makes the warp land.
//
// Locally, `window.heroDemo` exposes the settings live so the dev Test tools panel can dial the
// whole thing in without an edit-and-rebuild — see resources/views/livewire/dev/test-panel.blade.php.

import { gsap } from 'gsap';
import { createTimewarp } from './timewarp.js';
import { createCardwheel } from './cardwheel.js';

const STORAGE_KEY = 'hero-demo-settings';

/**
 * Four characters for the warp, so the effect can be judged rather than guessed at.
 *
 * They differ in the thing that actually matters here: how long a teacher can SEE any one picture
 * from the lesson. "hyperspace" is the most dramatic and the least readable; "procession" is the
 * most readable and the least dramatic. Pick with ?warp=<name>, or from the Test tools panel.
 */
export const VARIANTS = {
    // Order, chaos, order. The old flat wheel image rebuilt from individual cards — same size,
    // same gutter, all turned inward — then dragged into the middle one at a time, then put back
    // exactly where they were once the lesson is ready. Uses its own field (hero/cardwheel.js).
    cardwheel: {
        label: 'Cardwheel',
        field: 'cardwheel',
        inhale: 1.6, windUp: 3.4, brake: 1.5,
        warpGlow: 0.34, warpScale: 1, warpSpread: 1,
        warpSpin: 0.35,
        // Unused by this field, but the storyboard reads them for every variant.
        warpRate: 1, warpTrail: 1, warpDensity: 1,
        warpFirstGap: 1, warpCadence: 1,
    },
    // One card. A long wait. Another. A shorter wait. The gap collapses fast, so the build is
    // felt as a quickening rather than seen as a rate change.
    heartbeat: {
        label: 'Heartbeat',
        inhale: 1.7, windUp: 2.6, brake: 1.4,
        warpRate: 5.2, warpSpin: 1.9, warpTrail: 0.26, warpDensity: 0.7, warpGlow: 0.2,
        warpScale: 1.35, warpSpread: 1.05,
        warpFirstGap: 0.95, warpCadence: 0.74,
        surge: { at: 0.45, rate: 1.2, hold: 0.6 },
    },
    // Starts sooner and tightens gently: a steady swell into a thick, busy climax.
    cascade: {
        label: 'Cascade',
        inhale: 1.4, windUp: 3, brake: 1.4,
        warpRate: 6.2, warpSpin: 2.4, warpTrail: 0.2, warpDensity: 0.85, warpGlow: 0.24,
        warpScale: 1.15, warpSpread: 1,
        warpFirstGap: 0.7, warpCadence: 0.84,
    },
    // Almost no accelerando — an even stream throughout. The control: if the others do not beat
    // this, the rhythm is not earning its keep.
    metronome: {
        label: 'Metronome',
        inhale: 1.5, windUp: 2.4, brake: 1.3,
        warpRate: 5.6, warpSpin: 2.1, warpTrail: 0.22, warpDensity: 0.8, warpGlow: 0.2,
        warpScale: 1.2, warpSpread: 1,
        warpFirstGap: 0.42, warpCadence: 0.97,
    },
    // A long hang, then the floor gives way: the gap collapses hardest of the four and the last
    // second is a flood of lesson pictures.
    avalanche: {
        label: 'Avalanche',
        inhale: 2, windUp: 2.8, brake: 1.6,
        warpRate: 7, warpSpin: 2.9, warpTrail: 0.17, warpDensity: 0.9, warpGlow: 0.26,
        warpScale: 1.45, warpSpread: 1.1,
        warpFirstGap: 1.15, warpCadence: 0.66,
        surge: { at: 0.5, rate: 1, hold: 0.5 },
    },
};

export const DEFAULT_VARIANT = 'cardwheel';

/** Every number worth arguing about. The dev panel edits this shape and replays. */
export const DEFAULTS = {
    beat: 1.3, // pause between beats, seconds
    typeSpeed: 22, // characters per second
    inhale: 1.7, // held breath before the warp
    windUp: 1.8, // creeping drift tightening into real speed
    brake: 1.3, // coming out the other side
    // Both time scales sit below 1 and match: the story never speeds up, it just runs slow and
    // lets the field do the accelerating. Dialled in on the Test tools panel, 2026-08-01.
    timeScaleStart: 0.7,
    timeScaleWarp: 0.7,
    warpRate: 7.4, // depth e-folds per second at full warp
    warpSpin: 3.15, // radians per second the tunnel turns
    warpTrail: 0.16, // lower = longer streaks
    warpDensity: 0.9,
    warpGlow: 0.2,
    warpScale: 1,
    warpSpread: 1,
    warpFirstGap: 1.4,
    warpCadence: 0.9,
    variant: DEFAULT_VARIANT,
};

const CARET_BLINK = 0.55;

const TIMESCALE_SKIP = 14;

// Idle is a slow dusting of thumbnails behind the questions. It must not read as an animation
// yet — but it cannot be frozen either: the tube is 18000 deep, and if nothing drifts inward
// while the questions are being answered, the warp starts with every card still parked at the
// far end and never gets one close enough to actually look at. Settle is what you emerge
// into, calm enough to read a headline over. `trail` returns to a clean wipe: below 1 the canvas
// keeps painting navy over itself, which buries the hero's gradient under an opaque slab.
const IDLE = { speed: 0.16, spin: 0.04, alpha: 0.3, trail: 1, density: 0.22, glow: 0, gap: 2.2, cadence: 0.94 };
const SETTLE = { speed: 0.08, spin: 0.06, alpha: 0.12, trail: 1, density: 0.16, glow: 0 };

const prefersReducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Fold a variant's dials over the base settings. ?warp=<name> wins, so a link can show one. */
export const applyVariant = (settings, name) => {
    const variant = VARIANTS[name];

    if (!variant) {
        return settings;
    }

    const { label, surge, ...dials } = variant;

    return Object.assign(settings, { surge: null, field: 'tunnel' }, dials, { variant: name, surge });
};

const loadSettings = () => {
    let settings;

    try {
        settings = { ...DEFAULTS, ...JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') };
    } catch {
        settings = { ...DEFAULTS };
    }

    const requested = new URLSearchParams(window.location.search).get('warp');

    return applyVariant(settings, requested ?? settings.variant);
};

/** Types `text` into `target` by tweening a character index, so the timeline owns the pace. */
const typeInto = (timeline, target, text, position, charactersPerSecond) => {
    const cursor = { index: 0 };

    timeline.to(cursor, {
        index: text.length,
        duration: text.length / Math.max(1, charactersPerSecond),
        ease: 'none',
        onUpdate: () => {
            target.textContent = text.slice(0, Math.round(cursor.index));
        },
    }, position);
};

const loadAtlas = (img) => {
    if (img.complete && img.naturalWidth > 0) {
        return Promise.resolve(img);
    }

    return new Promise((resolve, reject) => {
        img.addEventListener('load', () => resolve(img), { once: true });
        img.addEventListener('error', reject, { once: true });
    });
};

/** The lesson has landed. The hero stops hiding its ending, and the site nav — which stepped
 *  back while the demo ran (see .site-nav in app.css) — comes forward again. */
const markRevealed = (root) => {
    root.classList.add('is-revealed');
    document.documentElement.classList.add('hero-revealed');
};

/** Put the hero back to "still building". Only the dev scrubber needs this: those two classes
 *  are one-way in normal playback, so scrubbing backwards past the reveal would otherwise leave
 *  the finished lesson sitting on top of the warp. */
const unmarkRevealed = (root) => {
    root.classList.remove('is-revealed');
    document.documentElement.classList.remove('hero-revealed');
};

const heroParts = (root) => ({
    goal: root.querySelector('[data-demo-step="goal"]'),
    audience: root.querySelector('[data-demo-step="audience"]'),
    typed: root.querySelector('[data-demo-typed]'),
    caret: root.querySelector('[data-demo-caret]'),
    chips: Array.from(root.querySelectorAll('[data-demo-chip]')),
    picked: root.querySelector('[data-demo-chip][data-demo-chip-pick]'),
    conversation: root.querySelector('[data-demo-conversation]'),
    reveal: root.querySelector('[data-demo-reveal]'),
    revealItems: Array.from(root.querySelectorAll('[data-demo-reveal] [data-reveal-item]')),
    // The static ring of thumbnails behind the hero. It is the same pictures the tunnel is made
    // of, so it goes down the tunnel with everything else and never comes back.
    wheel: root.querySelector('.wheel'),
});

const buildStoryboard = (root, warp, settings) => {
    const {
        goal, audience, typed, caret, chips, picked,
        conversation, reveal, revealItems, wheel,
    } = heroParts(root);

    const { beat } = settings;
    const master = gsap.timeline({ paused: true, defaults: { ease: 'power2.out' } });

    gsap.set([goal, audience], { autoAlpha: 0, y: 14 });
    gsap.set(chips, { autoAlpha: 0, y: 10, scale: 0.94 });
    gsap.set(revealItems, { autoAlpha: 0, y: 26, scale: 1.06 });
    gsap.set(reveal, { autoAlpha: 0 });
    gsap.set(conversation, { autoAlpha: 1, scale: 1 });
    gsap.set(caret, { autoAlpha: 1 });
    chips.forEach((chip) => chip.classList.remove('is-picked'));
    typed.textContent = '';

    if (wheel) {
        // The cardwheel field DRAWS the wheel, card by card, so the flat picture of one would sit
        // behind it as a ghost. Every other field still wants it as the ambient backdrop.
        gsap.set(wheel, settings.field === 'cardwheel'
            ? { autoAlpha: 0 }
            : { autoAlpha: 0.56, scale: 1 });
    }

    const caretPulse = gsap.to(caret, {
        autoAlpha: 0,
        duration: CARET_BLINK,
        repeat: -1,
        yoyo: true,
        ease: 'none',
    });

    // ── The questions answer themselves ─────────────────────────────────────
    master
        .to(goal, { autoAlpha: 1, y: 0, duration: 0.6 }, 0.4)
        // A real pause before anything is typed: somebody is thinking.
        .add('typing', `>+${beat}`);

    typeInto(master, typed, typed.dataset.demoTyped ?? '', 'typing', settings.typeSpeed);

    master
        .to(audience, { autoAlpha: 1, y: 0, duration: 0.5 }, `>+${beat}`)
        .to(chips, {
            autoAlpha: 1,
            y: 0,
            scale: 1,
            duration: 0.45,
            stagger: 0.09,
            ease: 'back.out(1.7)',
        }, `>+${beat * 0.6}`)
        // The choice makes itself, but only after a beat of deliberation.
        .call(() => picked?.classList.add('is-picked'), null, `>+${beat}`)
        .to(picked, { scale: 1.06, duration: 0.24, ease: 'back.out(2.4)' }, '<')
        .to(chips.filter((chip) => chip !== picked), {
            autoAlpha: 0.28,
            scale: 0.96,
            duration: 0.32,
        }, '<')
        .to(picked, { scale: 1, duration: 0.22 }, '>');

    // ── The warp ────────────────────────────────────────────────────────────
    //
    // Every dial uses an "in" ease, so the first seconds barely move and the last half-second is
    // violent. The inhale before it is the whole trick: without a floor to push off from, the
    // warp reads as "fast" and never as "here it comes".
    // Positions are always built from ONE offset off the `warp` label. Composing them as
    // afterWarp(launchAt + 0.5) produced "warp+=1.6+=0.5", which GSAP cannot parse — tweens silently
    // landed in the wrong place and the burst ran ahead of the implosion that feeds it.
    const afterWarp = (seconds) => `warp+=${Math.max(0, seconds)}`;
    const launchAt = settings.inhale;
    const brakeAt = settings.inhale + settings.windUp;
    const launch = afterWarp(launchAt);
    const brake = afterWarp(brakeAt);

    if (settings.field === 'cardwheel') {
        // The whole implosion is one number. `pull` eased in means the first card is tugged away
        // slowly and the last few are snatched — one at a time, accelerating, in ring order.
        master
            .addLabel('warp', `>+${beat}`)
            .call(() => caretPulse.pause(), null, 'warp')
            .to(conversation, {
                autoAlpha: 0,
                scale: 0.6,
                duration: 1.1,
                ease: 'power2.in',
            }, launch)
            .to(warp.state, {
                pull: 1,
                duration: settings.windUp,
                ease: 'power2.in',
            }, launch)
            .to(warp.state, {
                spin: settings.warpSpin,
                duration: settings.windUp,
                ease: 'power2.in',
            }, launch)
            .to(warp.state, {
                glow: settings.warpGlow,
                duration: settings.windUp * 0.8,
                ease: 'power3.in',
            }, launch)
            // The stock wheel has gone in; now the lesson itself comes back out. Overlapped on
            // purpose — the last few ring cards are still falling as the first Tasman picture
            // arrives, which is what makes it one event rather than two.
            .to(warp.state, {
                burst: 1,
                duration: settings.windUp * 0.75,
                ease: 'power1.in',
            }, afterWarp(launchAt + settings.windUp * 0.45))
            // The last card goes in and the middle flares.
            .to(warp.state, { glow: 0.95, duration: 0.3, ease: 'power2.out' }, brake)
            .to(warp.state, { glow: 0, duration: 1, ease: 'power2.out' }, afterWarp(brakeAt + 0.3));
    } else {

        master
            .addLabel('warp', `>+${beat}`)
            .call(() => caretPulse.pause(), null, 'warp')
            // Restart the rhythm: one card, a long pause, then each gap shorter than the last.
            .set(warp.state, {
                gap: settings.warpFirstGap ?? 1.4,
                cadence: settings.warpCadence ?? 0.9,
            }, 'warp')
            .to(warp.state, { speed: 0.004, duration: settings.inhale, ease: 'power2.out' }, 'warp')
            .to(warp.state, { glow: 0.07, duration: settings.inhale, ease: 'none' }, 'warp')
            .to(conversation, { scale: 0.97, duration: settings.inhale, ease: 'power2.out' }, 'warp')
            .call(() => {
                gsap.to(master, {
                    timeScale: settings.timeScaleWarp,
                    duration: settings.windUp * 0.6,
                    ease: 'power2.in',
                });
            }, null, `warp+=${settings.inhale + settings.windUp * 0.35}`)
            // The questions are pulled into the mouth with everything else.
            .to(conversation, {
                autoAlpha: 0,
                scale: 0.55,
                duration: 1.3,
                ease: 'power2.in',
            }, afterWarp(launchAt + 1.1))
            .to(wheel, {
                autoAlpha: 0,
                scale: 2.6,
                duration: 1.1,
                ease: 'power2.in',
                overwrite: true,
            }, launch);

        // The acceleration itself. A variant with a `surge` climbs, eases off long enough to let the
        // eye catch what just went past, then commits — which is what makes the second half land.
        if (settings.surge) {
            const climb = settings.windUp * settings.surge.at;

            master
                .to(warp.state, {
                    speed: settings.surge.rate,
                    duration: climb,
                    ease: 'power3.in',
                }, launch)
                .to(warp.state, {
                    speed: settings.surge.rate * 0.55,
                    duration: settings.surge.hold,
                    ease: 'power2.out',
                }, afterWarp(launchAt + climb))
                .to(warp.state, {
                    speed: settings.warpRate,
                    duration: settings.windUp - climb - settings.surge.hold,
                    ease: 'power4.in',
                }, afterWarp(launchAt + climb + settings.surge.hold));
        } else {
            master.to(warp.state, {
                speed: settings.warpRate,
                duration: settings.windUp,
                ease: 'power4.in',
            }, launch);
        }

        master
            // Rotation lags the travel slightly, so the corkscrew arrives after the speed does.
            .to(warp.state, {
                spin: settings.warpSpin,
                duration: settings.windUp * 0.9,
                ease: 'power3.in',
            }, afterWarp(launchAt + 0.5))
            .to(warp.state, {
                density: settings.warpDensity,
                alpha: 1,
                duration: 1.6,
                ease: 'power2.out',
            }, launch)
            // How big and how wide this variant flies — the difference between cards you can read
            // and cards that are only texture.
            .to(warp.state, {
                scale: settings.warpScale ?? 1,
                spread: settings.warpSpread ?? 1,
                duration: settings.windUp * 0.8,
                ease: 'power2.inOut',
            }, launch)
            // Streaks only once there is something to streak.
            .to(warp.state, {
                trail: settings.warpTrail,
                duration: settings.windUp * 0.75,
                ease: 'power3.in',
            }, afterWarp(launchAt + 0.8))
            .to(warp.state, {
                glow: settings.warpGlow,
                duration: settings.windUp * 0.7,
                ease: 'power3.in',
            }, afterWarp(launchAt + 1))

            // ── Breaking through ────────────────────────────────────────────────
            // The mouth flares, the field brakes hard, and the streaks resolve back into stillness.
            .to(warp.state, { glow: 0.95, duration: 0.28, ease: 'power2.out' }, brake)
            .to(warp.state, {
                speed: SETTLE.speed,
                spin: SETTLE.spin,
                duration: settings.brake,
                ease: 'power4.out',
            }, brake)
            .to(warp.state, {
                alpha: SETTLE.alpha,
                density: SETTLE.density,
                trail: SETTLE.trail,
                scale: 1,
                spread: 1,
                duration: settings.brake * 0.85,
                ease: 'power2.out',
            }, afterWarp(brakeAt + 0.2))
            .to(warp.state, { glow: 0, duration: 0.9, ease: 'power2.out' }, afterWarp(brakeAt + 0.3))
            .call(() => {
                gsap.to(master, {
                    timeScale: settings.timeScaleStart,
                    duration: 0.8,
                    ease: 'power2.out',
                });
            }, null, brake);

    }

    // ── The lesson lands ────────────────────────────────────────────────────
    // It arrives out of the tunnel: the card first, then the words. Slightly overscaled,
    // settling back as the flare dies.
    master
        .addLabel('reveal', afterWarp(brakeAt + 0.35))
        .set(reveal, { autoAlpha: 1 }, 'reveal')
        // The tunnel closes behind you: everything still in flight is sent back to the far end,
        // so the headline is never read through a card that happens to be passing.
        .call(() => warp.recede(), null, 'reveal')
        .call(() => markRevealed(root), null, 'reveal')
        .call(() => caretPulse.kill(), null, 'reveal')
        .to(revealItems, {
            autoAlpha: 1,
            y: 0,
            scale: 1,
            duration: 0.9,
            stagger: 0.16,
            ease: 'expo.out',
        }, 'reveal');

    // Order restored: the ring reassembles, every card back in the seat it left. Running `pull`
    // home is literally the implosion in reverse, which is why nothing can land out of place.
    if (settings.field === 'cardwheel') {
        master
            .to(warp.state, {
                pull: 0,
                duration: 2.2,
                ease: 'power3.out',
            }, 'reveal')
            .to(warp.state, {
                spin: 0.02,
                alpha: 0.55,
                duration: 2,
                ease: 'power2.out',
            }, 'reveal');
    }

    master.heroCaretPulse = caretPulse;

    return master;
};

/** Jump the whole hero to its finished state — reduced motion, or a visitor who skipped. */
const showFinalState = (root) => {
    const { conversation, reveal, revealItems } = heroParts(root);

    gsap.set(conversation, { autoAlpha: 0 });
    gsap.set(reveal, { autoAlpha: 1 });
    gsap.set(revealItems, { autoAlpha: 1, y: 0, scale: 1 });
    markRevealed(root);
};

export const setupHeroLessonDemo = async () => {
    const root = document.querySelector('[data-demo]');

    if (!root) {
        return;
    }

    const canvas = root.querySelector('[data-timewarp-canvas]');
    const atlas = root.querySelector('[data-timewarp-atlas]');

    if (prefersReducedMotion()) {
        showFinalState(root);

        return;
    }

    // Settings first: which field to build is a setting (the cardwheel is a different animal
    // from the tunnel, see hero/cardwheel.js).
    const settings = loadSettings();
    let warp = null;

    if (canvas && atlas) {
        try {
            // The lesson's own sheet is optional and must never hold up the hero: if it is
            // missing or slow, the tunnel runs on the generic cards alone.
            const lessonImg = root.querySelector('[data-timewarp-lesson-atlas]');
            const lessonAtlas = lessonImg
                ? await loadAtlas(lessonImg)
                    .then((image) => ({ image, cells: Number(lessonImg.dataset.cells) || 0 }))
                    .catch(() => null)
                : null;

            const build = settings.field === 'cardwheel' ? createCardwheel : createTimewarp;

            warp = build(canvas, await loadAtlas(atlas), {
                lessonAtlas: lessonAtlas?.cells ? lessonAtlas : null,
            });

            if (warp && settings.field === 'cardwheel') {
                // Hide it before the first frame, not on the first tween, or the flat wheel
                // flashes behind the drawn one.
                gsap.set(root.querySelector('.wheel'), { autoAlpha: 0 });
            }
        } catch {
            // A missing sprite must not cost the visitor the hero: fall through without particles.
            warp = null;
        }
    }

    // Without the canvas there is nothing to warp through, so skip straight to the lesson.
    if (!warp) {
        showFinalState(root);

        return;
    }

    let master = null;

    const start = () => {
        master?.heroCaretPulse?.kill();
        master?.kill();
        gsap.killTweensOf(warp.state);
        root.classList.remove('is-revealed');
        document.documentElement.classList.remove('hero-revealed');
        Object.assign(warp.state, IDLE);
        warp.restart();

        master = buildStoryboard(root, warp, settings);
        master.timeScale(settings.timeScaleStart).play();

        return master;
    };

    start();

    const skip = () => {
        if (root.classList.contains('is-revealed')) {
            return;
        }

        gsap.to(master, { timeScale: TIMESCALE_SKIP, duration: 0.3, ease: 'power1.in' });
    };

    root.querySelector('[data-demo-skip]')?.addEventListener('click', skip);

    // Live controls, but only where the dev Test tools panel is actually rendered (local, signed
    // in). No point shipping a tuning API to visitors with nothing to tune it with.
    if (document.querySelector('[data-dev-panel]')) {
        window.heroDemo = {
            settings,
            defaults: { ...DEFAULTS },
            variants: Object.fromEntries(
                Object.entries(VARIANTS).map(([name, v]) => [name, v.label]),
            ),
            setVariant: (name) => {
                applyVariant(settings, name);
                localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
                start();
            },
            state: warp.state,
            // The engine itself: `field.advance(seconds)` steps the tunnel by hand, which is the
            // only way to inspect it in a browser whose rAF is asleep (see the timewarp tests).
            field: warp,
            replay: start,
            play: () => master?.play(),
            pause: () => master?.pause(),
            seek: (fraction) => {
                if (! master) {
                    return;
                }

                const target = gsap.utils.clamp(0, 1, fraction);
                master.pause();
                master.progress(target);

                // The reveal flips two classes that a backward seek cannot undo on its own.
                const revealed = master.duration() * target >= master.labels.reveal;
                (revealed ? markRevealed : unmarkRevealed)(root);
            },
            progress: () => master?.progress() ?? 0,
            duration: () => master?.duration() ?? 0,
            save: () => localStorage.setItem(STORAGE_KEY, JSON.stringify(settings)),
            reset: () => {
                Object.assign(settings, DEFAULTS);
                localStorage.removeItem(STORAGE_KEY);
                start();
            },
        };
    }
};

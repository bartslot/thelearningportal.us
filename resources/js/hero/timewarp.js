// Hero timewarp — lesson pictures launched one at a time, out of the middle and past your head.
//
// Every card flies its OWN flight. That is the whole design, and the thing three earlier versions
// got wrong: when the field shares one clock, every card grows at the same moment and the effect
// reads as one burst that swells and fades together. Here each card has its own launch moment,
// its own duration and its own exit direction, so they arrive one by one and overlap the way real
// objects passing a camera do.
//
// A flight runs from the vanishing point to somewhere off the edge of the screen:
//
//   progress 0 ── a speck in the centre dot
//   progress ↑ ── depth collapses (log-eased), so it grows and drifts outward, accelerating
//   progress 1 ── past the lens, off-frame, retired and relaunched later
//
// Growth and outward travel come from the same projection (Z_REF / z), which is why a card looks
// like it is coming at you rather than merely scaling up. Fast growth smears it along its own
// direction of travel — a real directional motion blur, not a filter.
//
// Cards are cells of two sprite sheets: the generic history set (public/assets/timewarp-cards.avif)
// and, when it exists, the lesson's own artwork packed by `lessons:build-warp-atlas`.
//
// The engine owns no storyboard. It exposes a plain `state` object that GSAP tweens
// (see hero/lesson-demo.js).

import { gsap } from 'gsap';
import { EASING } from '../easing.js';

const ATLAS_COLS = 4;
const ATLAS_ROWS = 6;
const CELL_COUNT = ATLAS_COLS * ATLAS_ROWS;

// How often a card comes from the lesson's own atlas rather than the generic history set. High
// on purpose: the tunnel should be recognisably about the lesson being built.
const LESSON_CELL_BIAS = 0.72;

const TAU = Math.PI * 2;

// Depth. Size and distance-from-centre both scale by Z_REF / z, measured in fractions of the
// viewport so a narrow window gets the same composition as a wide one. Z_FAR is deliberately
// enormous: a card starting there projects to a couple of pixels on the axis, so it is born
// inside the centre dot and grows out of it.
const Z_REF = 1600;
const Z_NEAR = 110;
const Z_FAR = 18000;

// Where a flight aims. Cards leave through the edge of the frame, never toward the exact centre
// of it, so the middle stays open — that hole is what makes it a tunnel and not confetti.
const EXIT_MIN_RATIO = 0.14;
const EXIT_MAX_RATIO = 0.55;

// Card size at Z_REF, as a share of the short edge. Generous on purpose: one card at a time is
// passing, so it can afford to be big enough to actually recognise.
const CARD_MIN_RATIO = 0.085;
const CARD_MAX_RATIO = 0.155;

// A flight's own length in seconds at unit speed. Varying it per card is what stops the field
// from pulsing in lockstep even when several launch close together.
const FLIGHT_MIN_SECONDS = 3.4;
const FLIGHT_MAX_SECONDS = 6.2;

// The launch rhythm is an accelerando, not a rate: one card, a pause, another card, a slightly
// shorter pause, and so on until they are pouring out. `state.gap` is the current wait and
// `state.cadence` is what multiplies it after every launch — 1 is a metronome, 0.7 collapses.
const LAUNCH_GAP_MIN = 0.05;

// Motion blur. Once a card grows by more than this share of its own size in one frame it is
// smeared backwards along its direction of travel, in this many steps.
const BLUR_THRESHOLD = 0.035;
const BLUR_SAMPLES = 4;
// Weights for the motion-blur copies: a solid head with a fading tail, which is what a real
// smear looks like. Splitting the alpha evenly across the samples (the obvious way) makes a fast
// card 25% opaque and washes the picture out to a ghost.
const BLUR_WEIGHTS = [0.6, 0.21, 0.12, 0.07];

const BLUR_MAX_SPAN = 0.9; // of the card's size, at the most violent growth

// A card past the lens is far off-frame; this is only a fill-rate backstop.
const CULL_SIZE_RATIO = 1.8;

// Appearing and disappearing are always fades, never pops, and they are eased by intent —
// decelerate in, accelerate away — per resources/js/easing.js. The exit runs at ~75% of the
// entry, which is the house rule for every exit in the app.
const FADE_IN_SECONDS = 0.7;
const FADE_OUT_SECONDS = 0.53;

const CORNER_RADIUS_RATIO = 0.19; // matches the rounded squares in the Figma wheel
const GLOW_RADIUS_RATIO = 0.55; // of the smaller viewport edge

const DESKTOP_PARTICLES = 90;
const MOBILE_PARTICLES = 44;
const MOBILE_BREAKPOINT = 768;
const MAX_PIXEL_RATIO = 2;
const MAX_FRAME_SECONDS = 1 / 20; // clamp so a backgrounded tab cannot teleport the field

/** One offscreen canvas per atlas cell, pre-masked with rounded corners so the draw loop
 *  never has to clip. Clipping every sprite every frame is what makes this kind of effect stutter. */
const sliceAtlas = (image, cols = ATLAS_COLS, rows = ATLAS_ROWS, count = null) => {
    const cellWidth = image.naturalWidth / cols;
    const cellHeight = image.naturalHeight / rows;
    const size = Math.round(Math.min(cellWidth, cellHeight));
    const radius = size * CORNER_RADIUS_RATIO;

    return Array.from({ length: count ?? cols * rows }, (_, index) => {
        const cell = document.createElement('canvas');
        cell.width = size;
        cell.height = size;

        const ctx = cell.getContext('2d');

        if (!ctx) {
            return null;
        }

        ctx.beginPath();
        // roundRect is Chrome 99 / Safari 16.4; older engines simply get square cards.
        if (typeof ctx.roundRect === 'function') {
            ctx.roundRect(0, 0, size, size, radius);
        } else {
            ctx.rect(0, 0, size, size);
        }
        ctx.closePath();
        ctx.clip();
        ctx.drawImage(
            image,
            (index % cols) * cellWidth,
            Math.floor(index / cols) * cellHeight,
            cellWidth,
            cellHeight,
            0,
            0,
            size,
            size,
        );

        return cell;
    }).filter(Boolean);
};

/** Depth for a point in the flight. Log-interpolated and eased, so a card lingers as a speck in
 *  the middle and only then rushes out — the slow-then-sudden that reads as coming at you. */
const depthAt = (progress) => Z_FAR * ((Z_NEAR / Z_FAR) ** EASING.easeInQuad(progress));

/**
 * @param {HTMLCanvasElement} canvas
 * @param {HTMLImageElement} image  the loaded generic sprite sheet
 * @param {{lessonAtlas?: {image: HTMLImageElement, cells: number}}} options
 */
export const createTimewarp = (canvas, image, options = {}) => {
    const ctx = canvas.getContext('2d', { alpha: true });
    const cells = ctx ? sliceAtlas(image) : [];

    if (!ctx || cells.length === 0) {
        return null;
    }

    // The lesson's own pictures, packed by `lessons:build-warp-atlas`. When they are there the
    // tunnel is made mostly of THIS lesson — Tasman's fleet, the Golden Bay encounter, the 1644
    // chart — instead of stock history cards. That is the difference between a decorative effect
    // and watching the lesson you asked for being assembled.
    const lessonCells = options.lessonAtlas
        ? sliceAtlas(
            options.lessonAtlas.image,
            ATLAS_COLS,
            Math.ceil(options.lessonAtlas.cells / ATLAS_COLS),
            options.lessonAtlas.cells,
        )
        : [];

    const pickCell = () => (lessonCells.length && Math.random() < LESSON_CELL_BIAS
        ? lessonCells[Math.floor(Math.random() * lessonCells.length)]
        : cells[Math.floor(Math.random() * cells.length)]);

    // The dials. GSAP owns these; the loop only reads them.
    const state = {
        speed: 0.16, // how fast flights run, and how tightly they are launched
        spin: 0.04, // radians per second the whole field turns on the axis
        alpha: 0.3, // master opacity
        trail: 1, // 1 = wipe the frame clean, lower = longer motion streaks
        density: 0.5, // share of the pool allowed in the air at once
        gap: 1.4, // seconds until the next launch
        cadence: 0.9, // what the gap is multiplied by after each launch (<1 accelerates)
        glow: 0, // brightness of the mouth you are heading into
        scale: 1, // multiplier on card size
        spread: 1, // multiplier on how far out a flight aims
    };

    const count = window.innerWidth < MOBILE_BREAKPOINT ? MOBILE_PARTICLES : DESKTOP_PARTICLES;

    /** A retired card, waiting for its turn. */
    const makeParticle = () => ({
        active: false,
        progress: 1,
        fade: 0,
        target: 0,
        hiding: false,
        z: Z_FAR,
        lastSize: 0,
    });

    const particles = Array.from({ length: count }, makeParticle);

    /** Send one card on a fresh flight: its own direction, distance, size, length and picture. */
    const launch = (particle) => {
        particle.active = true;
        particle.progress = 0;
        particle.fade = 0;
        particle.hiding = false;
        particle.z = Z_FAR;
        particle.lastSize = 0;
        particle.cell = pickCell();
        particle.angle = Math.random() * TAU;
        // sqrt keeps exit points spread evenly over the frame instead of hugging the middle.
        particle.exitRatio = EXIT_MIN_RATIO
            + Math.sqrt(Math.random()) * (EXIT_MAX_RATIO - EXIT_MIN_RATIO);
        particle.sizeRatio = gsap.utils.random(CARD_MIN_RATIO, CARD_MAX_RATIO);
        particle.duration = gsap.utils.random(FLIGHT_MIN_SECONDS, FLIGHT_MAX_SECONDS);
        particle.roll = gsap.utils.random(-0.25, 0.25);
        particle.rollSpeed = gsap.utils.random(-0.3, 0.3);
    };

    let width = 0;
    let height = 0;
    let lastTime = 0;
    let fieldAngle = 0;
    let sinceLaunch = 0;

    const resize = () => {
        const ratio = Math.min(window.devicePixelRatio || 1, MAX_PIXEL_RATIO);
        const rect = canvas.getBoundingClientRect();

        width = Math.max(1, Math.round(rect.width));
        height = Math.max(1, Math.round(rect.height));
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    };

    const wipe = () => {
        if (state.trail >= 0.999) {
            ctx.clearRect(0, 0, width, height);

            return;
        }

        // Painting the previous frame down instead of clearing it is what turns fast cards into
        // streaks. Because the cards travel radially outward, the smear points outward too.
        ctx.fillStyle = `rgba(2, 8, 26, ${state.trail})`;
        ctx.fillRect(0, 0, width, height);
    };

    /** Release cards one at a time, each after a shorter wait than the last. This is the stagger
     *  AND the build: a single card, a pause, another, a shorter pause, until they come together.
     *  `density` caps how many may be in the air at once, so raising it thickens the climax
     *  without touching the rhythm that gets you there. */
    const scheduleLaunches = (seconds) => {
        sinceLaunch += seconds;

        // No floor: density 0 means an empty sky, which is what the reveal asks for.
        const maxInFlight = Math.round(particles.length * state.density);

        while (sinceLaunch >= state.gap) {
            sinceLaunch -= state.gap;

            if (particles.filter((particle) => particle.active).length >= maxInFlight) {
                sinceLaunch = 0;
                break;
            }

            const idle = particles.find((particle) => !particle.active);

            if (!idle) {
                sinceLaunch = 0;
                break;
            }

            launch(idle);
            state.gap = Math.max(LAUNCH_GAP_MIN, state.gap * state.cadence);
        }
    };

    /** Ease a card's own fade toward where it wants to be. Nothing appears or disappears without
     *  one — decelerate in, accelerate away. */
    const updateFade = (particle, seconds) => {
        const wanted = particle.active && !particle.hiding;
        const target = wanted ? 1 : 0;
        const rate = target === 1 ? seconds / FADE_IN_SECONDS : -seconds / FADE_OUT_SECONDS;

        particle.fade = gsap.utils.clamp(0, 1, particle.fade + rate);
        particle.target = target;

        if (particle.hiding && particle.fade === 0) {
            particle.active = false;
            particle.hiding = false;
        }
    };

    /** Smear the card backwards along its own line of travel. Real directional blur: several
     *  cheap copies, not a filter, and only while it is actually growing fast. */
    const drawBlurred = (particle, x, y, size, angle, alpha, growth) => {
        const cell = particle.cell;
        const blur = gsap.utils.clamp(0, 1, (growth - BLUR_THRESHOLD) / 0.25);

        ctx.save();
        ctx.globalAlpha = alpha;
        ctx.translate(x, y);
        ctx.rotate(angle + particle.roll);

        if (blur <= 0) {
            ctx.drawImage(cell, -size / 2, -size / 2, size, size);
            ctx.restore();

            return;
        }

        // The trail points back toward the centre, which is where the card came from.
        const span = size * BLUR_MAX_SPAN * blur;

        for (let sample = 0; sample < BLUR_SAMPLES; sample += 1) {
            const back = (span * sample) / BLUR_SAMPLES;
            const shrink = 1 - (0.16 * sample) / BLUR_SAMPLES;
            const drawn = size * shrink;
            ctx.globalAlpha = alpha * BLUR_WEIGHTS[sample];
            ctx.drawImage(cell, -drawn / 2 - back, -drawn / 2, drawn, drawn);
        }

        ctx.restore();
    };

    const drawParticle = (particle) => {
        if (particle.fade <= 0.001 || !particle.cell) {
            return;
        }

        const minDimension = Math.min(width, height);
        const projection = Z_REF / particle.z;
        const size = particle.sizeRatio * state.scale * minDimension * projection;

        if (size < 2 || size > minDimension * CULL_SIZE_RATIO) {
            particle.lastSize = size;

            return;
        }

        const angle = particle.angle + fieldAngle;
        // Where this flight is headed: its own exit point, reached as depth collapses.
        const screenRadius = particle.exitRatio * state.spread * minDimension * projection;
        const x = width / 2 + Math.cos(angle) * screenRadius;
        const y = height / 2 + Math.sin(angle) * screenRadius;

        if (x < -size || x > width + size || y < -size || y > height + size) {
            particle.lastSize = size;

            return;
        }

        // Decelerate in, accelerate away — EASING, not linear (resources/js/easing.js).
        const eased = particle.target === 1
            ? EASING.easeOutCubic(particle.fade)
            : EASING.easeInCubic(particle.fade);
        const growth = particle.lastSize > 0 ? (size - particle.lastSize) / particle.lastSize : 0;

        drawBlurred(particle, x, y, size, angle, state.alpha * eased, growth);
        particle.lastSize = size;
    };

    /** The mouth of the wormhole: a bloom on the axis, brightest at the moment you arrive. */
    const drawGlow = () => {
        if (state.glow <= 0.002) {
            return;
        }

        const radius = Math.min(width, height) * GLOW_RADIUS_RATIO;
        const gradient = ctx.createRadialGradient(
            width / 2, height / 2, 0,
            width / 2, height / 2, radius,
        );
        gradient.addColorStop(0, 'rgba(186, 230, 253, 0.9)');
        gradient.addColorStop(0.35, 'rgba(56, 189, 248, 0.28)');
        gradient.addColorStop(1, 'rgba(56, 189, 248, 0)');

        ctx.save();
        ctx.globalCompositeOperation = 'lighter';
        ctx.globalAlpha = gsap.utils.clamp(0, 1, state.glow);
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, width, height);
        ctx.restore();
    };

    const render = (time) => {
        // Clamped at BOTH ends. The ceiling stops a backgrounded tab from teleporting the field;
        // the floor matters just as much, because a delta that goes negative — the ticker's clock
        // jumping backwards relative to the last frame — would run every flight in reverse.
        const seconds = lastTime
            ? gsap.utils.clamp(0, MAX_FRAME_SECONDS, (time - lastTime) / 1000)
            : 0;
        lastTime = time;

        fieldAngle = (fieldAngle + state.spin * seconds) % TAU;
        scheduleLaunches(seconds);

        wipe();

        particles.forEach((particle) => {
            updateFade(particle, seconds);

            if (!particle.active) {
                return;
            }

            // Each flight runs on its OWN clock. `speed` scales them all, which is what the warp
            // ramps — but no two cards share a moment of growth.
            particle.progress += (seconds * state.speed) / particle.duration;
            particle.roll += particle.rollSpeed * seconds;
            particle.z = depthAt(Math.min(1, particle.progress));

            if (particle.progress >= 1) {
                // Past the lens and far off-frame: retire it, to be launched again later.
                particle.active = false;
                particle.fade = 0;

                return;
            }

            drawParticle(particle);
        });

        drawGlow();
    };

    const tick = () => render(gsap.ticker.time * 1000);

    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(resize) : null;
    observer?.observe(canvas);
    window.addEventListener('resize', resize, { passive: true });

    resize();
    gsap.ticker.add(tick);

    return {
        state,
        particles,
        /** Start the field over: empty sky, cards launching one at a time again. */
        restart() {
            sinceLaunch = 0;
            state.gap = 1.4;
            particles.forEach((particle) => Object.assign(particle, makeParticle()));
        },
        /** Clear the sky: everything in flight fades out on its own easing, and nothing new is
         *  launched until `density` opens again. Used when the lesson lands, so the headline is
         *  never read through a card that happens to be passing. */
        recede() {
            particles.forEach((particle) => {
                if (particle.active) {
                    particle.hiding = true;
                }
            });
        },
        /** Drive the field by a fixed step instead of the ticker. Deterministic, so a test — or
         *  a headless browser whose rAF is asleep — can render the warp frame by frame. */
        advance(seconds) {
            render((lastTime || 0) + seconds * 1000);
        },
        destroy() {
            gsap.ticker.remove(tick);
            observer?.disconnect();
            window.removeEventListener('resize', resize);
            ctx.clearRect(0, 0, width, height);
        },
    };
};

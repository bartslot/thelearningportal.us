// Hero cardwheel — the ring of lesson pictures, dragged into the middle one by one, then put back.
//
// The old hero showed a single flat image of a wheel of cards (public/assets/videocards.webp).
// This rebuilds that wheel out of individual sprites so each card can move on its own, and keeps
// what made the picture work: every card the same size, every gap identical, every card turned to
// face the centre. Order is the point — it is what makes the implosion read as chaos, and what
// makes the lesson landing feel like order restored.
//
// The whole animation is a single number. `state.pull` runs 0 → 1 and back:
//
//   pull 0.0 ── every card parked on the ring, evenly spaced
//   pull ↑   ── cards peel off one at a time, in ring order, and are dragged into the middle
//   pull 1.0 ── the ring is empty, everything has collapsed into the vanishing point
//   pull → 0 ── they come back out to exactly the position they left
//
// Deriving each card's position from `pull` rather than giving it its own state machine is what
// makes the return possible at all: the reform is the same animation played backwards, so a card
// cannot fail to find its home.
//
// Cards are cells of two sprite sheets: the generic history set and, when it exists, the lesson's
// own artwork packed by `lessons:build-warp-atlas`.

import { gsap } from 'gsap';
import { EASING } from '../easing.js';

const ATLAS_COLS = 4;
const ATLAS_ROWS = 6;

// How often a card comes from the lesson's own atlas rather than the generic history set.
const LESSON_CELL_BIAS = 0.72;

const TAU = Math.PI * 2;

// The ring. Depth is what moves — a card at Z_HOME sits on the ring at its true size, and a card
// pulled toward Z_GONE shrinks and slides inward together, because both size and distance from
// centre scale by Z_REF / z. That is why the drag reads as depth and not as a flat tween.
const Z_REF = 1600;
const Z_HOME = 1600;
const Z_GONE = 16000;

const RING_RATIO = 0.4; // ring radius, as a share of the stage span
const GUTTER_RATIO = 0.022; // the gap between neighbours, same share — this is the "gutter"

/**
 * The stage the ring is measured against: the shorter edge of the canvas, but never below this.
 *
 * The canvas fills the hero, which is the height of the device, so on a short window the shorter
 * edge IS the height and the whole wheel shrank with it — on a laptop the ring came out small
 * enough to stop reading as a ring at all. Bart: "should not be less height as 960px … min-h-960px
 * is big enough for the wheel to shine."
 *
 * Below the floor the wheel simply overflows and the hero clips it, which is correct: these cards
 * are background art, not content, and a cropped wheel at full size reads far better than a
 * complete one too small to see. Matches the flat backdrop's own floor in .wheel (app.css).
 */
const MIN_STAGE_SPAN = 960;
export const stageSpan = (width, height) => Math.max(MIN_STAGE_SPAN, Math.min(width, height));
const CARD_COUNT = 22;

// Cards in flight get a small random tilt — they are tumbling, so it reads as motion.
//
// Cards ON THE RING do not. A square rotated off-tangent has a tangential extent of
// s·(|cos θ| + |sin θ|), so a random tilt makes each card eat a different amount of its
// neighbours' space and the gaps stop looking equal even though the centres are exact. The
// rotation the ring wants is the one that follows the circle, and nothing else.
const TILT_MAX = 0.2; // radians, in-flight cards only

// What bursts out of the middle once the ring has been swallowed: the lesson's own footage,
// flying at the viewer. These are separate from the ring cards and use the lesson atlas only.
const BURST_COUNT = 26;
const BURST_MIN_RATIO = 0.09; // card size at Z_REF, share of the short edge
const BURST_MAX_RATIO = 0.17;
const BURST_EXIT_MIN = 0.16; // where it leaves the frame
const BURST_EXIT_MAX = 0.6;
const BURST_Z_START = 16000;
const BURST_Z_END = 120;

// How much of the sequence one card's journey occupies. 1/CARD_COUNT is strictly one at a time;
// wider overlaps them, so the ring empties as a wave rather than a queue.
const SLOT_WIDTH = 2.4 / CARD_COUNT;

// Motion blur: once a card grows or shrinks by more than this share of its size in a frame it is
// smeared along its own line of travel.
const BLUR_THRESHOLD = 0.03;
const BLUR_SAMPLES = 4;
// Weights for the motion-blur copies: a solid head with a fading tail, which is what a real
// smear looks like. Splitting the alpha evenly across the samples (the obvious way) makes a fast
// card 25% opaque and washes the picture out to a ghost.
const BLUR_WEIGHTS = [0.6, 0.21, 0.12, 0.07];

const BLUR_MAX_SPAN = 0.8;

const CORNER_RADIUS_RATIO = 0.19; // matches the rounded squares in the Figma wheel
const GLOW_RADIUS_RATIO = 0.55;
const MAX_PIXEL_RATIO = 2;
const MAX_FRAME_SECONDS = 1 / 20;

/** One offscreen canvas per atlas cell, pre-masked with rounded corners. */
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
            0, 0, size, size,
        );

        return cell;
    }).filter(Boolean);
};

/**
 * Card size that leaves an identical gutter between every pair of neighbours.
 *
 * Neighbouring centres sit a chord apart: 2R·sin(π/N). Each card is rotated to follow the circle,
 * so the space it occupies along that chord is exactly its own side — which makes the edge-to-edge
 * gap chord − side, the same for every pair on the ring. Solve for side and the gutter is exact by
 * construction rather than by eye.
 */
const cardSideFor = (radius, count, gutter) => Math.max(8, 2 * radius * Math.sin(Math.PI / count) - gutter);

/**
 * @param {HTMLCanvasElement} canvas
 * @param {HTMLImageElement} image  the loaded generic sprite sheet
 * @param {{lessonAtlas?: {image: HTMLImageElement, cells: number}}} options
 */
export const createCardwheel = (canvas, image, options = {}) => {
    const ctx = canvas.getContext('2d', { alpha: true });
    const cells = ctx ? sliceAtlas(image) : [];

    if (!ctx || cells.length === 0) {
        return null;
    }

    const lessonCells = options.lessonAtlas
        ? sliceAtlas(
            options.lessonAtlas.image,
            ATLAS_COLS,
            Math.ceil(options.lessonAtlas.cells / ATLAS_COLS),
            options.lessonAtlas.cells,
        )
        : [];

    // The ring is the wheel the visitor already knows — the generic history cards. What comes
    // back OUT of the middle is this lesson. Keeping the two pools apart is the whole gag: the
    // stock wheel goes in, Abel Tasman comes out.
    const ringCell = (index) => cells[(index * 5 + 1) % cells.length];
    const burstCell = (index) => (lessonCells.length
        ? lessonCells[index % lessonCells.length]
        : cells[index % cells.length]);

    // The dials. GSAP owns these; the loop only reads them.
    const state = {
        pull: 0, // 0 = ring intact, 1 = everything collapsed into the middle
        spin: 0.02, // radians per second the whole wheel turns
        alpha: 1,
        trail: 1,
        glow: 0,
        scale: 1,
        burst: 0, // 0 = nothing out yet, 1 = every lesson picture has been thrown at the viewer
        burstSpeed: 0.55, // how fast a thrown card crosses the room
        speed: 0, // unused here; kept so the storyboard can tween one shape of state
        density: 1,
        spread: 1,
    };

    const particles = Array.from({ length: CARD_COUNT }, (_, index) => ({
        index,
        // Seats are evenly spaced and the card is turned to face the middle — the two things that
        // made the original flat wheel image look deliberate.
        // Seats are exact: i·(2π/N), starting at twelve o'clock.
        homeAngle: (index / CARD_COUNT) * TAU - Math.PI / 2,
        // Two pictures per seat. The generic card is what the visitor arrives to; the lesson's
        // own footage is what is in that seat when the wheel comes back. The swap happens while
        // the card is deep in the middle and far too small to read, so the ring reforms visibly
        // CHANGED rather than visibly redrawn.
        cellBefore: ringCell(index),
        cellAfter: lessonCells.length ? lessonCells[index % lessonCells.length] : ringCell(index),
        swapped: false,
        z: Z_HOME,
        lastSize: 0,
        // Departure order runs around the ring rather than at random: a queue reads as somebody
        // taking the cards, a scatter reads as a glitch.
        order: index / CARD_COUNT,
    }));

    /** The lesson's own footage, waiting to be thrown out of the middle. */
    const burstCards = Array.from({ length: BURST_COUNT }, (_, index) => ({
        index,
        cell: burstCell(index),
        // Its slot in the burst: card 0 leaves first, and they overlap from there.
        threshold: index / BURST_COUNT,
        angle: (index * 2.39996) % TAU, // golden angle — even spray, never a visible spoke
        exitRatio: BURST_EXIT_MIN + ((index * 7) % BURST_COUNT) / BURST_COUNT
            * (BURST_EXIT_MAX - BURST_EXIT_MIN),
        sizeRatio: BURST_MIN_RATIO + ((index * 5) % BURST_COUNT) / BURST_COUNT
            * (BURST_MAX_RATIO - BURST_MIN_RATIO),
        tilt: (((index * 40503) % 1000) / 1000 - 0.5) * 2 * TILT_MAX,
        progress: 0,
        active: false,
        spent: false,
        lastSize: 0,
    }));

    let width = 0;
    let height = 0;
    let lastTime = 0;
    let fieldAngle = 0;

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

        ctx.fillStyle = `rgba(2, 8, 26, ${state.trail})`;
        ctx.fillRect(0, 0, width, height);
    };

    /** How far into its own journey this card is: 0 parked, 1 gone. */
    const journeyOf = (particle) => gsap.utils.clamp(
        0,
        1,
        (state.pull - particle.order * (1 - SLOT_WIDTH)) / SLOT_WIDTH,
    );

    const drawBlurred = (cell, x, y, size, rotation, alpha, change) => {
        const blur = gsap.utils.clamp(0, 1, (Math.abs(change) - BLUR_THRESHOLD) / 0.22);

        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(rotation);

        if (blur <= 0) {
            ctx.globalAlpha = alpha;
            ctx.drawImage(cell, -size / 2, -size / 2, size, size);
            ctx.restore();

            return;
        }

        // Smear along the radial line the card is travelling down — inward while imploding,
        // outward while it returns.
        const span = size * BLUR_MAX_SPAN * blur * Math.sign(change || 1);

        for (let sample = 0; sample < BLUR_SAMPLES; sample += 1) {
            const back = (span * sample) / BLUR_SAMPLES;
            const drawn = size * (1 - (0.14 * sample) / BLUR_SAMPLES);
            ctx.globalAlpha = alpha * BLUR_WEIGHTS[sample];
            ctx.drawImage(cell, -drawn / 2 - back, -drawn / 2, drawn, drawn);
        }

        ctx.restore();
    };

    const drawParticle = (particle) => {
        const minDimension = stageSpan(width, height);
        const journey = journeyOf(particle);

        // Accelerate away from the ring, so the drag starts as a tug and ends as a snatch.
        const eased = EASING.easeInCubic(journey);
        particle.z = Z_HOME * ((Z_GONE / Z_HOME) ** eased);

        // Swallowed: from here on this seat belongs to the lesson.
        if (journey >= 0.97) {
            particle.swapped = true;
        }

        const projection = Z_REF / particle.z;
        const ringRadius = RING_RATIO * minDimension * state.spread * projection;
        const side = cardSideFor(
            RING_RATIO * minDimension,
            CARD_COUNT,
            GUTTER_RATIO * minDimension,
        ) * state.scale * projection;

        if (side < 1) {
            return;
        }

        const angle = particle.homeAngle + fieldAngle;
        const x = width / 2 + Math.cos(angle) * ringRadius;
        const y = height / 2 + Math.sin(angle) * ringRadius;

        // Rotated to follow the circle, and nothing more. This is what makes the ring read as a
        // ring instead of as squares scattered near a circle.
        const rotation = angle + Math.PI / 2;
        // Gone means gone: fade out over the last of the journey rather than shrinking to a dot.
        const fade = 1 - EASING.easeInQuad(gsap.utils.clamp(0, 1, (journey - 0.55) / 0.45));
        const change = particle.lastSize > 0 ? (side - particle.lastSize) / particle.lastSize : 0;

        drawBlurred(
            particle.swapped ? particle.cellAfter : particle.cellBefore,
            x, y, side, rotation, state.alpha * fade, change,
        );
        particle.lastSize = side;
    };

    /** The lesson's own footage, thrown out of the middle at the viewer. Same projection as the
     *  ring, run the other way: from far and tiny on the axis to big and off the frame. */
    const drawBurst = (card) => {
        if (!card.active) {
            return;
        }

        const minDimension = stageSpan(width, height);
        // Accelerate toward the lens, so it arrives rather than drifts.
        const eased = EASING.easeInQuad(Math.min(1, card.progress));
        const z = BURST_Z_START * ((BURST_Z_END / BURST_Z_START) ** eased);
        const projection = Z_REF / z;
        const size = card.sizeRatio * minDimension * state.scale * projection;

        if (size < 2 || size > minDimension * 2.2) {
            card.lastSize = size;

            return;
        }

        const angle = card.angle + fieldAngle;
        const radius = card.exitRatio * minDimension * state.spread * projection;
        const x = width / 2 + Math.cos(angle) * radius;
        const y = height / 2 + Math.sin(angle) * radius;

        if (x < -size || x > width + size || y < -size || y > height + size) {
            card.lastSize = size;

            return;
        }

        const fade = EASING.easeOutCubic(gsap.utils.clamp(0, 1, card.progress / 0.12));
        const change = card.lastSize > 0 ? (size - card.lastSize) / card.lastSize : 0;

        drawBlurred(card.cell, x, y, size, angle + card.tilt, state.alpha * fade, change);
        card.lastSize = size;
    };

    const drawGlow = () => {
        if (state.glow <= 0.002) {
            return;
        }

        // The same stage the ring is measured against, so the halo stays sized to the WHEEL rather
        // than to the window. Left on the raw canvas it would shrink out from under a floored ring
        // on a short screen and light only its middle.
        const radius = stageSpan(width, height) * GLOW_RADIUS_RATIO;
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
        const seconds = lastTime
            ? gsap.utils.clamp(0, MAX_FRAME_SECONDS, (time - lastTime) / 1000)
            : 0;
        lastTime = time;

        fieldAngle = (fieldAngle + state.spin * seconds) % TAU;

        // Throw the next lesson pictures out of the middle as `burst` climbs, then let each one
        // cross the room on its own clock — no two arrive together.
        burstCards.forEach((card) => {
            if (!card.active && !card.spent && state.burst > card.threshold) {
                card.active = true;
                card.progress = 0;
                card.lastSize = 0;
            }

            if (card.active) {
                card.progress += seconds * state.burstSpeed;

                if (card.progress >= 1) {
                    card.active = false;
                    card.spent = true; // thrown once, not on a loop
                }
            }
        });

        wipe();
        // Farthest first, so a card on its way in passes behind the ones still on the ring.
        [...particles].sort((a, b) => b.z - a.z).forEach(drawParticle);
        // The glow is the mouth the footage is coming OUT of, so it belongs behind it. Drawn
        // last (over everything) it washed the pictures out to pale ghosts.
        drawGlow();
        burstCards.forEach(drawBurst);
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
        // Exposed for the same reason as `particles`: a test (or a headless browser whose rAF is
        // asleep) needs to see what the field actually did.
        burstCards,
        restart() {
            state.pull = 0;
            state.burst = 0;
            fieldAngle = 0;
            particles.forEach((particle) => {
                particle.lastSize = 0;
                particle.swapped = false;
            });
            burstCards.forEach((card) => {
                card.active = false;
                card.spent = false;
                card.progress = 0;
                card.lastSize = 0;
            });
        },
        /** Put the ring back. The storyboard tweens `pull` home, so this only exists to match the
         *  tunnel engine's shape — there is nothing to recycle here. */
        recede() {},
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

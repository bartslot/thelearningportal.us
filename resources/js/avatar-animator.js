// resources/js/avatar-animator.js
// Animates a portrait canvas: mouth sprites, eye blink, subtle head drift.

// Smooth 1D value-noise interpolated with cosine easing
const _noiseTable = Array.from({ length: 256 }, () => Math.random() * 2 - 1);
function smoothNoise(t) {
    const i  = Math.floor(t) & 255;
    const f  = t - Math.floor(t);
    const u  = f * f * (3 - 2 * f); // smoothstep
    return _noiseTable[i] * (1 - u) + _noiseTable[(i + 1) & 255] * u;
}

export function initAvatarAnimator(canvasId, previewElId) {
    const previewEl = document.getElementById(previewElId);
    const canvas    = document.getElementById(canvasId);
    if (!canvas || !previewEl) return;

    const ctx        = canvas.getContext('2d');
    const portrait   = new Image();
    portrait.crossOrigin = 'anonymous';
    const landmarks  = JSON.parse(previewEl.dataset.landmarks  || '{}');
    const spriteDefs = JSON.parse(previewEl.dataset.sprites    || '{}');

    function loadImg(src) {
        if (!src) return null;
        const i = new Image();
        i.crossOrigin = 'anonymous';
        i.src = src;
        return i;
    }

    const mouthImgs = (spriteDefs.mouth || []).map(loadImg);
    const eyeImgs   = {
        leftOpen:    loadImg(spriteDefs.left_eye_open),
        leftClosed:  loadImg(spriteDefs.left_eye_closed),
        rightOpen:   loadImg(spriteDefs.right_eye_open),
        rightClosed: loadImg(spriteDefs.right_eye_closed),
    };

    portrait.src = previewEl.dataset.portrait;

    // ── Animation state ──────────────────────────────────────────────────────
    let samples        = [];
    let audioStartTime = null;
    let demoMode       = true;

    // Eye blink state machine
    const BLINK_OPEN_MIN  = 2500;
    const BLINK_OPEN_MAX  = 5000;
    const BLINK_CLOSE_MS  = 130;
    let   eyeOpen         = true;
    let   nextBlinkAt     = Date.now() + randBetween(BLINK_OPEN_MIN, BLINK_OPEN_MAX);
    let   blinkCloseUntil = 0;

    function randBetween(a, b) { return a + Math.random() * (b - a); }

    // Public API: load manifest and start synced playback
    window.avatarLoadManifest = function (manifestJson, audioStartedAt) {
        samples        = manifestJson.samples;
        audioStartTime = audioStartedAt;
        demoMode       = false;
    };

    // ── Amplitude ────────────────────────────────────────────────────────────
    function currentAmp() {
        if (demoMode) {
            // Gentle sine wave so mouth cycles slowly for demo
            return (Math.sin(Date.now() / 600) + 1) / 2 * 0.5;
        }
        if (!audioStartTime || samples.length === 0) return 0;
        const elapsed = (performance.now() - audioStartTime) / 1000;
        let lo = 0, hi = samples.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (samples[mid].t <= elapsed) lo = mid; else hi = mid - 1;
        }
        return samples[lo].amp;
    }

    function ampToMouthFrame(amp) {
        if (amp < 0.15) return 0;
        if (amp < 0.35) return 1;
        if (amp < 0.60) return 2;
        return 3;
    }

    // ── Draw loop ─────────────────────────────────────────────────────────────
    function draw(ts) {
        requestAnimationFrame(draw);

        if (!portrait.complete || !portrait.naturalWidth) return;

        const t   = ts / 1000;
        const amp = currentAmp();

        const cw = canvas.width;
        const ch = canvas.height;

        // Scale factors: portrait coords → canvas coords
        const scaleX = cw / portrait.naturalWidth;
        const scaleY = ch / portrait.naturalHeight;

        // Subtle head drift — slow smooth noise, very small amplitude
        const dx  = smoothNoise(t * 0.4)       * 1.5;   // ±1.5 px
        const dy  = smoothNoise(t * 0.35 + 10) * 1.5;
        const rot = smoothNoise(t * 0.25 + 5)  * 0.004; // ±0.004 rad

        ctx.clearRect(0, 0, cw, ch);
        ctx.save();

        // Rotate around canvas centre
        ctx.translate(cw / 2 + dx, ch / 2 + dy);
        ctx.rotate(rot);
        ctx.translate(-cw / 2, -ch / 2);

        // Portrait
        ctx.drawImage(portrait, 0, 0, cw, ch);

        // ── Mouth sprite ───────────────────────────────────────────────────
        const mouthFrame = ampToMouthFrame(amp);
        const mouthImg   = mouthImgs[mouthFrame];
        if (mouthImg?.complete && mouthImg.naturalWidth && landmarks.mouth) {
            const m = landmarks.mouth;
            ctx.drawImage(
                mouthImg,
                Math.round(m.x * scaleX),
                Math.round(m.y * scaleY),
                Math.round(m.w * scaleX),
                Math.round(m.h * scaleY),
            );
        }

        // ── Eye blink ──────────────────────────────────────────────────────
        const now = Date.now();
        if (eyeOpen && now >= nextBlinkAt) {
            eyeOpen       = false;
            blinkCloseUntil = now + BLINK_CLOSE_MS;
        }
        if (!eyeOpen && now >= blinkCloseUntil) {
            eyeOpen    = true;
            nextBlinkAt = now + randBetween(BLINK_OPEN_MIN, BLINK_OPEN_MAX);
        }

        if (!eyeOpen) {
            for (const [imgKey, lmKey] of [['leftClosed', 'left_eye'], ['rightClosed', 'right_eye']]) {
                const img = eyeImgs[imgKey];
                const lm  = landmarks[lmKey];
                if (img?.complete && img.naturalWidth && lm) {
                    ctx.drawImage(
                        img,
                        Math.round(lm.x * scaleX),
                        Math.round(lm.y * scaleY),
                        Math.round(lm.w * scaleX),
                        Math.round(lm.h * scaleY),
                    );
                }
            }
        }

        ctx.restore();
    }

    portrait.onload = () => requestAnimationFrame(draw);
    if (portrait.complete && portrait.naturalWidth) requestAnimationFrame(draw);
}

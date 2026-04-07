// resources/js/avatar-animator.js
// Animates a portrait canvas using an amplitude manifest.
// Layers: mouth sprites, eye blink, head Perlin drift, breathing CSS.

// Minimal Perlin-like noise (1D)
function perlin(t) {
    const n = Math.sin(t * 12.9898 + t * 78.233) * 43758.5453;
    return (n - Math.floor(n)) * 2 - 1; // -1 to 1
}

export function initAvatarAnimator(canvasId, previewElId) {
    const previewEl = document.getElementById(previewElId);
    const canvas    = document.getElementById(canvasId);
    if (!canvas || !previewEl) return;

    const ctx       = canvas.getContext('2d');
    const portrait  = new Image();
    const landmarks = JSON.parse(previewEl.dataset.landmarks || '{}');
    const spriteDefs = JSON.parse(previewEl.dataset.sprites  || '{}');

    function loadImg(src) {
        if (!src) return null;
        const i = new Image();
        i.src = src;
        return i;
    }

    const mouthImgs = (spriteDefs.mouth || []).map(src => loadImg(src));
    const eyeImgs   = {
        leftOpen:    loadImg(spriteDefs.left_eye_open),
        leftClosed:  loadImg(spriteDefs.left_eye_closed),
        rightOpen:   loadImg(spriteDefs.right_eye_open),
        rightClosed: loadImg(spriteDefs.right_eye_closed),
    };

    portrait.src = previewEl.dataset.portrait;

    // Animation state
    let samples        = [];
    let audioStartTime = null;
    let demoMode       = true;
    let eyeOpen        = true;
    let nextBlinkAt    = Date.now() + randomBlinkInterval();

    function randomBlinkInterval() {
        return 2000 + Math.random() * 3000;
    }

    // Public: load manifest and start synced animation
    window.avatarLoadManifest = function (manifestJson, audioStartedAt) {
        samples        = manifestJson.samples;
        audioStartTime = audioStartedAt;
        demoMode       = false;
    };

    function currentAmp() {
        if (demoMode) {
            return (Math.sin(Date.now() / 200) + 1) / 2 * 0.6;
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
        if (amp < 0.1) return 0;
        if (amp < 0.3) return 1;
        if (amp < 0.6) return 2;
        return 3;
    }

    function draw(ts) {
        const t   = ts / 1000;
        const amp = currentAmp();

        // Head micro-movement via Perlin noise
        const dx  = perlin(t * 0.3) * 2;
        const dy  = perlin(t * 0.4 + 10) * 2;
        const rot = perlin(t * 0.2 + 5) * 0.008;

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.save();
        ctx.translate(canvas.width / 2 + dx, canvas.height / 2 + dy);
        ctx.rotate(rot);
        ctx.translate(-canvas.width / 2, -canvas.height / 2);

        if (portrait.complete && portrait.naturalWidth) {
            ctx.drawImage(portrait, 0, 0, canvas.width, canvas.height);
        }

        const scaleX = canvas.width  / (portrait.naturalWidth  || canvas.width);
        const scaleY = canvas.height / (portrait.naturalHeight || canvas.height);

        // Mouth sprite
        const mouthImg = mouthImgs[ampToMouthFrame(amp)];
        if (mouthImg?.complete && landmarks.mouth) {
            const m = landmarks.mouth;
            ctx.drawImage(mouthImg, m.x * scaleX, m.y * scaleY, m.w * scaleX, m.h * scaleY);
        }

        // Eye blink logic
        const now = Date.now();
        if (now >= nextBlinkAt) {
            eyeOpen    = !eyeOpen;
            nextBlinkAt = now + (eyeOpen ? randomBlinkInterval() : 120);
        }

        if (!eyeOpen) {
            const pairs = [
                ['leftClosed',  'left_eye'],
                ['rightClosed', 'right_eye'],
            ];
            for (const [imgKey, lmKey] of pairs) {
                const img = eyeImgs[imgKey];
                const lm  = landmarks[lmKey];
                if (img?.complete && lm) {
                    ctx.drawImage(img, lm.x * scaleX, lm.y * scaleY, lm.w * scaleX, lm.h * scaleY);
                }
            }
        }

        ctx.restore();
        requestAnimationFrame(draw);
    }

    if (portrait.complete && portrait.naturalWidth) {
        requestAnimationFrame(draw);
    } else {
        portrait.onload = () => requestAnimationFrame(draw);
    }
}

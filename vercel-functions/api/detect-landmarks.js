// api/detect-landmarks.js
// POST { portrait_base64: string } → { landmarks, mouth_frames: [base64 x4], eye_frames: { left_open, left_closed, right_open, right_closed } }

import path from 'path';

// Vercel deploys to /var/task/vercel-functions/; locally models/ is relative to this file's parent
const MODELS_PATH = process.env.VERCEL
    ? '/var/task/vercel-functions/models'
    : path.resolve(process.cwd(), 'models');

// ── Minimal Canvas / Image polyfill for face-api (pure-JS, no native deps) ──

class FakeCanvas {
    constructor(width, height) {
        this.width  = width;
        this.height = height;
        this._data  = new Uint8ClampedArray(width * height * 4);
    }
    getContext() { return new FakeCtx(this); }
    toDataURL(mime = 'image/png') {
        // We never call this on the detection canvas — only on crop canvases
        return '';
    }
}

class FakeCtx {
    constructor(canvas) { this.canvas = canvas; }
    drawImage(src, sx, sy, sw, sh, dx, dy, dw, dh) {
        if (src instanceof FakeCanvas) {
            // Simple pixel copy (same-size assumed for main canvas)
            if (arguments.length === 3) {
                this.canvas._data.set(src._data);
            } else {
                // Crop copy: sx,sy,sw,sh → dx,dy,dw,dh
                for (let y = 0; y < dh; y++) {
                    for (let x = 0; x < dw; x++) {
                        const srcX = Math.round(sx + (x / dw) * sw);
                        const srcY = Math.round(sy + (y / dh) * sh);
                        const si   = (srcY * src.width + srcX) * 4;
                        const di   = ((dy + y) * this.canvas.width + (dx + x)) * 4;
                        this.canvas._data[di]     = src._data[si];
                        this.canvas._data[di + 1] = src._data[si + 1];
                        this.canvas._data[di + 2] = src._data[si + 2];
                        this.canvas._data[di + 3] = src._data[si + 3];
                    }
                }
            }
        }
    }
    getImageData(x, y, w, h) {
        const out = new Uint8ClampedArray(w * h * 4);
        for (let row = 0; row < h; row++) {
            for (let col = 0; col < w; col++) {
                const si = ((y + row) * this.canvas.width + (x + col)) * 4;
                const di = (row * w + col) * 4;
                out[di]     = this.canvas._data[si];
                out[di + 1] = this.canvas._data[si + 1];
                out[di + 2] = this.canvas._data[si + 2];
                out[di + 3] = this.canvas._data[si + 3];
            }
        }
        return { data: out, width: w, height: h };
    }
    putImageData(imgData, x, y) {
        for (let row = 0; row < imgData.height; row++) {
            for (let col = 0; col < imgData.width; col++) {
                const si = (row * imgData.width + col) * 4;
                const di = ((y + row) * this.canvas.width + (x + col)) * 4;
                this.canvas._data[di]     = imgData.data[si];
                this.canvas._data[di + 1] = imgData.data[si + 1];
                this.canvas._data[di + 2] = imgData.data[si + 2];
                this.canvas._data[di + 3] = imgData.data[si + 3];
            }
        }
    }
    fillRect(x, y, w, h) {
        const [r, g, b, a] = this._fillRgba;
        for (let row = y; row < y + h; row++) {
            for (let col = x; col < x + w; col++) {
                const i = (row * this.canvas.width + col) * 4;
                this.canvas._data[i]     = Math.round(r * a + this.canvas._data[i]     * (1 - a));
                this.canvas._data[i + 1] = Math.round(g * a + this.canvas._data[i + 1] * (1 - a));
                this.canvas._data[i + 2] = Math.round(b * a + this.canvas._data[i + 2] * (1 - a));
                this.canvas._data[i + 3] = 255;
            }
        }
    }
    set fillStyle(val) {
        // parse "rgba(r,g,b,a)" or "#rrggbb"
        const m = String(val).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
        this._fillRgba = m ? [+m[1], +m[2], +m[3], m[4] !== undefined ? +m[4] : 1] : [0, 0, 0, 1];
    }
}

class FakeImage {
    constructor() { this.src = ''; this.width = 0; this.height = 0; }
}

class FakeImageData {
    constructor(data, width, height) {
        this.data   = data instanceof Uint8ClampedArray ? data : new Uint8ClampedArray(data);
        this.width  = width;
        this.height = height;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

let modelsLoaded = false;
let faceapi = null;
let Jimp = null;

async function ensureModels() {
    if (modelsLoaded) return;

    // Dynamic imports so we can set CPU backend before face-api loads TF
    const tf = await import('@tensorflow/tfjs');
    await tf.setBackend('cpu');
    await tf.ready();

    const fa = await import('@vladmandic/face-api/dist/face-api.node-wasm.js');
    faceapi = fa.default ?? fa;

    const jimpMod = await import('jimp');
    Jimp = jimpMod.Jimp ?? jimpMod.default;

    faceapi.env.monkeyPatch({
        Canvas:    FakeCanvas,
        Image:     FakeImage,
        ImageData: FakeImageData,
        createCanvasElement: () => new FakeCanvas(1, 1),
        createImageElement:  () => new FakeImage(),
    });
    await faceapi.nets.tinyFaceDetector.loadFromDisk(MODELS_PATH);
    await faceapi.nets.faceLandmark68Net.loadFromDisk(MODELS_PATH);
    modelsLoaded = true;
}

function jimpToFakeCanvas(jimg) {
    const canvas = new FakeCanvas(jimg.bitmap.width, jimg.bitmap.height);
    // Jimp stores pixels as RGBA in bitmap.data
    canvas._data.set(jimg.bitmap.data);
    return canvas;
}

async function fakeCanvasToBase64(canvas, bounds) {
    // Crop via Jimp from the canvas pixel data
    const jimg = new Jimp({ width: canvas.width, height: canvas.height, color: 0 });
    jimg.bitmap.data.set(canvas._data);
    const cropped = jimg.clone().crop({ x: bounds.x, y: bounds.y, w: bounds.w, h: bounds.h });
    return await cropped.getBase64('image/png');
}

function boundsFromPoints(points, padX = 10, padY = 10) {
    const xs = points.map(p => p.x);
    const ys = points.map(p => p.y);
    return {
        x: Math.max(0, Math.round(Math.min(...xs)) - padX),
        y: Math.max(0, Math.round(Math.min(...ys)) - padY),
        w: Math.round(Math.max(...xs) - Math.min(...xs)) + padX * 2,
        h: Math.round(Math.max(...ys) - Math.min(...ys)) + padY * 2,
    };
}

async function cropToBase64(jimg, bounds) {
    return await jimg.clone()
        .crop({ x: bounds.x, y: bounds.y, w: bounds.w, h: bounds.h })
        .getBase64('image/png');
}

async function generateMouthFrames(jimg, bounds) {
    const frames = [];
    const adjustments = [-0.4, -0.2, 0, 0.1];
    for (const adj of adjustments) {
        let crop = jimg.clone().crop({ x: bounds.x, y: bounds.y, w: bounds.w, h: bounds.h });
        if (adj !== 0) {
            crop.scan((x, y, idx) => {
                crop.bitmap.data[idx]     = Math.min(255, Math.max(0, crop.bitmap.data[idx]     + adj * 255));
                crop.bitmap.data[idx + 1] = Math.min(255, Math.max(0, crop.bitmap.data[idx + 1] + adj * 255));
                crop.bitmap.data[idx + 2] = Math.min(255, Math.max(0, crop.bitmap.data[idx + 2] + adj * 255));
            });
        }
        frames.push(await crop.getBase64('image/png'));
    }
    return frames;
}

async function generateEyeClosedFrame(jimg, bounds) {
    const crop = jimg.clone().crop({ x: bounds.x, y: bounds.y, w: bounds.w, h: bounds.h });
    const barH = Math.max(4, Math.round(bounds.h * 0.45));
    const barY = Math.round((bounds.h - barH) / 2);
    // Draw skin-tone bar over the eye to simulate closed lid
    const skinR = 210, skinG = 170, skinB = 130, alpha = 0.85;
    crop.scan((x, y, idx) => {
        if (y >= barY && y < barY + barH) {
            crop.bitmap.data[idx]     = Math.round(skinR * alpha + crop.bitmap.data[idx]     * (1 - alpha));
            crop.bitmap.data[idx + 1] = Math.round(skinG * alpha + crop.bitmap.data[idx + 1] * (1 - alpha));
            crop.bitmap.data[idx + 2] = Math.round(skinB * alpha + crop.bitmap.data[idx + 2] * (1 - alpha));
        }
    });
    return await crop.getBase64('image/png');
}

export default async function handler(req, res) {
    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const { portrait_base64 } = req.body ?? {};
    if (!portrait_base64 || typeof portrait_base64 !== 'string') {
        return res.status(400).json({ error: 'portrait_base64 required' });
    }

    try {
        await ensureModels();

        // Strip data URI prefix if present
        const base64Data = portrait_base64.replace(/^data:image\/\w+;base64,/, '');
        const buffer = Buffer.from(base64Data, 'base64');

        // Load with Jimp
        const jimg = await Jimp.fromBuffer(buffer);
        const { width, height } = jimg.bitmap;

        // Build a FakeCanvas for face-api detection
        const canvas = jimpToFakeCanvas(jimg);

        const detection = await faceapi
            .detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks();

        if (!detection) {
            return res.status(422).json({ error: 'No face detected in portrait' });
        }

        const lm = detection.landmarks;
        const mouthPoints    = lm.getMouth();
        const leftEyePoints  = lm.getLeftEye();
        const rightEyePoints = lm.getRightEye();

        const mouthBounds    = boundsFromPoints(mouthPoints, 12, 8);
        const leftEyeBounds  = boundsFromPoints(leftEyePoints, 8, 6);
        const rightEyeBounds = boundsFromPoints(rightEyePoints, 8, 6);
        const faceBox        = detection.detection.box;
        const faceBounds     = {
            x: Math.round(faceBox.x), y: Math.round(faceBox.y),
            w: Math.round(faceBox.width), h: Math.round(faceBox.height),
        };

        // Clamp bounds to image dimensions
        for (const b of [mouthBounds, leftEyeBounds, rightEyeBounds]) {
            b.x = Math.max(0, Math.min(b.x, width - 1));
            b.y = Math.max(0, Math.min(b.y, height - 1));
            b.w = Math.min(b.w, width - b.x);
            b.h = Math.min(b.h, height - b.y);
        }

        const mouthFrames    = await generateMouthFrames(jimg, mouthBounds);
        const leftEyeOpen    = await cropToBase64(jimg, leftEyeBounds);
        const leftEyeClosed  = await generateEyeClosedFrame(jimg, leftEyeBounds);
        const rightEyeOpen   = await cropToBase64(jimg, rightEyeBounds);
        const rightEyeClosed = await generateEyeClosedFrame(jimg, rightEyeBounds);

        return res.status(200).json({
            landmarks: {
                mouth:       mouthBounds,
                left_eye:    leftEyeBounds,
                right_eye:   rightEyeBounds,
                face_bounds: faceBounds,
            },
            mouth_frames: mouthFrames,
            eye_frames: {
                left_open:    leftEyeOpen,
                left_closed:  leftEyeClosed,
                right_open:   rightEyeOpen,
                right_closed: rightEyeClosed,
            },
        });
    } catch (err) {
        console.error('detect-landmarks error:', err);
        return res.status(500).json({ error: 'Landmark detection failed', detail: err.message });
    }
}

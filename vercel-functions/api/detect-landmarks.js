// api/detect-landmarks.js
// POST { portrait_base64: string } → { landmarks, mouth_frames: [base64 x4], eye_frames: { left_open, left_closed, right_open, right_closed } }

import * as faceapi from '@vladmandic/face-api';
import { createCanvas, loadImage, Image, ImageData } from '@napi-rs/canvas';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MODELS_PATH = path.join(__dirname, '..', 'models');

let modelsLoaded = false;

async function ensureModels() {
    if (modelsLoaded) return;
    faceapi.env.monkeyPatch({ Canvas: createCanvas, Image, ImageData });
    await faceapi.nets.tinyFaceDetector.loadFromDisk(MODELS_PATH);
    await faceapi.nets.faceLandmark68Net.loadFromDisk(MODELS_PATH);
    modelsLoaded = true;
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

function cropRegion(srcCanvas, bounds) {
    const dst = createCanvas(bounds.w, bounds.h);
    const ctx = dst.getContext('2d');
    ctx.drawImage(srcCanvas, bounds.x, bounds.y, bounds.w, bounds.h, 0, 0, bounds.w, bounds.h);
    return dst;
}

function generateMouthFrames(mouthCanvas) {
    const frames = [];
    const adjustments = [-0.4, -0.2, 0, 0.1];
    for (const adj of adjustments) {
        const dst = createCanvas(mouthCanvas.width, mouthCanvas.height);
        const ctx = dst.getContext('2d');
        ctx.drawImage(mouthCanvas, 0, 0);
        if (adj !== 0) {
            const imageData = ctx.getImageData(0, 0, dst.width, dst.height);
            for (let i = 0; i < imageData.data.length; i += 4) {
                imageData.data[i]     = Math.min(255, Math.max(0, imageData.data[i]     + adj * 255));
                imageData.data[i + 1] = Math.min(255, Math.max(0, imageData.data[i + 1] + adj * 255));
                imageData.data[i + 2] = Math.min(255, Math.max(0, imageData.data[i + 2] + adj * 255));
            }
            ctx.putImageData(imageData, 0, 0);
        }
        frames.push(dst.toDataURL('image/png'));
    }
    return frames;
}

function generateEyeClosedFrame(eyeCanvas) {
    const dst = createCanvas(eyeCanvas.width, eyeCanvas.height);
    const ctx = dst.getContext('2d');
    ctx.drawImage(eyeCanvas, 0, 0);
    ctx.fillStyle = 'rgba(210, 170, 130, 0.85)';
    const barH = Math.max(4, Math.round(eyeCanvas.height * 0.45));
    const barY = Math.round((eyeCanvas.height - barH) / 2);
    ctx.fillRect(0, barY, eyeCanvas.width, barH);
    return dst.toDataURL('image/png');
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

        const img = await loadImage(portrait_base64.startsWith('data:')
            ? portrait_base64
            : `data:image/jpeg;base64,${portrait_base64}`);

        const canvas = createCanvas(img.width, img.height);
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);

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

        const mouthCrop    = cropRegion(canvas, mouthBounds);
        const leftEyeCrop  = cropRegion(canvas, leftEyeBounds);
        const rightEyeCrop = cropRegion(canvas, rightEyeBounds);

        const mouthFrames    = generateMouthFrames(mouthCrop);
        const leftEyeOpen    = leftEyeCrop.toDataURL('image/png');
        const leftEyeClosed  = generateEyeClosedFrame(leftEyeCrop);
        const rightEyeOpen   = rightEyeCrop.toDataURL('image/png');
        const rightEyeClosed = generateEyeClosedFrame(rightEyeCrop);

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

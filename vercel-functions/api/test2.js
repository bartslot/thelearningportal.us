import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MODELS_PATH = path.join(__dirname, '..', 'models');

class FakeCanvas {
    constructor(width, height) {
        this.width = width;
        this.height = height;
        this._data = new Uint8ClampedArray(width * height * 4);
    }
    getContext() { return {}; }
}

let modelsLoaded = false;
let faceapi = null;
let Jimp = null;

async function ensureModels() {
    if (modelsLoaded) return;
    const tf = await import('@tensorflow/tfjs');
    await tf.setBackend('cpu');
    await tf.ready();
    const fa = await import('@vladmandic/face-api/dist/face-api.node-wasm.js');
    faceapi = fa.default ?? fa;
    const jimpMod = await import('jimp');
    Jimp = jimpMod.Jimp ?? jimpMod.default;
    faceapi.env.monkeyPatch({
        Canvas: FakeCanvas,
        Image: class { constructor() { this.src = ''; this.width = 0; this.height = 0; } },
        ImageData: class { constructor(d, w, h) { this.data = d; this.width = w; this.height = h; } },
        createCanvasElement: () => new FakeCanvas(1, 1),
        createImageElement: () => ({}),
    });
    await faceapi.nets.tinyFaceDetector.loadFromDisk(MODELS_PATH);
    await faceapi.nets.faceLandmark68Net.loadFromDisk(MODELS_PATH);
    modelsLoaded = true;
}

export default async function handler(req, res) {
    try {
        await ensureModels();
        return res.status(200).json({ status: 'models loaded', backend: faceapi.tf?.getBackend?.() });
    } catch (e) {
        return res.status(500).json({ error: e.message, stack: e.stack?.split('\n').slice(0,5) });
    }
}

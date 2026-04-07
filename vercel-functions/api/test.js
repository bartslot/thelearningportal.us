export default async function handler(req, res) {
    const errors = [];

    try {
        const tf = await import('@tensorflow/tfjs');
        await tf.setBackend('cpu');
        await tf.ready();
        errors.push('tf: OK, backend=' + tf.getBackend());
    } catch (e) {
        errors.push('tf FAILED: ' + e.message);
    }

    try {
        const fa = await import('@vladmandic/face-api/dist/face-api.node-wasm.js');
        errors.push('face-api: OK');
    } catch (e) {
        errors.push('face-api FAILED: ' + e.message);
    }

    try {
        const { Jimp } = await import('jimp');
        errors.push('jimp: OK');
    } catch (e) {
        errors.push('jimp FAILED: ' + e.message);
    }

    return res.status(200).json({ results: errors });
}

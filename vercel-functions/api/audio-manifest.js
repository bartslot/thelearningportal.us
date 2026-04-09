// api/audio-manifest.js
// POST { audio_url: string } → { duration: number, samples: [{t, amp}] }

import audioDecode from 'audio-decode';
import fetch from 'node-fetch';

export default async function handler(req, res) {
    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const { audio_url } = req.body ?? {};
    if (!audio_url || typeof audio_url !== 'string') {
        return res.status(400).json({ error: 'audio_url required' });
    }

    try {
        const response = await fetch(audio_url);
        if (!response.ok) {
            return res.status(422).json({ error: 'Failed to fetch audio' });
        }

        const arrayBuffer = await response.arrayBuffer();
        const audio = await audioDecode(Buffer.from(arrayBuffer));

        const { channelData, sampleRate, duration } = audio;
        const mono = channelData[0]; // use first channel

        const windowMs = 50; // 50ms windows → 20 samples/sec
        const windowSize = Math.floor(sampleRate * windowMs / 1000);
        const samples = [];

        for (let i = 0; i < mono.length; i += windowSize) {
            const window = mono.slice(i, i + windowSize);
            // RMS amplitude
            const rms = Math.sqrt(window.reduce((sum, v) => sum + v * v, 0) / window.length);
            const t = parseFloat((i / sampleRate).toFixed(3));
            const amp = parseFloat(Math.min(rms * 4, 1).toFixed(3)); // scale to 0–1
            samples.push({ t, amp });
        }

        return res.status(200).json({ duration: parseFloat(duration.toFixed(3)), samples });
    } catch (err) {
        console.error('audio-manifest error:', err);
        return res.status(500).json({ error: 'Audio processing failed' });
    }
}

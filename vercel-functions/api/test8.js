import path from 'path';

const MODELS_PATH = path.join('/var/task/vercel-functions', 'models');

export default async function handler(req, res) {
    return res.status(200).json({ MODELS_PATH });
}

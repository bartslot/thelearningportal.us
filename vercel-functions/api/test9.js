import path from 'path';
import fs from 'fs';

const MODELS_PATH = process.env.VERCEL
    ? '/var/task/vercel-functions/models'
    : path.resolve(process.cwd(), 'models');

export default async function handler(req, res) {
    let files = [];
    try { files = fs.readdirSync(MODELS_PATH); } catch (e) { files = ['ERROR: ' + e.message]; }
    return res.status(200).json({ MODELS_PATH, files, isVercel: !!process.env.VERCEL });
}

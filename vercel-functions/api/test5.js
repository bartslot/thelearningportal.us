import path from 'path';
import fs from 'fs';

export default async function handler(req, res) {
    const modelsPath = path.join(process.cwd(), 'models');
    let files = [];
    try { files = fs.readdirSync(modelsPath); } catch (e) { files = ['ERROR: ' + e.message]; }
    return res.status(200).json({ cwd: process.cwd(), modelsPath, files });
}

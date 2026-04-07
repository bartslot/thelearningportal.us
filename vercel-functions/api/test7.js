import path from 'path';

export default async function handler(req, res) {
    let metaUrl = 'unavailable';
    try { metaUrl = import.meta.url; } catch(e) { metaUrl = 'ERROR: ' + e.message; }
    const modelsPath = path.join('/var/task/vercel-functions', 'models');
    return res.status(200).json({ metaUrl, modelsPath, dirname: path.dirname(metaUrl.replace('file://', '')) });
}

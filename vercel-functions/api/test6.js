import fs from 'fs';

export default async function handler(req, res) {
    let files = [];
    try { files = fs.readdirSync('/var/task'); } catch (e) { files = ['ERROR: ' + e.message]; }
    return res.status(200).json({ files });
}

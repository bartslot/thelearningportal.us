import path from 'path';

export default async function handler(req, res) {
    return res.status(200).json({ path_sep: path.sep, cwd: process.cwd() });
}

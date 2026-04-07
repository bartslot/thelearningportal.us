export default async function handler(req, res) {
    try {
        const path = await import('path');
        const { fileURLToPath } = await import('url');
        const __dirname = path.dirname(fileURLToPath(import.meta.url));
        return res.status(200).json({ __dirname, meta: import.meta.url });
    } catch (e) {
        return res.status(500).json({ error: e.message });
    }
}

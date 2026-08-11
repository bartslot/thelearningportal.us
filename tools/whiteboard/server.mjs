/**
 * A whiteboard both of us can draw on.
 *
 * The board lives in ONE file — board.json — and this serves it over two routes. Bart draws in the
 * browser; Claude reads and writes the same file directly from the repo. Neither side needs to know
 * the other exists: the file is the bridge.
 *
 * No dependencies, no build step. `node tools/whiteboard/server.mjs`
 */
import { createServer } from 'node:http'
import { readFile, writeFile, stat } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const DIR = dirname(fileURLToPath(import.meta.url))
const BOARD = join(DIR, 'board.json')
const PORT = Number(process.env.PORT ?? 4599)

// The file's own mtime is the version. Anything else would be a second source of truth to keep in
// sync with it, and would go stale the moment someone edits board.json in an editor.
const version = () => stat(BOARD).then((s) => String(s.mtimeMs), () => '0')

const send = (res, code, body, type = 'application/json') =>
  res.writeHead(code, { 'content-type': type, 'cache-control': 'no-store' }).end(body)

createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost')

  if (url.pathname === '/board' && req.method === 'GET') {
    const snapshot = await readFile(BOARD, 'utf8').catch(() => 'null')
    return send(res, 200, `{"version":${JSON.stringify(await version())},"snapshot":${snapshot}}`)
  }

  if (url.pathname === '/board' && req.method === 'PUT') {
    const chunks = []
    for await (const chunk of req) chunks.push(chunk)
    await writeFile(BOARD, Buffer.concat(chunks))
    return send(res, 200, `{"version":${JSON.stringify(await version())}}`)
  }

  // The bundled tldraw app. Vendored rather than pulled from a CDN so the board opens offline and
  // does not change under us the day the CDN resolves a different version. `npm run build` remakes it.
  if (url.pathname.startsWith('/vendor/') && !url.pathname.includes('..')) {
    const asset = await readFile(join(DIR, url.pathname.slice(1))).catch(() => null)
    const type = url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'
    return asset ? send(res, 200, asset, type) : send(res, 404, '{"error":"run: npm run build"}')
  }

  const page = await readFile(join(DIR, 'index.html'), 'utf8').catch(() => null)
  return page
    ? send(res, 200, page, 'text/html; charset=utf-8')
    : send(res, 404, '{"error":"index.html is missing"}')
}).listen(PORT, () => {
  console.log(`whiteboard  http://localhost:${PORT}`)
  console.log(`board file  ${BOARD}`)
})

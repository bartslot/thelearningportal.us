/**
 * serve.mjs — bundle the harness and serve it, with the app's own `public/` underneath.
 *
 *   node tools/sun-harness/serve.mjs --port 5212
 *
 * esbuild rather than Vite because the harness must not depend on the Laravel app booting: the
 * point of it is to exercise the globe layers on their own, so a broken PHP install or a missing
 * queue worker cannot be the reason the sun did not appear.
 */

import esbuild from 'esbuild'
import http from 'node:http'
import { readFile, mkdir, copyFile, writeFile } from 'node:fs/promises'
import { createReadStream, existsSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`)
  return i > -1 ? process.argv[i + 1] : fallback
}

const PORT = Number(arg('port', 5212))
// Anchored to this file, not to the working directory: the harness has to serve the worktree it
// lives in, whichever directory the process happens to be launched from.
const HERE = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.resolve(HERE, '../..')
const PUBLIC = path.join(ROOT, 'public')
const OUT = path.join(HERE, '.build')
const SHOTS = path.join(HERE, 'shots')

const TYPES = {
  '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.pbf': 'application/x-protobuf',
  '.geojson': 'application/json', '.svg': 'image/svg+xml',
}

await mkdir(OUT, { recursive: true })
// MapLibre's stylesheet is a plain file in the package; copy it rather than bundling CSS.
await copyFile(path.join(ROOT, 'node_modules/maplibre-gl/dist/maplibre-gl.css'), path.join(OUT, 'maplibre-gl.css'))

const context = await esbuild.context({
  entryPoints: [path.join(HERE, 'harness.js')],
  bundle: true,
  format: 'esm',
  sourcemap: 'inline',
  outfile: path.join(OUT, 'harness.js'),
  logLevel: 'info',
})
await context.rebuild()
await context.watch()

const send = (res, file) => {
  const type = TYPES[path.extname(file)] || 'application/octet-stream'
  // Vector tiles on disk are gzipped in place, which is how MapLibre wants them over the wire.
  const headers = { 'content-type': type }
  if (file.endsWith('.pbf')) headers['content-encoding'] = 'gzip'
  res.writeHead(200, headers)
  createReadStream(file).pipe(res)
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`)
  const name = decodeURIComponent(url.pathname)

  // The page cannot write a file and the agent cannot read the framebuffer, so the PNG comes back
  // over the wire. Same reason as the probe layer: the pane runs without `preserveDrawingBuffer`,
  // so the only image that exists is the one built from `readPixels` inside the render pass.
  if (req.method === 'POST' && name === '/shot') {
    const chunks = []
    for await (const chunk of req) chunks.push(chunk)
    const { file, dataUrl } = JSON.parse(Buffer.concat(chunks).toString())
    const safe = path.basename(String(file || 'shot')).replace(/[^\w.-]/g, '_')
    const target = path.join(SHOTS, safe.endsWith('.png') ? safe : `${safe}.png`)
    await mkdir(SHOTS, { recursive: true })
    await writeFile(target, Buffer.from(String(dataUrl).split(',')[1], 'base64'))
    res.writeHead(200, { 'content-type': 'application/json' })
    return void res.end(JSON.stringify({ written: target }))
  }

  if (name === '/' || name === '/index.html') {
    res.writeHead(200, { 'content-type': 'text/html' })
    return void res.end(await readFile(path.join(HERE, 'index.html')))
  }
  if (name === '/harness.js') return send(res, path.join(OUT, 'harness.js'))
  if (name === '/vendor/maplibre-gl.css') return send(res, path.join(OUT, 'maplibre-gl.css'))

  const asset = path.join(PUBLIC, name)
  // Never serve outside public/, whatever a request claims its path is.
  if (asset.startsWith(PUBLIC) && existsSync(asset) && statSync(asset).isFile()) return send(res, asset)

  res.writeHead(404, { 'content-type': 'text/plain' })
  res.end('not found')
})

server.listen(PORT, () => console.log(`sun harness on http://localhost:${PORT}`))

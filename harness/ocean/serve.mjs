/**
 * serve.mjs — build the harness bundle and serve it, with the real assets alongside.
 *
 * Three roots on one origin, because the layer fetches its distance field by absolute path and a
 * harness that served it from somewhere else would not be testing the same code:
 *
 *   /                      the harness page and its bundle (built here, into memory-on-disk)
 *   /timemap/...           public/timemap — the baked fields, exactly where the layer looks
 *   /vendor/maplibre-gl.css  from node_modules, so nothing is fetched off the network
 */

import { createServer } from 'node:http'
import { readFile, writeFile, mkdir } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { dirname, extname, join, resolve, basename } from 'node:path'
import { fileURLToPath } from 'node:url'
import * as esbuild from 'esbuild'

const HERE = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(HERE, '../..')
const PORT = Number(process.env.PORT || 5183)

const TYPES = {
  '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css',
  '.webp': 'image/webp', '.png': 'image/png', '.json': 'application/json',
}

const build = await esbuild.context({
  entryPoints: [join(HERE, 'main.js')],
  bundle: true,
  outfile: join(HERE, '.build', 'bundle.js'),
  format: 'iife',
  sourcemap: 'inline',
  logLevel: 'info',
})
await build.rebuild()

const ROUTES = [
  ['/bundle.js', join(HERE, '.build', 'bundle.js')],
  ['/', join(HERE, 'index.html')],
  ['/vendor/maplibre-gl.css', join(REPO, 'node_modules/maplibre-gl/dist/maplibre-gl.css')],
]

const SHOTS = join(HERE, '.build', 'shots')

createServer(async (request, response) => {
  const path = new URL(request.url, 'http://localhost').pathname

  // The page hands finished frames back here to be written to disk. A PNG built from readPixels is
  // the only image of this map that is trustworthy — see the preserveDrawingBuffer note in main.js.
  if (request.method === 'POST' && path.startsWith('/shot/')) {
    const chunks = []
    for await (const chunk of request) chunks.push(chunk)
    await mkdir(SHOTS, { recursive: true })
    const name = basename(path.slice('/shot/'.length)).replace(/[^\w.-]/g, '_')
    const file = join(SHOTS, name.endsWith('.png') ? name : `${name}.png`)
    await writeFile(file, Buffer.concat(chunks))
    console.log(`saved ${file} (${(Buffer.concat(chunks).length / 1024).toFixed(0)} KB)`)
    response.writeHead(200, { 'access-control-allow-origin': '*' }).end(file)
    return
  }

  let file = ROUTES.find(([route]) => route === path)?.[1]
  // Anything under /timemap is a real committed asset, served from where it really lives.
  if (!file && path.startsWith('/timemap/')) file = join(REPO, 'public', path)
  if (!file && path.startsWith('/compare/')) file = join(HERE, '.build', path.slice('/compare/'.length))

  if (!file || !existsSync(file)) {
    response.writeHead(404).end('not here')
    return
  }
  response.writeHead(200, {
    'content-type': TYPES[extname(file)] || 'application/octet-stream',
    'cache-control': 'no-store',
    'access-control-allow-origin': '*',
  })
  response.end(await readFile(file))
}).listen(PORT, () => console.log(`ocean harness on http://localhost:${PORT}`))

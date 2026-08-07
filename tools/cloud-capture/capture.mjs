/**
 * capture.mjs — render the cloud dive to a transparent video.
 *
 * Drives the capture page frame by frame in headless Chromium, then packs the frames with ffmpeg.
 *
 *   node tools/cloud-capture/capture.mjs --fps 30 --seconds 10.5 --width 1920 --height 1080
 *
 * ALPHA PACKING, not an alpha codec. WebM/VP9 with alpha does not play on Safari or iOS, and this
 * app ships an iOS PWA — so a video that only works in Chrome is a video that fails in half the
 * classroom. Instead the colour goes in the top half of an ordinary H.264 frame and the alpha, as
 * greyscale, in the bottom half; playback recombines them in a two-line shader. Costs double the
 * pixels, works in every browser there is, needs no special codec support.
 */

import { chromium } from '@playwright/test'
import { spawn } from 'node:child_process'
import { mkdir, writeFile, rm } from 'node:fs/promises'
import path from 'node:path'

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`)
  return i > -1 ? process.argv[i + 1] : fallback
}

const FPS = Number(arg('fps', 30))
const SECONDS = Number(arg('seconds', 10.5))
const WIDTH = Number(arg('width', 1280))
const HEIGHT = Number(arg('height', 720))
const STEPS = Number(arg('steps', 96))
const URL = arg('url', 'http://localhost:5211/capture.html')
const OUT_DIR = arg('out', path.resolve('tools/cloud-capture/out'))
const FRAME_DIR = path.join(OUT_DIR, 'frames')

const run = (cmd, args) => new Promise((resolve, reject) => {
  const child = spawn(cmd, args, { stdio: ['ignore', 'ignore', 'pipe'] })
  let stderr = ''
  child.stderr.on('data', (d) => { stderr += d })
  child.on('close', (code) => (code === 0 ? resolve() : reject(new Error(`${cmd} exited ${code}\n${stderr.slice(-1500)}`))))
})

const main = async () => {
  await rm(FRAME_DIR, { recursive: true, force: true })
  await mkdir(FRAME_DIR, { recursive: true })

  const browser = await chromium.launch({
    headless: true,
    // SwiftShader keeps this reproducible on a machine with no GPU (CI); on a workstation ANGLE
    // will use the real one. Either way the frames are identical, because nothing is timing-based.
    args: ['--enable-unsafe-swiftshader', '--use-gl=angle', '--use-angle=default'],
  })
  const page = await browser.newPage({ viewport: { width: WIDTH, height: HEIGHT }, deviceScaleFactor: 1 })
  const failures = []
  page.on('pageerror', (e) => failures.push(String(e.message)))

  await page.goto(`${URL}?steps=${STEPS}`, { waitUntil: 'load' })
  const info = await page.evaluate(() => window.__captureReady)
  console.log(`canvas ${info.width}x${info.height}, track ${info.duration}s`)
  if (failures.length) throw new Error(`page errors: ${failures.join('; ')}`)

  const total = Math.round(SECONDS * FPS)
  const started = Date.now()
  for (let frame = 0; frame < total; frame++) {
    const seconds = frame / FPS
    const dataUrl = await page.evaluate((t) => window.__renderFrame(t), seconds)
    const png = Buffer.from(dataUrl.split(',')[1], 'base64')
    await writeFile(path.join(FRAME_DIR, `f${String(frame).padStart(5, '0')}.png`), png)
    if (frame % 15 === 0 || frame === total - 1) {
      const pace = (Date.now() - started) / (frame + 1)
      process.stdout.write(`\r  frame ${frame + 1}/${total}  ${pace.toFixed(0)} ms/frame  eta ${(((total - frame) * pace) / 1000).toFixed(0)}s   `)
    }
  }
  process.stdout.write('\n')
  await browser.close()

  // Colour on top, alpha beneath. yuv420p because it is the only pixel format every browser and
  // every phone decodes without complaint.
  const packed = path.join(OUT_DIR, 'cloud-dive.packed.mp4')
  await run('ffmpeg', [
    '-y', '-framerate', String(FPS), '-i', path.join(FRAME_DIR, 'f%05d.png'),
    '-filter_complex', '[0:v]split=2[colour][mask];[mask]alphaextract[alpha];[colour][alpha]vstack=inputs=2,format=yuv420p[v]',
    '-map', '[v]', '-c:v', 'libx264', '-preset', 'slow', '-crf', '20',
    '-movflags', '+faststart', packed,
  ])

  // A straight VP9-alpha version too, for browsers that do support it — smaller, and no unpacking
  // shader needed. Playback picks whichever the browser can actually play.
  const webm = path.join(OUT_DIR, 'cloud-dive.alpha.webm')
  await run('ffmpeg', [
    '-y', '-framerate', String(FPS), '-i', path.join(FRAME_DIR, 'f%05d.png'),
    '-c:v', 'libvpx-vp9', '-pix_fmt', 'yuva420p', '-b:v', '0', '-crf', '32', '-row-mt', '1', webm,
  ]).catch((e) => console.warn(`  (no VP9-alpha build available, packed mp4 only: ${e.message.split('\n')[0]})`))

  console.log(`\npacked  ${packed}`)
  console.log(`webm    ${webm}`)
}

main().catch((e) => { console.error(e); process.exit(1) })

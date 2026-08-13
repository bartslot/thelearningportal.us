/**
 * perf.mjs — frame cost, measured somewhere the clock can be trusted.
 *
 *   node tools/sun-harness/serve.mjs --port 5212      # in another terminal
 *   node tools/sun-harness/perf.mjs
 *
 * WHY NOT JUST MEASURE IN THE AGENT'S BROWSER PANE. Pixels read there are trustworthy; time is not.
 * The page is held hidden, so its scheduling is throttled and its 95th percentile is full of the
 * throttle rather than of the GPU. The median survives that — a frame takes as long as it takes,
 * however rarely frames happen — but a number that matters deserves a second opinion from a browser
 * that is not being throttled at all.
 *
 * Both measurements bracket the frame with `gl.finish()` in a custom layer at each end, which is
 * the only way to get an honest figure out of WebGL: commands are queued, so a plain CPU timer
 * around a repaint stops long before the GPU has started, and has been measured reporting a 49 ms
 * layer as 0.28 ms.
 */

import { chromium } from '@playwright/test'

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`)
  return i > -1 ? process.argv[i + 1] : fallback
}

const URL = arg('url', 'http://localhost:5212/')
const WIDTH = Number(arg('w', 1280))
const HEIGHT = Number(arg('h', 720))
const FRAMES = Number(arg('frames', 240))

const browser = await chromium.launch({
  headless: true,
  // The real GPU through ANGLE. Without this Chromium falls back to SwiftShader, which is a CPU
  // rasteriser: the numbers are then perfectly repeatable and describe a machine nobody has.
  args: ['--use-gl=angle', '--use-angle=default', '--enable-gpu', '--ignore-gpu-blocklist'],
})

try {
  const page = await browser.newPage({ viewport: { width: WIDTH + 40, height: HEIGHT + 40 } })
  const errors = []
  page.on('pageerror', (error) => errors.push(String(error)))
  await page.goto(`${URL}?w=${WIDTH}&h=${HEIGHT}`, { waitUntil: 'load' })
  await page.waitForFunction(() => window.sunHarnessReady === true, null, { timeout: 20000 })

  const renderer = await page.evaluate(() => {
    const gl = document.querySelector('canvas').getContext('webgl2') || document.querySelector('canvas').getContext('webgl')
    const info = gl && gl.getExtension('WEBGL_debug_renderer_info')
    return info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : 'unknown'
  })

  const results = await page.evaluate(async (frames) => {
    const H = window.sunHarness
    const out = {}
    await H.view({ beyondLimb: 0.9, radii: 5 })
    const run = async (label, apply) => {
      apply()
      await H.frames(4)
      out[label] = await H.benchmark(frames)
    }
    await run('allLayers', () => { H.layers.bloom.setOptions({ strength: 0.9 }); H.layers.sun.setOptions({ brightness: 1 }) })
    await run('withoutBloom', () => { H.layers.bloom.setOptions({ strength: 0 }) })
    await run('withoutSunOrBloom', () => { H.layers.sun.setOptions({ brightness: 0 }) })
    return out
  }, FRAMES)

  // Deltas from the MEAN, not the median: performance.now() is clamped to 100 microseconds, so a
  // pass cheaper than that has a median of either 0 or 0.1 and a difference of exactly nothing.
  const cost = (a, b) => Number((results[a].meanMs - results[b].meanMs).toFixed(4))
  console.log(JSON.stringify({
    renderer,
    canvas: results.allLayers.canvas,
    frameMeanMs: {
      allLayers: results.allLayers.meanMs,
      withoutBloom: results.withoutBloom.meanMs,
      withoutSunOrBloom: results.withoutSunOrBloom.meanMs,
    },
    frameMedianMs: results.allLayers.medianMs,
    bloomPassMs: cost('allLayers', 'withoutBloom'),
    sunLayerMs: cost('withoutBloom', 'withoutSunOrBloom'),
    impliedFps: results.allLayers.impliedFps,
    p95Ms: results.allLayers.p95Ms,
    pageErrors: errors,
  }, null, 2))
} finally {
  await browser.close()
}

/** Generate a seamless grayscale paper-grain tile → public/timemap/parchment.png.
 * Overlaid at low opacity (mix-blend: overlay) on the map for a papery look. Replace this file
 * with your own scanned parchment if you prefer. Run: node scripts/build-parchment.mjs */
import { resolve } from 'node:path'
import { chromium } from 'playwright'
const SIZE = 512
const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${SIZE}" height="${SIZE}">
  <filter id="paper" x="0" y="0" width="100%" height="100%">
    <feTurbulence type="fractalNoise" baseFrequency="0.62 0.74" numOctaves="4" seed="7" stitchTiles="stitch" result="n"/>
    <feColorMatrix in="n" type="saturate" values="0"/>
    <feComponentTransfer><feFuncA type="linear" slope="1"/></feComponentTransfer>
    <feColorMatrix type="matrix" values="0 0 0 0 0.5  0 0 0 0 0.5  0 0 0 0 0.5  0 0 0 0.85 0"/>
  </filter>
  <rect width="100%" height="100%" fill="#808080"/>
  <rect width="100%" height="100%" filter="url(#paper)"/>
</svg>`
const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: SIZE, height: SIZE } })
await p.setContent(`<!doctype html><body style="margin:0">${svg}</body>`)
await p.locator('svg').screenshot({ path: resolve('public/timemap/parchment.png') })
await b.close(); console.log('parchment.png', SIZE + 'x' + SIZE)

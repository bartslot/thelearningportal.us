/**
 * build-glyphs.mjs — generate MapLibre SDF glyph PBFs for the calligraphy label fonts, WITHOUT any
 * native tooling. TinySDF runs inside a Playwright page (real Canvas) with the font loaded from
 * Google Fonts via @font-face; the SDF bitmaps come back to Node and we encode the glyph PBF with
 * `pbf` (the same protobuf MapLibre expects).
 *
 * Output: public/fonts/<Font Name>/<start>-<end>.pbf  (ranges 0-255, 256-511 — Latin + diacritics)
 * The map's style `glyphs` URL then points at /fonts/{fontstack}/{range}.pbf (local, no runtime CDN).
 *
 * Run: node scripts/build-glyphs.mjs
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { chromium } from 'playwright'
import { PbfWriter as Pbf } from 'pbf'

// jobs: { gf = Google Fonts family spec, family = CSS family, weight, style, out = fontstack name }
const JOBS = [
  { gf: 'Eagle+Lake', family: 'Eagle Lake', weight: 400, style: 'normal', out: 'Eagle Lake' },
  { gf: 'Cinzel:wght@600', family: 'Cinzel', weight: 600, style: 'normal', out: 'Cinzel' },
  { gf: 'Inter:wght@500', family: 'Inter', weight: 500, style: 'normal', out: 'inter' }, // app sans — modern (city) names. lowercase folder = matches existing inter/ webfonts (case-sensitive prod)
]
// Basic Latin + Latin-1 + Latin Extended-A/B + IPA + combining/Greek + Latin Extended Additional
// (the last covers transliterated place names: ḥ ṣ ṭ ā … — avoids missing-glyph 404s on the map).
const RANGES = [[0, 255], [256, 511], [512, 767], [768, 1023], [7680, 7935]]
const FONT_SIZE = 24, BUFFER = 3, RADIUS = 8, CUTOFF = 0.25

// TinySDF source, transformed so it attaches to window inside the page.
const tinySdfSrc = readFileSync(resolve('node_modules/@mapbox/tiny-sdf/index.js'), 'utf8')
  .replace('export default class TinySDF', 'window.TinySDF = class TinySDF')

// glyphs.proto encoders (field numbers per the MapLibre/Mapbox glyph spec)
function writeGlyph (g, pbf) {
  pbf.writeVarintField(1, g.id)
  if (g.bitmap && g.bitmap.length) pbf.writeBytesField(2, g.bitmap)
  pbf.writeVarintField(3, g.width)
  pbf.writeVarintField(4, g.height)
  pbf.writeSVarintField(5, g.left)
  pbf.writeSVarintField(6, g.top)
  pbf.writeVarintField(7, g.advance)
}
function writeStack (s, pbf) {
  pbf.writeStringField(1, s.name)
  pbf.writeStringField(2, s.range)
  for (const g of s.glyphs) pbf.writeMessage(3, writeGlyph, g)
}
function encode (stack) {
  const pbf = new Pbf()
  pbf.writeMessage(1, writeStack, stack)
  return Buffer.from(pbf.finish())
}

const browser = await chromium.launch()
for (const job of JOBS) {
  const page = await browser.newPage()
  await page.setContent(`<!doctype html><head>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=${job.gf}&display=block"></head><body></body>`)
  await page.addScriptTag({ content: tinySdfSrc })
  await page.evaluate(async (f) => { await document.fonts.load(`${f.weight} 24px "${f.family}"`); await document.fonts.ready }, job)

  const dir = resolve('public/fonts', job.out)
  mkdirSync(dir, { recursive: true })

  for (const [start, end] of RANGES) {
    const glyphs = await page.evaluate(({ start, end, job, FONT_SIZE, BUFFER, RADIUS, CUTOFF }) => {
      const sdf = new window.TinySDF({ fontSize: FONT_SIZE, buffer: BUFFER, radius: RADIUS, cutoff: CUTOFF, fontFamily: job.family, fontWeight: String(job.weight), fontStyle: job.style })
      const out = []
      for (let code = start; code <= end; code++) {
        const ch = String.fromCharCode(code)
        // skip control chars
        if (code < 32) continue
        const g = sdf.draw(ch)
        const hasInk = g.glyphWidth > 0 && g.glyphHeight > 0
        out.push({
          id: code,
          width: g.glyphWidth, height: g.glyphHeight,
          left: g.glyphLeft, top: g.glyphTop,
          advance: Math.round(g.glyphAdvance),
          bitmap: hasInk ? Array.from(g.data) : null,
        })
      }
      return out
    }, { start, end, job, FONT_SIZE, BUFFER, RADIUS, CUTOFF })

    // keep only glyphs that have a real advance or ink (drop empties to shrink files)
    const useful = glyphs.filter((g) => g.advance > 0 || g.bitmap)
    const stack = {
      name: job.out,
      range: `${start}-${end}`,
      glyphs: useful.map((g) => ({ ...g, bitmap: g.bitmap ? Buffer.from(g.bitmap) : null })),
    }
    writeFileSync(resolve(dir, `${start}-${end}.pbf`), encode(stack))
    console.log(`${job.out} ${start}-${end}: ${useful.length} glyphs`)
  }
  await page.close()
}
await browser.close()
console.log('done →', JOBS.map((j) => j.out).join(', '))

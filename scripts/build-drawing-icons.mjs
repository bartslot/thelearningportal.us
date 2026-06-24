/**
 * build-drawing-icons.mjs — extract the terrain assets from the Inkscape "drawing.svg", which groups
 * artwork by terrain type via inkscape:label (volcanoes, sanddunes, hills→sandhill/rounded_hills,
 * mountains→ranges). Each labelled group holds several individual icons (its child <path>s).
 *
 * Output (ink-on-transparent, normalized to a target height per type):
 *   public/timemap/assets/dunes/      <- sanddunes      + manifest.json {ids:[…]}
 *   public/timemap/assets/hills/      <- rounded_hills+sandhill + manifest.json
 *   public/timemap/assets/volcanoes/  <- volcanoes      + manifest.json
 *   public/timemap/assets/ranges/     <- the 4 detailed ranges + manifest.json
 * Also refreshes the dune list in public/timemap/assets/scatter/manifest.json so the desert scatter
 * uses these real dunes (run build-terrain-icons.mjs first; then this; then build-land-scatter.mjs).
 *
 * Run: node scripts/build-drawing-icons.mjs
 */
import { readFileSync, writeFileSync, readdirSync, unlinkSync, mkdirSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { chromium } from 'playwright'

const SRC = '/Users/bartslot/Downloads/drawing.svg'
const ASSETS = resolve('public/timemap/assets')
const INK = '#3a2c1a'

// terrain group label → { dir, targetH, mirror } . `dir` under assets/. Each child path = one icon.
const GROUPS = {
  sanddunes:     { dir: 'dunes',     targetH: 7,  mirror: true },
  rounded_hills: { dir: 'hills',     targetH: 10, mirror: true, prefix: 'r' },
  sandhill:      { dir: 'hills',     targetH: 10, mirror: true, prefix: 's' },
  volcanoes:     { dir: 'volcanoes', targetH: 18, mirror: false },
}
// single-icon range labels (children=0 or a shadow+body group)
const RANGES = ['horizontal_range', 'vertical_range', 'medium_vertical_range', 'small_vertical_range']

const raw = readFileSync(SRC, 'utf8')
const browser = await chromium.launch()
const page = await browser.newPage()
await page.setContent(`<!doctype html><body style="margin:0">${raw}</body>`)

const data = await page.evaluate(({ groupLabels, rangeLabels }) => {
  const svg = document.querySelector('svg')
  const labelOf = (e) => e.getAttribute('inkscape:label') || e.getAttributeNS('http://www.inkscape.org/namespaces/inkscape', 'label')
  const find = (lab) => [...svg.querySelectorAll('*')].find((e) => labelOf(e) === lab)
  function rootBBox (el) {
    const bb = el.getBBox(); const m = el.getCTM()
    const xs = [], ys = []
    for (const [x, y] of [[bb.x, bb.y], [bb.x + bb.width, bb.y], [bb.x, bb.y + bb.height], [bb.x + bb.width, bb.y + bb.height]]) {
      xs.push(m.a * x + m.c * y + m.e); ys.push(m.b * x + m.d * y + m.f)
    }
    return { x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) }
  }
  const pctm = (el) => { const m = el.parentNode.getCTM(); return `matrix(${m.a},${m.b},${m.c},${m.d},${m.e},${m.f})` }
  const out = { groups: {}, ranges: {} }
  for (const lab of groupLabels) {
    const g = find(lab); if (!g) continue
    out.groups[lab] = [...g.children].filter((c) => c.tagName.toLowerCase() === 'path').map((c) => {
      const bb = rootBBox(c); return (bb.w && bb.h) ? { bb, pctm: pctm(c), html: c.outerHTML } : null
    }).filter(Boolean)
  }
  for (const lab of rangeLabels) {
    const el = find(lab); if (!el) continue
    const bb = rootBBox(el)
    out.ranges[lab] = { bb, pctm: pctm(el), html: el.outerHTML }
  }
  return out
}, { groupLabels: Object.keys(GROUPS), rangeLabels: RANGES })
await browser.close()

const PAD = 1
const SHADOW_INK = '#241b10' // the cast-shadow tone (darker than the ink)
// Build an icon. With shadow=true a darker SILHOUETTE of the same art is laid behind, offset
// down-LEFT (sun upper-right) — since the art is filled shapes it peeks out as a shaded relief
// face, stronger than a blurred drop-shadow and matching the mountains' lit side.
function iconSvg (it, targetH, flip, shadow = false) {
  const x = it.bb.x - PAD, y = it.bb.y - PAD, w = it.bb.w + PAD * 2, h = it.bb.h + PAD * 2
  const s = targetH / h
  const W = +(w * s).toFixed(2)
  const tag = `matrix(${s.toFixed(4)},0,0,${s.toFixed(4)},${(-x * s).toFixed(3)},${(-y * s).toFixed(3)})`
  const norm = (html) => `<g transform="${tag}"><g transform="${it.pctm}">${html}</g></g>`
  const wrapFlip = (g) => (flip ? `<g transform="translate(${W} 0) scale(-1 1)">${g}</g>` : g)
  const ink = wrapFlip(norm(it.html.replace(/fill:#000000/g, `fill:${INK}`).replace(/fill="#000000"/g, `fill="${INK}"`)))
  const head = '<?xml version="1.0" encoding="UTF-8"?>'
  if (!shadow) return `${head}<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${targetH}">${ink}</svg>`
  const shade = wrapFlip(norm(it.html.replace(/fill:#000000/g, `fill:${SHADOW_INK}`).replace(/fill="#000000"/g, `fill="${SHADOW_INK}"`)))
  const ox = -(targetH * 0.045).toFixed(3), oy = +(targetH * 0.06).toFixed(3)
  const M = +(targetH * 0.09).toFixed(2) // padding so the offset shadow isn't clipped
  return `${head}<svg xmlns="http://www.w3.org/2000/svg" viewBox="${-M} ${-M} ${(W + 2 * M).toFixed(2)} ${(targetH + 2 * M).toFixed(2)}"><g transform="translate(${ox} ${oy})" opacity="0.5">${shade}</g>${ink}</svg>`
}

function wipe (dir) {
  if (!existsSync(dir)) { mkdirSync(dir, { recursive: true }); return }
  for (const f of readdirSync(dir)) if (/\.svg$/.test(f)) unlinkSync(resolve(dir, f))
}

const manifests = {}
for (const [lab, cfg] of Object.entries(GROUPS)) {
  const dir = resolve(ASSETS, cfg.dir)
  if (!manifests[cfg.dir]) { wipe(dir); manifests[cfg.dir] = [] }
  const pre = cfg.prefix || cfg.dir[0]
  data.groups[lab].forEach((it, i) => {
    for (const flip of (cfg.mirror ? [false, true] : [false])) {
      const id = `${pre}-${i}${flip ? 'f' : ''}`
      writeFileSync(resolve(dir, `${id}.svg`), iconSvg(it, cfg.targetH, flip))
      manifests[cfg.dir].push(id)
    }
  })
}
// ranges (one icon each)
{
  const dir = resolve(ASSETS, 'ranges'); wipe(dir); manifests.ranges = []
  for (const [lab, it] of Object.entries(data.ranges)) {
    const targetH = lab.startsWith('horizontal') ? 16 : 30
    const id = lab
    writeFileSync(resolve(dir, `${id}.svg`), iconSvg(it, targetH, false))
    manifests.ranges.push(id)
  }
}
for (const [dir, ids] of Object.entries(manifests)) {
  writeFileSync(resolve(ASSETS, dir, 'manifest.json'), JSON.stringify({ ids }, null, 2))
}

// refresh the desert dune list in the scatter manifest to point at the real dunes
const scatMan = resolve(ASSETS, 'scatter/manifest.json')
if (existsSync(scatMan)) {
  const m = JSON.parse(readFileSync(scatMan, 'utf8'))
  // copy dune svgs into the scatter folder so the one scatter layer can render them
  const duneIds = manifests.dunes.map((id) => `dune-${id}`)
  manifests.dunes.forEach((id, k) => {
    const svg = readFileSync(resolve(ASSETS, 'dunes', `${id}.svg`), 'utf8')
    writeFileSync(resolve(ASSETS, 'scatter', `${duneIds[k]}.svg`), svg)
  })
  // drop old hand-drawn s-dune-* from scatter folder
  for (const f of readdirSync(resolve(ASSETS, 'scatter'))) if (/^s-dune-.*\.svg$/.test(f)) unlinkSync(resolve(ASSETS, 'scatter', f))
  m.dune = duneIds
  writeFileSync(scatMan, JSON.stringify(m, null, 2))
}

// ---- Patch the MOUNTAIN set (built from mountains.svg by build-terrain-icons.mjs, run first):
//      low tiers become gentle hills; the detailed ranges join the large tier WITH a vector shadow.
const MTN = resolve(ASSETS, 'mountains')
if (existsSync(resolve(MTN, 'manifest.json'))) {
  const mMan = JSON.parse(readFileSync(resolve(MTN, 'manifest.json'), 'utf8'))
  for (const f of readdirSync(MTN)) if (/^m-(smaller|small)-/.test(f)) unlinkSync(resolve(MTN, f)) // drop low peak glyphs
  const rounded = data.groups.rounded_hills || [], sand = data.groups.sandhill || []
  const writeTier = (tier, items, h) => {
    mMan[tier] = items.map((it, i) => { const id = `m-${tier}-h${i}`; writeFileSync(resolve(MTN, `${id}.svg`), iconSvg(it, h, false, false)); return id })
  }
  writeTier('smaller', rounded.slice(0, 6), 11)               // lowest = gentle rounded hills
  writeTier('small', [...rounded.slice(0, 4), ...sand.slice(0, 4)], 15)
  // detailed ranges → extra large-tier variants, with the consistent vector sun-shadow
  for (const [lab, th] of [['horizontal_range', 30], ['medium_vertical_range', 34]]) {
    const it = data.ranges[lab]; if (!it) continue
    const id = `m-large-${lab}`; writeFileSync(resolve(MTN, `${id}.svg`), iconSvg(it, th, false, true)); mMan.large.push(id)
  }
  writeFileSync(resolve(MTN, 'manifest.json'), JSON.stringify(mMan, null, 2))
  console.log(`mountains patched: smaller/small → hills, large += ranges (shadowed)`)
}

console.log(Object.entries(manifests).map(([d, ids]) => `${d}×${ids.length}`).join(', '))

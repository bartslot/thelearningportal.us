/**
 * build-terrain-icons.mjs — extract the detailed pen-ink mountains + tree-cluster groves from the
 * master art sheet and write them as the live map icons, with VARIANTS per size so the field isn't
 * one glyph repeated. Each base shape is also written mirrored (…f) to double the apparent variety;
 * the builders (build-mountain-points / build-forest-points) pick a variant per feature at random.
 *
 * Source: the "SGD - Terrain 1" master sheet. Pieces are matched by SHAPE SIGNATURE (bbox w×h +
 * child count), NOT raw index — the sheet gets re-exported and indices shift, but the drawings keep
 * their proportions. Update the PIECES signatures if the art is redrawn (run scripts/list-pieces.mjs
 * to read current bboxes).
 *
 * Output:
 *   public/timemap/assets/mountains/<id>.svg   + manifest.json  { smaller:[ids], small:[…], … }
 *   public/timemap/assets/forests/<id>.svg      + manifest.json  { small:[ids], medium:[…] }
 * Icons are ink-on-transparent (mountains) / parchment-canopy + ink outline (groves), normalized to
 * a target height so the size ladder is explicit and the layer's icon-size scales predictably.
 */
import { readFileSync, writeFileSync, readdirSync, unlinkSync, mkdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { chromium } from 'playwright'

const SRC = '/Users/bartslot/Downloads/SGD - Terrain 1/vector/mountains.svg'
const MTN = resolve('public/timemap/assets/mountains')
const FOR = resolve('public/timemap/assets/forests')
const SCT = resolve('public/timemap/assets/scatter')
const INK = '#3a2c1a'

// Catalogue of the drawings we use, by shape signature { w, h, n } (bbox + child count).
const PIECES = {
  massif:    { w: 32.7, h: 27.3, n: 3 }, // grand multi-peak massif
  peak:      { w: 27.4, h: 17.0, n: 2 }, // tall sharp peak
  range:     { w: 18.7, h: 8.5,  n: 0 }, // detailed ridge line
  foothills: { w: 15.9, h: 14.7, n: 0 }, // craggy foothills
  ridge:     { w: 14.6, h: 4.3,  n: 0 }, // low ridge
  dab:       { w: 7.7,  h: 5.8,  n: 2 },  // scattered brush dabs → forest/scrub + land texture
}
// size tier → the pieces drawn at that height (mixing shapes per tier breaks the repetition; the
// same shape reused at a smaller height is a fine extra variant).
const MOUNTAIN_VARIANTS = {
  smaller: ['ridge', 'foothills'],
  small:   ['ridge', 'range', 'foothills'],
  medium:  ['range', 'foothills', 'peak'],
  large:   ['massif', 'peak'],
}
const TREE_VARIANTS = {
  small:  ['dab'],
  medium: ['dab'],
}
const TARGET_H = { smaller: 12, small: 16, medium: 24, large: 33, tree_small: 18, tree_medium: 24 }

const raw = readFileSync(SRC, 'utf8')
const b = await chromium.launch(); const page = await b.newPage()
await page.setContent(`<!doctype html><body style="margin:0">${raw}</body>`)
// pull every child's bbox + child count + markup, then signature-match each named piece.
const children = await page.evaluate(() => {
  const kids = [...document.querySelector('svg').children].filter(e => e.tagName !== 'defs')
  return kids.map((el) => { let bb; try { bb = el.getBBox() } catch { bb = { x: 0, y: 0, width: 0, height: 0 } }; return { x: bb.x, y: bb.y, w: bb.width, h: bb.height, n: el.children.length, html: el.outerHTML } })
})
await b.close()

function matchPiece (sig) {
  let best = null, bestScore = Infinity
  for (const c of children) {
    if (!c.w || !c.h) continue
    const score = (c.w - sig.w) ** 2 + (c.h - sig.h) ** 2 + (c.n === sig.n ? 0 : 400)
    if (score < bestScore) { bestScore = score; best = c }
  }
  return best
}
const boxes = {}
for (const [name, sig] of Object.entries(PIECES)) {
  const m = matchPiece(sig)
  if (!m) throw new Error(`no match for piece "${name}"`)
  boxes[name] = m
  console.log(`piece ${name.padEnd(10)} → ${m.w.toFixed(1)}×${m.h.toFixed(1)} n=${m.n}`)
}

const PAD = 0.8
function frame (el, targetH) {
  const w = el.w + PAD * 2, h = el.h + PAD * 2
  const s = targetH / h
  const W = +(w * s).toFixed(2)
  return { W, s, open: `<g transform="matrix(${s.toFixed(4)},0,0,${s.toFixed(4)},${(-(el.x - PAD) * s).toFixed(3)},${(-(el.y - PAD) * s).toFixed(3)})">` }
}
// wrap with optional horizontal mirror (translate(W) scale(-1,1))
const flipWrap = (W, flip) => (flip ? `<g transform="translate(${W} 0) scale(-1 1)">` : '')
const flipClose = (flip) => (flip ? '</g>' : '')

function mountainSvg (el, targetH, flip) {
  const inner = el.html
    .replace(/<polygon[^>]*class="st0"[^>]*\/>/g, '').replace(/\sclass="st0"/g, '')
    .replace(/<polygon[^>]*id="bg[0-9]*"[^>]*\/>/g, '')
  const { W, open } = frame(el, targetH)
  return `<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${targetH}">${flipWrap(W, flip)}<g fill="${INK}">${open}${inner}</g></g>${flipClose(flip)}</svg>`
}

// wipe old generated icons (keep README / pen-ink reference files)
mkdirSync(SCT, { recursive: true })
for (const dir of [MTN, FOR, SCT]) {
  for (const f of readdirSync(dir)) {
    if (/^(m-|g-|s-|mountains_mountain-|tree-).*\.svg$/.test(f)) unlinkSync(resolve(dir, f))
  }
}

const mManifest = {}
for (const [size, names] of Object.entries(MOUNTAIN_VARIANTS)) {
  mManifest[size] = []
  names.forEach((name, v) => {
    for (const flip of [false, true]) {
      const id = `m-${size}-${v}${flip ? 'f' : ''}`
      writeFileSync(resolve(MTN, `${id}.svg`), mountainSvg(boxes[name], TARGET_H[size], flip))
      mManifest[size].push(id)
    }
  })
}
writeFileSync(resolve(MTN, 'manifest.json'), JSON.stringify(mManifest, null, 2))

// Forest = the scattered ink dabs, rendered like the mountains (ink on transparent), so a forest
// region reads as a stipple of brush-marks rather than uniform tree icons.
const fManifest = {}
for (const [size, names] of Object.entries(TREE_VARIANTS)) {
  fManifest[size] = []
  names.forEach((name, v) => {
    for (const flip of [false, true]) {
      const id = `g-${size}-${v}${flip ? 'f' : ''}`
      writeFileSync(resolve(FOR, `${id}.svg`), mountainSvg(boxes[name], TARGET_H['tree_' + size], flip))
      fManifest[size].push(id)
    }
  })
}
writeFileSync(resolve(FOR, 'manifest.json'), JSON.stringify(fManifest, null, 2))

// Scatter — texture sprinkled over empty land. Two terrains:
//  • tree : the dab cropped into distinct little clumps (varied scrub/woodland), ink dabs.
//  • dune : hand-drawn sand-dune ripples for deserts (where forest scatter is suppressed).
const sManifest = { tree: [], dune: [] }

// Frame an arbitrary sub-rect of a piece to targetH (lets us crop the dab into different clumps).
function frameRect (x, y, w, h, targetH) {
  const s = targetH / (h + PAD * 2)
  const W = +((w + PAD * 2) * s).toFixed(2)
  return { W, open: `<g transform="matrix(${s.toFixed(4)},0,0,${s.toFixed(4)},${(-(x - PAD) * s).toFixed(3)},${(-(y - PAD) * s).toFixed(3)})">` }
}
function dabClumpSvg (el, targetH, crop, flip) {
  const inner = el.html.replace(/class="st0"/g, '').replace(/<polygon[^>]*id="bg[0-9]*"[^>]*\/>/g, '')
  const sx = el.x + crop[0] * el.w, sw = (crop[1] - crop[0]) * el.w
  const { W, open } = frameRect(sx, el.y, sw, el.h, targetH)
  return `<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${targetH}">${flipWrap(W, flip)}<g fill="${INK}">${open}${inner}</g></g>${flipClose(flip)}</svg>`
}
const TREE_CROPS = [[0, 1], [0, 0.5], [0.5, 1], [0.25, 0.75], [0.1, 0.6]] // whole + 4 different clumps
TREE_CROPS.forEach((crop, ci) => {
  for (const flip of [false, true]) {
    const id = `s-tree-${ci}${flip ? 'f' : ''}`
    writeFileSync(resolve(SCT, `${id}.svg`), dabClumpSvg(boxes.dab, 12, crop, flip))
    sManifest.tree.push(id)
  }
})

// Dune icons are owned by scripts/build-drawing-icons.mjs (real sand-dune art from drawing.svg);
// it fills sManifest.dune after this script runs. Leave the list empty here.
writeFileSync(resolve(SCT, 'manifest.json'), JSON.stringify(sManifest, null, 2))

console.log('mountains:', Object.entries(mManifest).map(([k, v]) => `${k}×${v.length}`).join(', '))
console.log('forests:', Object.entries(fManifest).map(([k, v]) => `${k}×${v.length}`).join(', '))
console.log('scatter:', `tree×${sManifest.tree.length}, dune×${sManifest.dune.length}`)

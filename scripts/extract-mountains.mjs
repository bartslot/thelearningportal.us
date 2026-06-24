/**
 * extract-mountains.mjs — slice the named mountain glyphs out of an Illustrator terrain sheet
 * into the per-size icons the map's mountain layer consumes.
 *
 * The sheet (SGD terrain pack) holds <g id="small|smaller|medium|big"> groups, each a cream
 * silhouette <polygon class="st0"> + black ink line-art path(s). map-mountains.js loads each
 * size SVG, recolours its #bg silhouette to the live land colour, and rasterises by viewBox —
 * so each output must (1) carry id="bg" on the silhouette and (2) have a tight viewBox.
 *
 *   node scripts/extract-mountains.mjs "/path/to/mountains.svg"
 */
import { readFileSync, writeFileSync } from 'node:fs'

const SRC = process.argv[2] || '/Users/bartslot/Downloads/SGD - Terrain 1/vector/mountains.svg'
const OUT = 'public/timemap/assets/mountains/'
const SIZE_MAP = { big: 'large', medium: 'medium', small: 'small', smaller: 'smaller' }

const src = readFileSync(SRC, 'utf8')

/** Extract the balanced inner content of <g id="NAME"> … </g> (handles nested <g>). */
function extractGroup (svg, id) {
  const open = svg.indexOf(`<g id="${id}"`)
  if (open < 0) return null
  let i = svg.indexOf('>', open) + 1
  const start = i
  let depth = 1
  while (i < svg.length && depth > 0) {
    const nextOpen = svg.indexOf('<g', i)
    const nextClose = svg.indexOf('</g>', i)
    if (nextClose < 0) break
    if (nextOpen >= 0 && nextOpen < nextClose) { depth++; i = nextOpen + 2 } else { depth--; i = nextClose + 4 }
  }
  return svg.slice(start, i - 4)
}

/** Bounding box of the first <polygon points="…"> — the mountain's silhouette outline. */
function polygonBBox (content) {
  const m = content.match(/<polygon\b[^>]*\bpoints="([^"]+)"/)
  if (!m) return null
  const n = m[1].trim().split(/[\s,]+/).map(Number)
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (let k = 0; k + 1 < n.length; k += 2) {
    const x = n[k], y = n[k + 1]
    if (x < minX) minX = x; if (x > maxX) maxX = x
    if (y < minY) minY = y; if (y > maxY) maxY = y
  }
  return { minX, minY, w: maxX - minX, h: maxY - minY }
}

for (const [srcId, outName] of Object.entries(SIZE_MAP)) {
  let content = extractGroup(src, srcId)
  if (!content) { console.error(`✗ no <g id="${srcId}">`); continue }

  const bb = polygonBBox(content)
  if (!bb) { console.error(`✗ no silhouette polygon in ${srcId}`); continue }

  // The silhouette must be id="bg" so the map can recolour it; sheet uses bg/bg1/bg2/bg3.
  content = content.replace(/(<polygon\b[^>]*?)\bid="[^"]*"/, '$1id="bg"')
  if (!/<polygon\b[^>]*\bid="bg"/.test(content)) content = content.replace('<polygon', '<polygon id="bg"')

  const pad = Math.max(bb.w, bb.h) * 0.04 // small margin so ink ridges aren't clipped
  const vb = [bb.minX - pad, bb.minY - pad, bb.w + 2 * pad, bb.h + 2 * pad].map((v) => +v.toFixed(2)).join(' ')

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${vb}"><defs><style>.st0{fill:#e8dec4;}</style></defs>${content.trim()}</svg>`
  writeFileSync(OUT + `mountains_mountain-${outName}.svg`, svg)
  console.log(`✓ ${outName.padEnd(8)} viewBox="${vb}"  paths=${(content.match(/<path/g) || []).length}`)
}

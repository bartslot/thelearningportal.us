#!/usr/bin/env node
/**
 * build-sky-assets.mjs — turn two public-domain astronomy datasets into the two files the sky layer
 * ships: a panorama of the Milky Way, and a table of real stars.
 *
 * Run it once; the outputs are committed. It exists so the provenance of those files is code rather
 * than folklore, and so the tone curve baked into the panorama can be argued with.
 *
 *   node scripts/build-sky-assets.mjs
 *
 * WHY THESE SOURCES.
 *
 * The panorama is NASA SVS's Deep Star Maps 2020 "Milky Way background" — the one WITHOUT stars in
 * it. That sounds like the wrong file to pick for a sky, and it is exactly the right one here: the
 * stars are drawn separately from a catalogue, at their true positions and their true brightnesses.
 * A panorama with stars baked in would double every one of them, slightly offset, which reads as
 * blur. So the image carries only what a catalogue cannot: the nebulosity, the dust lanes, the glow.
 *
 * It is also in CELESTIAL coordinates (ICRF/J2000), not galactic — so it lines up with the star
 * catalogue and with the ephemeris the rest of this map already runs on, with no extra rotation.
 *
 * The stars are the Yale Bright Star Catalogue, 5th edition: about 9,100 stars, essentially every
 * star visible to the naked eye. It is small enough to ship whole.
 *
 * WHY 4096 AND NOT 8192 OR 16384 — measured, not assumed.
 *
 * The obvious move is the biggest file the budget allows. It was tried and it is the wrong one, for
 * a reason specific to this image: the top octave of the 8192-wide source is NOISE, not structure.
 * Halve it, put it back, and look at what is left over — the residual's lag-1 autocorrelation is
 * 0.08 across and -0.16 down in the galactic plane, which is the signature of uncorrelated shot
 * noise rather than of anything on the sky. Survey data has speckle, and the gamma below amplifies
 * it hard, because the gamma's whole job is to stretch the faint end where the speckle lives.
 *
 * The cost of encoding that noise is not small. At 8192 across it came to 16 MB, four times the
 * budget; blurring it enough to compress produced a 5.6 MB file whose extra pixels were, by
 * construction, blur. At 4096 the downscale averages the noise away for free, and 3.6 MB buys
 * near-lossless quality on all the structure there actually is.
 *
 * 4096 is also the largest texture every WebGL device is required to support, so nothing here can
 * fail as a silently black sky on school hardware.
 *
 * The sharpness in this sky does not come from the panorama anyway. It comes from the catalogue
 * stars, which are drawn analytically and have no resolution at all.
 */

import { execFileSync, execSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const CACHE = resolve(ROOT, 'storage/app/sky-sources')
const OUT_IMG = resolve(ROOT, 'public/img/map/sky')
const OUT_DATA = resolve(ROOT, 'public/data/sky')
const OUT_FIXTURE = resolve(ROOT, 'resources/js/timemap/__tests__/fixtures')

const SOURCES = {
  panorama: {
    url: 'https://svs.gsfc.nasa.gov/vis/a000000/a004800/a004851/milkyway_2020_8k.exr',
    file: 'milkyway_2020_8k.exr',
  },
  catalogue: {
    url: 'https://raw.githubusercontent.com/brettonw/YaleBrightStarCatalog/master/bsc5-short.json',
    file: 'bsc5-short.json',
  },
}

/**
 * The tone curve baked into the panorama.
 *
 * The source is linear high dynamic range: the galactic centre is a hundred times the brightness of
 * the emptiest sky, and almost all of the interesting structure lives in the bottom two percent of
 * that range. Written straight to eight bits it is a black image with one white smudge.
 *
 * So: subtract a black point, apply a gain, then a gamma. The black point is set just under the
 * darkest real sky so the galactic poles go black rather than muddy grey; the gain puts the galactic
 * centre near the top of the range; the gamma is what actually rescues the dust lanes, spending most
 * of the eight bits on the faint end where the structure is.
 *
 * This is display-referred on purpose — the curve is baked in rather than applied in the shader,
 * because undoing a gamma at runtime throws away the precision the gamma was there to buy.
 */
const BLACK_POINT = Number(process.env.SKY_BLACK ?? 0.0009)
const GAIN = Number(process.env.SKY_GAIN ?? 8.0)
const GAMMA = 1 / Number(process.env.SKY_GAMMA ?? 2.2)

/**
 * A third of a pixel of blur before encoding — enough to take the last of the speckle off the
 * downscale, not enough to touch a dust lane, which is degrees wide. Quality is high because at
 * this size there is budget for it: see the note above on where the bytes went.
 */
const SMOOTH_SIGMA = Number(process.env.SKY_SMOOTH ?? 0.35)
const QUALITY = Number(process.env.SKY_QUALITY ?? 92)

const ensureDir = (path) => { if (!existsSync(path)) mkdirSync(path, { recursive: true }) }

const download = ({ url, file }) => {
  ensureDir(CACHE)
  const target = resolve(CACHE, file)
  if (existsSync(target)) {
    console.log(`cached   ${file}`)
    return target
  }
  console.log(`fetching ${url}`)
  execFileSync('curl', ['-sL', '--fail', '--max-time', '1800', '-o', target, url], { stdio: 'inherit' })
  return target
}

/** Decode an EXR to planar float32 at a chosen size. ffmpeg's planar order is G, B, R. */
const decodeExr = (path, width, height, sigma = 0) => {
  const filters = [`scale=${width}:${height}`]
  if (sigma > 0) filters.push(`gblur=sigma=${sigma}`)
  const raw = execFileSync('ffmpeg', [
    '-v', 'error', '-i', path, '-vf', filters.join(','),
    '-f', 'rawvideo', '-pix_fmt', 'gbrpf32le', '-',
  ], { maxBuffer: 1 << 30 })
  const plane = width * height
  const floats = new Float32Array(raw.buffer, raw.byteOffset, plane * 3)
  return { g: floats.subarray(0, plane), b: floats.subarray(plane, plane * 2), r: floats.subarray(plane * 2) }
}

const encode = (linear) => {
  const lifted = Math.max(0, linear - BLACK_POINT) * GAIN
  return Math.round(255 * Math.min(1, Math.pow(Math.min(1, lifted), GAMMA)))
}

const writePanorama = (source, width, height, outFile, quality = QUALITY) => {
  const { r, g, b } = decodeExr(source, width, height, SMOOTH_SIGMA)
  // PPM, because cwebp reads it and it costs no dependency to write.
  const header = Buffer.from(`P6\n${width} ${height}\n255\n`, 'ascii')
  const pixels = Buffer.allocUnsafe(width * height * 3)
  for (let i = 0; i < width * height; i++) {
    pixels[i * 3] = encode(r[i])
    pixels[i * 3 + 1] = encode(g[i])
    pixels[i * 3 + 2] = encode(b[i])
  }
  const ppm = resolve(CACHE, `panorama-${width}.ppm`)
  writeFileSync(ppm, Buffer.concat([header, pixels]))
  ensureDir(OUT_IMG)
  execFileSync('cwebp', ['-q', String(quality), '-m', '6', '-sharp_yuv', ppm, '-o', outFile], { stdio: ['ignore', 'ignore', 'inherit'] })
  const bytes = readFileSync(outFile).length
  console.log(`panorama ${width}x${height} -> ${outFile.replace(ROOT + '/', '')}  ${(bytes / 1024).toFixed(0)} KB`)
  return { r, g, b }
}

/**
 * A 128x64 luminance thumbnail of the panorama, committed as a test fixture.
 *
 * The unit tests need to check that the frame maths puts the galactic centre where the galactic
 * centre is, and that needs real pixels — a mirrored sky passes every test that only reads code.
 * Eight kilobytes of the real image is enough to tell the galactic centre from the anticentre by a
 * factor of twenty, which is all the test is asking.
 */
const writeFixture = (source) => {
  const W = 128, H = 64
  const { r, g, b } = decodeExr(source, W, H)
  const luma = Buffer.allocUnsafe(W * H)
  for (let i = 0; i < W * H; i++) luma[i] = encode(0.2126 * r[i] + 0.7152 * g[i] + 0.0722 * b[i])
  ensureDir(OUT_FIXTURE)
  const out = resolve(OUT_FIXTURE, 'milkyway-luma.bin')
  writeFileSync(out, luma)
  console.log(`fixture  ${W}x${H} luminance -> ${out.replace(ROOT + '/', '')}  ${luma.length} bytes`)
}

const parseRa = (text) => {
  const m = /^(\d+)h\s*(\d+)m\s*([\d.]+)s$/.exec((text || '').trim())
  return m ? (Number(m[1]) + Number(m[2]) / 60 + Number(m[3]) / 3600) * 15 : null
}

const parseDec = (text) => {
  const m = /^([+-])(\d+)°\s*(\d+)′\s*([\d.]+)″$/.exec((text || '').trim())
  if (!m) return null
  const value = Number(m[2]) + Number(m[3]) / 60 + Number(m[4]) / 3600
  return m[1] === '-' ? -value : value
}

/**
 * The catalogue, as four float32s per star: right ascension, declination, visual magnitude, and
 * surface temperature in kelvin.
 *
 * Float32 rather than something packed because the whole file is under 150 KB either way, and every
 * byte saved by quantising is a byte of somebody later wondering whether the quantisation is why a
 * star sits slightly wrong.
 */
const writeCatalogue = (path) => {
  const rows = JSON.parse(readFileSync(path, 'utf8'))
  const stars = rows
    .map((row) => ({
      ra: parseRa(row.RA),
      dec: parseDec(row.Dec),
      mag: Number(row.V),
      kelvin: Number(row.K),
    }))
    .filter((s) => s.ra !== null && s.dec !== null && Number.isFinite(s.mag))
    .sort((a, b) => a.mag - b.mag)

  const data = new Float32Array(stars.length * 4)
  stars.forEach((s, i) => {
    data[i * 4] = s.ra
    data[i * 4 + 1] = s.dec
    data[i * 4 + 2] = s.mag
    // Novae and a few oddities carry no temperature; 5800 K is the sun's, a safe neutral.
    data[i * 4 + 3] = Number.isFinite(s.kelvin) && s.kelvin > 1000 ? Math.min(40000, s.kelvin) : 5800
  })

  ensureDir(OUT_DATA)
  const out = resolve(OUT_DATA, 'bright-stars.bin')
  writeFileSync(out, Buffer.from(data.buffer))
  console.log(`stars    ${stars.length} entries -> ${out.replace(ROOT + '/', '')}  ${(data.byteLength / 1024).toFixed(0)} KB`)
  console.log(`         brightest ${stars[0].mag} (${rows.length - stars.length} rows dropped: no position or no magnitude)`)
}

const panorama = download(SOURCES.panorama)
const catalogue = download(SOURCES.catalogue)

ensureDir(OUT_IMG)
const only = Number(process.env.SKY_ONLY ?? 0)
if (!only || only === 4096) writePanorama(panorama, 4096, 2048, resolve(OUT_IMG, 'milkyway-4k.webp'))
// The placeholder. It loads in a blink and holds the sky while the real one arrives, so nobody
// watches a black rectangle on a school connection.
if (!only || only === 1024) writePanorama(panorama, 1024, 512, resolve(OUT_IMG, 'milkyway-1k.webp'), 80)
if (only) process.exit(0)
writeFixture(panorama)
writeCatalogue(catalogue)

console.log('\nSources, for the credits:')
console.log('  Panorama: NASA/Goddard Space Flight Center Scientific Visualization Studio,')
console.log('            Deep Star Maps 2020. Gaia DR2: ESA/Gaia/DPAC. Public domain.')
console.log('  Stars:    Yale Bright Star Catalogue, 5th Revised Edition (Hoffleit & Warren, 1991).')

import { readFileSync, writeFileSync } from 'node:fs'

const RAW = new URL('./horizons-raw/', import.meta.url)

const EPOCHS = [
  { label: 'columbus-landfall', jdTdb: 2266296.0, iso: '1492-10-21T12:00:00.000Z', note: '12 Oct 1492 in the Julian calendar Columbus kept — 21 Oct proleptic Gregorian, which is what a JS Date means' },
  { label: 'barentsz-arctic', jdTdb: 2304157.0, iso: '1596-06-19T12:00:00.000Z', note: 'Barentsz sights Spitsbergen' },
  { label: 'galileo-moons', jdTdb: 2309117.0, iso: '1610-01-17T12:00:00.000Z', note: '7 Jan 1610 Julian — Galileo first sees the moons of Jupiter' },
  { label: 'cook-venus-transit', jdTdb: 2367328.0, iso: '1769-06-03T12:00:00.000Z', note: 'Cook observes the transit of Venus from Tahiti' },
  { label: 'j2000', jdTdb: 2451545.0, iso: '2000-01-01T12:00:00.000Z', note: 'the epoch itself' },
  { label: 'present-day', jdTdb: 2461262.5, iso: '2026-08-10T00:00:00.000Z', note: 'a date in the app’s own present' },
]

const parse = (file) => {
  const text = readFileSync(new URL(file, RAW), 'utf8')
  const block = text.slice(text.indexOf('$$SOE'), text.indexOf('$$EOE'))
  const rows = []
  const lines = block.split('\n')
  for (let n = 0; n < lines.length; n++) {
    const stamp = lines[n].match(/^(\d+\.\d+) = A\.D\./)
    if (!stamp) continue
    const vec = lines[n + 1].match(/X =\s*(\S+)\s*Y =\s*(\S+)\s*Z =\s*(\S+)/)
    if (!vec) throw new Error(`no vector after ${lines[n]} in ${file}`)
    rows.push({ jd: Number(stamp[1]), xyz: [Number(vec[1]), Number(vec[2]), Number(vec[3])] })
  }
  if (rows.length !== EPOCHS.length) throw new Error(`${file}: ${rows.length} rows, expected ${EPOCHS.length}`)
  rows.forEach((row, index) => {
    if (Math.abs(row.jd - EPOCHS[index].jdTdb) > 1e-6) throw new Error(`${file}: row ${index} is JD ${row.jd}`)
  })
  return rows.map((row) => row.xyz)
}

const HELIO = ['mercury', 'venus', 'embary', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune']

/** OBSERVER tables: apparent ecliptic longitude/latitude referred to the equinox OF DATE, in UT. */
const parseOfDate = (file) => {
  const text = readFileSync(new URL(file, RAW), 'utf8')
  const block = text.slice(text.indexOf('$$SOE'), text.indexOf('$$EOE'))
  const rows = block.split('\n')
    .map((line) => line.match(/^\s*\d{4}-\w{3}-\d{2} [\d:.]+\s+(-?[\d.]+)\s+(-?[\d.]+)\s*$/))
    .filter(Boolean)
    .map((m) => [Number(m[1]), Number(m[2])])
  if (rows.length !== EPOCHS.length) throw new Error(`${file}: ${rows.length} rows`)
  return rows
}

const fixture = {
  source: 'NASA/JPL Horizons API (https://ssd.jpl.nasa.gov/api/horizons.api), DE441',
  retrieved: '2026-08-10',
  query: {
    EPHEM_TYPE: 'VECTORS', REF_PLANE: 'ECLIPTIC', REF_SYSTEM: 'ICRF',
    VEC_CORR: 'NONE', OUT_UNITS: 'AU-D', VEC_TABLE: '1',
    heliocentricCenter: '500@10', geocentricCenter: '500@399',
  },
  frame: 'Mean ecliptic and equinox of J2000. Geometric — no light-time, aberration or nutation, which is what a Keplerian element set referred to J2000 produces.',
  units: 'au',
  timescale: 'Vector tables are stamped in TDB. The ISO strings are the same numeric Julian Date read as UTC; the module treats its input Date the same way, so the pair is consistent and the residual TT−UT1 offset is accounted for separately.',
  bodies: {
    mercury: '199', venus: '299', embary: '3 (Earth/Moon barycentre — what the JPL element table is fitted to)',
    mars: '4', jupiter: '5', saturn: '6', uranus: '7', neptune: '8',
    moon: '301 relative to 399', sun: '10 relative to 399',
  },
  epochs: EPOCHS,
  heliocentric: Object.fromEntries(HELIO.map((name) => [name, parse(`helio-${name}.txt`)])),
  geocentric: { moon: parse('geo-moon.txt'), sun: parse('geo-sun.txt') },
  ofDate: {
    note: 'Apparent geocentric ecliptic longitude and latitude, degrees, referred to the ecliptic and mean equinox OF DATE (Horizons QUANTITIES=31). Includes light-time, aberration and nutation, which together are under 0.01°. OBSERVER tables are stamped in UT, not TDB — the same numeric Julian Date as the vectors above, read on the other time scale, so comparing the two shows the TT−UT1 offset directly.',
    sun: parseOfDate('ofdate-sun.txt'),
    moon: parseOfDate('ofdate-moon.txt'),
    jupiter: parseOfDate('ofdate-jupiter.txt'),
  },
  apparentEquatorial: {
    note: 'Apparent geocentric right ascension and declination, degrees, equinox of date (Horizons QUANTITIES=2), stamped in UT. The end of the chain: pair these with GMST and you have the sub-point.',
    sun: parseOfDate('radec-sun.txt'),
    moon: parseOfDate('radec-moon.txt'),
  },
}

const target = process.argv[2]
writeFileSync(target, `${JSON.stringify(fixture, null, 2)}\n`)
console.log(`wrote ${target}`)
for (const name of HELIO) console.log(name, fixture.heliocentric[name][0].map((v) => v.toFixed(6)).join(' '))
console.log('moon', fixture.geocentric.moon[0].map((v) => v.toFixed(8)).join(' '))

import { readFileSync, writeFileSync, readdirSync } from 'node:fs'
const RAW = '/Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us/.claude/worktrees/orbital-mechanics/resources/js/timemap/__tests__/fixtures/horizons-raw-precision/'
const JDS = "2232565 2238175 2252541 2266296 2276135 2276539 2284772 2304157 2309117 2321116 2337410 2367328 2367658 2380616 2391537 2415021 2433283 2451545 2455348.5 2461262.5 2462577.75 2469808".split(' ').map(Number)
const LABELS = ['plague-1400','agincourt-1415','gutenberg-1455','columbus-landfall','magellan-departure','magellan-strait','copernicus-1543','barentsz-arctic','galileo-moons','tasman-tasmania','newton-principia','cook-venus-transit','cook-endeavour-1770','napoleon-1805','darwin-beagle-1835','1900','1950','j2000','2010','present-day','2030','2050']
const NOTES = {'columbus-landfall':'12 Oct 1492 Julian, the landfall Columbus recorded','magellan-departure':'20 Sep 1519 Julian, leaving Sanlucar','magellan-strait':'28 Oct 1520 Julian, entering the strait','barentsz-arctic':'Barentsz sights Spitsbergen','galileo-moons':'7 Jan 1610 Julian, the moons of Jupiter','tasman-tasmania':'Tasman sights Van Diemens Land','cook-venus-transit':'Cook observes the transit of Venus from Tahiti','j2000':'the epoch itself','present-day':'a date in the app own present'}
const epochs = JDS.map((jd,i)=>({label:LABELS[i], jd, iso:new Date((jd-2440587.5)*86400000).toISOString(), note:NOTES[LABELS[i]]||''}))

const vec = (f) => {
  const text = readFileSync(RAW+f+'.txt','utf8')
  const b = text.slice(text.indexOf('$$SOE'), text.indexOf('$$EOE')).split('\n')
  const rows=[]
  for(let n=0;n<b.length;n++){
    const st=b[n].match(/^(\d+\.?\d*) = A\.D\./); if(!st) continue
    const v=b[n+1].match(/X =\s*(\S+)\s*Y =\s*(\S+)\s*Z =\s*(\S+)/); if(!v) throw new Error('no vec '+f)
    rows.push({jd:Number(st[1]), xyz:[Number(v[1]),Number(v[2]),Number(v[3])]})
  }
  if(rows.length!==JDS.length) throw new Error(`${f}: ${rows.length}`)
  rows.forEach((r,i)=>{ if(Math.abs(r.jd-JDS[i])>1e-6) throw new Error(`${f} row ${i} jd ${r.jd}`) })
  return rows.map(r=>r.xyz)
}
const ang = (f) => {
  const text = readFileSync(RAW+f+'.txt','utf8')
  const b = text.slice(text.indexOf('$$SOE'), text.indexOf('$$EOE')).split('\n')
  const rows = b.map(l=>l.match(/^\s*\d{4}-\w{3}-\d{2} [\d:.]+\s+(-?[\d.]+)\s+(-?[\d.]+)\s*$/)).filter(Boolean).map(m=>[Number(m[1]),Number(m[2])])
  if(rows.length!==JDS.length) throw new Error(`${f}: ${rows.length}`)
  return rows
}
const PL=['mercury','venus','mars','jupiter','saturn','uranus','neptune']
const out = {
  source:'NASA/JPL Horizons API (https://ssd.jpl.nasa.gov/api/horizons.api), DE441',
  retrieved:'2026-08-10',
  regenerate:'bash fetch-precision.sh && node build-precision.mjs horizons-precision.json',
  frames:{
    heliocentric:'VECTORS, geometric (VEC_CORR=NONE), ecliptic and equinox J2000, au, stamped in TDB. Tests the theory with no light-time, aberration, precession or delta-T in the way.',
    geocentricMoon:'VECTORS, geometric, ecliptic and equinox J2000, au, TDB.',
    astrometric:'OBSERVER QUANTITIES=1: astrometric RA/Dec, degrees, J2000, light-time corrected, no aberration. Stamped in UT, so it also tests the UT to TT conversion.',
    apparent:'OBSERVER QUANTITIES=2: apparent RA/Dec, degrees, equinox of date. The whole chain, including precession and nutation, as an observer would see it. Stamped in UT.',
  },
  bodies:{mercury:'199',venus:'299',earth:'399 (the planet, matching VSOP87A.ear)',mars:'4',jupiter:'5',saturn:'6',uranus:'7',neptune:'8',moon:'301 from 399',sun:'10 from 399'},
  epochs,
  heliocentric:Object.fromEntries(['mercury','venus','earth','mars','jupiter','saturn','uranus','neptune'].map(n=>[n,vec('helio-'+n)])),
  geocentric:{moon:vec('geo-moon')},
  astrometric:Object.fromEntries([...PL.map(n=>[n,ang('astro-'+n)]),['moon',ang('astro-moon')]]),
  apparent:Object.fromEntries([...PL.map(n=>[n,ang('app-'+n)]),['moon',ang('app-moon')],['sun',ang('app-sun')]]),
}
writeFileSync(process.argv[2], JSON.stringify(out,null,1)+'\n')
console.log('epochs',out.epochs.length,'| helio',Object.keys(out.heliocentric).length,'| astro',Object.keys(out.astrometric).length,'| app',Object.keys(out.apparent).length)

#!/bin/bash
#
# Regenerate horizons-j2000-ecliptic.json from JPL Horizons.
#
#   bash fetch.sh && node build-fixture.mjs horizons-j2000-ecliptic.json
#
# Three tables per body, because they answer different questions and disagree in instructive ways:
#
#   VECTORS   geometric position, J2000 ecliptic, stamped in TDB. What a J2000 element set
#             produces, so it is the fair comparison for planets.js.
#   OBSERVER  QUANTITIES=31, apparent ecliptic longitude/latitude for the equinox OF DATE, in UT.
#             The comparison for anything that has been through celestial-frames.js.
#   OBSERVER  QUANTITIES=2, apparent right ascension/declination of date, in UT. Pair with GMST
#             and it is the sub-point, which is the end of the chain.
#
# Times go in as Julian Dates, never calendar dates: Horizons reads pre-1582 calendar dates as
# JULIAN calendar, JavaScript reads them as proleptic Gregorian, and the two differ by nine days
# in 1492. A JD is unambiguous on both sides.
#
# Outer planets use BARYCENTRE ids (4..8) because the planet-centre ephemerides do not reach back
# before 1600, and the JPL element tables are fitted to the barycentres anyway.
set -u
OUT="$(dirname "$0")/horizons-raw"
mkdir -p "$OUT"

# Columbus 1492, Barentsz 1596, Galileo's moons 1610, Cook's transit 1769, J2000, and today.
JDS="2266296.0 2304157.0 2309117.0 2367328.0 2451545.0 2461262.5"

horizons () {   # $1 body  $2 centre  $3 outfile  $4.. extra params
  local tl=$(printf '%s\n' $JDS) out="$3"; shift 3
  curl -s --max-time 90 -G "https://ssd.jpl.nasa.gov/api/horizons.api" \
    --data-urlencode "format=text" --data-urlencode "COMMAND='$BODY'" \
    --data-urlencode "OBJ_DATA='NO'" --data-urlencode "MAKE_EPHEM='YES'" \
    --data-urlencode "CENTER='$CENTRE'" --data-urlencode "TLIST=$tl" "$@" \
    > "$OUT/$out.txt"
  echo "$out: $(grep -cE 'X =|^ [0-9]{4}-' "$OUT/$out.txt") rows"
}

vectors () {
  BODY="$1" CENTRE="$2" horizons "$1" "$2" "$3" \
    --data-urlencode "EPHEM_TYPE='VECTORS'" --data-urlencode "REF_PLANE='ECLIPTIC'" \
    --data-urlencode "REF_SYSTEM='ICRF'" --data-urlencode "VEC_TABLE='1'" \
    --data-urlencode "VEC_CORR='NONE'" --data-urlencode "OUT_UNITS='AU-D'"
}

observer () {   # $4 = QUANTITIES
  BODY="$1" CENTRE="$2" horizons "$1" "$2" "$3" \
    --data-urlencode "EPHEM_TYPE='OBSERVER'" --data-urlencode "QUANTITIES='$4'" \
    --data-urlencode "ANG_FORMAT='DEG'"
}

for pair in "199 mercury" "299 venus" "3 embary" "4 mars" "5 jupiter" "6 saturn" "7 uranus" "8 neptune"; do
  set -- $pair
  vectors "$1" "500@10" "helio-$2"
done
vectors 301 "500@399" geo-moon
vectors 10  "500@399" geo-sun

observer 10  "500@399" ofdate-sun     31
observer 301 "500@399" ofdate-moon    31
observer 5   "500@399" ofdate-jupiter 31
observer 10  "500@399" radec-sun       2
observer 301 "500@399" radec-moon      2

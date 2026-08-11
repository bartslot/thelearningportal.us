#!/bin/bash
#
# Reference data for the arcsecond-class modules (vsop87.js, elp2000.js).
#
#   bash fetch-precision.sh && node build-precision.mjs horizons-precision.json
#
# Twenty-two dates from 1400 to 2050, chosen so the accuracy statement is a distribution rather
# than a spot check, and including the four voyages the history map actually cares about: Columbus
# 1492, Magellan 1519, Barentsz 1596, Tasman 1642.
#
# THREE TABLES, because they isolate different things and the differences are the point:
#
#   VECTORS,  TDB, J2000 ecliptic, geometric    tests the THEORY alone — no light-time, no
#                                               aberration, no precession, no ΔT
#   OBSERVER QUANTITIES=1, UT, astrometric J2000  adds light-time and the UT→TT conversion
#   OBSERVER QUANTITIES=2, UT, apparent of date   the whole chain, as an observer would see it
#
# Bodies: VSOP87 solves for the BARYCENTRE of each outer planetary system, so Mars through Neptune
# are fetched as barycentres (4..8). Mercury and Venus have no satellites and Earth is fetched as
# 399, the planet itself, because VSOP87A.ear is the earth rather than the Earth/Moon barycentre.
#
# Times go in as Julian Dates, never calendar dates: Horizons reads a pre-1582 calendar date as
# JULIAN calendar and JavaScript reads it as proleptic Gregorian, and in 1492 those are nine days
# apart. A JD is unambiguous on both sides.
set -u
OUT="$(dirname "$0")/horizons-raw-precision"
mkdir -p "$OUT"

JDS="2232565 2238175 2252541 2266296 2276135 2276539 2284772 2304157 2309117 2321116 2337410 \
2367328 2367658 2380616 2391537 2415021 2433283 2451545 2455348.5 2461262.5 2462577.75 2469808"

call () {   # $1 body  $2 centre  $3 outfile  $4.. extra
  local body="$1" centre="$2" out="$3"; shift 3
  local tl=$(printf '%s\n' $JDS)
  curl -s --max-time 120 -G "https://ssd.jpl.nasa.gov/api/horizons.api" \
    --data-urlencode "format=text" --data-urlencode "COMMAND='$body'" \
    --data-urlencode "OBJ_DATA='NO'" --data-urlencode "MAKE_EPHEM='YES'" \
    --data-urlencode "CENTER='$centre'" --data-urlencode "TLIST=$tl" "$@" \
    > "$OUT/$out.txt"
  local n=$(grep -cE 'X =|^ [0-9]{4}-[A-Z]' "$OUT/$out.txt")
  echo "$out: $n rows"
  [ "$n" -gt 0 ] || head -6 "$OUT/$out.txt"
}

vectors () {
  call "$1" "$2" "$3" \
    --data-urlencode "EPHEM_TYPE='VECTORS'" --data-urlencode "REF_PLANE='ECLIPTIC'" \
    --data-urlencode "REF_SYSTEM='ICRF'" --data-urlencode "VEC_TABLE='1'" \
    --data-urlencode "VEC_CORR='NONE'" --data-urlencode "OUT_UNITS='AU-D'"
}

observer () {   # $4 = QUANTITIES
  call "$1" "$2" "$3" \
    --data-urlencode "EPHEM_TYPE='OBSERVER'" --data-urlencode "QUANTITIES='$4'" \
    --data-urlencode "ANG_FORMAT='DEG'" --data-urlencode "EXTRA_PREC='YES'"
}

PLANETS="199:mercury 299:venus 399:earth 4:mars 5:jupiter 6:saturn 7:uranus 8:neptune"

for pair in $PLANETS; do
  vectors "${pair%%:*}" "500@10" "helio-${pair##*:}"
done
vectors 301 "500@399" geo-moon

for pair in $PLANETS; do
  [ "${pair##*:}" = earth ] && continue
  observer "${pair%%:*}" "500@399" "astro-${pair##*:}" 1
  observer "${pair%%:*}" "500@399" "app-${pair##*:}"   2
done
observer 301 "500@399" astro-moon 1
observer 301 "500@399" app-moon   2
observer 10  "500@399" app-sun    2

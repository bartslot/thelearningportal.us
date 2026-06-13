#!/usr/bin/env bash
#
# fetch-public-assets.sh
#
# Pulls gitignored public/ assets that are too large for git but are served
# by the live site. Run once after a fresh clone.
#
#   ./scripts/fetch-public-assets.sh
#
# NOTE: This fetches the flag tiles used by the time-map. The heavy 3D avatar
# models (public/avatars/*/character.glb) and the animation library are NOT
# hosted online and are not fetched here — a clone renders avatar thumbnails
# but not the 3D avatars. See README for details.
#
set -euo pipefail

HOST="${ASSET_HOST:-https://thelearningportal.us}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FLAGS_DIR="$ROOT/public/flags"

echo "Fetching public assets from $HOST"

# --- Flags -----------------------------------------------------------------
mkdir -p "$FLAGS_DIR"
echo "→ flags: downloading manifest"
curl -fsSL -m 30 "$HOST/public/flags/manifest.json" -o "$FLAGS_DIR/manifest.json"

# manifest.json is a JSON array of ids, e.g. ["-2660203","12345",...]
ids=$(tr -d '[]" ' < "$FLAGS_DIR/manifest.json" | tr ',' '\n' | grep -v '^$')
total=$(printf '%s\n' "$ids" | wc -l | tr -d ' ')
echo "→ flags: $total tiles to fetch"

n=0
fail=0
while IFS= read -r id; do
  n=$((n + 1))
  out="$FLAGS_DIR/$id.png"
  if [ -s "$out" ]; then continue; fi
  if ! curl -fsSL -m 30 "$HOST/public/flags/$id.png" -o "$out"; then
    rm -f "$out"
    fail=$((fail + 1))
    echo "  ! missing: $id.png"
  fi
  if [ $((n % 200)) -eq 0 ]; then echo "  …$n/$total"; fi
done <<< "$ids"

echo "✓ flags done ($((total - fail))/$total fetched, $fail missing)"
echo
echo "Done. Heavy avatar 3D models / animations are not hosted and were skipped."

#!/usr/bin/env bash
#
# Copy narrator media (portraits and introduction videos) to production.
#
# scripts/deploy-prod.sh deliberately excludes storage/ — that directory holds every lesson image
# and every narration file production has generated, and rsyncing the local copy over it would be a
# catastrophe. So narrator media, which DOES belong on both, needs its own trip.
#
# This is the media half of "sync a narrator to production". The other half is the database row,
# which travels as code: database/seeders/NarratorSeeder.php, run after deploying. Run this FIRST,
# so the seeder finds the files and does not warn.
#
# Usage:  scripts/sync-narrator-media.sh [--dry-run]
#
set -euo pipefail

# ssh.historyportal.eu, NOT ssh.thelearningportal.us: the marketing site moved to a separate
# SiteGround server, taking that hostname with it, so the old one now resolves to a machine
# this key cannot open and the app is not on. The SSH host has to follow the app.
HOST="u2628-emomoo15slu6@ssh.historyportal.eu"
PORT=18765
KEY="$HOME/.ssh/siteground_tlp2"
# Found, not assumed — the site directory is named after the primary domain, which has moved twice.
APP=$(ssh -p "$PORT" -i "$KEY" "$HOST" \
  "for d in www/*/app; do [ -f \"\$d/artisan\" ] && [ ! -L \"\$(dirname \"\$d\")\" ] && { echo \"\$d\"; exit; }; done" \
  2>/dev/null | head -1 | tr -d '\r')
if [[ -z "$APP" ]]; then
  echo "✗ could not find the app on the server. Refusing to sync." >&2
  exit 1
fi
DEST="$APP/storage/app/public"
DRY=""

[[ "${1:-}" == "--dry-run" ]] && DRY="--dry-run"

cd "$(dirname "$0")/.."

# Only these two subtrees, and NO --delete. A narrator retired locally must not take production's
# copy with it, and nothing else under storage/ is any of this script's business.
for dir in avatars/portraits avatar-introductions; do
  if [[ ! -d "storage/app/public/$dir" ]]; then
    echo "▸ skip $dir (nothing local)"
    continue
  fi
  echo "▸ $dir"
  # rsync will not create a MISSING PARENT on the receiver (only the final directory), and a fresh
  # server has never had avatars/ at all — the first run failed on exactly that. mkdir -p first.
  [[ -z "$DRY" ]] && ssh -p "$PORT" -i "$KEY" "$HOST" "mkdir -p '$DEST/$dir'"
  rsync -az $DRY --itemize-changes \
    -e "ssh -p $PORT -i $KEY" \
    "storage/app/public/$dir/" "$HOST:$DEST/$dir/"
done

[[ -n "$DRY" ]] && { echo "▸ dry run only, nothing changed"; exit 0; }

echo "▸ now on the server: php artisan db:seed --class=NarratorSeeder --force"

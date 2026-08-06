#!/usr/bin/env bash
#
# Deploy to SiteGround production.
#
# The procedure used to live in someone's head, and on 2026-08-05 that cost us every image and
# every narration file on the live site: rsync --delete removed public/storage, the symlink that
# serves all lesson media. It is not tracked in git, so it was not in the export, so --delete
# considered it deleted. The page still answered 200 and the console showed nothing but 404s on
# assets nobody was checking.
#
# Hence this file. Two guards below carry the whole lesson:
#   - public/storage is EXCLUDED, so it can never be a deletion candidate
#   - storage:link runs afterwards regardless, because it is idempotent and free
#
# Usage:  scripts/deploy-prod.sh [--dry-run]
#
set -euo pipefail

HOST="u2628-emomoo15slu6@ssh.thelearningportal.us"
PORT=18765
KEY="$HOME/.ssh/siteground_tlp2"
DEST="www/history.thelearningportal.us/app/"
DRY=""

[[ "${1:-}" == "--dry-run" ]] && DRY="--dry-run"

cd "$(dirname "$0")/.."

# Never rsync the working tree. Other sessions share it, and their half-finished work would ship
# with yours. HEAD is the only thing that has been reviewed.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
echo "▸ exporting HEAD ($(git rev-parse --short HEAD)) to a clean tree"
git archive HEAD | tar -x -C "$STAGE"

# public/build is gitignored, so it is absent from the archive unless it was force-added. Shipping
# a manifest whose assets were never committed is the other classic way to break the live site.
if [[ ! -d "$STAGE/public/build/assets" ]]; then
  echo "✗ public/build/assets is missing from HEAD." >&2
  echo "  Run: npm run build && git add -f public/build && git commit" >&2
  exit 1
fi

echo "▸ rsync $DRY"
rsync -az --delete $DRY --stats \
  --exclude='.env' --exclude='.env.*' \
  --exclude='public/storage' \
  `# ^ the symlink to storage/app/public. Excluded, never deleted. See the header.` \
  --exclude='storage/' \
  --exclude='bootstrap/cache/' \
  `# ^ Laravel's compiled package/service manifests. Git tracks only the .gitignore in here, so` \
  `# --delete treats the compiled files as removed and takes them. They do regenerate on the next` \
  `# request, but that leaves the first visitor after a deploy paying for package discovery, and` \
  `# a half-written manifest under concurrent requests is a 500 nobody can reproduce afterwards.` \
  --exclude='vendor/' --exclude='node_modules/' --exclude='.git/' \
  --exclude='tests/' --exclude='.claude/' \
  -e "ssh -p $PORT -i $KEY" \
  "$STAGE/" "$HOST:$DEST" | grep -E 'Number of|deleted' || true

[[ -n "$DRY" ]] && { echo "▸ dry run only, nothing changed"; exit 0; }

echo "▸ post-deploy"
ssh -p "$PORT" -i "$KEY" "$HOST" "cd $DEST && \
  composer dump-autoload -o -q && \
  php artisan storage:link && \
  php artisan config:clear -q && \
  php artisan view:clear -q && \
  php artisan migrate --force && \
  php artisan view:cache -q && echo 'caches warmed'"

# A 200 is not proof. The page can render perfectly while every image behind it 404s — which is
# exactly what a missing storage link looks like. Check a real media file, not just the HTML.
echo "▸ verifying"
CODE=$(curl -s -o /dev/null -w '%{http_code}' https://history.thelearningportal.us --max-time 25)
echo "  homepage      $CODE"
MEDIA=$(ssh -p "$PORT" -i "$KEY" "$HOST" "cd $DEST && php artisan tinker --execute=\"
  \\\$s = App\\Models\\Scene::whereNotNull('image_path')->first();
  echo \\\$s ? Storage::disk('public')->url(\\\$s->image_path) : '';\"" 2>/dev/null | tail -1 | tr -d '\r')
if [[ -n "$MEDIA" ]]; then
  echo "  media         $(curl -s -o /dev/null -w '%{http_code}' "$MEDIA" --max-time 25)  $MEDIA"
fi

echo "▸ now OPEN THE SITE and look at it. Screenshot it. A status code is not a rendered page."

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

# ssh.historyportal.eu, NOT ssh.thelearningportal.us: the marketing site moved to a separate
# SiteGround server, taking that hostname with it, so the old one now resolves to a machine
# this key cannot open and the app is not on. The SSH host has to follow the app.
HOST="u2628-emomoo15slu6@ssh.historyportal.eu"
PORT=18765
KEY="$HOME/.ssh/siteground_tlp2"
DRY=""

# Where the app lives on the server, FOUND rather than assumed.
#
# SiteGround names a site's directory after its primary domain, so changing that domain renames the
# directory underneath us — which is exactly what the move to historyportal.eu does. A hardcoded
# path would not fail loudly here; rsync would happily CREATE the old directory again and deploy a
# second, unserved copy of the app, leaving the real site untouched and the deploy reporting success.
#
# So: ask the server which directory holds an artisan file. Exactly one does.
# Counted by REAL path: a hostname can be a symlink to the site it shares (historyportal.eu and
# history.historyportal.eu are one app reached two ways), and counting those as two apps made this
# refuse a perfectly ordinary deploy. Two DISTINCT real paths still means a half-finished move, and
# guessing between them is how the wrong one gets deployed to.
APPS=$(ssh -p "$PORT" -i "$KEY" "$HOST" \
  "for d in www/*/app; do [ -f \"\$d/artisan\" ] && readlink -f \"\$d\"; done | sort -u" \
  2>/dev/null | tr -d '\r')
COUNT=$(printf '%s\n' "$APPS" | grep -c . || true)

if [[ "$COUNT" -eq 0 ]]; then
  echo "✗ could not find the app on the server (no www/*/app/artisan). Refusing to deploy." >&2
  exit 1
fi
if [[ "$COUNT" -gt 1 ]]; then
  echo "✗ $COUNT distinct app directories on the server — deploy by hand until that is resolved:" >&2
  printf '    %s\n' $APPS >&2
  exit 1
fi

# rsync wants the path as the server names it, not the resolved one — but prefer the REAL directory
# over a hostname symlink pointing at it, so the log says where the files actually land.
DEST=$(ssh -p "$PORT" -i "$KEY" "$HOST" \
  "for d in www/*/app; do [ -f \"\$d/artisan\" ] && [ ! -L \"\$(dirname \"\$d\")\" ] && { echo \"\$d/\"; exit; }; done
   for d in www/*/app; do [ -f \"\$d/artisan\" ] && { echo \"\$d/\"; exit; }; done" \
  2>/dev/null | head -1 | tr -d '\r')

echo "▸ app directory: $DEST"

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

# The directory being present is not the same as it being CURRENT, and the difference broke the live
# player on 2026-08-11. public/build is only partially tracked, so a rebuild stages the deletion of
# the old hashed bundles and the manifest that names the new ones — both tracked — while the new
# bundles stay ignored. HEAD then holds a manifest pointing at files nobody committed, the deploy
# ships it happily, and every page importing one 404s. The homepage does not, which is why the
# verifier at the end of this script still went green. So check the manifest against the tree.
MISSING="$(cd "$STAGE/public/build" && python3 -c "
import json, os, sys
try:
    manifest = json.load(open('manifest.json'))
except OSError:
    sys.exit(0)   # no manifest at all is a different problem; the directory check above owns it
wanted = set()
for entry in manifest.values():
    if 'file' in entry:
        wanted.add(entry['file'])
    wanted.update(entry.get('css', []))
print('\n'.join(sorted(f for f in wanted if not os.path.exists(f))))
")"
if [[ -n "$MISSING" ]]; then
  echo "✗ HEAD's manifest names assets that were never committed:" >&2
  printf '    %s\n' $MISSING >&2
  echo "  public/build is gitignored — a rebuild has to be force-added:" >&2
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
  php artisan route:clear -q && \
  `# ^ bootstrap/cache/ is EXCLUDED from the rsync, so a route cache generated on the server` \
  `# outlives every deploy. /launch shipped correctly — file present, view present — and 404'd,` \
  `# because routes-v7.php still held the pre-deploy table. A new route would silently never` \
  `# exist. Clearing is cheap; the cache is rebuilt below.` \
  php artisan migrate --force && \
  php artisan route:cache -q && \
  php artisan view:cache -q && echo 'caches warmed'"

# A 200 is not proof. The page can render perfectly while every image behind it 404s — which is
# exactly what a missing storage link looks like. Check a real media file, not just the HTML.
echo "▸ verifying"
# The public URL comes from the server's own APP_URL rather than being written here. The site is
# moving to historyportal.eu, and a hardcoded hostname in the verification step is the kind that
# keeps reporting 200 for the OLD domain long after the new one is the real site.
SITE_URL=$(ssh -p "$PORT" -i "$KEY" "$HOST" "cd $DEST && php -r \"
  foreach (file('.env', FILE_IGNORE_NEW_LINES) as \\\$l) {
    if (preg_match('/^APP_URL=(.+)\\\$/', \\\$l, \\\$m)) { echo trim(\\\$m[1], '\\\"'); break; }
  }\"" 2>/dev/null | tail -1 | tr -d '\r')
SITE_URL=${SITE_URL:-https://history.thelearningportal.us}
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$SITE_URL" --max-time 25)
echo "  homepage      $CODE  $SITE_URL"
MEDIA=$(ssh -p "$PORT" -i "$KEY" "$HOST" "cd $DEST && php artisan tinker --execute=\"
  \\\$s = App\\Models\\Scene::whereNotNull('image_path')->first();
  echo \\\$s ? Storage::disk('public')->url(\\\$s->image_path) : '';\"" 2>/dev/null | tail -1 | tr -d '\r')
if [[ -n "$MEDIA" ]]; then
  echo "  media         $(curl -s -o /dev/null -w '%{http_code}' "$MEDIA" --max-time 25)  $MEDIA"
fi

echo "▸ now OPEN THE SITE and look at it. Screenshot it. A status code is not a rendered page."

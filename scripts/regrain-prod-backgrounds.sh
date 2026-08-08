#!/usr/bin/env bash
#
# Put the film grain back on production's scene backgrounds.
#
# `lessons:optimise-backgrounds --apply` run ON the server produces correct, tiny AVIF with NO
# grain, because SiteGround has no avifenc, no compiler, and no AV1 encoder in its ffmpeg — GD's
# AVIF encoder is the best rung available there and it cannot write grain parameters. The result is
# a Turner sky compressed to flat plateaus at 90 KB.
#
# Grain is not decoration. The decoder synthesises it from ~100 bytes in the bitstream, so the
# encoder can compress the smooth image underneath far harder and still avoid the banding that heavy
# compression leaves in skies and shadows. It is most of the reason these files are AVIF at all.
#
# So: encode here, where avifenc exists, and ship the bytes. Every original is still on the server
# (optimise-backgrounds renames it to bg.original.<ext> rather than deleting it), so this re-encodes
# from the SOURCE — there is no second generation of loss.
#
# Usage:  scripts/regrain-prod-backgrounds.sh [--dry-run]
#
set -euo pipefail

HOST="u2628-emomoo15slu6@ssh.historyportal.eu"
PORT=18765
KEY="$HOME/.ssh/siteground_tlp2"
SSH="ssh -p $PORT -i $KEY"
DRY=""
[[ "${1:-}" == "--dry-run" ]] && DRY="--dry-run"

cd "$(dirname "$0")/.."

# Find the app the same way deploy-prod.sh does, for the same reason: SiteGround names a site's
# directory after its primary domain, so a hardcoded path silently syncs into a directory nobody
# serves and reports success.
APP=$($SSH "$HOST" "for d in www/*/app; do [ -f \"\$d/artisan\" ] && readlink -f \"\$d\" && break; done" 2>/dev/null | tr -d '\r')
[[ -z "$APP" ]] && { echo "✗ could not find the app on the server." >&2; exit 1; }
REMOTE="$APP/storage/app/public/lessons"
echo "▸ remote: $REMOTE"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Pull the ORIGINALS only. The compressed files already live there and re-encoding one of those
# would stack a second generation of loss on top of the first.
echo "▸ pulling kept originals"
rsync -az --stats \
  --include='*/' --include='*.original.*' --exclude='*' \
  -e "$SSH" "$HOST:$REMOTE/" "$WORK/" | grep -E 'Number of files transferred|Total transferred' || true

COUNT=$(find "$WORK" -name '*.original.*' | wc -l | tr -d ' ')
echo "▸ $COUNT originals"
[[ "$COUNT" -eq 0 ]] && { echo "nothing to do"; exit 0; }

echo "▸ encoding with grain"
if [[ -n "$DRY" ]]; then
  php artisan lessons:encode-originals "$WORK"
  echo "▸ dry run only, nothing pushed"
  exit 0
fi
php artisan lessons:encode-originals "$WORK" --apply

# Push ONLY what we just wrote. The work directory holds exactly two things — the originals we
# pulled and the files we encoded — so "everything that is not an original" is precisely the output,
# whatever it happens to be called (bg, skybox, shot_0, a painting's own slug).
#
# No --delete. This directory is a filtered subset of what is on the server, and --delete against a
# subset removes everything the filter left out: every narration file, every image the optimiser
# skipped, the lot.
echo "▸ pushing"
rsync -az --stats \
  --exclude='*.original.*' \
  -e "$SSH" "$WORK/" "$HOST:$REMOTE/" | grep -E 'Number of files transferred|Total transferred' || true

# A file on disk is not a file being served. Check one over HTTP, and check the app agrees it is
# still the scene's image.
echo "▸ verifying"
URL=$($SSH "$HOST" "cd $APP && php artisan tinker --execute=\"
  \\\$s = App\\Models\\Scene::where('image_path','like','%bg.avif')->first();
  echo \\\$s ? Storage::disk('public')->url(\\\$s->image_path) : '';\"" 2>/dev/null | tail -1 | tr -d '\r')
if [[ -n "$URL" ]]; then
  read -r CODE BYTES < <(curl -s -o /dev/null -w '%{http_code} %{size_download}' "$URL" --max-time 25 | tr ' ' ' ')
  echo "  $CODE  $BYTES bytes  $URL"
fi
echo "▸ now OPEN a lesson and look at a background. Grain is a thing you see, not a status code."

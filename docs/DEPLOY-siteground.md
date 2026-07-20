# SiteGround deploy runbook

Turnkey steps to publish the app + the 7 demo lessons. Code is already on GitHub (`main`).
Assets are pre-built and committed (`public/build`), so **no `npm` is needed on the server**.

## 0. Two files to upload (from this machine)
- **DB dump** — `storage/app/backups/demo-snapshot.sql` (1.1 MB) — all 7 lessons + data.
- **Media** — `storage/app/backups/lessons-media.tar.gz` (219 MB) — lesson audio + images.
  Start this SFTP upload FIRST; it's the slow part.

## 1. Code
```bash
cd ~/www/thelearningportal.us      # your SiteGround app root
git pull origin main
composer install --no-dev --optimize-autoloader
```

## 2. .env (production)
Copy `.env.example` → `.env` and set:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://thelearningportal.us
# APP_KEY — generate a fresh one:  php artisan key:generate

# App DB — SiteGround LOCAL Postgres (sub-ms; NOT a remote cloud DB). Create the
# DB + user in Site Tools -> PostgreSQL Manager, then ASSIGN the user to the DB.
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1  DB_PORT=5432  DB_DATABASE=<sg_db>  DB_USERNAME=<sg_user>  DB_PASSWORD=...
DB_SEARCH_PATH=app  DB_SSLMODE=prefer

# Corpus (unchanged — already secured with RLS)
CORPUS_DB_HOST=aws-1-us-east-2.pooler.supabase.com
CORPUS_DB_PORT=5432  CORPUS_DB_DATABASE=postgres
CORPUS_DB_USERNAME=postgres.ophofmkxmehmeojvsijc  CORPUS_DB_PASSWORD=...

# AI keys (needed only to CREATE new lessons; the 7 demo lessons are pre-generated)
OPENAI_API_KEY=...  OPENAI_MODEL=gpt-4o-mini
ELEVENLABS_API_KEY=...
LESSON_STORAGE_DISK=public
QUEUE_CONNECTION=database
```

## 3. Database (the 7 lessons)
Import the dump into your prod Postgres (fresh DB recommended — the dump carries schema + data):
```bash
psql "$DATABASE_URL" < storage/app/backups/demo-snapshot.sql
# app DB = SiteGround's LOCAL Postgres; the read-only corpus stays on Supabase.
# To re-migrate app data off a cloud DB:  pg_dump -n app --no-owner --no-privileges <src> | psql <local>
```
If the dump's roles/owners differ on prod, it was made with --no-owner so it should apply cleanly.

## 4. Media
```bash
tar xzf storage/app/backups/lessons-media.tar.gz -C storage/app/public   # → storage/app/public/lessons/...
php artisan storage:link                                                 # public/storage → storage/app/public
```

## 5. Teacher account + caches
```bash
php artisan app:create-teacher hello@bartslot.com 'YOUR_PASSWORD' --name="Bart Slot"
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Pending post-deploy tasks

Run once on the server with the next deploy, then tick off here:

- [ ] **Re-seed first, THEN backfill** — order matters. Backfill reads `cities.wikidata_qid`
      from the DB, so the corrected QIDs must be written to prod rows before it runs:
      1. `php artisan db:seed --class=Database\\Seeders\\HistoricalCityNamesSeeder --force`
         (updates wikidata_qid from the corrected `historical_city_names.php`)
      2. `php artisan cities:backfill-coords` (fills lat/lng-0 stubs from Wikidata P625)

      Prod's cities table still has lat/lng-0 seeder stubs (they put wizard map-block focus
      pins at (0,0) in the Gulf of Guinea). Six QIDs were hallucinated wrong and fixed
      2026-07-20 (Persepolis→Moscow, Merv→Australia, Fez→Russia, etc.); the re-seed is what
      propagates that fix. If a backfill already ran with the old QIDs (non-zero wrong coords),
      those rows won't re-fill — reset the six to lat/lng 0 first, or update them directly.

## 6. Smoke test
- `https://your-domain/` → "Lessons ready to play" shows 7 tiles.
- Open one → it plays (audio + scenes). Caesar's has the Rubicon strategy game.
- `/login` → sign in as hello@bartslot.com → teacher dashboard.

## Notes / gotchas
- **No queue worker on SiteGround.** Fine for the demo — all 7 lessons are pre-generated.
  Creating NEW lessons in prod needs a worker (cron `php artisan queue:work --stop-when-empty`,
  or a Supervisor process). See the audit doc for the production hardening list.
- The corpus is read-only and already locked down (RLS enabled this session).
- This is demo-grade. Before real students: the items in `docs/AUDIT-what-we-might-have-missed.md`
  (teacher-review gate, content moderation, COPPA/FERPA, captions) still apply.

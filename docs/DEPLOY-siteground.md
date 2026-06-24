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

# App DB (your prod Postgres — e.g. a Supabase app project)
DB_CONNECTION=pgsql
DB_HOST=...  DB_PORT=5432  DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD=...
DB_SEARCH_PATH=app

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
# (or paste it into the Supabase SQL editor)
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

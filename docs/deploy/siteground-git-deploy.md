# SiteGround Git Deployment — thelearningportal.us

Deploy this Laravel 12 app to **SiteGround shared hosting** via **Site Tools → Git**.
The production database stays on **Supabase Postgres** (cloud) — there is **no MySQL** and
**no migration of data**. SiteGround only runs the PHP app, queue, and scheduler.

> Stack recap: Laravel 12, Livewire 3, Tailwind v4 + DaisyUI 5 (Vite build), Sanctum API,
> `database` queue + `database` cache + `database` session, Postgres via the Supabase pooler.

---

## 1. Production `.env`

Create `.env` **on the server** (it is gitignored — never commit it). The repo ships a
placeholder template at the repo root: **`.env.production.example`**. The same block is
reproduced here for convenience. Fill every `>> SECRET <<` value yourself.

```dotenv
# ── App ───────────────────────────────────────────────────────────────────────
APP_NAME=theLearningPortal
APP_ENV=production
APP_KEY=                                   # >> SECRET <<  php artisan key:generate
APP_DEBUG=false
APP_URL=https://thelearningportal.us
APP_LOCALE=us
APP_FALLBACK_LOCALE=en
APP_AUTO_LOGIN=false                        # dev auto-login OFF in prod
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

# ── Logging ───────────────────────────────────────────────────────────────────
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

# ── Database: APP schema (Supabase Postgres pooler) ───────────────────────────
DB_CONNECTION=pgsql
DB_HOST=aws-1-us-east-2.pooler.supabase.com
DB_PORT=5432                                # 5432 = session pooler, 6543 = transaction pooler
DB_DATABASE=postgres
DB_USERNAME=postgres.<PROJECT_REF>          # e.g. postgres.ophofmkxmehmeojvsijc
DB_PASSWORD=                                # >> SECRET <<  (quote if it contains # $ % etc.)
DB_SEARCH_PATH=app                          # app tables live in the `app` schema
DB_SSLMODE=require
DB_PERSISTENT=true                          # reuse TLS handshake across Livewire requests

# ── Database: read-only CORPUS schema (same Supabase pooler) ──────────────────
CORPUS_DB_HOST=aws-1-us-east-2.pooler.supabase.com
CORPUS_DB_PORT=5432
CORPUS_DB_DATABASE=postgres
CORPUS_DB_USERNAME=postgres.<PROJECT_REF>   # prefer a SELECT-only Supabase role
CORPUS_DB_PASSWORD=                         # >> SECRET <<
CORPUS_DB_PERSISTENT=false                  # pooler drops idle conns — keep OFF

# ── Session / Cache / Queue (all DB-backed, no Redis on SiteGround) ───────────
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_DOMAIN=.thelearningportal.us
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=660
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

# ── Mail ──────────────────────────────────────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=<smtp.host>
MAIL_PORT=587
MAIL_USERNAME=                              # >> SECRET <<
MAIL_PASSWORD=                              # >> SECRET <<
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS="noreply@thelearningportal.us"
MAIL_FROM_NAME="${APP_NAME}"

# ── LLM / TTS / images (hosted APIs — local AI services are NOT used in prod) ─
OPENAI_API_KEY=                            # >> SECRET <<
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_MODEL=gpt-4o-mini
OPENAI_JSON_FORMAT=json_object
OPENAI_MAX_TOKENS=4096
OPENAI_TTS_MODEL=tts-1
OPENAI_TTS_VOICE=alloy
ANTHROPIC_API_KEY=                         # >> SECRET <<  (optional alt LLM)

OPENAI_API_KEY_IMG=                        # >> SECRET <<  (falls back to OPENAI_API_KEY)
OPENAI_IMAGE_MODEL=gpt-image-1
OPENAI_IMAGE_SIZE=1536x1024
OPENAI_IMAGE_FORMAT=webp
FAL_AI_KEY=                                # >> SECRET <<
FAL_IMAGE_MODEL=fal-ai/flux/schnell
FAL_UPSCALE_ENABLED=true
UNSPLASH_ACCESS_KEY=                       # >> SECRET <<
PEXELS_API_KEY=                            # >> SECRET <<
EUROPEANA_API_KEY=                         # >> SECRET <<

# ── Avatar voice (ElevenLabs scheduled warm task + Azure lip-sync) ────────────
ELEVENLABS_API_KEY=                        # >> SECRET <<
ELEVENLABS_VOICE_ID=JBFqnCBsd6RMkjVDRZzb
AZURE_SPEECH_KEY=                          # >> SECRET <<
AZURE_SPEECH_REGION=eastus

# ── Optional (off unless used) ────────────────────────────────────────────────
WORLD_LABS_ENABLED=false
# WORLD_LABS_API_KEY / FAL_KEY only needed if WORLD_LABS_ENABLED=true

# ── Lesson settings ───────────────────────────────────────────────────────────
LESSON_STORAGE_DISK=local
MAX_LESSON_GENERATION_ATTEMPTS=3
```

**Secrets to fill in:** `APP_KEY`, `DB_PASSWORD`, `CORPUS_DB_PASSWORD`, `MAIL_USERNAME`,
`MAIL_PASSWORD`, `OPENAI_API_KEY`, `OPENAI_API_KEY_IMG`, `ANTHROPIC_API_KEY`, `FAL_AI_KEY`,
`UNSPLASH_ACCESS_KEY`, `PEXELS_API_KEY`, `EUROPEANA_API_KEY`, `ELEVENLABS_API_KEY`,
`AZURE_SPEECH_KEY` (+ `WORLD_LABS_API_KEY`/`FAL_KEY` only if WorldLabs is enabled).

> **Supabase pooler ports:** use `6543` (transaction pooler) if you hit "too many
> connections" on shared hosting; otherwise `5432` (session pooler) is fine and supports
> `DB_PERSISTENT=true`. Transaction pooler does **not** support persistent/prepared
> statements — if you switch to 6543, set `DB_PERSISTENT=false`.

---

## 2. Connect the repo in SiteGround

SiteGround supports Git two ways. **Option A (recommended): push to SiteGround's own repo.**

1. **Site Tools → Devs → Git → Create Repo.** Pick the domain
   (`thelearningportal.us`). SiteGround creates a bare repo and shows an SSH remote like:
   `ssh://USER@gitREGION.siteground.us/home/customer/www/thelearningportal.us/private/repository.git`
2. Locally add it as a remote and push the deploy branch:
   ```bash
   git remote add siteground ssh://USER@gitREGION.siteground.us/.../repository.git
   git push siteground main
   ```
3. SiteGround checks the working tree out into the site's document root area.

**Deploy branch:** `main`.

> **Option B:** "Deploy from external repository" (GitHub) — paste the repo URL + a deploy
> key. Same post-deploy steps apply. Use this only if you keep the source on GitHub.

After the first checkout, set the site's document root to the Laravel **`public/`** folder
(Site Tools → Domain → document root), or point the app's domain at `.../public`.

---

## 3. Post-deploy command sequence

Run these over **SSH** (Site Tools → Devs → SSH Keys Manager to enable SSH) from the app
root after every deploy. SiteGround does **not** run them automatically.

```bash
cd /home/customer/www/thelearningportal.us/public_html   # adjust to your real app root

# 1. PHP deps (production, no dev packages)
composer install --no-dev --optimize-autoloader

# 2. Front-end build — see the Node note below
npm ci && npm run build

# 3. Run migrations against Supabase (non-interactive)
php artisan migrate --force

# 4. Symlink public/storage -> storage/app/public
php artisan storage:link

# 5. Cache config, routes, and views for speed
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Re-deploys:** also run `php artisan config:clear` first if you changed `.env`, then
> re-run the cache commands. After pulling new code, `php artisan optimize:clear` is the
> safe reset before re-caching.

### Node / Vite build on SiteGround

SiteGround shared hosting historically has **no Node.js runtime** (Node is only on Cloud/
higher tiers). The Vite build (`npm run build`) produces `public/build/` and is required —
the app loads assets via `@vite` and will 500 without the manifest.

**This repo gitignores `/public/build`,** so the compiled assets are NOT in the repo by
default. Two ways to handle it:

- **A — Build locally, then ship the output (recommended for shared hosting):**
  ```bash
  npm ci && npm run build           # locally, generates public/build/
  git add -f public/build           # force past .gitignore
  git commit -m "build: ship vite assets for siteground deploy"
  git push siteground main
  ```
  Then **skip step 2** on the server. (Re-run + re-commit whenever front-end assets change.)
  Alternatively, drop `/public/build` from `.gitignore` so it is always tracked.

- **B — If your SiteGround plan has Node:** run `npm ci && npm run build` on the server as
  shown. Verify with `node -v` first; if it errors, use option A.

---

## 4. Queue + scheduler cron (no daemon on shared hosting)

SiteGround shared hosting has **no persistent worker / Supervisor**, so a long-running
`queue:work` daemon is not possible. Drive both the **queue** and Laravel's **scheduler**
from cron. Add these in **Site Tools → Devs → Cron Jobs**.

**Queue worker — drain pending jobs each minute, then exit:**

```cron
* * * * * cd /home/customer/www/thelearningportal.us/public_html && /usr/local/bin/php artisan queue:work database --queue=default,worldlabs --stop-when-empty --max-time=55 --tries=3 >> storage/logs/queue.log 2>&1
```

**Scheduler — runs `elevenlabs:warm` (every 50 min) and any future scheduled tasks:**

```cron
* * * * * cd /home/customer/www/thelearningportal.us/public_html && /usr/local/bin/php artisan schedule:run >> storage/logs/schedule.log 2>&1
```

**Rationale**

- `queue:work --stop-when-empty --max-time=55` starts each minute, processes the lesson-
  generation pipeline jobs (and the `worldlabs` queue), then exits at ~55s so the next
  minute's cron starts cleanly — no overlap, no orphaned daemon. `--tries=3` matches
  `MAX_LESSON_GENERATION_ATTEMPTS`.
- The app registers a real scheduled task (`Schedule::command('elevenlabs:warm')->cron('*/50 * * * *')`
  in `routes/console.php`), so `schedule:run` **must** run every minute — Laravel decides
  internally when each task is due. Skipping this means the ElevenLabs warm-up never fires.
- Confirm PHP's path: SiteGround usually exposes the CLI binary as `/usr/local/bin/php`
  (or use the version-specific alias from Site Tools → Devs → PHP Manager, e.g. `php8.2`).
  Run `which php` over SSH to verify and substitute.

> If you prefer a single cron line, you can run only `schedule:run` and move the queue
> drain into the scheduler with `->everyMinute()`, but the explicit two-line setup above is
> clearer to debug on shared hosting.

---

## 5. Pre-flight checklist

- [ ] **`.env` present on server**, `APP_ENV=production`, `APP_DEBUG=false`,
      `APP_AUTO_LOGIN=false`. `.env` is **not** in git (confirm: `git check-ignore .env`).
- [ ] **`APP_KEY` is set** (`php artisan key:generate` once; keep it stable across deploys —
      changing it invalidates sessions and encrypted data).
- [ ] **`public/build` is available** on the server — either committed via `git add -f`
      (option A) or built on the server (option B). Verify `public/build/manifest.json` exists.
- [ ] **Document root points at `public/`** (not the repo root).
- [ ] **Storage writable:** `chmod -R 775 storage bootstrap/cache` and ensure the web user
      owns them; run `php artisan storage:link`.
- [ ] **Supabase allows SiteGround's outbound IP** — Supabase pooler is public, but if you
      enabled network restrictions/allowlists, add the SiteGround server IP (and prefer
      `DB_SSLMODE=require`). Test: `php artisan migrate:status`.
- [ ] **Both crons added** (`queue:work --stop-when-empty` and `schedule:run`) with the
      correct PHP binary path and app root.
- [ ] **Caches rebuilt** after deploy (`config:cache route:cache view:cache`); run
      `php artisan optimize:clear` first if `.env`/routes changed.
- [ ] **Migrations applied** against Supabase (`php artisan migrate --force`) into the
      `app` schema (`DB_SEARCH_PATH=app`).
- [ ] **HTTPS enforced** and `SESSION_SECURE_COOKIE=true` (SiteGround provides Let's Encrypt).

---

## 6. Quick redeploy loop

```bash
# locally (only if front-end changed and SiteGround has no Node):
npm ci && npm run build && git add -f public/build && git commit -m "build: assets"

git push siteground main

# over SSH on the server:
cd /home/customer/www/thelearningportal.us/public_html \
  && composer install --no-dev --optimize-autoloader \
  && php artisan migrate --force \
  && php artisan optimize:clear \
  && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

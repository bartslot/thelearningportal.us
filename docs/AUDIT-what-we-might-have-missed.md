# Audit — What We Might Have Missed

**Subject:** thelearningportal.us — AI-generated, story-driven K-12 history lessons narrated by AI avatars, consumed by **teachers** and **children** via web + a Flutter/PWA app.
**Audit date:** 2026-06-24 · **Branch:** `main`
**Lenses:** (A) Agent/LLM pipeline architecture · (B) Ed-tech product readiness for a child-facing product.
**Method:** Direct code reading + targeted searches. Every finding cites `file:line` or states **absent**. No padding.

---

## 1. Executive verdict

**Production-readiness for a child-facing product: NOT READY.** The lesson-generation engine is a capable, well-structured agent pipeline (source → outline → per-scene script → image → TTS → quiz), and the *Flutter API* path enforces the right access rules. But the product is missing the entire legal and safety substrate a real K-12 tool requires, and the pipeline has a class of silent failure that strands lessons forever. A demo will look great; shipping to real children in this state carries genuine legal (COPPA/FERPA/Section 508) and reputational risk, plus an uncapped-cost risk to the business.

The three things that genuinely separate "impressive demo" from "safe to put in front of a child":

**TOP 3 MOST URGENT FIXES**

1. **Close the content-safety / teacher-review bypass on the public web player, and stop disabling the image safety checker.** A lesson is publicly playable at `/lesson/{code}` and is listed on the public homepage as soon as it hits `Previewable` — a status set *automatically* the moment a teacher reaches the preview step (`app/Livewire/Wizard/Step3SceneConfigurator.php:500`, `app/Livewire/Wizard/Step4Preview.php:26`), **before** anyone clicks "Publish." Worse, AI image generation runs with `'enable_safety_checker' => false` (`app/Services/FalAiImageService.php:35`) and there is **zero** moderation on the AI-generated *text* children read. Unreviewed, unmoderated AI content can reach a child with no human in the loop. (Findings C-1, C-2.)

2. **Add the COPPA/FERPA layer before any real student account exists.** There is no age/DOB capture, no parental-consent mechanism, no data-deletion path, and no privacy policy anywhere in the codebase (Findings C-3, C-4, H-7). A US ed-tech product handling under-13 children's PII and education records without these is non-compliant on day one. The student-provisioning flow is currently *unbuilt* — which is the ideal moment to build consent/age-gating in rather than retrofit.

3. **Make the pipeline fail loudly and cap its spend.** A single permanently-failing scene leaves the whole lesson stuck in `ScenesGenerating` forever with no teacher-visible error and no recovery (Finding C-5: `Bus::batch(...)->then()` with no `->catch()/->finally()` at `app/Jobs/BuildLessonOutline.php:240-243`, and no `failed()` handler on any job). Simultaneously there is no rate limit, spend cap, or scene/regeneration quota anywhere (Finding C-6) — one careless or compromised teacher account can run an unbounded OpenAI/ElevenLabs/fal bill. Both must be fixed before exposing generation to non-trusted users.

The rest of this document is the severity-ranked detail.

---

## 2. Severity-ranked findings

Severity scale: **CRITICAL** (block launch) · **HIGH** (fix before real users) · **MEDIUM** (fix soon) · **LOW** (track).

---

### CRITICAL

---

#### C-1 — "Teacher review before students see it" is bypassable on the public web player

- **Severity:** CRITICAL (content safety + product invariant)
- **Mechanism:** `CLAUDE.md` states lessons aren't visible to students until `status=published`. This is true **only for the Flutter API** (`app/Http/Controllers/Api/StudentLessonController.php:46` strictly requires `status === 'published'`). The **public web player** does not:
  - `routes/web.php:44` — `GET /lesson/{lessonCode}` is **public, no auth**.
  - `app/Http/Controllers/LessonPlayerController.php:16,19` — plays any lesson whose status is **`Published` OR `Previewable`**.
  - A lesson is set to `Previewable` **automatically**, with no human approval, just by navigating the wizard to the preview step: `app/Livewire/Wizard/Step3SceneConfigurator.php:500` and `app/Livewire/Wizard/Step4Preview.php:26` (`mount()` flips status to `Previewable` on page load).
  - The **public homepage lists `Previewable` lessons** for anyone to open: `routes/web.php:23-34` (`->whereIn('status', [Published, Previewable])`).
- **Risk to children:** An in-progress, un-reviewed, AI-generated lesson — possibly containing a hallucinated fact or an unmoderated image — is reachable by URL and surfaced on the landing page before the teacher has approved anything. The review gate the product markets as "a feature, not a limitation" does not hold on the web.
- **Evidence:** `routes/web.php:23-34,44`; `app/Http/Controllers/LessonPlayerController.php:16-19`; `app/Livewire/Wizard/Step3SceneConfigurator.php:500`; `app/Livewire/Wizard/Step4Preview.php:25-26,92-102`.
- **Fix:**
  - The public player must accept **only** `Published` (keep the existing "teacher previewing their own lesson while authenticated" branch at `LessonPlayerController.php:24-29`, which is fine because it's owner-scoped).
  - Remove `Previewable` from the public homepage query (`routes/web.php:24-26`).
  - Rename the concept: `Previewable` should mean "teacher-only preview," never "world-readable." Treat `publish()` (`Step4Preview.php:92`) as the *single* gate that makes a lesson public.

---

#### C-2 — AI content for children has no moderation, and image safety checking is explicitly disabled

- **Severity:** CRITICAL (content safety)
- **Mechanism:** Two independent gaps:
  1. **Text:** The lesson script, scene narration, and quiz are LLM output shown to children. A repo-wide search for `moderat*`, `profan*`, `inappropriate`, `nsfw`, `content_filter`, `safesearch`, `blocklist` returns **zero matches in `app/`**. There is no profanity filter, no moderation API call (e.g. OpenAI Moderations), and no age-appropriateness check on generated text beyond a grade-level *instruction* in the prompt.
  2. **Images:** `app/Services/FalAiImageService.php:35` sends `'enable_safety_checker' => false` — the provider's NSFW/safety filter is **deliberately turned off** on the service that generates the pictures children look at.
- **Risk to children:** Generative models can produce violent, disturbing, sexualized, or biased imagery/text even from innocuous historical prompts (war, slavery, executions are core history topics here — see the `[serious]` examples in `app/Services/LlmService.php:53-56`). With the safety checker off and no text moderation, an inappropriate image or passage can reach a child. This is the single highest reputational/safety risk in the product.
- **Evidence:** `app/Services/FalAiImageService.php:35` (`enable_safety_checker => false`); absence of any moderation in `app/Services/` (LlmService, OpenAiLlmService, OpenAiImageService, SceneScriptPrompt, QuizPrompt); `app/Livewire/Wizard/Step4Preview.php` (publish path performs **no** content check, only `allReady` status check at `:92-97`).
- **Fix:**
  - Set `enable_safety_checker => true` on fal (and confirm the equivalent on `OpenAiImageService` — gpt-image-1 has a moderation parameter; set it to the stricter level for a children's product).
  - Add a moderation pass on generated **text** (OpenAI Moderations endpoint or equivalent) after `GenerateSceneScript`/`BuildLessonOutline`, flagging the scene/lesson for mandatory human review on any hit rather than auto-advancing.
  - Make `publish()` require an explicit "I have reviewed this content" affirmation, and block publish if any scene is moderation-flagged.

---

#### C-3 — No COPPA mechanism: no age gate, no parental consent

- **Severity:** CRITICAL (US child-privacy law)
- **Mechanism:** Students are `User` rows (`role='student'`) in the same table as teachers (`app/Models/User.php:20-25`; `database/migrations/2025_01_01_000010_add_role_to_users_table.php`). The PII stored on a student is `name` + `email` (`database/migrations/0001_01_01_000000_create_users_table.php:16-18`), plus `ip_address`/`user_agent` in `sessions`. There is **no** `age`, `dob`, or `date_of_birth` column anywhere on users. A repo-wide search for `consent`, `parental`, `guardian`, `coppa`, `ferpa`, `dob`, `birth` returns **0 matches** across `app/`, `routes/`, `resources/`, `database/`.
- **Risk to children/business:** COPPA requires verifiable parental consent (or valid school authorization under the school-consent exception, with its own conditions) before collecting personal information from a child under 13. The app cannot even *identify* an under-13 user, so it cannot apply COPPA rules in principle. IP address is COPPA-covered personal information and is collected by default (`sessions` table). FTC enforcement of COPPA against ed-tech is active and expensive.
- **Evidence:** `app/Models/User.php:20-25`; `database/migrations/0001_01_01_000000_create_users_table.php:16-18,33-35`; absence of any consent/age model or migration (verified by search).
- **Fix:** Before any non-demo student account is created: (a) capture grade/age or operate strictly under the COPPA school-consent exception with documented teacher/district authorization; (b) build a parental-consent record model and gate student-account activation on it; (c) minimize PII — reconsider storing real student email at all (consider teacher-issued usernames). Build this at the (currently nonexistent) student-provisioning step — see C-9.

---

#### C-4 — FERPA: named-student education records with no governing controls or deletion path

- **Severity:** CRITICAL (US education-records law)
- **Mechanism:** `student_progress` stores `answers` (JSON), `score`, `watch_seconds`, `video_completed`, timestamps, keyed to a named student (`database/migrations/2025_01_01_000060_create_student_progress_table.php`; `app/Models/StudentProgress.php`). These are **education records** under FERPA. There is:
  - **No deletion/erasure path** — no `Route::delete`, no `destroy` action, no account-deletion route anywhere in `routes/` (verified by search).
  - **No soft delete or purge on `StudentProgress`** — the model has no `SoftDeletes` trait and no `deleted_at`; no scheduled purge exists.
  - **No data-retention policy** in code or docs.
- **Risk to children/business:** FERPA (and many state student-privacy laws, plus district contracts/DPAs) require the ability to delete/return student records and to honor retention limits. None of that exists. A school district's privacy review will fail this immediately.
- **Evidence:** `app/Models/StudentProgress.php` (no SoftDeletes); `database/migrations/2025_01_01_000060_create_student_progress_table.php`; absence of delete routes (`routes/web.php`, `routes/api.php`).
- **Fix:** Add soft deletes to `StudentProgress`; build teacher/admin (and parent, where applicable) "delete this student and their records" actions with hard-delete cascade; add a retention policy + scheduled purge; document it in a privacy policy (C-4 pairs with H-7).

---

#### C-5 — A single failed scene strands the lesson forever (no batch failure handling, no `failed()` handlers, no stale sweep)

- **Severity:** CRITICAL (reliability — silent dead-end for teachers)
- **Mechanism:** The scene fan-out dispatches `Bus::batch($jobs)->then(fn () => GenerateLessonQuiz::dispatch(...))->dispatch()` at `app/Jobs/BuildLessonOutline.php:240-243`. There is **only a `->then()` (success) callback — no `->catch()` and no `->finally()`** (verified: 0 matches for `->catch(`/`->finally(`/`->allowFailures(` in `app/`). Consequently, when any one scene job (`GenerateSceneScript/Image/Audio`) exhausts its 3 tries — e.g. **Kokoro TTS is currently down**, or ElevenLabs returns 429, or the image API errors:
  - The batch is marked failed; `->then()` never fires; **`GenerateLessonQuiz` never dispatches**.
  - Nothing transitions the lesson out of `LessonStatus::ScenesGenerating` (set at `BuildLessonOutline.php:140`). The lesson is stuck **forever**.
  - There is **no `failed()` method on any job** (verified: 0 matches for `function failed(`) and **no scheduled stale/stuck-lesson sweep** (the only scheduled task is `elevenlabs:warm`, `routes/console.php:11`).
  - The teacher's progress screen auto-advances only when *one* scene becomes ready (`app/Livewire/Wizard/Step2Generate.php:39-42`), so a partial failure either shows a broken scene or spins indefinitely with no "generation failed" message.
- **Risk to teachers/business:** With the very service that's down (TTS), every lesson started right now ends in a permanent silent hang. A teacher prepping a class the night before a lesson gets a spinner that never resolves and no error. This will read as "the product is broken."
- **Evidence:** `app/Jobs/BuildLessonOutline.php:140,240-243`; `app/Jobs/GenerateSceneAudio.php:65-68` and `GenerateSceneScript.php:51-57` (per-scene `catch` sets the *scene* to `failed` but nothing aggregates to the lesson); `app/Jobs/Concerns/MarksSceneReady.php`; `routes/console.php:11`.
- **Fix:**
  - Add `->catch(fn (Batch $b, Throwable $e) => /* set lesson Failed + error_message */)` and `->finally(...)` to the batch.
  - Add a `failed(Throwable $e)` method to each scene job that writes a teacher-visible error and, if the batch can't complete, marks the lesson `Failed`.
  - Add a scheduled sweep that marks lessons stuck in `*Generating` past a threshold as `Failed` with a message, and surface a "Retry generation" affordance (one already exists but is `local`/`testing`-only — `routes/web.php:146-147`).

---

#### C-6 — No AI-cost controls: no rate limiting, no spend cap, no scene/regeneration quota; retries amplify paid calls

- **Severity:** CRITICAL (business — uncapped cost / abuse)
- **Mechanism:** The pipeline calls paid APIs (Anthropic, OpenAI TTS + images, ElevenLabs, fal.ai, World Labs). There are no guardrails:
  - **No throttle** on login (`routes/web.php:50`; `AuthController` via `routes/api.php:15`) or on lesson generation (`routes/web.php:137-143`) — no `throttle` middleware appears in `web.php` at all.
  - **No scene cap:** scene count comes straight from the LLM outline (`BuildLessonOutline.php:157-182`) with no maximum; teachers can add unlimited scenes (`Step3SceneConfigurator.php` `addScene`) and re-generate any asset unlimited times (`Step3SceneConfigurator.php` `regenerate`; `Step2Generate.php` `retryAsset`) — each click is a fresh paid call.
  - **No per-teacher daily quota or cooldown** on lesson creation.
  - **Retries hit paid endpoints on 4xx too:** `app/Services/OpenAiImageService.php:319`, `app/Services/OpenAiLlmService.php:79`, `app/Services/FalAiImageService.php:29` use `->retry(..., when: fn () => true, throw: false)`, so a non-transient error still burns 2–3 paid attempts; combined with job-level `tries=3`, a single failing scene can drive ~9 paid attempts.
  - **`MAX_LESSON_GENERATION_ATTEMPTS` is largely dead config:** it maps to `config('lessons.max_generation_attempts')` but is only consulted by the **legacy** `GenerateLesson` path (`app/Models/Lesson.php:478`, `app/Jobs/GenerateLesson.php:106`). The active wizard jobs hardcode `$tries = 3` and never read it.
- **Risk to business:** One careless teacher (or one compromised account, given there's no login throttle) can generate hundreds of lessons/images and run a large, real bill with no ceiling and no alert.
- **Evidence:** `routes/web.php:50,137-143`; `app/Jobs/BuildLessonOutline.php:157-182`; `app/Services/OpenAiImageService.php:319`; `app/Services/OpenAiLlmService.php:79`; `app/Services/FalAiImageService.php:29`; `app/Models/Lesson.php:478`.
- **Fix:** Add `throttle` to login and to generate/regenerate actions; enforce a per-teacher daily lesson + regeneration quota; hard-cap scene count; change the three `->retry(when: fn()=>true)` calls to retry only on 5xx/429/connection errors; add a global daily spend ceiling with a kill-switch.

---

#### C-7 — `debug123` master-password backdoor, env-gated only

- **Severity:** CRITICAL (auth / account takeover)
- **Mechanism:** `app/Http/Controllers/Auth/LoginController.php:27-39` — if `app()->isLocal()` and the submitted password equals the hardcoded string `'debug123'`, it logs in **any user by email without checking their real password**. The only guard is `APP_ENV=local`.
- **Risk to everyone:** If production is ever booted with `APP_ENV` unset or misconfigured — a classic shared-hosting / SiteGround footgun — this becomes full account takeover of every child, teacher, and admin by email address alone.
- **Evidence:** `app/Http/Controllers/Auth/LoginController.php:27-39`.
- **Fix:** Delete this branch entirely. If a dev shortcut is truly needed, gate it on `App::environment('local') && App::hasDebugModeEnabled()` *and* a value from `.env` that is absent in prod — but deletion is strongly preferred for an auth path in a child-facing app.

---

#### C-8 — No error tracking / monitoring: the founder is blind in production

- **Severity:** CRITICAL (operability — you won't see failures)
- **Mechanism:** No Sentry/Bugsnag/Flare/Rollbar installed (`composer.json` requires only dev-only `nunomaduro/collision`; the `rollbar` hit in `composer.lock` is a Monolog *suggestion*, not a dependency). `bootstrap/app.php:32-33` — `withExceptions()` is empty. Prod logging is `LOG_LEVEL=warning` to a single file on the SiteGround box; a `slack` log channel exists in `config/logging.php` but `LOG_SLACK_WEBHOOK_URL` is unset.
- **Risk to business:** Combined with C-5 (silent stuck lessons), C-6 (silent cost), and the queue-worker dependency (H-1), the founder has **no signal** when generation fails, lessons hang, costs spike, or an AI service goes down. Problems will surface as teacher complaints, not alerts.
- **Evidence:** `composer.json` (no error-tracker); `bootstrap/app.php:32-33`; `config/logging.php` (`slack` channel present, webhook unset).
- **Fix:** Install Sentry (free tier is sufficient) and report from `withExceptions`. Wire the `slack` channel for `critical` and emit a critical log on lesson-generation failure and on AI-service outage.

---

### HIGH

---

#### H-1 — Queue worker on SiteGround is operator-dependent and silently fatal if forgotten

- **Severity:** HIGH (reliability — but the mechanism exists)
- **Mechanism:** `QUEUE_CONNECTION=database` (`.env.example:59`; also the prod template), **not `sync`** — so lesson creation does not hang the web request; the wizard dispatches `BuildLessonOutline` and shows a `wire:poll` progress screen. A correct shared-hosting worker mechanism **is documented**: `docs/deploy/siteground-git-deploy.md:204-237` prescribes a per-minute cron running `php artisan queue:work database --stop-when-empty --max-time=55 --tries=3` plus `schedule:run`. **But** this cron is a manual SiteGround Site-Tools step, not in code, and there is no in-app check that the queue is being drained. If it's forgotten or the PHP path is wrong, **every lesson sits in `Outlining`/`ScenesGenerating` forever with no error** — and the codebase already knows this failure mode (`app/Providers/AppServiceProvider.php:24-32` adds a lock precisely because "with no queue worker running… thousands of jobs pile up").
- **Risk:** Misses the cron → product appears completely broken (no lesson ever finishes), with no alert.
- **Evidence:** `.env.example:59`; `config/queue.php:16`; `docs/deploy/siteground-git-deploy.md:204-237`; `app/Providers/AppServiceProvider.php:24-32`; `app/Models/Lesson.php:209`.
- **Fix:** Add a queue-depth / "last job processed at" healthcheck (and alert via C-8); make the cron a hard release-gate checklist item; consider a self-check on the teacher dashboard ("generation worker last seen N min ago").

---

#### H-2 — No captions or transcripts for narrated audio/avatar video (Section 508 / ADA)

- **Severity:** HIGH (accessibility law — and unusually cheap to fix here)
- **Mechanism:** The core content is narrated audio + animated avatar. US schools receiving federal funds are legally required (Section 508 / ADA Title II) to provide captions/transcripts for audio and video. The **student** player renders **none**: `resources/views/lesson/player.blade.php` has no `<track kind="captions">`, no transcript element, and never displays the script — even though the script text is passed to JS (`player.blade.php:20,53`) and **word-level timing already exists** (`resources/js/lesson-player.js:609-610` consumes ElevenLabs per-word `alignment` *only* for lip-sync). A working synced-transcript component already exists in the repo (`resources/views/components/audio-player.blade.php:80-90`) but is used **only** in admin/teacher screens, never wired into the student player.
- **Risk:** Deaf/hard-of-hearing students are excluded; a single district accessibility complaint can block adoption. The data and the rendering component already exist, so this is a wiring task, not new infrastructure.
- **Evidence:** `resources/views/lesson/player.blade.php` (no track/transcript); `resources/js/lesson-player.js:609-610`; `resources/views/components/audio-player.blade.php:80-90`; usage only at `resources/views/livewire/admin/avatar-studio.blade.php:306-309`.
- **Fix:** Render a synchronized transcript (reuse the `audio-player` word-highlight pattern, driven by the existing `audio_alignment`) or emit a `<track>` VTT generated from `alignment`, in `lesson/player.blade.php`.

---

#### H-3 — Hallucination control is prompt-only; no verification step on facts children read

- **Severity:** HIGH (content accuracy for children)
- **Mechanism:** `CLAUDE.md` mandates "Only use facts from the provided source… never invent." This is enforced **only as prompt text**, in three places — `app/Services/LlmService.php:85-86`, `app/Services/LessonOutlinePrompt.php:53,55`, `app/Services/SceneScriptPrompt.php:30`. There is **no** post-generation verification: no check that generated claims appear in the source, no grounding/citation pass, no fact-check (verified: no `verif*`/`fact.check`/`grounded` logic in `app/Services` or `app/Jobs`). Compounding factors:
  - Temperature is **0.4** (`LlmService.php:155,194`), not 0 — some creative latitude remains.
  - The fallback chain ends in `buildFallbackScript()` (`LlmService.php:135-139,349-359`) which just stitches the first 5 source sentences — degraded but at least source-derived. (Note: this `LlmService` is the *legacy* single-call path; the active per-scene pipeline uses `OpenAiLlmService` with the same prompt-only guarantee.)
  - The per-scene generator runs **independent LLM calls per scene** that cannot see each other (`SceneScriptPrompt.php:13-25`), so a fact stated in one scene isn't cross-checked against another or the source.
- **Risk to children:** Children read these scripts as authoritative history. An invented date, name, or causal claim ships unflagged. "We told the model not to" is not a control.
- **Evidence:** `app/Services/LlmService.php:85-86,155,194`; `app/Services/LessonOutlinePrompt.php:53,55`; `app/Services/SceneScriptPrompt.php:30`; no verification logic anywhere in `app/`.
- **Fix:** Add a grounding/verification pass (e.g. ask a second model to flag any claim in the script not supported by the source text, or do retrieval-based claim-checking against the fetched article) and surface flagged claims in the mandatory teacher review (ties to C-1/C-2). At minimum, drop temperature toward 0 for the factual outline pass and make the teacher-review affirmation explicit.

---

#### H-4 — `prefers-reduced-motion` is ignored by the always-animated film-grain overlays (and player motion)

- **Severity:** HIGH (accessibility — vestibular/photosensitivity, children)
- **Mechanism:** The grain overlays animate continuously with `infinite` and no reduced-motion guard. `resources/css/app.css:294` `.lp-grain-poster::after { animation: poster-grain 0.7s steps(7) infinite; }` and `:321` `.skybox-grain-overlay { animation: skybox-grain 1s steps(10) infinite; }` (the skybox overlay is placed in the player at `player.blade.php:236`). `resources/css/app.css` contains **0** `prefers-reduced-motion` blocks. The Ken Burns slideshow and `animate-pulse/spin/bounce` utilities in the player are likewise unguarded. (The team *does* know the pattern — it's used in `resources/js/timemap/index.js:271` and `resources/views/livewire/lesson-composer.blade.php:117` — just not on the player or grain.)
- **Risk to children:** WCAG 2.2 (2.2.2 Pause/Stop/Hide, 2.3.3 Animation from Interactions). Constant grain animation is a real trigger for children with vestibular disorders or photosensitivity, and runs the entire time a lesson is on screen.
- **Evidence:** `resources/css/app.css:266,283-294,299-321`; `resources/views/lesson/player.blade.php:236`; 0 reduced-motion blocks in `app.css`.
- **Fix:** Add `@media (prefers-reduced-motion: reduce) { .lp-grain-poster::after, .skybox-grain-overlay { animation: none; } }` and pause the Ken Burns interval when the query matches.

---

#### H-5 — Dev auto-login middleware is globally registered, env/config-gated only

- **Severity:** HIGH (auth)
- **Mechanism:** `app/Http/Middleware/AutoLoginDev.php:21-37` auto-authenticates a seeded account (admin by default, or `student`/`teacher` via `APP_USER_ROLE`) when `app()->isLocal() && config('app.auto_login')`. It is appended to the global web middleware stack and **prepended to the auth priority list** (`bootstrap/app.php:23-30`). Two gates is better than C-7's one, but it's a second env-dependent auth-skip path wired into every web request.
- **Risk:** Same class as C-7 — a prod env/config slip silently logs visitors in as a seeded user.
- **Evidence:** `app/Http/Middleware/AutoLoginDev.php:21-37`; `bootstrap/app.php:23-30`.
- **Fix:** Additionally refuse to run unless `APP_DEBUG` is true, and ensure `config('app.auto_login')` can never be true in the prod `.env`. Prefer removing it from the global stack and enabling only behind an explicit local-only route group.

---

#### H-6 — Production-seedable real admin credentials

- **Severity:** HIGH (auth)
- **Mechanism:** `database/seeders/DatabaseSeeder.php:20-27` seeds a real admin (`bartslot@gmail.com`) with password `password`; teacher/student demo accounts likewise use weak known passwords. If `db:seed` ever runs against production (easy to do during a SiteGround setup), there's a known-credentials admin login.
- **Risk:** Trivial admin takeover if seeded in prod.
- **Evidence:** `database/seeders/DatabaseSeeder.php:20-27,36,47`.
- **Fix:** Never seed in prod; pull any seeded passwords from env or generate random; guard the seeder against `App::environment('production')`.

---

#### H-7 — No privacy policy / terms / COPPA disclosure surface

- **Severity:** HIGH (legal disclosure)
- **Mechanism:** No privacy-policy, terms, or COPPA/FERPA disclosure route or view exists (search of `routes/` and `resources/views/` returns 0). The marketing site even claims "Content mapped to curriculum standards" (`resources/views/about.blade.php:97`) — a claim with **no implementation** (see M-2).
- **Risk:** COPPA requires a clear online privacy notice describing what's collected from children and how. Schools/districts require a DPA and published privacy terms before signing. Marketing a standards claim you don't implement is a separate credibility/legal exposure.
- **Evidence:** absence in `routes/`, `resources/views/`; `resources/views/about.blade.php:97`.
- **Fix:** Publish a privacy policy + terms (with explicit COPPA/FERPA sections) before launch; remove or implement the standards claim.

---

#### H-8 — No DB backup strategy for student/teacher data

- **Severity:** HIGH (data durability)
- **Mechanism:** No `spatie/laravel-backup` (not in `composer.json`), no `config/backup.php`, no backup cron in the deploy doc. Production data (teacher accounts, classrooms, student progress) relies entirely on the hosting provider's backups (Supabase Postgres per the deploy doc / project memory; free-tier PITR is limited).
- **Risk:** A bad migration or accidental delete has no application-managed restore path for children's education records.
- **Evidence:** `composer.json` (no backup pkg); `docs/deploy/siteground-git-deploy.md` (no backup cron).
- **Fix:** Add `spatie/laravel-backup` to off-box storage, or confirm and document a paid Supabase PITR tier and a tested restore procedure.

---

#### H-9 — Quiz answers may be non-interactive / unlabeled in the web slide (keyboard + SR)

- **Severity:** HIGH **if** students answer quizzes on web (confirm Flutter vs web)
- **Mechanism:** The web quiz slide renders answer options as static `<li>` items (`resources/views/lessons/modules/quiz-mcq/slide.blade.php:16-27`) — no `<button>`/`<input>`, no labels, not keyboard-operable — produced for both teacher and student audiences by `app/Lessons/Modules/Types/QuizMcqModule.php`. If any student answers via this web view, it fails WCAG 2.1.1 (keyboard), 1.3.1/4.1.2 (name/role/value), 3.3.2 (labels).
- **Risk:** Keyboard-only and screen-reader students cannot take the quiz on web.
- **Evidence:** `resources/views/lessons/modules/quiz-mcq/slide.blade.php:16-27`.
- **Fix:** If quizzes are answered on web, render real `<button>`/radio `<input>` with labels and focus states. If quizzes are Flutter-only, this slide is preview-only — document that and ensure the Flutter quiz UI is itself accessible.

---

### MEDIUM

---

#### M-1 — Sanctum API tokens never expire

- **Severity:** MEDIUM
- **Mechanism:** `config/sanctum.php` is **absent**, so `expiration` defaults to `null` → tokens minted to children's devices (`app/Http/Controllers/Api/AuthController.php`) never expire; no token pruning.
- **Risk:** A lost/stolen child's device keeps a valid token indefinitely.
- **Fix:** Publish `config/sanctum.php`, set a finite `expiration`, schedule `sanctum:prune-expired`.

---

#### M-2 — "Mapped to curriculum standards" is marketing-only; no standards alignment exists

- **Severity:** MEDIUM (product credibility; teacher buying criterion)
- **Mechanism:** Standards alignment (Common Core / NGSS / state standards / TEKS) is a primary teacher purchasing criterion. The only reference is the marketing line at `resources/views/about.blade.php:97`; there is **no** standards field on `Lesson`, no tagging, no alignment data (search returns 0 implementation hits).
- **Risk:** Teachers/districts expecting standards alignment find none; the about-page claim is unsupported.
- **Fix:** Either add a standards-tagging model and surface it per lesson, or remove the claim until built.

---

#### M-3 — No classroom-management or lesson-assignment UI for teachers

- **Severity:** MEDIUM (product completeness — delivery path to students is unbuilt)
- **Mechanism:** The data model exists — `app/Models/Classroom.php` has `students()`, `lessons()`, and auto-generates a `join_code` — but there is **no teacher UI** to create a classroom, roster students, or assign lessons (the only consumer of these relations is `app/Http/Controllers/Api/StudentLessonController.php`). The student API filters to "lessons assigned to the student's classrooms," but nothing in the app actually performs that assignment or enrollment.
- **Risk:** Even once a lesson is published, there is no in-product way for a teacher to get it to specific students. The core teacher→student loop is incomplete.
- **Evidence:** `app/Models/Classroom.php:47-57`; absence of classroom/roster/assign UI in `app/Livewire/` and `resources/views/teacher/`.
- **Fix:** Build classroom CRUD, student rostering (join-code or teacher invite), and lesson-assignment UI. **Design COPPA/FERPA consent (C-3) and PII minimization into this flow from the start** — this is the right insertion point (ties to C-9).

---

#### M-4 — Service wrappers can hang the single shared-hosting worker; one passes the TTS key on the CLI

- **Severity:** MEDIUM (reliability + secret exposure)
- **Mechanism:**
  - `app/Services/ElevenLabsService::getVoices()` has **no HTTP timeout and no try/catch** — a hung ElevenLabs blocks the single cron worker; it's called by the scheduled `elevenlabs:warm` and on web boot (`AppServiceProvider.php:30`).
  - `AzureTtsService` shells out via `exec()` with no `timeout` wrapper (can hang the worker) **and passes the Azure key as a CLI argument** (`--key`), visible in a process listing on shared hosting.
  - Several paid HTTP calls set a read `->timeout()` but omit `->connectTimeout()`, so a dead host can stall on connect.
- **Risk:** With only one drain-and-exit worker (H-1), a single hung call stalls all generation; the Azure key is exposable via `ps`.
- **Evidence:** `app/Services/ElevenLabsService.php` (`getVoices`); `app/Services/AzureTtsService.php`; `app/Providers/AppServiceProvider.php:30`.
- **Fix:** Add timeouts + try/catch to `getVoices`; wrap `exec()` with a timeout and pass the Azure key via child-process env, not argv; add `connectTimeout()` to outbound AI calls.

---

#### M-5 — Stock `/up` healthcheck verifies nothing useful; no AI-service preflight

- **Severity:** MEDIUM (operability)
- **Mechanism:** `/up` is the stock Laravel endpoint (`bootstrap/app.php:12`) — it only confirms the framework boots, not DB/queue/AI reachability. No endpoint or pre-generation check confirms OpenAI/ElevenLabs/Azure/fal are up before a teacher (pays to) start a pipeline.
- **Risk:** Teachers kick off generation against a down service and only discover it via a stuck lesson (C-5).
- **Fix:** Add a custom health check (DB + queue-last-processed + a cheap AI-reachability probe); show service status on the teacher dashboard before "Generate."

---

#### M-6 — `database/*.sqlite` is not explicitly gitignored

- **Severity:** MEDIUM (potential child-PII leak into git)
- **Mechanism:** `.gitignore` covers `.env` but has no explicit `database/*.sqlite*` line. No sqlite file is tracked today, but a stray `git add -A` with a populated dev DB containing real student rows would commit child PII into history.
- **Evidence:** `.gitignore` (no sqlite line).
- **Fix:** Add `database/*.sqlite*` to `.gitignore` now.

---

#### M-7 — CORS config absent for the public API

- **Severity:** MEDIUM
- **Mechanism:** `config/cors.php` is absent; Laravel's defaults apply. Confirm `allowed_origins` for `/api/v1/*` is restricted to the Flutter app's origins before launch.
- **Fix:** Publish and lock down `config/cors.php`.

---

### LOW / NOTES

---

- **L-1 — `MAX_LESSON_GENERATION_ATTEMPTS` is misleading dead config.** It's read only by the legacy `GenerateLesson` path (`app/Models/Lesson.php:478`); active wizard jobs hardcode `$tries = 3`. Either wire it through or remove it to avoid a false sense of a tunable cap. (`app/Jobs/BuildLessonOutline.php:28`, etc.)
- **L-2 — Legacy vs active pipeline duplication.** `GenerateLesson`/`LlmService` (single-call, `tries=1`, swallows to `Failed`) coexist with the active `BuildLessonOutline`/`OpenAiLlmService` per-scene pipeline. The retry route (`routes/web.php:146-166`) is `local`/`testing`-only. Dead/legacy paths increase the chance of editing the wrong one — consider removing the legacy path. (Per project memory + `app/Jobs/GenerateLesson.php`.)
- **L-3 — Per-user API authorization is sound (positive).** The student API correctly scopes every read/write to `$request->user()` (no IDOR): `StudentLessonController.php:23-29,42-48,121-124`, withholds correct answers until submission (`:71,163-171`). Keep this pattern when building the teacher/classroom API (M-3).
- **L-4 — No PII in URLs or logs (positive).** Student routes key off the authenticated user, not an id in the path; no `Log::*` call logs request payloads/emails/tokens (verified). Maintain this discipline.
- **L-5 — Secret hygiene in source is clean (positive).** No hardcoded API keys/tokens in tracked source; `.env` is ignored and `0600`. (The exceptions are the seeded demo passwords, H-6, and the Azure-key-on-argv runtime exposure, M-4.)
- **L-6 — Icon-only player buttons use `title=` not `aria-label`.** `resources/views/lesson/player.blade.php:146-180` (play/pause/stop/mute) and the QR button lack reliable accessible names; inner SVGs aren't `aria-hidden`. Add `aria-label`s and `aria-hidden` on decorative SVGs.
- **L-7 — Narration autoplays after the initial "Start lesson" gesture.** First sound is user-initiated (OK for WCAG 1.4.2), but each subsequent scene auto-plays (`resources/js/lesson-player.js:651`); pause/mute exist. Acceptable; just confirm the pause control is always reachable.

---

## 3. Ordered fix plan (code-first, most urgent first)

**Phase 0 — Safety & legal gates (block launch to children):**
1. **C-7:** Delete the `debug123` backdoor (`LoginController.php:27-39`). *(minutes)*
2. **C-2:** Set `enable_safety_checker => true` (`FalAiImageService.php:35`) and the equivalent moderation level on `OpenAiImageService`; add a text-moderation pass after script/outline generation. *(hours)*
3. **C-1:** Public player accepts only `Published`; drop `Previewable` from the homepage query; make `publish()` the sole public gate. *(`LessonPlayerController.php:16-19`, `routes/web.php:24-26`, `Step3SceneConfigurator.php:500`, `Step4Preview.php:26`)* *(hours)*
4. **C-3 + C-4 + H-7:** Add age/consent capture + parental-consent record, soft-delete + deletion path for `StudentProgress`, retention purge, and a published privacy policy/terms. Build into the new student-provisioning flow (M-3). *(days — start now)*

**Phase 1 — Reliability & cost (fix before non-trusted users):**
5. **C-5:** Add `->catch()/->finally()` to the scene batch (`BuildLessonOutline.php:240-243`) + `failed()` handlers on scene jobs + a scheduled stuck-lesson sweep. *(hours)*
6. **C-6:** Add login + generate throttles, per-teacher daily quota, hard scene cap, and fix the `->retry(when: fn()=>true)` calls to retry only on 5xx/429. *(hours)*
7. **C-8:** Install Sentry; report from `withExceptions`; wire `slack` for `critical`. *(hours)*
8. **H-1 + M-5:** Queue-depth / last-processed healthcheck + AI-reachability probe surfaced on the teacher dashboard; make the SiteGround cron a hard release gate. *(hours)*
9. **H-8:** `spatie/laravel-backup` to off-box storage (or confirm + document Supabase PITR). *(hours)*

**Phase 2 — Accessibility (legal for schools):**
10. **H-2:** Wire the existing `audio_alignment` + `audio-player` word-highlight into the student player as a synchronized transcript/captions. *(hours)*
11. **H-4:** Add `prefers-reduced-motion` guards for grain + Ken Burns. *(minutes)*
12. **H-9 + L-6:** Make web quiz answers real interactive controls with labels (if answered on web); add `aria-label`s to icon buttons. *(hours)*

**Phase 3 — Content integrity & product completeness:**
13. **H-3:** Add a fact-grounding/verification pass surfaced in mandatory teacher review; lower temperature on the factual outline pass. *(days)*
14. **M-3:** Build classroom CRUD + rostering + lesson assignment (with C-3 consent baked in). *(days)*
15. **M-1, M-2, M-4, M-6, M-7, L-1, L-2:** Sanctum expiry; remove/implement the standards claim; service timeouts + Azure key off argv; gitignore sqlite; lock CORS; clean up dead config and the legacy pipeline.

---

*End of audit. Findings are evidence-backed against `main` as of 2026-06-24. The biggest gaps are not in the AI pipeline's cleverness — that part is solid — but in the safety, legal, failure-handling, and cost-control substrate that a product for children and teachers must have before it ships.*

# The Learning Portal — CLAUDE.md

## Project Overview
**thelearningportal.us** is an AI-powered K-12 EdTech platform that generates engaging,
gamified, story-driven lessons narrated by animated historical avatars. Teachers create
lessons in minutes; students watch, interact, and complete quizzes via a Flutter mobile app
or PWA.

The **History Portal** is the first subject vertical (history lessons with AI avatars of
historical figures like Julius Caesar). Future verticals: Science, Literature, Civics.

**Tagline:** "Where Storytelling Meets Learning. AI-Powered. Teacher-Centric. Results-Driven."

---

## Tech Stack

Laravel 12 + Livewire 3 + Alpine + Tailwind v4 + DaisyUI 5 — see `composer.json` / `package.json`
for versions. DaisyUI's active theme is `learningportal`. Postgres locally, MySQL on SiteGround.

### Frontend (student-facing)
- **Flutter** — separate codebase → iOS + Android + PWA; talks to Laravel over REST (Sanctum tokens).

### Local AI Services (development — all free)
All AI services run locally via `start-local.sh`. In production, swap URLs in `.env`.

| Service | Local URL | Production |
|---|---|---|
| LLM (story generation) | `http://localhost:11434` (Ollama) | Claude Haiku / GPT-4o mini API |
| TTS (narration audio) | `http://localhost:8880` (Kokoro TTS) | OpenAI TTS API |
| Avatar video | `http://localhost:7860` (SadTalker) | fal.ai / Replicate API |
| Image generation | `http://localhost:8188` (ComfyUI) | Optional |

---

## Architecture

**Generation entry point:** `Lesson::startGenerationPipeline()` — it requires a `LessonSource` row
first, then dispatches `BuildLessonOutline`, which chains the rest. Read `app/Jobs/` for the current
chain rather than trusting a diagram here; it has changed more than once.

**Lessons can also be authored as data**, with no AI involved: a spec in `resources/lessons/*.php`
built by `php artisan lessons:compose`. See `app/Services/LessonComposer.php`.

Models live in `app/Models/`; scene media is stored under
`storage/app/public/lessons/{lesson_id}/scenes/{scene_id}/`.

---

## Development Conventions

### PHP / Laravel
- PHP 8.2+, strict types everywhere: `declare(strict_types=1);`
- Use **Form Requests** for validation, never validate in controllers
- Use **Service classes** for external API calls (`app/Services/`)
- Use **Jobs** for async work (`app/Jobs/`)
- Use **Enums** for status fields (e.g. `LessonStatus::Pending`)
- Use **API Resources** for Flutter API responses
- Repository pattern NOT needed — use Eloquent directly
- Always use named routes

### Livewire
- One Livewire component per feature (not per page)
- Keep components in `app/Livewire/`
- Views in `resources/views/livewire/`
- Use `#[Validate]` attribute instead of `validate()` calls

### UI Components — priority order

1. **DaisyUI first** — always reach for a DaisyUI component before building anything custom.
   Use semantic class names: `btn`, `card`, `modal`, `badge`, `input`, `select`, `alert`,
   `navbar`, `tabs`, `progress`, `avatar`, `tooltip`, `dropdown`, `drawer`, etc.
   All components automatically inherit the `learningportal` theme (amber + deep navy + sky).
   Reference: https://daisyui.com/components/

2. **TALL stack fallback** — only build a custom component when DaisyUI has no equivalent.
   Use Livewire for reactivity, Alpine.js for local JS behaviour, Blade for markup.
   Always apply DaisyUI theme tokens (`--color-primary`, `--color-base-200`, etc.) or the
   brand utility classes (`.lp-grain`, `.lp-text-shimmer`, `.lp-bg-hero`, `.lp-bg-card`,
   `.lp-vignette`) so custom components stay visually consistent with the theme.

3. **Never** use raw Tailwind colour utilities (e.g. `bg-amber-500`, `text-slate-900`) for
   component chrome — use DaisyUI semantic classes or CSS variables instead so the theme
   remains the single source of truth.

### Blade
- Layouts in `resources/views/layouts/`
- Components in `resources/views/components/`
- No logic in blade — push to Livewire or controllers

### Database
- Always use migrations, never edit schema manually
- Use `->after()` in migrations for readability
- Soft deletes on Lesson, User
- All foreign keys must have `->constrained()->cascadeOnDelete()`

### Testing
- Feature tests for all API endpoints (Flutter integration)
- Unit tests for Service classes
- Run: `composer test`

---

## Environment Variables

See `.env.example` — it is the source of truth. Two that matter and aren't obvious:
`TTS_PROVIDER_OVERRIDE` forces one narration provider for the whole app (currently `azure`), and
`APP_USER_ROLE` decides which account `AutoLoginDev` signs in as locally.

---

## Local Development Setup

Standard Laravel install (`composer install`, `npm install`, `cp .env.example .env`,
`key:generate`, `migrate --seed`), then the two project-specific commands:

```bash
composer dev          # Laravel + queue worker + vite + log watcher (the queue worker matters)
./start-local.sh      # local AI services, in a separate terminal
```

Student-facing API routes live under `/api/v1/` with Sanctum tokens — see `routes/api.php`.

---

## Important Notes
- **No hallucination:** LLM is always given Wikipedia source text first. System prompt
  must include: "Only use facts from the provided source. If uncertain, omit — never invent."
- **Teacher review:** Lessons are not visible to students until teacher sets status to
  `published`. This is a feature, not a limitation.
- **Design:** Dark blue color scheme (`#0f172a` base). NOT red. Custom "History" font family.
  DaisyUI theme `learningportal` is the single source of truth for all colours and component
  styles. Full brand guidelines: `docs/brand-guidelines.md`.
- **Avatar quality:** For v1, pre-render MP4 videos. Do NOT attempt real-time avatar
  streaming in v1.
- **SiteGround deployment:** Uses MySQL in production. Set DB_CONNECTION=mysql in prod .env.
  SiteGround shared hosting has no queue worker support — use SiteGround cron + queue:work.

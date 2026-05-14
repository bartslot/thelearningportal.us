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

### Backend (this repo)
- **Laravel 12** — framework
- **Livewire 3** — reactive UI components (teacher dashboard)
- **Alpine.js** — lightweight JS interactivity
- **Tailwind CSS v4** — styling
- **DaisyUI 5** — component library (active theme: `learningportal`)
- **SQLite** (local dev) / **MySQL** (production on SiteGround)
- **Laravel Queues** — async lesson generation pipeline
- **Laravel Sanctum** — API auth for Flutter app

### Frontend (student-facing)
- **Flutter** — single codebase → iOS + Android + PWA
- Communicates with Laravel via REST API (Sanctum tokens)

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

### Lesson Generation Pipeline
```
Teacher submits form (topic, grade, tone, historical figure)
    ↓
GenerateLesson Job (queued)
    ↓
1. WikipediaService::fetchFacts($topic)         → raw facts string
2. LlmService::generateScript($facts, $params)  → dramatic script
3. TtsService::generateAudio($script, $voice)   → audio.mp3
4. AvatarService::generateVideo($audio, $image) → avatar.mp4
5. Lesson::update(['status' => 'ready', ...])
    ↓
Teacher notified → students can access lesson
```

### Key Models
- `User` — teacher or student (role column)
- `Lesson` — topic, script, status, grade_level, tone, historical_figure
- `LessonMedia` — audio_path, video_path, portrait_path
- `QuizQuestion` — question, options (JSON), correct_answer, lesson_id
- `StudentProgress` — student_id, lesson_id, score, completed_at
- `Classroom` — teacher_id, name, join_code
- `ClassroomStudent` — pivot: classroom_id, student_id

### File Storage
- `storage/app/lessons/{lesson_id}/audio.mp3`
- `storage/app/lessons/{lesson_id}/avatar.mp4`
- `storage/app/lessons/{lesson_id}/portrait.jpg`

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

## Environment Variables (add to .env)

```env
# Local AI Services
OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=llama3.1:8b
KOKORO_TTS_URL=http://localhost:8880
SADTALKER_URL=http://localhost:7860
COMFYUI_URL=http://localhost:8188

# Production AI APIs (used when local services are unavailable)
OPENAI_API_KEY=
OPENAI_TTS_MODEL=tts-1
OPENAI_TTS_VOICE=alloy
FAL_AI_KEY=
ANTHROPIC_API_KEY=

# App
LESSON_STORAGE_DISK=local
MAX_LESSON_GENERATION_ATTEMPTS=3
```

---

## Local Development Setup

```bash
# 1. Install PHP dependencies
composer install

# 2. Install JS dependencies
npm install

# 3. Set up environment
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed

# 4. Start everything
composer dev          # Laravel + queue + vite + log watcher
./start-local.sh      # Ollama + Kokoro TTS + SadTalker (run in separate terminal)
```

---

## Key API Endpoints (Flutter)

All routes under `/api/v1/`, authenticated with Sanctum tokens.

```
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
GET    /api/v1/student/lessons          → lessons assigned to student
GET    /api/v1/student/lessons/{id}     → lesson detail + media URLs
POST   /api/v1/student/lessons/{id}/progress  → save quiz answers
GET    /api/v1/student/progress         → overall progress/scores
```

---

## Milestones

### Milestone 1 — Foundation (current)
- [x] Laravel 12 setup
- [ ] Livewire + Sanctum installed
- [ ] Core migrations + models
- [ ] Lesson generation pipeline (GenerateLesson job)
- [ ] Basic teacher dashboard (Livewire)
- [ ] API endpoints for Flutter

### Milestone 2 — Avatar MVP
- [ ] SadTalker integration (local Ollama → fal.ai in prod)
- [ ] Julius Caesar as fixed avatar (one portrait, all lessons)
- [ ] Video player in Flutter app
- [ ] End-to-end: teacher creates lesson → student watches avatar

### Milestone 3 — Student App
- [ ] Flutter login + lesson list + video player + quiz
- [ ] PWA build deployed alongside Laravel

### Milestone 4 — Multiple Avatars + Subjects
- [ ] Avatar selection by historical figure
- [ ] Science Portal vertical
- [ ] School/classroom management

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

# Boss Test Checklist — features shipped ~July 8–14, 2026

Everything below landed in the last week. The coding agents ran their own smoke tests,
but none of it has had **your** eyes on it yet. Work top-to-bottom; each item has a link
and what you should see.

## Before you start

1. Run the app locally: `composer dev` (Laravel + queue + vite). AI services: `./start-local.sh`.
2. Base URL: **http://localhost:8000**
3. Log in as yourself: **bartslot@gmail.com** / `password`
   (or the demo teacher: **demo@thelearningportal.us** / `demo1234`).
4. **Dev test panel:** the floating 🧪 button, bottom-right on every page (local only).
   Use it to seed fake results and download fake answer-sheet photos — you'll need it below.
5. Two ready-made lessons to play as a student:
   - **FRREV9** — French Revolution demo → http://localhost:8000/lesson/FRREV9
   - **UD9KRN** — parallax/story-pack lesson → http://localhost:8000/lesson/UD9KRN
   For teacher pages that need a specific lesson, open the lesson from the dashboard first.

---

## 1. Lesson creation

**Agentic chat lesson creation (K-12)** — http://localhost:8000/teacher/lessons/chat
Type a learning goal in plain language. Expect: the bot parses it, offers chips to confirm
grade/topic/type, and builds a configured lesson in ~1 minute, then hands off to the wizard.

**Lesson-type presets (K-13)** — http://localhost:8000/teacher/lessons/create
Pick a type: Story / Spel-verhaal (game story) / Comprehension check / Deep dive. Expect the
preset to auto-fill framework, module order and defaults — you configure almost nothing. The
chat flow (above) uses the same presets.

**Etching / ink / engraved visual styles** — inside the wizard/composer image step.
Expect the identity image to render in the chosen style, full-screen sharp, rule-of-thirds
composition, no gutter artifacts.

---

## 2. Game-story ("spel-verhaal") — the big new epic

**Author it** — open a lesson → wizard Step 2 has the spel-verhaal toggle:
http://localhost:8000/teacher/lessons/{lesson}/wizard
Expect: turning it on makes the outline generate meters, roles and 3 reconverging choice
points; Step 3 gives you game-effects + meters authoring panels with a publish-completeness guard.

**Play it** — open the lesson as a student (http://localhost:8000/lesson/{code}).
Expect: player choice overlay, a live meter HUD, choices that change consequences, a
game-over screen and an end-of-run summary.

**Printed game pack (PDF)** — http://localhost:8000/teacher/lessons/{lesson}/print/game-pack
Expect a printable pack: role cards, tokens, event cards, and a meter poster.

---

## 3. Results & assessment (biggest cluster of the week)

Open a lesson's report, then use the 🧪 panel → **Seed 12 results** first so there's data.

**Results hub** — http://localhost:8000/teacher/results
Expect per-lesson, per-day activity rows.

**Lesson report** — http://localhost:8000/teacher/lessons/{lesson}/results
Expect Overview / Questions / Players tabs, difficult-question drilldown, and a CSV export.

**Printable answer sheet** — http://localhost:8000/teacher/lessons/{lesson}/results/answer-sheet
Expect a print-friendly bubble sheet with digibord options.

**Paper answer import (AI vision)** — from the report page, "Import paper answers".
In the 🧪 panel click **Download test answer sheets** to get a fake photo (Emma = all correct,
Liam = mixed). Upload it. Expect: vision extracts the answers, fuzzy-matches the names to your
roster, and shows an editable review grid before import.

**Re-quiz difficult questions + answer shuffle** — as a student, after finishing a quiz.
Expect the option to re-quiz just the hard questions, with answers shuffled (3-level).

**Class join + player submission** — students join with a class code and their answers are
recorded against the lesson. Feeds the report/hub above.

---

## 4. Classes

**Class management** — http://localhost:8000/teacher/classes
Expect: create a class, manage the roster, and assign lessons. Manage one at
http://localhost:8000/teacher/classes/{classroom} — it has a join code for students, and teams
auto-form from the roster.

---

## 5. Modules & handouts

**Prior-knowledge module (K-4) + Conclusion module (K-6)** — in the composer:
http://localhost:8000/teacher/lessons/{lesson}/composer
Expect both modules available to add/reorder, and they render in the player (a
"what do you already know?" opener and a closing conclusion).

**Printable lesson handout (PDF)** — http://localhost:8000/teacher/lessons/{lesson}/print/handout
Expect a clean dompdf handout of the lesson.

---

## 6. Voices & narration

**Language-aware narration (Dutch)** — create/play a Dutch lesson.
Expect a native Dutch narrator (not an English voice reading Dutch), correct SSML language,
and the pronunciation lexicon fix (says "liemes", not English "limes").

**Avatar Studio — voice catalog (admin)** — http://localhost:8000/admin/avatars → pick an avatar →
studio. Expect: European/edge-tts voice catalog, Dutch voices, a sortable table with language
filters, per-language preferred voices, accent options, and instant voice samples. (Admin login required.)

---

## 7. Story asset packs + parallax player (E3b)

**Parallax player** — http://localhost:8000/lesson/UD9KRN
Expect a 2-layer parallax scene: background + transparent hero panning at ~0.6× with Ken Burns
drift and subtle hero "breathing". This lesson was built with **zero** image-generation calls.

**Story pack review (admin)** — http://localhost:8000/admin/stories
Expect the pack review UI: ~6 backgrounds + hero poses (transparent) generated once per story.

---

## 8. Behind-the-scenes (harder to see in the UI)

- **Low-AI-credit email alert + branded email layout** — fires when credits run low; check
  your mail log / Mailtrap rather than a page.
- **TTS provider override (ElevenLabs → Azure backup)** — a global config switch for when the
  primary TTS provider is down; verify narration still generates with the override on.

---

*Note: `{lesson}` and `{classroom}` are IDs — reach those pages by clicking through the
dashboard/classes list rather than guessing numbers.*

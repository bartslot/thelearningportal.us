# Teacher Results & Analytics — Design

**Date:** 2026-07-08 · **Status:** approved in brainstorming (visual companion session)
**Problem:** Teachers have no way to check quiz results or analyse student progress. The
platform collects scores (and integrity telemetry) but exposes nothing beyond the student
leaderboard. Reference models researched: Kahoot reports (difficult questions <35%, needs-help
list, per-player drill-down, CSV) and LessonUp (class-anchored progress).

## Decisions made with the founder

1. **Identity: hybrid (C).** Anonymous nicknames stay the default. When a lesson is assigned
   to a classroom, students attach to a persistent, account-less `classroom_member` via class
   join code + name. Progress-over-time unlocks only for classroom-linked runs.
2. **v1 scope: A + B** (per-lesson report, per-student drill-down) then **C + D**
   (classes overview, CSV — CSV per-lesson ships in v1, classes page is fast-follow).
3. **Navigation: both entry points, one report page.** 📊 Results button on lesson cards AND
   a "Results" navbar hub listing recent activity — both land on the same lesson report.
4. **No-device classrooms: photo-graded paper sheets** with an AI-extraction review grid
   (manual quick-entry is the degenerate case of the same grid). Plickers-style camera
   cards explicitly deferred.
5. **Overview tab is the teacher's landing view.** Re-quiz button makes v1, with an
   answer-shuffle option.

## Data model

### New: `quiz_answers`
One row per answered question, submitted with the score (web) or imported (paper).
Snapshot columns because quiz questions are deleted/recreated on regeneration — reports
must stay readable after the source questions are gone.

| column | type | notes |
|---|---|---|
| quiz_score_id | FK cascadeOnDelete | parent run |
| quiz_question_id | FK nullOnDelete | best-effort link while the question exists |
| question_order | int | position in the segment at answer time |
| question_text | text | snapshot |
| chosen_text | text | snapshot of the picked option |
| correct_text | text | snapshot |
| was_correct | bool | |
| response_ms | int nullable | null for paper |
| asks_ahead | bool | copied from the question; excluded from needs-help math |

### New: `classroom_members`
Account-less roster entry: `classroom_id` FK, `display_name` (convention "Emma V." —
first name + last-name initial; unique per classroom on normalized name), timestamps.
Deliberately NOT `users` — no school account provisioning.
Existing `classroom_students`/`student_progress` (Flutter, real accounts) stay untouched.

### Extended: `quiz_scores`
+ `classroom_member_id` FK nullable (null = anonymous), + `source` string default `web`
(`web` | `paper`).

### Player submit payload (extends existing POST /lesson/{code}/quiz-score)
+ `answers[]` (question snapshots incl. response_ms), + optional `class_code` + `member_name`
(both remembered in localStorage; join step shown only when the lesson has an assigned
classroom). Invalid class code → error, score still storable anonymously on retry/skip.

## Pages

### Lesson report — `/teacher/lessons/{lesson}/results` (Livewire `Teacher\LessonReport`)
Auth + teacher-owns-lesson. Header: class filter, date-range filter; actions:
🖨 Print answer sheets · 📷 Import paper answers · ⬇ CSV (streamed).

- **Overview tab (landing):** stat tiles (players, avg correct %, needs-help count);
  teacher leaderboard with integrity chips (⚡ rapid guesses, 👀 focus drops, 🔁 same-letter
  streak, 📄 paper run); difficult-questions panel (all questions <50% correct, ranked,
  red <35% / amber <50%) with **Re-quiz** button.
- **Questions tab:** every question ranked by % correct; expand → answer distribution
  (count per option) + list of students who missed it. ⤳ asks-ahead questions shown but
  excluded from needs-help calculations.
- **Players tab:** roster (classroom members + anonymous nicknames), per-player: score,
  correct/total, integrity summary; click → per-question drill-down (their answer vs
  correct, response time).

**Needs-help rule (Kahoot-inspired):** correct-rate < 50% on non-asks-ahead questions,
computed per player per lesson within the active filters.

### Results hub — `/teacher/results` (Livewire `Teacher\ResultsHub`)
Recent-activity rows across the teacher's lessons, grouped per lesson per calendar day
(players, avg %, needs-help count), with lesson/class/date filters (class options = the
lesson's assigned classrooms via `classroom_lessons`) → click through to the report.
Navbar gains "Results"; teacher dashboard lesson cards gain the 📊 Results button.

### Re-quiz
From the difficult-questions panel: appends a NEW quiz scene at the end of the lesson,
seeded with the selected difficult questions ("reinforce these" prompt variant through the
existing `GenerateLessonQuiz` validation path). The original segment and its results are
never touched. **Shuffle option** (per quiz scene config):
`off` | `once` (one new order for the whole class — digibord/paper-safe) | `per_player`
(digital runtime shuffle, already implemented; disabled with explanation when printing
sheets, since a shared screen/paper cannot vary per student).

## Paper flow (no-device classrooms)

1. **Print:** `GET .../results/answer-sheet` — print-CSS blade (no PDF dependency):
   lesson title, class line, name field, per question the question text + ⓐⓑⓒⓓ bubbles
   (options projected on the digibord), footer with lesson code + layout version marker.
2. **Import:** photo upload (multiple sheets per photo OK) → queued `ExtractPaperAnswers`
   job → vision model (existing OpenAI vision pattern, strict JSON: name + choices per
   sheet, `null` for unreadable) → **review grid**: one row per sheet, green = roster
   match (Levenshtein ≤ 2 on normalized name; "Emma Visser"/"emma v" both match "Emma V."
   — normalization strips to first name + initial), amber = fix name / unreadable answer;
   every cell editable (manual entry = same grid) → Confirm → bulk insert `quiz_scores`
   (`source: paper`, no integrity telemetry) + `quiz_answers` (`response_ms` null).
3. Paper runs appear everywhere with the 📄 marker; downstream analytics identical.

## Privacy & access

- All report routes: `auth` + lesson ownership (`teacher_id`). Admins are not special-cased in v1.
- Integrity chips remain teacher-eyes only (existing leaderboard endpoint behavior unchanged).
- Class join codes are the existing `classrooms.join_code`. Member naming convention:
  **first name + first letter of last name** ("Emma V.") — disambiguates duplicate first
  names (common in a class) while staying AVG/GDPR-friendly. The join form and the paper
  sheet's name line both show this format as the placeholder/instruction; never full surnames.

## Explicit v1 cuts

Classes overview page (cross-lesson progress charts) — fast-follow once classroom linkage
has data. CSV on the hub. Plickers-style camera scanning. Live teacher-paced quiz mode.
Flutter `student_progress` merge into these reports.

## Testing

- Feature: report routes (ownership 403s, tab data correctness incl. asks-ahead exclusion,
  needs-help math, filters), CSV shape, quiz-score submit with `answers[]` + class code
  attach (valid/invalid/absent), paper import end-to-end with mocked vision JSON, review-grid
  confirm bulk insert, re-quiz generation seeding.
- Unit: fuzzy name matcher, difficult-question ranking, shuffle-mode setting guard.
- Manual: print sheet in Chrome/Safari print dialog; photo import with a real phone photo
  of 3 filled sheets (happy path + one unreadable).

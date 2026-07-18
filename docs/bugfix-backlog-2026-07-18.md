# Bugfix / UX backlog — captured 2026-07-18

Observations from a teacher test pass (French Revolution lesson). Grouped, deduplicated,
and annotated with code pointers for the "big bugfix session later". Not started yet.

Severity: 🔴 blocker · 🟠 high · 🟡 medium · 🟢 small/polish · ✨ new feature

---

## Lesson creation

- 🔴 **Creating a new branching lesson + `lesson_game` errors.** A truncated-JSON outline
  crash was fixed (commit `9145e04`: `OpenAiLlmService` per-call `maxTokens`, `BuildLessonOutline`
  → 16k, truncation repair). **Re-test story_game specifically** — if it still errors, capture the
  new stack (likely `LessonOutlinePrompt` / branch-scene creation in `BuildLessonOutline`, or
  `EditsStoryGame`).
- ✨ **Import a Word document** to seed a lesson … then **split it into chapters.** New ingest path
  (docx → source text → outline/scenes). Chapters already exist (`ChapterNamer`, `scenes.chapter_name`).
- ✨ **Upload images for the background** (much needed). Today background = AI-gen / Paintings / Drawing;
  add a plain upload → store on `public` disk → `image_path`.

## Script / narration

- 🟡 **Narration could ask questions** e.g. "What is the third estate?" (rhetorical, class-facing).
  Prompt tuning in `SceneScriptPrompt` / `LessonScriptPrompt`.
- 🟡 **Editing text: selecting text should …** (note trailing off — clarify intent). Likely: a
  selection toolbar (bold-free) or the paragraph Regenerate/Summarize toolbar we added should also
  act on a selection. Confirm with teacher.
- 🟡 **Play after an edit regenerates the audio** — this is the intended dirty→re-narrate flow
  (`onPlay`→`renarrate`), but see next item (too slow).
- 🟠 **Re-narrating audio takes forever.** Offer a fast local TTS for previews: browser
  `SpeechSynthesis` (instant, no server) or on-machine (edge-tts / Piper already wired in
  `TtsService`). Keep OpenAI/Azure for the final render. Consider a "preview voice" toggle.

## Canvas · objects · format

- 🟠 **Color doesn't update live on canvas** — only after leaving the color picker / re-selecting.
  The picker should push the value to the overlay on `input`, not just on change/blur.
- 🟢 **Object list: delete (×) instead of the settings/adjust icon** — swap the per-row gear for a
  delete action (`resources/views/livewire/wizard/step3-scene-configurator.blade.php`, `objectList`
  rows `[data-obj-adjust]`).
- 🟡 **Alignment toolbar overlaps the script panel** (z-index / stacking when the script panel sits
  in front). Reserve space or raise the overlay toolbar above the docked script panel.

## Scenes

- 🟢 **Rename "Add Scene" → "Add".** (`timeline.blade` add button + `[data-rail-label]`.)
- 🟡 **Scene type should be changeable from the Inspector top** (narration ⇄ quiz ⇄ game) instead of
  only at add-time. Today `addScene(kind, gameType)` fixes it; add a type switcher in the inspector.

## Quiz

- 🟠 **Don't lock the correct answer.** Currently the correct answer can't be redrawn (only distractors)
  — teachers must be able to edit both questions and answers freely.
  (`EditsQuizQuestions`; see `test_correct_answer_cannot_be_redrawn_but_distractors_can` — that rule
  needs relaxing.)
- 🟠 **Changing the question count at the top doesn't add question slots** — only "Add question" does.
  Wire the count control to add/remove draft slots (`quiz_question_count` ↔ `quizDraft`).
- ✨ **Auto-generate distractors** when a new question is typed and the teacher focuses the correct-
  answer field (LLM, like the existing `generateQuizQuestion`/`regenerateQuizOption`).

## Debate game

- ✨ **Auto-generate debatable topics** (e.g. "Kill the king or not?"). New generator for
  `game_type=debate` (parallels quiz/strategy generation).

## Map

- 🟠 **Map doesn't update the year set in the Inspector** (`lesson-map.js` / timemap; the year change
  isn't re-driving the borders/era).
- 🟡 **Blank strip / marker at the cursor** on the map.
- 🟠 **Cities render at lat 0 / lng 0** (missing/zeroed coordinates in the city data or the payload).
- 🟠 **Map constantly refreshes; no information present.** Likely the 3s status poll re-mounting the
  MapLibre preview (see the wizard poll + `#lesson-map-preview`); needs a mount guard like the canvas.

## Clipart

- ✨ **More France clipart** (asset library expansion; SVG/ink corpus).
- 🟠 **Added clipart shouldn't need a refresh and shouldn't go "into the library"** — selecting a
  clipart should insert it straight onto the canvas as a layer, same as the Paintings tab's
  direct-insert. (Align clipart insert with the paintings apply flow.)

## Strategy game

- 🟡 **Games aren't self-explanatory** — unclear what you're selecting (e.g. "Napoleon at Waterloo").
  Add titles + short descriptions/thumbnails in the strategy-game picker so the teacher understands
  each option.

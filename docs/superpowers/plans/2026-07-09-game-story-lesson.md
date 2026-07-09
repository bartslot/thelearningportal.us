# Game Story Lesson (Spel-verhaal) — Implementation Plan

> Executes `docs/superpowers/specs/2026-07-09-game-story-lesson-design.md`.
> **Reality check that reshaped this plan:** branching-lite was never wired — `scenes.branch_group/branch_role/branch_choice_label`
> columns, the `Branching` enum value and spine beats exist, but there is NO branch generation, NO player
> choice overlay, and NO choice persistence. Segment 3 therefore builds the branch player mechanics from
> scratch (spec assumed they existed).

**Goal:** branching story lesson where choices move class meters (Reigns mechanic), an AI-pregenerated
game master narrates consequences, meters at 0 = game over (restartable), plus a printed PDF game pack.

**Key existing pieces (verified):**
- `scenes` branch columns exist and are fillable/cast on `Scene`
- `StorySpine::for(NarrativeFramework)` — beats only, `Branching => Setup/Approach/Choice/Rejoin/End`
- Player: linear `_sceneIndex` + `_playScene(index)` / `_advanceScene(index)` (resources/js/lesson-player.js:820,960)
- Payload: `resources/views/lesson/player.blade.php:51-76` scene map (no branch fields yet)
- Progress: `POST /api/v1/student/lessons/{lesson}/progress` — `answers` JSON free-form (StudentLessonController:109+)
- `LessonTeam` exists; `lessons.game_type` string ('quiz'|'strategy'|'debate'), set in Step2Story

## Segments (one commit each)

### 1. Foundation (schema + spine + payload + Step2 toggle)
- Migration `add_game_config_to_lessons_table`: `game_config` json nullable
- `Lesson`: fillable + `'game_config' => 'array'` cast
- `StorySpine::forStoryGame(): self` → beats `Setup → Keuze 1 → Gevolg → Keuze 2 → Gevolg → Keuze 3 → Climax → Slot`
- Player payload: lesson `game_config`; per-scene `branch_group`, `branch_role`, `branch_choice_label`
- Step2Story: when framework=branching, sub-toggle "Spel-verhaal" sets `game_type='story_game'`
  (+ `game_config['print_pack']` checkbox); tests for all

### 2. Generation (outline prompt + persistence)
- `LessonOutlinePrompt`: when `game_type=story_game` request additional JSON:
  `meters[]` (3-4, topic-fitting Dutch labels, start 40-80), per-brief branch markers
  (`branch_group` 1..3, `branch_role` question|option_a|option_b, `choice_label`),
  per-option `branch_effects {deltas (±25 max), consequence_line, historical_note}`, `roles[]` (5, per-TEAM)
  Hard rule: options differ in approach, never outcome — history stays true.
- `BuildLessonOutline`: persist meters+roles+print_pack → `lesson.game_config`;
  branch fields → scene columns; `branch_effects` → `scene.config`
- Tests: fake LLM outline → assert scene rows + game_config persisted; clamp deltas

### 3. Player (branch mechanics + meters — THE new machinery)
- Choice overlay when entering a scene whose `branch_role='question'` (or first option of an unchosen
  group when no question scene): two labeled buttons from the group's option scenes
- Chosen option plays; sibling skipped; reconverge at first scene after the group
- Meter HUD from `game_config.meters` (top bar, animates deltas, floating ±N)
- After option scene: `consequence_line` shown as game-master caption (parchment styling)
- Meter ≤0: game-over interstitial w/ `historical_note`, restart-from-act, teacher override-continue
- End: run summary; progress POST `answers.game = {choices, meters_final, survived, restarts}`
- Solo + classroom identical v1 (team-turn banner = Phase 3)

### 4. Authoring (Step3 panels)
- Branch-option scene inspector: "Game effects" panel (4 delta steppers ±25, consequence line, historical note)
- Lesson panel: meters editor (label/icon/start), only when story_game
- Publish validation: every branch group complete (both options' effects present)

### 5. Print pack + lesson PDFs (dompdf)
- `composer require barryvdh/laravel-dompdf`
- `GameCardPdfService` + `resources/views/pdf/game-pack/*.blade.php`: team role cards (A5),
  manschappen tokens, event cards, meter poster, team badges — per spec §6 (roles are per TEAM)
- `GenerateGamePack` job (batch-appended when `print_pack`), `game_pack_path` on lesson
- ALSO (separate commit): teacher lesson handout PDF (summary, objectives, script excerpts, quiz answer key)
  downloadable from lesson pages — distinct deliverable sharing the dompdf foundation

## Testing
Pest/PHPUnit per segment; full-suite baseline: 7 pre-existing errors + 11 failures — only new failures matter.
`npm run build` after JS. Manual: generate a Napoleon story_game lesson end-to-end.

## Out of scope (v1)
Live LLM judging, student-device voting, paper-scan of game answers, story divergence (never), campaign mode.

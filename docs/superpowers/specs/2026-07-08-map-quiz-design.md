# Map Quiz Questions — Design

**Date:** 2026-07-08
**Status:** Approved direction (recommendations accepted); spec pending final user review
**Context:** "Testing with Alfonso" TODO. First of two features (second — open-ended
answers incl. handwriting — gets its own spec later).

---

## 1. Goal

Students answer geography/history questions **on the interactive world map** inside the
lesson player: "Which country is Cuba?" → tap the territory (or pick a numbered marker).
Teachers author these questions by clicking territories on a mini-map, with per-block
control over historical vs. modern territory naming and a modern-borders overlay switch.

## 2. Decisions (locked during brainstorm)

| Topic | Decision |
|---|---|
| Placement | Own scene block: `kind=game`, **`game_type='map_quiz'`** (new enum value) |
| Archetypes | ① `map_locate` ② `map_identify` ③ `map_actor` (enemy/partner) |
| Answer mode | Per question: `click` (tap territory) or `numbered` (①–④ markers + panel buttons) |
| Naming mode | Per scene: `hist` \| `hist_modern` \| `modern` |
| Modern borders | Per-scene teacher setting *allows* the switch; student flips it freely in player |
| Authoring | Pick-on-map (QID from tiles = ground truth) + AI assist for question text; distractor suggestions come from tile data, never from the LLM |
| Data model | Extend `quiz_questions` (`type` + `map_payload`), reuse options/correct_index contract |
| Scoring | Unchanged: 10 pts + streak bonus (cap 15), `QuizAnswer` snapshots, leaderboard, integrity flags |
| Paper handout | **v2** — numbered mode is designed paper-compatible (①–④ ↔ index 0–3), but print/vision-import ships later |

**Verified feasibility:** Cliopatria tiles (`public/cliopatria-tiles/`) carry `Name`,
`Wikidata` (QID), `FromYear`, `ToYear` per polity, with data reaching **ToYear 2024**.
Modern borders = the same tileset filtered to year 2024; no new data source.

## 3. Data model

### 3.1 `quiz_questions` — two new columns

```
type         string, default 'mc'        -- 'mc' | 'map_locate' | 'map_identify' | 'map_actor'
map_payload  json, nullable              -- null for 'mc'
```

`map_payload` shape:

```json
{
  "answer_mode": "click" | "numbered",
  "target":      { "qid": "Q241", "label_hist": "Cuba", "label_modern": "Cuba" },
  "distractors": [ { "qid": "...", "label_hist": "...", "label_modern": "..." } ],
  "relation":    "enemy" | "partner" | null
}
```

- `distractors`: exactly 3 for `numbered` and `map_identify`; empty for `click` locate/actor.
- `relation`: only for `map_actor`; rendered into question context, not used for grading.
- Labels are **captured at authoring time** from tile properties (hist = scene year,
  modern = same centroid queried at year 2024), and teacher-editable. No runtime
  Wikidata/LLM lookups in the player.

### 3.2 `options` / `correct_index` stay the answer contract

On autosave, `options` are regenerated from `map_payload` labels using the scene's
`naming_mode`:

- `hist` → `label_hist`
- `modern` → `label_modern`
- `hist_modern` → `label_hist (now: label_modern)` — the "now:" connector is a
  translation key (Dutch pilot: "nu:").

Consequences:
- `QuizAnswer` snapshots (`question_text`, `chosen_text`, `correct_text`, `was_correct`)
  work unchanged → results hub, difficult-questions, re-quiz, CSV export, leaderboard
  all apply to map questions **with zero pipeline changes**.
- `click` mode: correctness = clicked feature's QID `=== target.qid`; snapshot
  `chosen_text` = clicked territory's name resolved via naming mode. `options` holds
  only the correct label (index 0) so results rendering has a `correct_text`.
- Changing `naming_mode` re-triggers option regeneration on next autosave.

### 3.3 Scene config (`game_type='map_quiz'`)

```json
{
  "year": 1820,
  "center": [lng, lat], "zoom": 3,
  "naming_mode": "hist" | "hist_modern" | "modern",
  "modern_borders_switch": true | false,
  "quiz_shuffle": "off" | "once" | "per_player"   // reuse existing semantics
}
```

`quiz_shuffle` applies to numbered-marker assignment and option order; `click` mode
questions are unaffected by shuffle.

## 4. Player UI & flow

### 4.1 Layout

```
DESKTOP (≥ lg)                              MOBILE
┌─────────────────────────┬──────────┐      ┌──────────────┐
│                         │ Q 2/6    │      │   MAP (~55%) │
│   FULLSCREEN MAPLIBRE   │──────────│      │  [borders ⊙] │
│   (existing             │ Which    │      ├──────────────┤
│    #lesson-map-stage)   │ country  │      │ Q 2/6        │
│                         │ is Cuba? │      │ Which country│
│  [🗺 modern borders ⊙─] │          │      │ is Cuba?     │
│   (only if allowed)     │ ① ② ③ ④ │      │  ① ② ③ ④    │
│                         │ (numbered│      └──────────────┘
│                         │  mode)   │      panel below map,
└─────────────────────────┴──────────┘      map stays visible
       right ⅓ overlay panel (card layered above map, DaisyUI card)
```

- Reuses the existing map scene stage (`#lesson-map-stage`, `renderLessonMap`
  machinery in `resources/js/lesson-map.js`) extended with quiz interaction; question
  panel is a DaisyUI card overlay, consistent with the existing quiz card on
  `#lesson-game-overlay`.
- Timer / points / streak HUD: same components as text quiz.
- Map is pan/zoomable during questions (small territories like Cuba need zoom).

### 4.2 Interaction per archetype

| Archetype | Map shows | Student answers by |
|---|---|---|
| `map_locate` + `click` | plain era map | tapping the territory polygon (hover/tap highlight via existing feature-state) |
| `map_locate` + `numbered` | ①–④ markers on the 4 territories' centroids | tapping ①–④ button in panel (or marker itself) |
| `map_identify` | target territory highlighted/pulsing | picking among 4 text options in panel |
| `map_actor` | context territory (e.g. Rome) highlighted; question names the relation | same as locate: click or numbered per `answer_mode` |

Feedback (both modes): correct → territory flashes success color; wrong → chosen flashes
error color, then correct territory highlights with its resolved name. Then next question.
All colors via DaisyUI theme tokens / existing feature-state styling — no raw Tailwind colors.

- `click` mode taps on ocean / non-polity features are ignored (no answer consumed).
- Numbered markers that would overlap at current zoom get simple pixel offsets with
  leader lines (v1: naive offset is acceptable).

### 4.3 Modern borders switch

- Toggle control on the map (top-left, DaisyUI `toggle` + label), rendered only when
  `scene.config.modern_borders_switch === true`.
- ON → adds one extra line layer: same Cliopatria source filtered to year 2024,
  dashed, subtle color, above era fills. No fills, no labels — orientation aid only.

### 4.4 Submission

Same `POST /lesson/{lessonCode}/quiz-score` snapshot flow as text quiz. Per-answer
snapshot gains nothing new — `question_text` / `chosen_text` / `correct_text` /
`was_correct` / `response_ms` as today. `QuizQuestion.type` is available for future
per-type analytics but v1 results screens need no change.

## 5. Teacher authoring (wizard / scene configurator)

- "Map quiz" appears in the add-scene picker alongside quiz/debate/strategy.
- Scene settings panel: year (defaults from lesson era), start view (mini-map with
  "use current view" button), naming mode (3-option radio), modern-borders switch
  (toggle), shuffle (existing control).
- Question rows extend the existing `EditsQuizQuestions` pattern (max 12/scene,
  autosave-on-complete, per-question validation):

```
┌─ Question 3 ────────────────────────────────────────────┐
│ type: [Locate ▾]   answer: [Click map ▾ / Numbered]      │
│ text: "Which country is Cuba?"        [✨ AI draft]      │
│ ┌──────────── mini-map (scene year) ────────────┐        │
│ │  target: click territory → Cuba (Q241) ✓      │        │
│ │  distractors (numbered): Haiti ✓ Jamaica ✓ +1 │        │
│ └───────────────────────────────────────────────┘        │
│ labels: hist [Cuba          ] modern [Cuba        ]      │
│ relation (actor only): [enemy ▾]                         │
└──────────────────────────────────────────────────────────┘
```

- **Target/distractor selection = clicks on the mini-map.** QID + `label_hist` read
  from the clicked feature's tile properties; `label_modern` from querying the same
  centroid with the year-2024 filter. Both labels editable text inputs.
- **AI assist scope (hallucination guard):** the LLM only drafts question `text`
  (given target label, archetype, relation, lesson context — reuse the
  `QuizQuestionDraftPrompt` pattern) and may *rank* distractor candidates. Candidates
  themselves come from a tile query (polities alive at scene year within/near the
  viewport). The LLM never invents QIDs or labels.
- Validation (complete question = eligible for autosave): question text, target QID,
  non-empty labels for the active naming mode, and for `numbered`/`map_identify`
  exactly 3 distractors. Incomplete rows held unsaved, same as text quiz today.
- Changing scene `year` after authoring re-validates every question's target and
  distractors against tile lifespans (`FromYear ≤ year ≤ ToYear`); stale ones get a
  warning badge and drop out of autosave until fixed.

## 6. Error handling

| Failure | Behavior |
|---|---|
| Map/tiles fail to load in player | Question panel shows error state with Retry; teacher-visible note. Map-quiz scene can be skipped via existing continue control so a class is never hard-blocked. No silent fallback to text MC in v1 (click-mode questions have no distractor labels to fall back on). |
| Clicked feature has no `Wikidata` QID | Treated as non-answer (same as ocean tap). |
| Territory absent at scene year (data drift after re-tiling) | Authoring re-validation catches it (warning badge); player-side guard: if target QID matches no feature at load, scene logs a server-side warning and behaves as tile failure above. |
| AI draft fails/times out | Same UX as existing quiz AI draft failure — inline error, manual entry still works. |

## 7. Testing

- **Unit (PHP):** label→options regeneration per naming mode (incl. "now:"
  translation), map_payload validation rules, correctness check for click (QID
  compare) and numbered (index compare), year-lifespan re-validation.
- **Feature (PHP):** autosave persists/holds map questions per completeness rules;
  submit endpoint accepts map-question snapshots and scores them; results hub /
  difficult questions / re-quiz / CSV include map questions; `mc` regression
  untouched (existing tests keep passing).
- **JS/player:** covered via the project's UI verification flow (test-ui skill /
  e2e pass): click-answer, numbered-answer, borders toggle, mobile layout stack.
- Coverage target per repo standard (80%) on new PHP code.

## 8. Out of scope (this spec)

- Open-ended / handwriting answers → separate spec (feature B).
- Printable map handout + paper vision import for map questions → v2 (design here is
  deliberately paper-compatible via numbered mode).
- Mixing map questions inside regular text-quiz scenes → revisit after Alfonso test.
- New analytics visualizations per question type.

## 9. Build shape (preview for planning)

1. Migration + model (`type`, `map_payload`, `game_type` enum) + options-regeneration service.
2. Authoring: scene picker entry, scene settings, question rows + mini-map picker, AI draft.
3. Player: map-quiz stage (extend lesson-map.js), 3 archetypes × 2 answer modes, borders toggle, feedback, submission.
4. Tests + test-ui verification pass.

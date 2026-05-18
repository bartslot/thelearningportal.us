# Lesson Creation Rebuild — Design Spec

**Date:** 2026-05-16
**Status:** Draft — pending user review
**Author:** Bart Slot + Claude
**Supersedes:** `app/Livewire/CreateLesson.php`, `app/Livewire/LessonSettings.php`, `app/Livewire/LessonShow.php`

---

## 1. Goal

Replace the single-form `CreateLesson` flow with a 4-step wizard that lets a teacher:

1. **Settings** — describe the lesson and configure the strategy game.
2. **Generate** — run a multi-call OpenAI pipeline that produces an outline, per-scene scripts, per-scene images, per-scene narration audio, and a quiz.
3. **Configure (Scene)** — review and edit the result in a fullscreen 3D scene configurator with a video-editor-style timeline. The existing Avatar Lab three.js engine becomes the underlying renderer.
4. **Preview** — play the full lesson end-to-end in the same fullscreen viewport, then publish.

The new flow folds the previously separate `CreateLesson`, `LessonSettings`, and `LessonShow` UIs into one continuous wizard backed by a single `Lesson` draft row.

## 2. Pipeline overview

```
Settings (Step 1)
  ├─ lesson basics  (topic, subject, grade, tone, details)
  ├─ source         (PDF / DOCX / Wikipedia / both)
  ├─ style          (1 of 6, grade-band recommended)
  ├─ avatar         (1 of N)
  ├─ strategy game  (catalog pick + team_count + game_split_count)
  ├─ meta           (lesson_code, duration, portrait)
  │
  └─→ saves Lesson(status=Draft) + LessonSource

Generate (Step 2)
  ├─ BuildLessonOutline             OpenAI JSON mode, persists Scene rows
  ├─ per scene (Bus::batch):
  │    ├─ GenerateSceneScript       OpenAI text
  │    ├─ GenerateSceneImage        DALL·E 3 (skybox sphere image)
  │    └─ GenerateSceneAudio        ElevenLabs (avatar voice)
  └─ GenerateQuiz                   OpenAI JSON mode
        status: Outlining → ScenesGenerating → ScenesReady

Configure (Step 3)        fullscreen three.js viewport + bottom timeline + collapsible inspector
  ├─ select scene, edit fields, per-asset regenerate
  └─ status: Configuring

Preview (Step 4)          same viewport, read-only timeline, full playback
  └─ Publish → status: Published
```

## 3. Architecture

### 3.1 Components

```
LessonWizard                          parent Livewire
├─ owns Lesson $lesson and int $step
├─ children:
│   ├─ Step1Settings                  full-page form
│   ├─ Step2Generate                  live progress (wire:poll.2s)
│   ├─ Step3SceneConfigurator         fullscreen + timeline + inspector
│   └─ Step4Preview                   fullscreen + timeline + Publish
└─ routes:
   /teacher/lessons/create                          → Step 1, no lesson yet
   /teacher/lessons/{lesson}/wizard?step=1..4       → resume

Shared blade:
  <x-lesson.timeline :scenes :editable>            used in Step 3 (editable) and Step 4 (read-only)
  <x-lesson.scene-thumb :scene>                    skybox or game thumb
```

### 3.2 Three.js modules (`resources/js/`)

Existing `avatar-3d.js` stays as the low-level renderer (avatar GLB loader, animation mixer, lipsync, scene/background). New modules layer on top:

```
scene/SkyboxSphere.js          inverted SphereGeometry(500, 60, 40), two instances for crossfade
scene/SceneOverlay.js          DOM overlay — year badge + location pin (white SVG icon)
scene/SceneTimelinePlayer.js   sequences scenes (play, pause, seek, scenechange, gamestart, gameend)
scene/GameTimerOverlay.js      countdown overlay during game scenes
scene/AmplitudeWaveform.js     renders scene.audio_alignment as bars in the timeline strip
```

The renderer is a singleton across Step 3 and Step 4 — the same fullscreen `<canvas>` persists when switching steps (Alpine store keeps the reference).

### 3.3 Backend services

```
App\Services\OpenAiLlmService          replaces local Ollama usage. JSON mode + text mode.
App\Services\OpenAiImageService        DALL·E 3, 1792×1024, style template injection.
App\Services\DocumentExtractor         PDF (smalot/pdfparser) + DOCX (phpoffice/phpword) → text.
App\Services\WikipediaService          existing, unchanged.
```

Config: `config/services.openai.php` with `model` (default `gpt-4o-mini` for text, `dall-e-3` for image), `api_key`, `organization`.

## 4. Data model

### 4.1 New tables

**`scenes`**

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `lesson_id` | fk → lessons | cascade delete |
| `order` | int | unique with `lesson_id` |
| `kind` | enum | `narration` \| `game` |
| `year` | string nullable | narration only, e.g. `"1810"` |
| `location` | string nullable | narration only, e.g. `"Paris, France"` |
| `script_segment` | text | narration scenes: spoken text; game scenes: segment intro |
| `image_prompt` | text nullable | last prompt used (editable for regen) |
| `image_path` | string nullable | storage path on `public` disk |
| `image_style` | enum nullable | overrides lesson default (`realistic`\|`sketched`\|`painted`\|`cinematic`\|`comic`\|`animation`) |
| `animation_clip_id` | fk → animation_clips nullable | narration only |
| `audio_path` | string nullable | |
| `audio_alignment` | json nullable | ElevenLabs character timings |
| `audio_script_hash` | string nullable | sha1 of script_segment when audio was generated (stale detection) |
| `duration_seconds` | int nullable | narration: actual audio duration; game: countdown duration |
| `game_segment_index` | int nullable | game only, 1..N |
| `status` | enum | `pending` \| `generating` \| `ready` \| `failed` |
| `error_message` | text nullable | |
| timestamps | | |

Index: `(lesson_id, order)`.

**`lesson_sources`**

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `lesson_id` | fk → lessons | cascade delete |
| `kind` | enum | `pdf` \| `docx` \| `wikipedia` \| `both` |
| `original_filename` | string nullable | |
| `file_path` | string nullable | `lessons/{id}/source.{ext}` |
| `extracted_text` | longtext | |
| `wikipedia_topic` | string nullable | |
| timestamps | | |

### 4.2 `lessons` table additions

| Column | Type | Notes |
|---|---|---|
| `image_style` | enum, default `realistic` | lesson-wide default style |
| `source_mode` | enum | `wikipedia` \| `upload` \| `both` |
| `team_count` | int nullable | game team count, lesson-level |
| `game_split_count` | int default 1 | how many segments to split the game into |
| `outline` | json nullable | `{title, scene_briefs:[…]}` from BuildLessonOutline |
| `wizard_step` | tinyint default 1 | last visited step |

**Deprecated** (kept one release for safe migration):
- `slideshow_images` (migrated into Scene rows)
- `intel_drop_enabled`, `intel_drop_at_minutes`, `intel_drop_script`, `intel_drop_audio_path`
  → lessons with `intel_drop_enabled=true` migrate to `game_split_count=2`; the `intel_drop_script` seeds the in-between narration scene.

### 4.3 `LessonStatus` enum additions

Existing: `Pending, Generating, Ready, Published, Failed`.
Add: `Draft, SourceReady, Outlining, ScenesGenerating, ScenesReady, Configuring, Previewable`.

State machine:
```
Draft → SourceReady → Outlining → ScenesGenerating → ScenesReady
                                                       ↓
                              Configuring ←→ Previewable → Published
                                  ↑                ↑
                                  └─ Failed ───────┘   (any step can flip to Failed)
```

## 5. Generate pipeline (Step 2)

### 5.1 Sequencing

Triggered by `Step1Settings::generate()`:

1. **BuildLessonOutline** (job)
   - Input: `LessonSource.extracted_text`, lesson basics, `team_count`, `game_split_count`, target duration.
   - OpenAI: JSON mode, prompt requires `{title, scene_briefs: [{order, kind, year?, location?, beat, image_prompt_seed?, game_segment_index?}]}`.
   - Writes `lesson.title`, `lesson.outline`, creates Scene rows with `status=pending`.
   - Transitions: `SourceReady → Outlining → ScenesGenerating`.

2. **Per-scene fan-out** (`Bus::batch`)
   For each Scene, depending on `kind`:
   - `GenerateSceneScript` (always)
   - `GenerateSceneImage` (always — game scenes get their own dim background image)
   - `GenerateSceneAudio` (always — game scenes narrate the segment intro)
   Each job flips `scene.status` to `generating` then `ready` (or `failed` with `error_message`).

3. **`batch->then()`**
   - Dispatch `GenerateQuiz`.
   - Set `lesson.status = ScenesReady`.

### 5.2 Source resolution

- `source_mode=wikipedia` → `WikipediaService::fetchFacts($topic)`
- `source_mode=upload` → `DocumentExtractor::extract($file)`
- `source_mode=both` → both, concatenated with section headers, both labelled authoritative in the LLM prompt.

The merged text is persisted on `lesson_sources.extracted_text` so retries don't re-extract.

### 5.3 Style templates

Style applied as prompt suffix in `GenerateSceneImage`:

```
realistic:  "photographic, period-accurate, natural light, shallow DOF, people in period clothing"
sketched:   "pencil ink sketch, hatching, period dress, sepia tones"
painted:    "oil painting, romantic era, visible brushstrokes, dramatic light"
cinematic:  "film still, anamorphic, dusk lighting, color graded"
comic:      "bold ink outlines, flat color panels, halftone shading"
animation:  "stylized 3D animation, soft lighting, expressive characters"
```

All prompts include:
- A panoramic hint: `"2:1 wide aspect, panoramic composition, ambient periphery"` (best-effort — DALL·E 3 is not natively equirectangular; see followups).
- A safety guardrail line: `"no children, no real living people, no readable text in image"`.
- Game scenes add: `"battle/scene illustration, dim mid-tones suitable for overlaid UI, no clutter"`.

### 5.4 Grade-band style recommendation

Selector returns the recommended style ID and powers a "RECOMMENDED" pill in the Settings style picker:

| Grade band | Recommended |
|---|---|
| K–3 | animation |
| 4–6 | comic, sketched |
| 7–9 | painted, cinematic |
| 10–12 | realistic, cinematic |

### 5.5 Retry surface

- Whole-lesson retry: if `BuildLessonOutline` fails, Step 2 shows "Retry outline".
- Per-scene retry: each scene has Regenerate for image/audio/script independently (re-dispatches that one job).
- Job-level: `$tries = 3`, `backoff() = [10, 30, 90]` seconds.

## 6. Step 1 — Settings UI

Single Livewire page, scrollable, grouped into 5 sections (see §6 in the brainstorming transcript for the visual layout).

Fields:

| Field | Type | Notes |
|---|---|---|
| Topic | required string min:3 max:100 | |
| Subject | required enum | history / science / literature / civics |
| Grade level | required string | drives style recommendation |
| Tone | optional string max:150 | |
| Details | optional string max:500 | |
| Source mode | enum | `wikipedia` \| `upload` \| `both` |
| Upload | optional file | PDF ≤10MB or DOCX ≤5MB; required if `source_mode != wikipedia` |
| Style | enum (6 options) | thumb-based picker with RECOMMENDED pill |
| Avatar | fk → avatars | horizontal scroller like Avatar Lab |
| Strategy game | fk → strategy_games | auto-match suggestion based on topic |
| Team count | int | range from `game.team_size_min..max` |
| Game split | enum | `1` \| `2` \| `3` \| `4` |
| Lesson code | string 6 alphanum | auto-generated, regenerate button |
| Duration | min + sec | target only, AI honors as guidance |
| Portrait | optional image ≤4MB | |

Actions:
- **Save as draft** → persist with `status=Draft`, redirect to `?step=1` (same page).
- **Generate lesson →** validate, persist, dispatch `BuildLessonOutline`, redirect to `?step=2`.

Authorization: `lesson.teacher_id === auth()->id()` on every wizard route.

## 7. Step 2 — Generate UI

- Live progress list of scenes with per-asset status (script / image / audio): ⟳ generating, ✓ ready, ⚠ failed (with Retry).
- Overall progress bar.
- Polled every 2 s with `wire:poll.2s`.
- **Continue to Configure** button enables when `≥1 scene` is fully ready (allows teacher to start editing while remaining scenes finish).
- On revisit: same view; auto-redirects to Step 3 when all-ready.

## 8. Step 3 — Scene Configurator UI

### 8.1 Layout

Fullscreen three.js viewport, with overlays:

- **Top-left overlay** — year badge (white circle outline + year text).
- **Bottom-left overlay** — location pin (white SVG icon, 21×26) + uppercase location text.
- **Top-right floating chip** — `[≡ Inspector]`. Opens slide-in drawer (~360 px) from the right. Persisted to localStorage per teacher.
- **Bottom strip** — timeline (see §8.3). Semi-transparent dark background `bg-base-300/85 backdrop-blur`.
- **Bottom-right floating buttons** — `[← Back to Generate]`, `[Continue to Preview →]`.

The canvas is `position: fixed; inset: 0; width: 100vw; height: 100vh; z-index: 0`. Renderer is sized via `ResizeObserver`. The renderer instance persists across Step 3 ↔ Step 4 via a shared Alpine store reference.

### 8.2 Inspector drawer

Two layouts depending on selected scene's `kind`:

**Narration scene Inspector**
- Year (text)
- Location (text)
- Style (dropdown, overrides lesson default)
- Animation (dropdown from avatar's `AvatarAnimationController` pool)
- Skybox thumb + Regenerate + collapsible Prompt textarea
- Script textarea + Re-narrate button
- Delete scene

**Game scene Inspector**
- Read-only header: game title + "Segment N of M"
- Segment intro script + Re-narrate
- Background image thumb + Regenerate
- Challenge duration `mm:ss` (drives `scene.duration_seconds`)
- Delete segment (cascades `game_segment_index` reindex + decrements `lesson.game_split_count`)

### 8.3 Timeline (shared `<x-lesson.timeline>`)

- **Ruler row** — mm:ss, ticks every 5 s.
- **Video track** — scene thumb cards in scene `order`. Thumbs use existing Avatar Lab tokens:
  - Selected: `ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900`.
  - Unselected: `ring-1 ring-slate-700/50 hover:ring-slate-500`.
  - Failed: `ring-1 ring-rose-500/50` + ⚠ overlay top-right.
  - Narration thumb: skybox image fill, `aspect-video`, year+location label.
  - Game thumb: indigo/emerald gradient, dice icon `🎲`, "GAME" label, game title underneath (matches the teal chip pattern at `avatar-lab.blade.php:205`).
- **Audio track** — `AmplitudeWaveform` rendered from `scene.audio_alignment`. Flat bar when `audio_path` is null.
- **Script track** — first ~80 chars of `scene.script_segment`.
- **Playhead** — vertical line. Step 3: marks selected scene. Step 4: animates with audio, draggable.
- **`+` add-scene button** — `w-9` height-matched, `rounded-lg border-2 border-slate-600 hover:border-amber-400`, single `+` icon. Appends a blank narration Scene at the end (per [add-more icon pattern](feedback_add_more_icon.md)).
- **Drag-to-reorder** — SortableJS (already used in Avatar Lab) on the video track → Livewire `reorder($orderedIds)`.
- **`:editable=false` mode** for Step 4 — drops drag, inspector, and the add-scene button; clicking a thumb jumps the playhead.

### 8.4 Game scene playback overlay

Activated when `SceneTimelinePlayer` emits `gamestart`:

- Skybox stays loaded (the scene's `image_path` or fallback to previous narration scene's skybox).
- Full-viewport dim layer: `bg-black/55`.
- Centered overlay:
  - Small uppercase label: `TIME TO COMPLETE THE CHALLENGE`.
  - Large countdown: `M:SS`, font weight bold, white.
- Countdown ticks at 1 Hz from `scene.duration_seconds`. At 0:00 emits `gameend`, player auto-advances.
- Pause/seek on the lesson timeline pauses/seeks the countdown in lockstep.

### 8.5 Event surface (Livewire ↔ JS)

Extends existing `avatar3d:*` namespace:

```
JS ← Livewire:
  scene:load        { sceneId, imageUrl, animationGlbUrl, year, location }
  scene:play        { sceneId, audioUrl, alignment, text }
  timeline:loadAll  { scenes: [...] }
  timeline:play
  timeline:pause
  timeline:seek     { time }

JS → Livewire:
  scene:loaded      { sceneId }
  scene:played      { sceneId, durationActual }
  timeline:tick     { time, sceneId }
  timeline:ended
```

Existing `avatar3d:load`, `avatar3d:setClip`, `avatar3d:speak`, `avatar3d:setBg` remain as primitives.

## 9. Step 4 — Preview UI

Same fullscreen layout and timeline as Step 3, with these differences:
- No Inspector chip.
- Timeline `:editable=false`.
- Big `▶ Play / ⏸` button bottom-center.
- Playhead animates with audio; click thumb = jump to that scene's start.
- Top-right `Publish` button. Sets `lesson.status = Published`. Requires `all scenes status=ready`.

## 10. Authorization & safety

- All wizard routes scoped to `lesson.teacher_id === auth()->id()` (mirrors `LessonShow`).
- Upload caps: PDF 10 MB, DOCX 5 MB; mime allowlist enforced in Form Request.
- Generated files on `public` disk under `lessons/{lesson_id}/scenes/{scene_id}/{kind}.{ext}`.
- DALL·E prompts include a "no children, no real living people, no readable text in image" guardrail line.

## 11. Error handling

| Failure | Behavior |
|---|---|
| `BuildLessonOutline` fails | `lesson.status=Failed`, Step 2 shows error card + Retry. |
| Per-scene job fails | `scene.status=Failed` + `error_message`. Lesson still progresses. Step 3 shows ⚠ + Regenerate. |
| `DocumentExtractor` cannot parse upload | Step 1 form validation error; teacher gets inline message. |
| OpenAI rate limit / 5xx | `$tries=3`, `backoff()=[10,30,90]`. After that, scene marked failed. |
| Asset 404 in player | Skip scene with toast; ⚠ shown on timeline thumb. |
| Script edited but audio not re-narrated | Player refuses to play scene where `scene.audio_script_hash !== sha1(scene.script_segment)`. Inspector flags it. |

## 12. Testing

| Layer | What |
|---|---|
| Unit | `OpenAiLlmService` (JSON contract, retry); `DocumentExtractor` (PDF + DOCX fixtures); style-template builder; recommended-style selector; game-split outline post-processor. |
| Feature | Step 1 form save creates Draft + LessonSource; Generate dispatches batch; per-scene Regenerate dispatches single job; cross-teacher access denied; Publish gated by `status=Previewable` and all scenes ready. |
| Job | `BuildLessonOutline` writes outline + Scene rows; per-scene jobs persist assets + flip status; failure paths write `error_message`; intel_drop migration covers `game_split_count=2`. |
| Browser (Dusk) | Wizard step nav with persisted lesson; timeline drag-reorder writes new `order`; regenerate single scene updates thumb; fullscreen viewport mounts on Step 3. |
| JS (Vitest) | `SkyboxSphere.crossfadeTo` resolves on tween-end; `SceneTimelinePlayer.seek` lands on correct scene + offset; `GameTimerOverlay` decrements and emits `gameend` at 0. |

All PHP tests run under `composer test` (Pest + SQLite in-memory).

## 13. Out of scope / Followups

1. **4K skybox upscale** — cheap post-process pass on `image_path` (Real-ESRGAN local, Replicate `nightmareai/real-esrgan` ≈ $0.0005/image, or Topaz Gigapixel). Add `scene.image_path_hi` column; player auto-picks hi-res when present.
2. **Real-time game runtime** — student-facing strategy game UI during game scenes. v1 shows the countdown overlay only; the actual game is teacher-facilitated in-class.
3. **Per-scene avatar swap** — v1 fixes avatar at the lesson level. Add `scene.avatar_id` when Napoleon vs. Wellington style scenes are needed.
4. **Streaming generation** — convert the multi-call pipeline to streamed JSON so Step 2 progress is sub-second granular.
5. **True equirectangular generation** — DALL·E 3 isn't natively panoramic. Try SDXL fine-tunes or Skybox AI for proper 360°.
6. **i18n** — fixed copy in `GameTimerOverlay` ("TIME TO COMPLETE THE CHALLENGE") and location pin.
7. **Deprecation cleanup** — drop `lessons.slideshow_images` and `lessons.intel_drop_*` one release after migration.

## 14. Implementation note

Per user preference (see [session per task](feedback_session_per_task.md)), the writing-plans phase that follows will structure tasks so each is independently executable in its own dev session/worktree, with explicit acceptance criteria and no implicit cross-task shared state.

# Avatar Lab — Movement Tab Design Spec
**Date:** 2026-04-09
**Status:** Approved
**Scope:** FBX animation library, retargeting pipeline, animation controller with clip pools, Three.js playback

---

## Overview

Add a **Movement** tab to the existing Avatar Lab page (`admin.avatar-lab`). The tab allows admins to browse the pre-seeded Subject 138 CMU mocap animation library, convert individual FBX files to GLB on demand, preview retargeted animations on a 3D avatar in the browser, and assign clips to an animation controller that drives avatar behaviour during lesson playback.

The animation controller uses **clip pools** per slot (not single clips) to avoid repetition — each time a state is entered, a different clip from the pool is picked.

**What stays untouched:** The existing 2D sprite pipeline, the Avatar Studio component, and the existing 3D Preview tab in Avatar Lab.

---

## Source Animations

**Location:** `public/avatars/animations/walking_talking/` (55 FBX files, already on server)
**Source:** CMU Mocap Database — Subject 138 "Marching, Walking and Talking"
- `138_01` – `138_06`, `138_08` – `138_10`: marching (9 clips)
- `138_07`: casual walk (1 clip)
- `138_11` – `138_55`: story / walk & talk gestures (45 clips)

**Bone convention:** CMU naming (`chest`, `lThigh`, `rForeArm`, `neck`, `head`, etc.)
**Root motion:** baked in — must be stripped on conversion
**License:** Free to use/modify/redistribute; credit mocap.cs.cmu.edu

---

## Architecture

### New Backend Pieces

```
app/Services/FbxConversionService.php     — wraps fbx2gltf CLI, strips root motion
app/Livewire/Admin/AvatarLab.php          — extended: new Movement tab methods
database/migrations/..._create_animation_tables.php
```

### New Frontend Pieces

```
resources/js/animation-controller.js     — runtime: pool selection, crossfade, state machine
resources/views/livewire/admin/avatar-lab/movement-tab.blade.php
```

### Modified

```
resources/views/livewire/admin/avatar-lab.blade.php   — add Movement tab
app/Models/AnimationClip.php              — new model
app/Models/AvatarAnimationController.php  — new model
```

### File Storage

```
public/avatars/animations/walking_talking/138_XX.fbx   ← source (already present)
public/avatars/animations/walking_talking/138_XX.glb   ← converted (generated on demand)
storage/app/avatars/{avatar_id}/controller.json        ← animation controller per avatar
```

---

## Database

### Migration — `animation_clips` table

```php
$table->id();
$table->string('clip_id');                    // "138_14"
$table->string('name');                       // "story (walk & talk)"
$table->string('category');                   // walk|idle|gesture|emotion
$table->string('source');                     // "cmu_138"
$table->string('fbx_path');                   // public/avatars/animations/walking_talking/138_14.fbx
$table->string('glb_path')->nullable();       // public/avatars/animations/walking_talking/138_14.glb
$table->enum('status', ['pending', 'converting', 'ready', 'failed'])->default('pending');
$table->string('conversion_error')->nullable();
$table->timestamps();
$table->unique('clip_id');
```

Seeded at migration time with all 55 Subject 138 entries (clip_id, name, fbx_path).

### Migration — `avatar_animation_controllers` table

```php
$table->id();
$table->foreignId('avatar_id')->constrained()->cascadeOnDelete();
$table->json('controller');   // see Controller JSON Format below
$table->timestamps();
$table->unique('avatar_id');
```

### No `bone_maps` table

The CMU → Rigify bone map is a static JSON file stored at:
```
public/avatars/animations/bone_maps/cmu_to_rigify.json
```
Written once by the developer. Not editable via UI in this spec — added to Avatar Lab as a read-only info panel.

---

## Controller JSON Format

```json
{
  "version": 1,
  "slots": {
    "idle": {
      "mode": "sequential",
      "clips": ["138_07"]
    },
    "walk": {
      "mode": "random",
      "clips": ["138_11", "138_14", "138_20", "138_25", "138_30"]
    },
    "gesture": {
      "mode": "random",
      "clips": ["138_38", "138_44"]
    },
    "emotion_excited": {
      "mode": "random",
      "clips": []
    },
    "emotion_serious": {
      "mode": "random",
      "clips": []
    },
    "emotion_whisper": {
      "mode": "random",
      "clips": []
    }
  },
  "transitions": {
    "gesture_fade_out": 0.3,
    "emotion_fade_out": 0.5,
    "walk_fade_in": 0.2,
    "idle_fade_in": 0.3
  }
}
```

**Slot rules:**
- `idle` — loops; plays continuously when no other state is active
- `walk` — loops; triggered by `[walk]` script tag; stops on `[/walk]` or segment end
- `gesture` — plays once; triggered by `[point]`, `[nod]`, `[gesture]` tags; crossfades back to idle/walk
- `emotion_excited` / `emotion_serious` / `emotion_whisper` — plays once; triggered by matching script tags; crossfades back

**Pool selection:**
- `random` — selects uniformly at random, avoids repeating the previous clip
- `sequential` — advances index each play, wraps to 0 at end

---

## Backend Pipeline

### FbxConversionService

Single responsibility: convert one FBX to GLB, strip root motion.

```
convert(AnimationClip $clip): void

1. Check fbx2gltf is installed (config/services.php → fbx2gltf.binary path)
2. Build output path: same dir as FBX, .glb extension
3. Run: fbx2gltf --input {fbx_path} --output {glb_path} --no-reindex
4. Strip root motion: post-process GLB via a small Node.js script
   scripts/strip-root-motion.js {glb_path}
   (zeroes translation keyframes on the root/hip bone, keeps rotation)
5. On success: AnimationClip::update(['status' => 'ready', 'glb_path' => ...])
6. On failure: AnimationClip::update(['status' => 'failed', 'conversion_error' => stderr])
```

**Root motion stripping:** The CMU README explicitly warns about baked root motion. The strip script reads the GLB binary, finds the hip/root bone translation track, zeroes X/Z translation keyframes (keeps Y for ground contact), writes back in place.

**fbx2gltf installation:** Added to `composer.json` scripts or documented in `SETUP.md`. On SiteGround production, fbx2gltf is run locally by the admin and GLBs are committed/deployed — conversion does not run on the production server.

### AvatarLab Livewire — Movement Tab Methods

```php
// Properties
public string $activeTab = 'preview';  // 'preview' | 'movement'
public ?int $selectedClipId = null;
public string $previewClipStatus = 'idle'; // idle|converting|ready|error

// Actions
public function selectClip(int $clipId): void
// Sets $selectedClipId, dispatches movement:clipSelected JS event

public function convertClip(int $clipId): void
// Sets clip status = converting
// Dispatches ConvertAnimationClip job (queued)
// Enables wire:poll.1s

public function pollConversionStatus(): void
// Checks AnimationClip status
// On ready: sets $previewClipStatus = 'ready', dispatches movement:clipReady JS event
// Stops polling

public function assignClipToSlot(int $clipId, string $slot): void
// Loads or creates AvatarAnimationController for current avatar
// Adds clip_id to controller.slots.{slot}.clips array (if not already present)
// Saves controller JSON

public function removeClipFromSlot(int $clipId, string $slot): void
// Removes clip_id from controller.slots.{slot}.clips array
// Saves controller JSON

public function setSlotMode(string $slot, string $mode): void
// Sets controller.slots.{slot}.mode to 'random' or 'sequential'
// Saves controller JSON
```

---

## Frontend — animation-controller.js

Manages the Three.js `AnimationMixer` at lesson playback time. Loaded alongside `avatar-3d.js`.

```javascript
export class AnimationController {
  #mixer;       // THREE.AnimationMixer
  #clips = {};  // { clip_id: THREE.AnimationClip }
  #controller;  // parsed controller JSON
  #currentSlot = 'idle';
  #currentAction = null;
  #lastClipPerSlot = {}; // { slot: clip_id } — for anti-repeat

  constructor(mixer, controllerJson) {}

  async loadClips(clipManifest)
  // clipManifest: [{ id: '138_14', url: '/avatars/animations/walking_talking/138_14.glb' }]
  // Uses THREE.GLTFLoader for each, extracts AnimationClip, stores in #clips

  trigger(slot, fadeIn)
  // Picks a clip from pool using mode (random anti-repeat or sequential)
  // Crossfades from #currentAction to new action
  // For 'gesture'/'emotion_*': sets up onFinished listener → crossfade back to previous slot

  stop(fadeOut)
  // Crossfades back to idle

  #pickClip(slot)
  // Returns clip_id string based on slot mode
  // random: Math.random(), excludes #lastClipPerSlot[slot]
  // sequential: increments stored index, wraps

  update(deltaTime)
  // Called every frame: #mixer.update(deltaTime)
}
```

### Script Tag → Controller Trigger mapping

The lesson script parser (in `avatar-3d.js`) maps script tags to `AnimationController.trigger()` calls:

| Script tag | Slot triggered |
|---|---|
| `[walk]` | `walk` |
| `[/walk]` or segment end | `idle` (stop) |
| `[point]`, `[nod]`, `[gesture]` | `gesture` |
| `[excited]` | `emotion_excited` |
| `[serious]` | `emotion_serious` |
| `[whisper]` | `emotion_whisper` |

---

## Movement Tab UI

### Left panel — Clip list (55 clips, pre-seeded)
- Grouped by description (marching / casual walk / story)
- Status indicators: `✓` assigned to any slot · `◉` converted (GLB exists) · `○` not converted
- Click to select → loads right panel
- No upload — all FBX are already on server

### Right panel — Convert + Preview + Assign
1. **Clip header** — clip ID, description, server path, current status badge
2. **Convert button** — shown when status is `pending` or `failed`; disabled when `converting` or `ready`; shows progress spinner during conversion
3. **Three.js preview** — loads GLB when status is `ready`; play/stop/loop controls; badges confirming "root motion stripped" and "bone map applied"
4. **Assign to Controller section** — shows all slots; each slot displays its current clip pool as a list of removable tags; "Add to [slot]" button appends current clip; random/sequential toggle per slot

### Controller persistence
- Saved to `storage/app/avatars/{avatar_id}/controller.json` on every assign/remove/mode change
- Loaded by `AnimationController` at lesson playback via `/api/v1/avatars/{id}/controller` endpoint

---

## API Endpoint

```
GET /api/v1/avatars/{avatar}/controller
Auth: Sanctum (student or teacher)
Response: { controller: { ... } }  ← raw controller JSON
```

Also serves the GLB URLs for each clip in the controller:

```json
{
  "controller": { ... },
  "clip_urls": {
    "138_11": "https://example.com/avatars/animations/walking_talking/138_11.glb",
    "138_14": "https://example.com/avatars/animations/walking_talking/138_14.glb"
  }
}
```

---

## Error Handling

| Failure | Behaviour |
|---|---|
| fbx2gltf not installed | Conversion button shows "fbx2gltf not found — see SETUP.md"; no crash |
| Conversion fails (bad FBX) | Status → `failed`, error message shown in UI with stderr output |
| GLB missing at playback | `AnimationController` skips that clip silently, logs warning; falls back to next clip in pool |
| Empty pool at playback | Slot plays nothing; no crash; previous state continues; missing slot collected for UI alert |
| Unknown tag in script | Tag ignored silently; avatar stays in current state; missing tag collected for UI alert |
| Root motion strip script missing | GLB still loads; avatar may drift; warning logged |

---

## Graceful Tag Degradation

The animation system must never error or stall playback because a tag is unrecognised or its pool is empty. All tag failures are silent at runtime — the lesson continues uninterrupted, with the avatar staying in its current state.

### Runtime behaviour in `AnimationController`

```javascript
trigger(slot, fadeIn) {
  // 1. Slot not in controller JSON at all (unknown tag)
  if (!this.#controller.slots[slot]) {
    this.#collectMissingSlot(slot);
    return; // no-op, stay in current state
  }

  // 2. Slot exists but pool is empty (no clips assigned)
  const pool = this.#controller.slots[slot].clips;
  if (!pool || pool.length === 0) {
    this.#collectMissingSlot(slot);
    return; // no-op, stay in current state
  }

  // 3. Clip exists in pool but GLB failed to load
  const clipId = this.#pickClip(slot);
  if (!this.#clips[clipId]) {
    this.#collectMissingSlot(slot);
    return; // no-op, stay in current state
  }

  // ... normal crossfade logic
}

#collectMissingSlot(slot) {
  if (!this.#missingSlots.has(slot)) {
    this.#missingSlots.add(slot);
    // Dispatch event so the lesson UI can pick it up
    window.dispatchEvent(new CustomEvent('animation:missingSlot', {
      detail: { slot }
    }));
  }
}
```

`#missingSlots` is a `Set` — each missing slot fires `animation:missingSlot` exactly once per playback session, no matter how many times the tag appears in the script.

### Lesson screen alert UI

The lesson player listens for `animation:missingSlot` events and accumulates them. After a short debounce (500ms after the last event), it shows a single non-blocking toast alert:

**Alert design:**
- Position: top-right corner of the lesson player, below any existing controls
- Style: amber/yellow — informational, not an error; dismissible
- Shown to: **admins and teachers only** — never to students
- Does not pause playback, does not require acknowledgement
- Auto-dismisses after 10 seconds if not manually dismissed

**Alert message varies by role:**

| Role | Message |
|---|---|
| `admin` | ⚠️ Some animation tags have no clips assigned: `[whisper]`, `[excited]` → [Go to Avatar Lab → Movement](#) `[Dismiss]` |
| `teacher` | ⚠️ Some avatar animations are missing for this lesson. Ask your administrator to assign them in the Avatar Lab. `[Dismiss]` |
| `student` | *(no alert shown)* |

Teachers are intentionally given a vague message — they don't need to know about slots, tags, or the Avatar Lab. They just need to know something is missing and who to contact. The admin gets the full actionable detail and a direct link.

**Implementation location:** `resources/js/avatar-3d.js` (or a small inline `<script>` in the lesson blade view).

```javascript
// lessonMeta passed from blade:
// { userRole: 'admin'|'teacher'|'student', avatarLabUrl: '/admin/avatar-lab?tab=movement' }
const { userRole, avatarLabUrl } = window.lessonMeta ?? {};

const missingSlots = new Set();
let debounceTimer = null;

window.addEventListener('animation:missingSlot', ({ detail }) => {
  if (userRole === 'student' || !userRole) return;
  missingSlots.add(detail.slot);
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    showAnimationAlert([...missingSlots]);
  }, 500);
});

function showAnimationAlert(slots) {
  // Role-aware message:
  if (userRole === 'admin') {
    // "Some animation tags have no clips assigned: [whisper], [excited]"
    // + direct link to avatarLabUrl
  } else {
    // "Some avatar animations are missing. Ask your administrator."
    // No link, no slot names
  }
  // Render amber dismissible toast, auto-dismiss after 10s
}
```

**The alert is never shown to students.** Students see only the lesson — the avatar simply stays in its current idle/walk state when a tag has no clips. No visual indication of anything missing.

---

## Environment Variables

```env
FBX2GLTF_BINARY=/usr/local/bin/fbx2gltf   # path to fbx2gltf binary
```

Add to `config/services.php`:
```php
'fbx2gltf' => [
    'binary' => env('FBX2GLTF_BINARY', 'fbx2gltf'),
],
```

---

## LLM Prompt Instructions — Animation Tags

Add the following to the system prompt in `LlmService::generateScript()`, after the existing emotion tag instructions from the 3D Lab spec.

---

### Prompt addition (append to system prompt)

```
ANIMATION TAGS — MOVEMENT AND EMOTION
======================================

You can wrap parts of your narration in animation tags to control how the avatar
moves and behaves while speaking. These tags are powerful storytelling tools —
use them to make the lesson feel alive, not like a static lecture.

Below are all available tags, when to use each one, and examples. Follow the
rules exactly: tags must open and close, must not overlap, and must only appear
where they genuinely improve the story.


[walk] ... [/walk]
------------------
Use when: The avatar should pace or move while narrating. Best for transitions
between topics, scene-setting passages, or when the story is moving forward in
time or space. The avatar walks left and right in front of the lesson backdrop
while speaking.

Good fits:
- Introducing a new scene or location ("As Caesar approached the Senate...")
- Moving through a sequence of events ("First, the armies gathered. Then...")
- Bridging two ideas where a pause would feel awkward
- Opening or closing a lesson segment

Rules:
- Wrap at least 1–3 full sentences. Do not use for a single word or phrase.
- Do not use [walk] during [excited], [serious], or [whisper] — close those first.
- Use 2–4 times per lesson. More than that feels restless.

Example:
[walk] For centuries, the Roman Republic had been the envy of the ancient world.
Senators debated, laws were passed, and power was — at least in theory — shared.
But by 44 BC, one man had changed all of that. [/walk]


[excited] ... [/excited]
------------------------
Use when: Something genuinely surprising, triumphant, fascinating, or hard to
believe just happened in the story. The avatar's body language shifts to express
high energy — arms out, forward lean, physical enthusiasm.

Good fits:
- A battle victory or unexpected military tactic
- A scientific discovery or invention that changed history
- A remarkable statistic or fact that will genuinely surprise students
- A plot twist in the historical narrative ("And that's when everything changed!")
- The climax of the story

Rules:
- Use sparingly — maximum 2 times per lesson. Overuse kills the effect.
- Wrap 1–2 sentences only. This is a burst of energy, not a monologue.
- Do not use for moderately interesting content — save it for genuinely
  jaw-dropping moments.
- Must follow or precede a calmer passage so the contrast lands.

Example:
[excited] Against all odds, a force of just 300 Spartans held back an army of
over 100,000 for three full days. Three hundred. Against one hundred thousand! [/excited]


[serious] ... [/serious]
------------------------
Use when: The content is grave, sobering, morally weighty, or requires the
student to reflect. The avatar's posture becomes still and deliberate — a
physical signal that what follows matters deeply.

Good fits:
- Deaths, suffering, or the consequences of war
- Moral dilemmas faced by historical figures
- Injustice, slavery, oppression, or persecution
- The human cost behind a famous event
- A moment of failure, tragedy, or loss
- When asking students to consider an ethical question

Rules:
- Use 1–2 times per lesson.
- Wrap 1–3 sentences. Let the weight of the words do the work.
- Do not immediately follow [excited] — always place a neutral sentence in
  between to reset the tone.
- Do not use for merely factual content that happens to involve death — only
  when the emotional weight is the point.

Example:
[serious] But not everyone celebrated the Roman Empire's rise. Thousands of
people were enslaved to build its monuments. Their names were never recorded.
Their stories were never told. [/serious]


[whisper] ... [/whisper]
------------------------
Use when: You are letting the student in on something — a secret, a rumour, a
piece of insider knowledge, a conspiracy, or something that "history books don't
always tell you." This is the avatar leaning in and lowering their voice, as if
sharing something just between themselves and the student.

Good fits:
- Historical gossip or rumour ("Some historians believe he was actually
  poisoned...")
- A conspiracy, plot, or betrayal being planned
- A behind-the-scenes fact that most people don't know
- Contradicting the "official" version of events
- Spies, secret messages, hidden motives
- Moments where the avatar breaks the fourth wall slightly to confide in
  the student

Rules:
- Use once per lesson — maximum twice. It loses its magic if overused.
- Wrap 1–2 sentences only. A whisper is brief and conspiratorial.
- Always transition back to normal narration immediately after — do not
  continue whispering.
- Works especially well after [walk] or a neutral passage, before returning
  to the main story.
- Perfect for K-12: kids love being "let in" on a secret.

Example:
[whisper] Now, between you and me — most historians think Brutus never actually
wanted Caesar dead. He was talked into it. And he regretted it for the rest of
his life. [/whisper]


[point] / [nod] / [gesture]
----------------------------
These are single-sentence gesture triggers — the avatar makes a pointing,
nodding, or general hand gesture that emphasises the line.

Use [point] when: Drawing attention to something specific — a place on a map,
an object, a person being described ("Look at this region here", "Notice how...").

Use [nod] when: Affirming something the student should agree with, reinforcing
a key fact, or responding to a rhetorical question ("Yes, exactly — and that
is why...").

Use [gesture] when: A general expressive hand movement fits — explaining,
enumerating, or describing with emphasis ("There were three key reasons...",
"Imagine standing here...").

Rules:
- Apply as inline tags to a single sentence only. Do not wrap paragraphs.
- Use 2–4 times per lesson total across all three gesture tags.
- Do not stack gesture tags on consecutive sentences.

Examples:
[point] This is the exact location where Caesar crossed the Rubicon River.
[nod] And that, students, is why we still study this moment two thousand years later.
[gesture] The army was divided into three groups, each with a different mission.


GENERAL RULES FOR ALL TAGS
===========================

1. Never invent tags not listed above.
2. Tags must always be closed: [walk]...[/walk], [excited]...[/excited],
   [serious]...[/serious], [whisper]...[/whisper].
   Gesture tags ([point], [nod], [gesture]) have no closing tag.
3. Tags must not overlap or nest inside each other.
   Wrong: [walk] text [excited] more text [/walk] [/excited]
   Right: [walk] text [/walk] [excited] more text [/excited]
4. Do not open a tag in the middle of a sentence. Tags wrap whole sentences only.
5. Untagged text is delivered in a natural narrative tone with the avatar in
   idle stance. Most of the lesson should be untagged — tags are accents,
   not wallpaper.
6. Think of the lesson as a performance: rising action, a climax, a whispered
   aside, a serious moment, a triumphant beat. Tags shape that arc.
7. Grade-level awareness:
   - Grades 3–5: favour [excited] and [whisper] — high energy and secrets
     delight younger students. Keep [serious] very brief.
   - Grades 6–8: balance all four emotion tags. Students can handle nuance.
   - Grades 9–12: [serious] and [whisper] carry more weight. [excited] should
     feel earned, not forced.
```

---

### Summary table (for quick reference in code comments)

| Tag | Closes? | Max per lesson | Duration | Best for |
|---|---|---|---|---|
| `[walk]...[/walk]` | yes | 4 | 1–3 sentences | Scene transitions, narrative flow |
| `[excited]...[/excited]` | yes | 2 | 1–2 sentences | Surprising facts, climax moments |
| `[serious]...[/serious]` | yes | 2 | 1–3 sentences | Human cost, tragedy, moral weight |
| `[whisper]...[/whisper]` | yes | 2 | 1–2 sentences | Secrets, conspiracies, insider knowledge |
| `[point]` | no | 4 total gestures | 1 sentence | Pointing at something specific |
| `[nod]` | no | 4 total gestures | 1 sentence | Affirming, reinforcing |
| `[gesture]` | no | 4 total gestures | 1 sentence | General expressive emphasis |

---

## Out of Scope (this spec)

- Mixamo FBX upload (separate source rig — future spec)
- Adding more animation subjects beyond Subject 138
- Editing the bone map via UI (developer-maintained JSON file)
- Student-facing playback integration with lesson script tags (depends on avatar-3d.js spec being implemented first)
- Root motion walking (avatar translates across scene floor) — deferred; in-place looping only for v1
- Per-avatar bone map overrides (all avatars share the same CMU → Rigify map for now)

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
- `emotion_excited` / `emotion_serious` — plays once; triggered by matching script tags; crossfades back

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
| Empty pool at playback | Slot plays nothing; no crash; previous state continues |
| Root motion strip script missing | GLB still loads; avatar may drift; warning logged |

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

## Out of Scope (this spec)

- Mixamo FBX upload (separate source rig — future spec)
- Adding more animation subjects beyond Subject 138
- Editing the bone map via UI (developer-maintained JSON file)
- Student-facing playback integration with lesson script tags (depends on avatar-3d.js spec being implemented first)
- Root motion walking (avatar translates across scene floor) — deferred; in-place looping only for v1
- Per-avatar bone map overrides (all avatars share the same CMU → Rigify map for now)

# Avatar Lab — Mixamo Redesign

**Date:** 2026-04-10
**Replaces:** `2026-04-09-avatar-lab-movement-design.md`
**Status:** Approved

---

## Overview

Replace the broken CMU mocap animation system with a simple Mixamo FBX-based approach.
The Avatar Lab UI is redesigned from a complex clip-management tool into a clean
three-column interface for browsing, previewing, and assigning body animations to avatars.

Facial animation (lip sync, blink, gaze) is unaffected — it continues to use
RPM ARKit blend shapes driven by Rhubarb phoneme data.

---

## What Gets Deleted

| Path | Reason |
|---|---|
| `public/avatars/animations/walking_talking/` | 55 CMU FBX + 8 partial GLBs |
| `public/avatars/animations/bone_maps/` | CMU → Rigify bone map JSON |
| `app/Models/AnimationClip.php` | Replaced by new simpler model |
| `database/migrations/2026_04_09_000001_create_animation_clips_table.php` | Replaced |
| `database/migrations/2026_04_09_000002_create_avatar_animation_controllers_table.php` | Replaced |
| `app/Services/FbxConversionService.php` | No conversion pipeline needed |
| `app/Jobs/ConvertAnimationClip.php` | No conversion pipeline needed |
| `scripts/strip-root-motion.mjs` | Mixamo "In Place" clips have no root motion |
| `database/seeders/AnimationClipSeeder.php` | CMU-specific |
| `database/factories/AnimationClipFactory.php` | CMU-specific |
| `tests/Feature/AvatarLabMovementTest.php` | Replaced |
| `tests/Unit/AnimationClipModelTest.php` | Replaced |
| `tests/Unit/AvatarAnimationControllerModelTest.php` | Replaced |
| `tests/Feature/ConvertAnimationClipJobTest.php` | Replaced |
| `tests/Unit/FbxConversionServiceTest.php` | Replaced |
| `docs/superpowers/specs/2026-04-09-avatar-lab-movement-design.md` | Superseded |

---

## Architecture

### Character + Animation Split

| Layer | Format | Purpose |
|---|---|---|
| Character mesh | RPM GLB (`public/avatars/{id}/character.glb`) | Visual appearance, skeleton, blend shapes |
| Facial animation | RPM ARKit blend shapes (52 morph targets) | Lip sync, blink, gaze — driven by Rhubarb |
| Body animation | Mixamo FBX (`public/avatars/animations/{category}/{id}.fbx`) | Walk, idle, gesture |

RPM characters use Mixamo-compatible bone names (`mixamorigHips`, `mixamorigSpine`, etc.).
Three.js `FBXLoader` extracts the `AnimationClip` from a Mixamo FBX and applies it to the
RPM skeleton via `AnimationMixer` — no bone remapping needed.

Mixamo animations must be downloaded with **"In Place"** checked so they contain no
root motion (no X/Z hip drift). No stripping script required.

---

## Database

### New `animation_clips` table

```sql
id              bigint unsigned PK
name            varchar(255)          -- e.g. "Walking Talking"
category        enum('idle','presenting','greeting')
fbx_path        varchar(500)          -- public/avatars/animations/{category}/{id}.fbx
sort_order      smallint default 0
created_at, updated_at
```

### `avatar_animation_controllers` table (simplified)

Keep table, replace JSON schema:

```json
{
  "idle":       ["1", "3"],
  "presenting": ["7"],
  "greeting":   ["12", "14"]
}
```

Arrays contain `animation_clip.id` values. Order within the array determines
sequential playback order; random mode picks without immediate repeat.

---

## Backend — Livewire `AvatarLab` Component

### Properties
```php
string $activeSection = 'animation-groups'; // sidebar nav state
int $selectedAvatarId;
?int $previewClipId = null;                 // clip loaded in viewport
```

### Actions

| Method | Description |
|---|---|
| `uploadClip(string $category, UploadedFile $file)` | Validate FBX, store to `public/avatars/animations/{category}/{id}.fbx`, create DB record |
| `deleteClip(int $id)` | Delete file + DB record, remove from any controller that references it |
| `reorder(string $category, array $orderedIds)` | Update `sort_order` for all clips in category |
| `assignToController(int $avatarId, string $category, int $clipId)` | Add clip ID to avatar's controller JSON array |
| `removeFromController(int $avatarId, string $category, int $clipId)` | Remove clip ID from controller JSON |
| `loadPreview(int $clipId)` | Set `$previewClipId`, dispatch browser event to viewport JS |

No queued jobs. No status polling. All operations synchronous.

### File storage
```
public/avatars/animations/
  idle/           {clip_id}.fbx
  presenting/     {clip_id}.fbx
  greeting/       {clip_id}.fbx
```

Files are served directly (public disk). No Livewire temporary upload disk — validate
by extension (`.fbx` only) and size (max 20 MB) before storing. Do not rely on MIME type
as browsers report FBX inconsistently (`application/octet-stream`, `model/fbx`, etc.).

---

## Frontend Layout

```
┌────────────────┬─────────────────────────┬──────────────────────┐
│ Sidebar ~220px │ Middle panel ~420px      │ 3D Viewport flex-1   │
│                │                          │                      │
│ AVATAR         │ Animation                │ "Idle 1"    Use ✓   │
│ [Andrew ▼]     │                          │                      │
│                │ Idle animations   Add +  │  [Three.js canvas]   │
│ Animation  ▼   │ ┌──────────────────────┐ │                      │
│   Groups       │ │ Flickity slider      │ │                      │
│   Controller   │ └──────────────────────┘ │                      │
│                │                          │                      │
│ Narration   ▶  │ Presenting        Add +  │                      │
│                │ ┌──────────────────────┐ │                      │
│ Audio       ▶  │ │ Flickity slider      │ │                      │
│                │ └──────────────────────┘ │                      │
│ Settings    ▶  │                          │                      │
│                │ Greeting          Add +  │                      │
│                │ ┌──────────────────────┐ │                      │
│                │ │ Flickity slider      │ │                      │
│                │ └──────────────────────┘ │                      │
└────────────────┴─────────────────────────┴──────────────────────┘
```

### Sidebar

Collapsible sections driven by Alpine.js `x-data`. Each section header is a chevron toggle.

**Animation** (expanded by default):
- Sub-links: "Animation Groups" | "Controller" — switch the middle panel view
- "Animation Groups" shows the category/Flickity layout (default)
- "Controller" shows a per-avatar summary of which clips are assigned to each category slot

**Audio** (collapsed by default, expands to show):
- Test Script textarea

**Settings** (collapsed by default, expands to show):
- Gender toggle (Male / Female)
- Age slider (Child → Teen → Adult → Elder)
- Name field

### Middle Panel — Animation Groups View

Each animation category renders as:
```
h3 "Idle animations"          [Add +]
[Flickity horizontal slider of cards]
```

**Animation card** (~140×140px):
- Dark background (`bg-slate-800`)
- Blue stick figure SVG icon — unique per animation archetype (standing, walking, pointing, waving, explaining)
- Clip name below card
- Circle indicator top-right — hollow = not assigned to controller, filled checkmark = assigned
- Click → dispatches `preview-clip` event with `clipId` and `clipName` to viewport JS

**"Add +"** button:
- Opens a hidden `<input type="file" accept=".fbx">` scoped to that category
- On file select → triggers `uploadClip($category, $file)` Livewire action
- Card appears in slider immediately after upload completes

**Flickity config:** `freeScroll: true`, `contain: true`, `prevNextButtons: false`, `pageDots: false`

### Viewport — Right Panel

- Three.js canvas fills the panel
- Top-right overlay (absolute positioned): animation name + "Use ✓" button
- "Use" calls `assignToController` for the selected avatar + current preview clip's category
- OrbitControls enabled for camera inspection

### Three.js Changes (`avatar-3d.js`)

1. Import `FBXLoader` from `three/addons/loaders/FBXLoader.js`
2. On `preview-clip` event:
   - `FBXLoader.load('/avatars/animations/{category}/{id}.fbx', onLoad)`
   - `onLoad(fbx)`: extract first `AnimationClip` from `fbx.animations[0]`
   - Create/replace `AnimationAction` on the existing `AnimationMixer`
   - Fade out previous body action (0.3s), fade in new action
3. Existing blend shape idle pass (breathing, blink) runs as a separate mixer layer — unaffected
4. Character GLB loads once on avatar select; FBX clips hot-swap without reloading the character

### `animation-controller.js` Simplification

Remove:
- CMU slot system (`idle`, `walk`, `gesture`, `emotion_*` named slots)
- Bone mapping references
- Conversion status polling
- `animation:missingSlot` event firing for CMU slots

Keep:
- Random clip selection (anti-repeat) within a category pool
- Sequential clip selection
- Crossfade between clips (configurable duration, default 0.3s)
- One-shot playback (gesture/greeting auto-returns to idle)

Reads the flat controller JSON from the API (`/api/admin/avatars/{id}/controller`).

---

## Stick Figure SVG Icons

One SVG per animation archetype, used as card thumbnails. At minimum:

| Archetype | Used for |
|---|---|
| Standing still | Idle |
| Walking + talking | Presenting (walking talking) |
| Arm raised | Presenting (pointing) |
| Explaining gesture | Presenting (explaining) / Greeting |
| Waving | Greeting (waving) |
| Waving expressive | Greeting (waving expressive) |

SVGs are inline in a Blade component `<x-animation-icon type="walking" />`.
All use the same blue fill (`#7C8FF8`) matching the design.

---

## Tests

Replace deleted CMU tests with:

| Test | Type |
|---|---|
| `AnimationClipTest` — fillable, category enum, fbx_path | Unit |
| `AvatarLabUploadTest` — upload FBX, record created, file exists | Feature |
| `AvatarLabDeleteTest` — delete clip, file removed, controller updated | Feature |
| `AvatarLabControllerTest` — assign/remove clip from controller JSON | Feature |

---

## Out of Scope

- Admin creating/renaming animation categories (categories are fixed: idle, presenting, greeting)
- FBX thumbnail auto-generation from the 3D model
- Mixamo character as the base mesh (RPM GLB remains the character format)
- Flutter playback of Mixamo animations
- Multiple avatars with separate controller configs per lesson (one controller per avatar)

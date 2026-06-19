# Plan — Retire the 3D Avatar Character System

**Status:** Draft for review (not started)
**Author:** cleanup follow-up to the lip-sync/sprite removal
**Goal:** Remove the dormant **3D avatar character** system (GLB characters, morph pipeline, AnimationController/AnimationClip, AvatarLab) now that avatars are **a static 2D image + an ElevenLabs voice**. Keep everything the 2D product uses.

---

## 1. Why this is safe-ish — the seam already exists

There is a feature flag: `config('avatars.use_2d')` → `env('AVATAR_USE_2D', true)`. **It defaults to `true`**, so the live product already renders the 2D image path; the 3D GLB path is the dormant `false` branch. Removal = delete the `false` branch + the 3D-only subsystem, and make 2D the only path.

```
lesson/player.blade.php:27   'avatar_glb_url' => config('avatars.use_2d') ? null : ($lesson->avatar?->glbUrl())
lesson/player.blade.php:115  @if (config('avatars.use_2d') && $lesson->avatar && portraitUrl) … show image
wizard/step3,4 blades        @php $use2dAvatar = config('avatars.use_2d'); @endphp
```

## 2. Resolve these BEFORE touching code (Phase 0 decisions)

1. **Keep the viseme / Rhubarb 2D lip-sync?** `RhubarbService` generates `visemes.json` from the audio; `lipSyncPlayer` (lesson-player.blade.php) animates the image's mouth from it. This is the "talking" on the 2D image — **assume KEEP**. (Removing it = a truly static image; only do that if explicitly wanted.) Affected if kept: `RhubarbService`, `Lesson::visemes_path/visemesUrl`, `SsmlBuilder`, `lipSyncPlayer`.
2. **Keep the 3D SCENE / skybox system?** `WorldLabsService`, `resources/js/scene/SkyboxSphere.js`, the scenes WorldLabs migration — this is 3D *backgrounds/scenes*, a **separate** feature from 3D avatars. **Assume KEEP.** This plan does NOT remove it.
3. **The avatar "3D fields" columns** (`emotion_style`, `expressiveness`, `speaking_speed`, `presentation_mode`, `age`, `gender`, `frame_background`): confirm whether any feed the **ElevenLabs voice** (keep) vs are purely 3D-render (drop). `expressiveness/emotion_style/speaking_speed` likely map to voice settings → **probably keep**; `presentation_mode`/`frame_background` are render hints → can drop. Audit during Phase 3.

## 3. Inventory

### 3D-avatar-ONLY → delete wholesale
| File | Kind |
|---|---|
| `app/Livewire/Admin/AvatarLab.php` + `resources/views/livewire/admin/avatar-lab.blade.php` | FBX/clip admin |
| `app/Models/AvatarAnimationController.php`, `app/Models/AnimationClip.php` | models |
| `app/Http/Controllers/Api/AnimationControllerApi.php` | API |
| `app/Jobs/GenerateAvatarPreview3d.php`, `app/Jobs/TransferAvatarMorphs.php` | jobs |
| `app/Services/AvatarProcessorService.php` | morph/GLB processor (8 3D hits — verify nothing 2D) |
| `resources/js/avatar-3d.js`, `animation-controller.js`, `animation-alert.js`, `avatar-animator.js` | three.js front-end |
| `database/factories/AnimationClipFactory.php` | factory |
| `database/seeders/AnimationClipSeeder.php`, `AnimationLibrarySeeder.php` | seeders |
| tests: `AvatarLabUploadTest`, `AvatarLabControllerTest`, `AvatarAnimationControllerModelTest`, `GenerateAvatarPreview3dTest`, AnimationClip tests | (the currently-failing 3D tests) |

### Shared → surgical edits
| File | 3D coupling to remove |
|---|---|
| `app/Models/Avatar.php` | `glbUrl()`, `thumbnailUrl()` (3D preview), `morph_status`, `source_id`, GLB/3D casts; relationships to AnimationController |
| `app/Services/LlmService.php` | animation-tag generation (4 hits — recent "animation tag instructions" commit) |
| `app/Services/OpenAiImageService.php` | morph/3D image method (2 hits) |
| `app/Models/Scene.php` | animation/3D scene fields (4 hits — keep WorldLabs scene bits) |
| `resources/views/lesson/player.blade.php` | remove the `use_2d ? : glb` branch → always image |
| `resources/views/livewire/wizard/step3/step4 blades` | remove `$use2dAvatar` branch → always 2D |
| `resources/js/lesson-player.js`, `scene/wizard-bridge.js`, `scene/SceneTimelinePlayer.js` | 3D-avatar load/animate calls (keep scene/viseme) |
| `routes/web.php` | `avatar-lab` route |
| `routes/api.php` | `avatars.controller` route |
| `package.json` | `three`, `@gltf-transform/core` (only if no scene/skybox use remains — SkyboxSphere uses three, so **likely KEEP three**) |
| `config/avatars.php` | remove the flag (2D becomes unconditional) |

### DB (new drop migration; keep history)
- Drop tables: `animation_clips`, `avatar_animation_controllers`.
- Drop avatar columns: `morph_status`, `source_id`, GLB/3D-only fields (NOT the voice-feeding ones — see Phase 0.3).

## 4. Phased execution (each phase = own commit, app stays green)

- **Phase 0 — decisions + audit.** Resolve §2. Grep-confirm `AvatarProcessorService`/`OpenAiImageService`/`LlmService`/`Scene` 2D-vs-3D split. Confirm `three` is still needed by SkyboxSphere (keep) vs only avatar-3d (drop).
- **Phase 1 — collapse the flag.** In player + wizard blades, delete the 3D branch; render 2D unconditionally. Remove `config('avatars.use_2d')` reads. App now never touches 3D at runtime. **Verify: lesson player renders image + audio + visemes.**
- **Phase 2 — delete 3D-only files** (table §3a) + their routes. Run suite; fix dangling refs.
- **Phase 3 — surgical edits** (table §3b): strip 3D from Avatar/LlmService/OpenAiImageService/Scene/JS. Keep voice + scene + viseme paths.
- **Phase 4 — DB drop migration** for the 3D tables/columns (after §2.3 audit). Apply on Postgres.
- **Phase 5 — deps + config.** Remove `config/avatars.php` flag; drop `three`/`@gltf-transform` from package.json **only if** SkyboxSphere no longer needs them. `npm run build`.
- **Phase 6 — verify.** Zero dangling refs grep; `php artisan route:list`; full PHP suite green (the 4 currently-failing 3D tests are deleted, not fixed); Playwright lesson-player smoke (image renders, audio plays); `npm run build`.

## 5. Risks
- **Existing lessons** may have `avatar_glb_url`/clip data — harmless once the player ignores it, but the drop migration removes the rows (irreversible; `down()` recreates empty tables only).
- **`three` is shared** with the 3D scene/skybox — do NOT remove the dep unless that's gone too. (Bundle stays ~large until scenes are also addressed — separate effort.)
- **Voice-feeding columns** (`expressiveness` etc.) — dropping them would break ElevenLabs voice config. Audit first (Phase 0.3).
- The viseme path and the 3D-scene path both live in `resources/js/scene/*` — don't over-delete.

## 6. Rollback
Each phase is its own commit. The flag-collapse (Phase 1) is reversible by restoring the branch; the DB drop (Phase 4) is the only hard-to-reverse step (back up the `animation_clips` / `avatar_animation_controllers` tables first if any production data matters).

---

**Estimated size:** ~25–30 files, 1 drop migration, 1 build. Recommend executing phase-by-phase with a commit + green suite between phases.

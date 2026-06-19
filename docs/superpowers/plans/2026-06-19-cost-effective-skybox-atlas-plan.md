# Cost-effective skybox generation — revised plan (1 pass → extract → upscayl)

**Date:** 2026-06-19
**Supersedes:** `~/Downloads/claude-code-skybox-implementation.md` (per-skybox + API seam-repair) and
`~/Downloads/claude-code-cost-effective-skybox-atlas.md` (atlas). This merges them and corrects
assumptions that don't hold against the model we actually use.
**Decision wanted:** the atlas backend (see §6).

## What's already fixed (this session)
- **Upscayl works again** (`fix(scenes): point Upscayl at an installed model…`): it now uses an
  installed model (`upscayl-standard-4x`, not the missing `realesrgan-x4plus`) and a relative
  `../models` path (upscayl-bin prepends its exe dir). Verified: 40 KB → **2.9 MB / 3072×1536**.
- **Blank-overwrite guard** (`fix(scenes): never let a failed upscale blank…`): a result that isn't
  *larger* than the source is rejected, so a degraded upscale can never wipe a good image again.
- `UPSCAYL_ENABLED=true` again.

So "extract → upscayl" is now real. This plan is about the "1 pass" (atlas) part.

## Goal
Generate a lesson's scene skyboxes in **one image API call** (an atlas), **extract** each panel
locally, **upscayl** each (free, local), and store per scene — instead of one API call per scene.
Be cautious on **cost** and **quality**.

## Corrections to the source briefs (read first)

1. **`gpt-image-1` max size is `1536×1024`.** The briefs assume `2048×1024` (single) and `3840×1280`
   (atlas) and a model called `gpt-image-2`. `gpt-image-1` only accepts `1024×1024`, `1536×1024`,
   `1024×1536`, `auto` (our working config is `1536×1024`). **A 3840×1280 atlas is impossible on
   `gpt-image-1`.** Either drop to a `1536×1024` atlas (tiny tiles) or use a different backend (§6).
   *Action: confirm whether a larger-canvas OpenAI model is actually available before assuming it.*

2. **Per-image API seam-repair is the expensive part — cut it.** Brief 1's masked `/images/edits`
   seam repair is **one extra API call per scene**. You explicitly want "extract + upscayl", so the
   default pipeline does **local** seam handling only (feather + score), never an API repair call.
   Keep the repair code but gate it behind an opt-in "make this one a hero" action.

3. **`gpt-image-1` `n>1` does not save money.** Image generation is billed per output image, so
   `n=5` costs the same as 5 calls. **Only a single atlas image (5 panels in 1 image) actually saves
   cost** — because you pay for one image and crop five skyboxes out of it.

## Recommended pipeline (per lesson)

```
collect the N scene prompts for the lesson (N ≈ 3–6)
↓
build ONE atlas prompt: N panels in a strict grid, each a 2:1 equirectangular panel
↓
ONE generation call  (backend + size per §6)
↓
extract N tiles locally (GD: crop cell → resize to exact 2:1, with a small crop margin)
↓
per tile:  trim bars → crop/keep 2:1 → local edge feather (finalizeSkyboxEdges)
↓
per tile:  upscayl 2x   (now working; guard keeps the original if it ever fails)
↓
assign tile → scene.image_path (skybox), mark scene ready
```

No per-tile API seam-repair by default. Local feather + 2× upscayl is the quality pass.

## §6 — Backend decision (the one thing to pick)

| Option | Atlas canvas | Tile (2×2 grid) after upscayl 2× | Relative cost / lesson | Quality |
|---|---|---|---|---|
| **A. `gpt-image-1` atlas** | `1536×1024` (max) | 768×384 → **1536×768** | 1 OpenAI image (cheapest OpenAI) | base tiles small; upscayl recovers sharpness, not detail |
| **B. fal Flux atlas** (recommended) | e.g. `2048×1024`+ (custom ok) | 1024×512 → **2048×1024** | 1 cheap fal call (Flux schnell is ~cents) | bigger base tiles; `flux/dev` > `schnell` for detail |
| C. Per-scene `gpt-image-1` (today) | `1536×1024` each | 1536×768 → **3072×1536** | N OpenAI images | highest, but N× the cost |

**Recommendation: B (fal.ai Flux atlas).** `FalAiImageService` already supports custom `width/height`
and `num_images`, it's far cheaper than OpenAI per image, and a large canvas means the extracted
tiles are usable resolution *before* upscayl. Use `fal-ai/flux/dev` (not `schnell`) for historical
detail; keep `schnell` as the ultra-cheap preview tier. Caveat to verify: exact fal pricing and the
max canvas Flux accepts in one call.

If you'd rather stay on OpenAI for art quality, **A** works but each scene starts at 768×384 — fine
for a background behind the avatar/text, weak as a hero shot. Hybrid: atlas (cheap) for all scenes +
one optional per-scene high-quality regen for the title/hero scene.

## Integration with the lesson pipeline

Today each scene runs its own `GenerateSceneImage` (one API call each). The atlas needs a **batched**
job:

- **New:** `app/Jobs/GenerateLessonSkyboxAtlas.php` — gathers all narration/game scenes' image
  prompts, calls the atlas once, extracts + upscayls, writes each tile to its scene, marks ready.
  Dispatched once from `BuildLessonOutline` instead of N `GenerateSceneImage` dispatches.
- **Keep** `GenerateSceneImage` for single-scene **regeneration** (teacher edits one scene) and as
  the fallback when the atlas grid comes back malformed for a tile.
- New service code lives in `app/Services/Support/SkyboxImageProcessor.php` (pure GD: extract, crop,
  2:1, seam score, feather, rotate) so it's unit-testable without API calls; HTTP stays in the image
  service. (This matches Brief 1 Task 16's "preferred clean architecture".)

## Quality guards (cautious-on-quality)
- Per-tile **seam score** (local). Store anyway as a preview; log high scores. Only the opt-in hero
  path may spend an API seam-repair call.
- The **upscayl-blank guard** is already in place.
- **Grid-adherence risk** (model ignores the grid / objects bleed across panels): mitigate with a
  per-cell crop margin (~8 px), thin-gutter prompt wording, and a tile-level sanity check (reject a
  near-solid tile → fall back to one per-scene `GenerateSceneImage`).
- Historical accuracy drops when one image must render N different scenes — keep panels within the
  **same era/region/style** (a lesson already is), and put the strongest composition in the center
  third (poles distort).

## Cost shape
- Today: **N** image calls / lesson (≈5).
- Atlas: **1** image call / lesson → ~**5× fewer** image charges; upscayl is local/free.
- Hero opt-in adds **1** call only for a chosen scene.

## Phases
1. `SkyboxImageProcessor` (extract/crop/2:1/seam/feather) + unit tests on GD fixtures (no API).
2. `SkyboxAtlasPrompt` builder (panels + shared era/style + technical skybox rules).
3. Atlas call via the chosen backend (§6) → extract → feather → upscayl → store, behind a config flag.
4. `GenerateLessonSkyboxAtlas` job; wire into `BuildLessonOutline` behind `SKYBOX_ATLAS_ENABLED`.
5. Manual QA (5 historical scenes) on a Three.js inverted sphere; check seams + tile resolution.
6. Optional hero per-scene high-quality regen + opt-in API seam-repair.

## Out of scope (next, per your message)
Script quality and using the wiki / corpus / history data to ground scenes — separate effort after
the skybox pipeline lands.

## Decision (resolved 2026-06-19)
**Backend: fal.ai Flux (`flux/dev`)** — option B. `FalAiImageService` already exists; needs a custom
atlas size + `flux/dev` model wired in. First build step: prototype one real atlas (1 fal call) →
extract → feather → upscayl 2× → check tiles on the sphere, before wiring `GenerateLessonSkyboxAtlas`.
Knobs to set during the prototype: Flux canvas (2:1-friendly grid, e.g. `2048×1024` for a 2×2 of
1024×512 tiles), steps, and the per-style upscayl model.

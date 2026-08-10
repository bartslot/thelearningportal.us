# Adaptive quality for the globe

Branch `quality-tiers`, module `resources/js/timemap/quality/`. Nothing here is wired into
`/timemap` — that is deferred for the whole programme.

Six sessions are each building toward a top tier: framebuffer bloom on the sun, a large sky
panorama, streamed relief, a depth-driven ocean, a tile pyramid, real orbital mechanics. The target
device is a school Chromebook. This module is what decides what that Chromebook gets, and it treats
that as a feature rather than as damage control.

## The failure this exists to prevent

A texture larger than `MAX_TEXTURE_SIZE` does not degrade. It fails to bind, draws nothing, and
raises no error. On a 4096-limit Chromebook an 8192 sky panorama is a black sky — and on every
machine any of us reviews it on, it looks perfect.

So the ceiling is not consulted after the fact. Every texture dimension in the tier table is a
*request*, and `planForTier` puts each one through `fitTexture` against the device's real limit
before a layer ever sees it. `layer.clamped` says it happened, so a layer can tell "reduced" from
"as asked". The invariant is asserted across the whole profile × tier matrix in
`__tests__/tiers.test.js` rather than claimed here.

## Two axes, not one

A tier says what the **device** can take. A surface says what the **place it is embedded in** can
afford. Identical hardware gets a different plan on the Time-Map, inside a lesson, and behind a
landing hero, and the difference is bytes over the network, not anything about the GPU.

```js
const plan = resolveQuality({ profile, surface: 'timemap' })
```

| surface | asset budget | single asset | notes |
|---|---:|---:|---|
| `timemap` | 8 MB | 4 MB | all layers |
| `lessonMap` | **0** | 1 MB | 1.5 MB per lesson that opts in |
| `landingHero` | 1 MB | 768 KB | after first paint |

A lesson that has not opted in gets `plan.enabled === false`, every layer off, and
`estimateAssetBytes(plan) === 0`. That is the zero-cost surface, and it is a test, not a promise.

A host that is not one of these three passes its own `{ assetBudgetBytes, maxSingleAssetBytes }`.
Nothing in the module knows what a lesson is.

## The tiers

`high` / `standard` / `minimal`, plus `video`. `TIERS` in `tiers.js` is the whole policy as one
data structure — read it rather than this table, which will drift.

| | high | standard | minimal | video |
|---|---|---|---|---|
| target | 60 fps | 60 fps | 30 fps | — |
| max DPR | 2 | 1.5 | 1 | 1 |
| VRAM budget | 256 MB | 96 MB | 32 MB | 0 |
| pyramid budget | **96 MB** | 40 MB | off | off |
| sky | 8192 global | 4096 | 2048 | — |
| sun | bloom + framebuffer + god rays | bloom only | flat sprite | — |
| relief | pyramid, self + cloud shadow | pyramid, self shadow | 1024 global, no shadow | — |
| ocean | pyramid, depth + specular + waves | pyramid, depth + specular | flat tint | — |
| clouds | volumetric + shadows | flat, drifting | off | — |
| orbits | Kepler + trails | Kepler | Kepler | — |

**The memory budget is now per tier.** It was one flat 96 MB. `high` keeps exactly that number, so
nothing regresses for the session that measured against it; the other tiers get their own.

Layers routed to the `pyramid` cost no global texture at all, which is most of why the top tier fits
its budget. Where the pyramid cannot run, they fall back to a global field automatically and
`plan.pyramid.enabled` goes false.

## Detection

`readCapabilities({ gl })` reads once, per context, and never throws — a driver can raise out of
`getParameter` on a lost context, and an exception there would take down the map on the device least
able to afford it. Failures land in `profile.errors`.

It reads `MAX_TEXTURE_SIZE`, WebGL version, texture-array support, extensions, `deviceMemory`,
`hardwareConcurrency`, DPR, viewport, reduced-motion and save-data.

Two decisions worth stating:

- **`deviceMemory` absent means unknown, never small.** Safari does not implement it. A rule that
  coerced it to a number would put every iPad and every Mac on the tier meant for 2 GB Android
  phones. The fixtures pin this.
- **No sniffing.** The renderer string is captured and marked `rendererIsAdvisory`. It is not an
  input to any rule. `selectTier` decides from numbers and from a measurement.

Every rule that can lower the tier is one entry in a single `RULES` list, and every downgrade is
recorded: `decision.reasons` names the rule, the tier before and the tier after. A plan that looks
wrong can be asked why.

## The probe, and what is not yet true about it

`runRenderProbe({ gl })` draws a deliberately fragment-bound shader into a 128² offscreen target and
times it, with a `readPixels` after each draw to drain the pipeline — timing without that measures
the cost of queueing work, not of doing it. It is bounded by frame count *and* by a wall-clock
ceiling, because the device where the measurement matters most is the one where it would take
longest.

It is what catches a software rasteriser. `SOFTWARE_RENDERER` in the fixtures reports WebGL2, 8192
textures, texture arrays and 8 GB, and takes 180 ms a frame. Every static signal calls it capable.

**The probe-to-frame-time constant is not calibrated.** Turning a 128² probe reading into an
estimate of a real frame needs a factor this repo has no measurement for; calibrating it means
paired readings — probe against real `render` timings — on GPU-backed hardware in at least two
classes of device, and figures from a software rasteriser are meaningless for it.

Rather than pretend, `estimateFrameMs` reports `calibrated: false`, and `tiers.js` refuses to make a
fine-grained judgement from an uncalibrated reading: it may only trigger the extreme "this device
cannot render at all" rule, at a threshold that survives the constant being wrong by an order of
magnitude. An unverified number quietly moving every device down a tier would be a worse failure
than not probing. There is a test for exactly this.

## A profile is allowed to say it learned nothing

The browser pane runs pages hidden: no animation frames at all, timers clamped to roughly one a
second, `innerWidth` of 0. None of it errors, which is what makes it dangerous for a module whose
whole job is reading what a device can do.

Nothing here was measured in the pane — tier selection runs against synthetic profiles and the GL
modules against a fake context that counts what it hands out. But the exposure was worth closing
properly, because the bad outcome is not a crash. Read under pane conditions, an earlier version of
this module returned tier `high` with `reasons: []`: perfectly reasonable-looking output meaning
"this device is excellent", when the truth was "we learned nothing."

So a profile now says which it is:

- `profile.measurable` is false when the document is hidden or the viewport has zero area.
- The controller **skips the probe entirely** in that state rather than running it and quietly
  believing the result.
- `plan.measured` says whether any measurement backed the tier. A tier from static signals alone is
  a reasonable guess; reporting it identically to a measured one is how a reading taken somewhere
  meaningless gets quoted later as a tested device.

**The static values are still read and still enforced.** A driver's texture limit is a fact whether
or not anything is on screen, and it is the load-bearing check — discarding it because timing is
unavailable would throw away the part that matters most. There is a test that a plan built in an
unmeasurable environment still clamps an 8192 sky to a 4096 Chromebook.

For anyone who does need to render in the pane: the fix is a MessageChannel shim carrying the frame
callbacks, and it must be an **inline classic script before the module tag** — ES module imports are
hoisted, so a shim at the top of a module body installs after MapLibre has already captured the
original `requestAnimationFrame` and does nothing at all. Working version in
`resources/js/timemap/tiles/__harness__/index.html` on branch `tiled-lod`. Resize the pane too, or
`innerWidth` stays 0.

`MAX_TEXTURE_SIZE` in that pane reads 16384. It says nothing whatever about a Chromebook.

## Runtime step-down

Startup detection is a guess about the next ten minutes. `createFrameMonitor` watches what actually
happens, and its design is dominated by one rule: **a tier that oscillates is worse than one that is
simply too low.**

- Stepping down needs a sustained window (2 s by default). Stepping back up needs five times as
  much.
- **By default it steps down once, and then stops.** Going further is a decision for a fresh session
  with fresh evidence, not something to slide into while a class is watching. A host that wants more
  passes `maxStepDowns`.
- Frames over `stallMs` are dropped, not counted. A backgrounded tab produces one multi-second
  frame, and reading that as slow rendering would step every device down the moment someone switched
  tabs.
- The first 30 frames are ignored: they contain shader compilation, which is slow everywhere.
- Runtime measurement stops at `minimal`. Dropping to the video loop is a startup decision, never
  something the system slides into mid-lesson.

The monitor emits an *intent* and applies nothing. Whether it is safe to change quality right now —
mid-flight, mid-tour, mid-transition — is the host's question, and the controller holds the decision
until `isAnimating()` says no. That is how a step-down avoids popping. The newest decision wins: one
that waited out a two-minute flight is describing a moment that has passed.

## The video fallback

For a device that cannot run the shaders at all, a pre-rendered loop.

**The footage already exists.** `tools/cloud-capture/` drives the real scene frame by frame in
headless Chromium and encodes two ways; what it never had was a playback half. This is that half,
and nothing in the capture pipeline was rewritten.

Three strategies, chosen from what the browser says it can decode:

| | source | needs | where |
|---|---|---|---|
| `native` | VP9 + alpha, WebM | nothing | Chrome, Firefox — so, the Chromebooks |
| `packed` | colour over alpha in H.264 | a two-line shader | everywhere, including iOS |
| `none` | opaque H.264 | nothing | last resort, no WebGL at all |

Packing exists because VP9-with-alpha does not play on Safari or iOS and this product ships an iOS
PWA. The one non-obvious rule: **never choose `packed` on a device with no WebGL.** Without the
unpacking shader it draws the picture with a greyscale copy of itself stacked underneath, which is a
worse failure than no transparency at all.

Nothing is fetched until `start()` — the element is created with `preload="none"` and no `src`. The
video is muted unconditionally and the option to unmute it does not exist.

### One thing the capture pipeline still owes this

There is no opaque encode. `capture.mjs` emits `cloud-dive.packed.mp4` and `cloud-dive.alpha.webm`,
and the `none` strategy needs a third output: the same frames composited over the page background,
no alpha. It is one more `ffmpeg` invocation in a file another session owns, so it is written down
here rather than reached into.

## Measured

### Bundle

The gate is a number, so:

| | minified | gzipped | when it loads |
|---|---:|---:|---|
| core (`index.js` and everything data-shaped) | 17,602 B | **6,497 B** | with the host |
| `video-fallback.js` | 1,669 B | **896 B** | video tier only |
| `video-unpack.js` | 2,702 B | **1,383 B** | packed-alpha path only |

Three chunks, verified by building with `esbuild --splitting`. The DOM-touching code and the
unpacking shader are behind dynamic imports and are not in the core chunk — asserted in
`controller.test.js`, not just arranged.

**Delta to the app bundle today: 0 bytes.** `npm run build` produces 8,210,379 bytes of JS and CSS
with this module present and 8,210,379 with the directory deleted — nothing imports it yet, because
integration is deferred. The 6,497 B figure above is what it will cost when something does.

### Tests

`npx vitest run resources/js/timemap/quality` — 118 tests, 8 files.

| | statements | branches | functions | lines |
|---|---:|---:|---:|---:|
| module | 92.4% | 81.8% | 88.2% | 94.7% |

Tier selection is tested against **synthetic capability profiles** (`__fixtures__/profiles.js`), not
against whatever machine the suite runs on. A test asserting "this laptop gets `high`" passes on the
laptop and says nothing about the Chromebook, which is the only device the decision matters for.

The GL modules run against a fake context that tracks every object it hands out, so the tests can
assert what is otherwise invisible: that a probe running on every page load frees its framebuffer,
on the success path and on all three failure paths.

Two mutations, run to check the tests bite rather than merely pass:

| mutation | caught by |
|---|---|
| remove the `fitTexture` clamp from `planForTier` | 2 tests |
| remove the step-down budget from the monitor | 6 tests |

No fps figures are quoted. Real ones need a GPU-backed run; SwiftShader numbers would be
meaningless and are not reported as though they were.

## Where the boundary with the tile pyramid sits

Agreed rather than assumed, because both sessions read the same signals.

**The pyramid owns** whether it *can* run — its own `texStorage3D` check behind `pyramid.supported`
— and how it spends what it is given.

**This module owns** whether it *should*, and how much. The plan carries
`{ enabled, budgetBytes, maxTiles, targetPixelsPerTexel }` to pass straight into
`createTilePyramid`. Detection reads `webglVersion === 2` as `profile.textureArrays` because a plan
has to be made before a pyramid exists, but that is an advance signal, not a second opinion.

**Where they disagree, the pyramid wins** — it is holding the real context. The host should
re-resolve the plan with the pyramid off rather than trusting the prediction.

## For the other five layer sessions

Your layer receives `plan.layers.<name>`:

```js
{
  enabled, source,            // 'pyramid' | 'global' | 'procedural' | 'none'
  textureSize, textureHeight, // already clamped to this device
  clamped,                    // true if you asked for more than it can bind
  requestedTextureSize,
  assetBudgetBytes,           // compressed bytes you may not exceed
  effects: { ... },           // per-tier switches, motion effects off under reduced-motion
}
```

`assetBudgetBytes` is a ceiling handed to you, not a measurement of a file that exists. If your
layer cannot hit its number, say so and it gets a different one. A layer that quietly ships double
is the failure the table exists to make visible.

## Open questions for the PM

1. **The probe constant.** Calibrating it needs paired probe-and-render readings on real GPUs. I can
   build the harness; it needs a GPU-backed run, which this environment cannot give. Until then the
   probe only catches the extreme case, which is the useful half.
2. **The opaque encode.** One more `ffmpeg` output in `tools/cloud-capture/capture.mjs`, owned by
   another session. Worth relaying rather than my editing their file.
3. **Asset ceilings are estimates.** The per-layer bytes-per-pixel figures in `tiers.js` are
   budgeted ceilings, not measured files. Every layer owner should push back on their number now
   rather than discover it at integration.
4. **`maxStepDowns` defaults to 1.** One automatic downgrade per session, deliberately. If a
   Chromebook in a real classroom needs two, that is a policy change and should be a decision, not a
   default.

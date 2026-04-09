# 3D Avatar Lab — Design Spec
**Date:** 2026-04-08
**Status:** Approved
**Scope:** Workflow 1 of 2 — Three.js runtime pipeline (character creation in Blender is a separate spec)

---

## Overview

Add a parallel 3D avatar pipeline alongside the existing 2D sprite system. The goal is to showcase professional-quality talking avatars that morph the face rather than overlaying static sprites. This is an admin-only "Lab" for now — a testing ground to compare quality before 3D is promoted to production lessons.

**What stays untouched:** The existing 2D pipeline (edge-tts → amplitude manifest → canvas sprite animator) continues working exactly as before.

**What changes minimally:** The `AvatarStudio` Greeting tab is removed; its settings move into the Settings tab. The `LlmService` system prompt gains emotion tag instructions.

---

## Tech Decisions

| Concern | Decision | Reason |
|---|---|---|
| TTS + lip sync data | Azure Speech Service (free tier) | Returns audio AND 55 ARKit blend shape weights at 60 FPS in one call |
| 3D runtime | Three.js (already installed, v0.183) | No new dependency; proven in existing portal animation |
| Character format | MPFB glTF/GLB export | Full rigify rig + morph targets; native Blender export |
| Emotion delivery | Azure SSML `<mstts:express-as>` | Neural model's own emotional prosody drives both voice and face |
| Lip sync approach | Morph target interpolation (55 blend shapes) | Industry standard; same as Apple ARKit / Ready Player Me |
| Architecture | Parallel modules, no shared state with 2D | Zero risk to existing pipeline during 3D testing phase |

---

## Architecture

### New Backend Pieces

```
app/Services/SsmlBuilder.php          — parses [emotion] tags → Azure SSML
app/Services/AzureTtsService.php      — calls Azure REST API, stores audio + blendshapes
app/Jobs/GenerateAvatarPreview3d.php  — queued job: SsmlBuilder → AzureTtsService → notify
app/Livewire/Admin/AvatarLab.php      — settings panel + preview dispatch + status polling
```

### New Frontend Pieces

```
resources/js/avatar-3d.js                              — Three.js player module
resources/views/admin/avatar-lab.blade.php             — page wrapper
resources/views/livewire/admin/avatar-lab.blade.php    — settings sidebar + canvas
```

### Modified (minimal)

```
app/Livewire/Admin/AvatarStudio.php                    — remove Greeting tab
resources/views/livewire/admin/avatar-studio.blade.php — remove Greeting tab view
app/Services/LlmService.php                            — add emotion tag instructions to prompt
resources/views/layouts/admin.blade.php                — add "3D Lab" nav entry
```

### File Storage

```
storage/app/lessons/{lesson_id}/audio.mp3           ← existing (2D, untouched)
storage/app/lessons/{lesson_id}/audio_3d.mp3        ← new (Azure Neural TTS)
storage/app/lessons/{lesson_id}/blendshapes.json    ← new (55-weight frames at 60fps)

public/avatars/{avatar_id}/character.glb            ← new (MPFB export, manually uploaded)
public/avatars/{avatar_id}/morph-map.json           ← new (MPFB name → Azure index mapping)
```

**GLB upload mechanism (Lab phase):** For the testing phase, `.glb` and `morph-map.json` are placed manually into `public/avatars/{id}/` by the admin (e.g. via direct file copy or `php artisan tinker`). The Avatar Lab UI detects the presence of `character.glb` and shows "No 3D character uploaded yet" if missing. A proper upload UI is deferred to the production promotion phase.

---

## Database

### Migration 1 — `avatars` table

```php
$table->enum('presentation_mode', ['fullscreen', 'framed', 'hidden'])->default('framed')->after('portrait_path');
$table->tinyInteger('age')->unsigned()->default(35)->after('presentation_mode');
$table->enum('gender', ['male', 'female'])->default('male')->after('age');
$table->enum('emotion_style', ['auto', 'narrative', 'cheerful', 'serious', 'excited', 'empathetic', 'whispering'])->default('auto')->after('gender');
$table->decimal('expressiveness', 3, 2)->default(1.20)->after('emotion_style');
$table->decimal('speaking_speed', 3, 2)->default(1.00)->after('expressiveness');
$table->string('frame_background', 7)->default('#0f172a')->after('speaking_speed');
```

### Migration 2 — `lesson_media` table

```php
$table->string('audio_3d_path')->nullable()->after('audio_path');
$table->string('blendshapes_path')->nullable()->after('audio_3d_path');
```

---

## Backend Pipeline

### 1. SsmlBuilder

Single responsibility: convert a raw script string + Avatar settings into valid Azure SSML.

```
Input:  string $script, Avatar $avatar
Output: string $ssml

Steps:
1. Split script on [emotion]…[/emotion] boundaries via regex
2. For tagged segments: wrap in <mstts:express-as style="{emotion}" styledegree="{avatar->expressiveness}">
3. For untagged segments: no wrapper (Azure delivers natural/narrative tone)
4. Wrap everything in <prosody rate="{avatar->speaking_speed}">
5. Wrap in <voice name="{resolveVoice($avatar)}">
6. resolveVoice(): looks up gender × age-band in a static table:
     male   + age  8–17  → en-US-BrandonNeural
     male   + age 18–60  → en-US-GuyNeural
     male   + age 61–80  → en-US-RogerNeural
     female + age  8–17  → en-US-AnaNeural
     female + age 18–60  → en-US-JennyNeural
     female + age 61–80  → en-US-NancyNeural
```

If `emotion_style` is not `auto`, skip tag parsing and wrap the entire script in a single `<mstts:express-as>` block with the chosen style.

### 2. AzureTtsService

**Important:** Azure's 3D blend shapes are only accessible via the Azure Speech **SDK**, not the plain REST API (blend shapes arrive as streamed `VisemeReceived` events, not in the HTTP response body). PHP has no Azure Speech SDK. The implementation therefore shells out to a small Python script — the same pattern used by `AvatarService` for SadTalker.

```
synthesize(string $ssml, string $storagePrefix): array

1. Write $ssml to a temp file
2. Shell out to: python3 scripts/azure_tts.py \
       --ssml /tmp/ssml.xml \
       --key  {AZURE_SPEECH_KEY} \
       --region {AZURE_SPEECH_REGION} \
       --audio-out storage/app/{storagePrefix}/audio_3d.mp3 \
       --shapes-out storage/app/{storagePrefix}/blendshapes.json

scripts/azure_tts.py (Python, uses azure-cognitiveservices-speech SDK):
   - Subscribes to synthesizer.viseme_received
   - Each event: evt.animation is a JSON string:
       { "FrameIndex": N, "BlendShapes": [[55 floats], ...] }
   - Collects all events during synthesis
   - Flattens to: { fps: 60, duration: X, frames: [{ t: float, w: [55 floats] }] }
       frame_time = (FrameIndex + frame_offset) / 60.0
   - Writes audio_3d.mp3 and blendshapes.json to the given paths
   - Exits 0 on success, 1 on failure (stderr = error message)

3. PHP checks exit code; on failure throws AzureTtsException(stderr)
4. Return: ['audio_3d_path' => '...', 'blendshapes_path' => '...']
```

**Python dependency:** `pip install azure-cognitiveservices-speech` — add to `requirements.txt` alongside SadTalker dependencies.

**Error handling:** If the Python script exits non-zero, throw `AzureTtsException`. The job catches this, marks preview status `failed`, stores the error message. No fallback to 2D TTS — the Lab is explicitly a 3D-only space.

**Free tier limit:** Azure F0 gives 500K characters/month for neural TTS. Each lesson script is ~1,500–3,000 characters. Roughly 150–330 lessons/month before cost. Log character usage per call.

### 3. GenerateAvatarPreview3d Job

```php
class GenerateAvatarPreview3d implements ShouldQueue
{
    public int $tries = 2;

    public function __construct(
        public readonly int $avatarId,
        public readonly string $script,
        public readonly ?int $lessonId = null,
    ) {}

    public function handle(SsmlBuilder $builder, AzureTtsService $azure): void
    {
        $avatar = Avatar::findOrFail($this->avatarId);
        $ssml   = $builder->build($this->script, $avatar);
        $prefix = "lessons/{$this->lessonId ?? 'previews/' . $this->avatarId}";
        $paths  = $azure->synthesize($ssml, $prefix);

        if ($this->lessonId) {
            LessonMedia::updateOrCreate(
                ['lesson_id' => $this->lessonId],
                $paths,
            );
        }

        // Notify Livewire component via cache flag
        Cache::put("avatar3d_preview_{$this->avatarId}", [
            'status'          => 'ready',
            'audio_url'       => Storage::url($paths['audio_3d_path']),
            'blendshapes_url' => Storage::url($paths['blendshapes_path']),
        ], now()->addMinutes(30));
    }

    public function failed(\Throwable $e): void
    {
        Cache::put("avatar3d_preview_{$this->avatarId}", [
            'status' => 'failed',
            'error'  => $e->getMessage(),
        ], now()->addMinutes(10));
    }
}
```

---

## AvatarLab Livewire Component

### Properties

```php
public ?int    $avatarId         = null;
public string  $presentationMode = 'framed';
public int     $age              = 35;
public string  $gender           = 'male';
public string  $emotionStyle     = 'auto';
public float   $expressiveness   = 1.2;
public float   $speakingSpeed    = 1.0;
public string  $frameBackground  = '#0f172a';
public string  $testScript       = '';
public string  $previewStatus    = 'idle'; // idle | generating | ready | error
public ?string $previewAudioUrl       = null;
public ?string $previewBlendShapesUrl = null;
public ?string $previewError          = null;
```

### Actions

**`generatePreview()`**
- Validates: `$testScript` not empty, `$avatarId` set
- Sets `$previewStatus = 'generating'`
- Dispatches `GenerateAvatarPreview3d::dispatch($this->avatarId, $this->testScript)`
- Enables `wire:poll.2s` by setting a flag

**`pollPreviewStatus()`** (called by wire:poll.2s while generating)
- Reads `Cache::get("avatar3d_preview_{$this->avatarId}")`
- On `ready`: sets `$previewAudioUrl`, `$previewBlendShapesUrl`, `$previewStatus = 'ready'`, dispatches `avatar3d:previewReady` JS event, stops polling
- On `failed`: sets `$previewStatus = 'error'`, `$previewError`, stops polling

**`saveSettings()`**
- Persists all settings to `Avatar::find($this->avatarId)` via mass assignment
- Flash success message

**`switchMode(string $mode)`** (2d | 3d)
- Dispatches `avatar3d:switchMode` JS event — JS swaps canvas visibility

---

## avatar-3d.js

```javascript
export class Avatar3DPlayer {
    #scene; #camera; #renderer; #mesh;
    #morphMap;     // { azureIndex: morphTargetIndex }
    #frames = [];  // [{ t, w:[55] }]
    #audio;
    #playing = false;
    #idleTimer;

    constructor(canvasEl, { characterUrl, morphMapUrl, presentationMode, frameBackground })

    async init()
    // THREE.WebGLRenderer on canvasEl
    // GLTFLoader → loads character.glb
    // fetch morph-map.json → builds #morphMap
    // Lights: AmbientLight(0xffffff, 0.6) + DirectionalLight(0xffffff, 0.8)
    // Camera: PerspectiveCamera(45°, aspect, 0.1, 100) positioned for bust shot
    // Starts #idleLoop()

    async loadPreview(audioUrl, blendShapesUrl)
    // fetch blendShapesUrl → #frames
    // new Audio(audioUrl) → #audio

    play()   // #audio.play(), #playing = true
    pause()  // #audio.pause(), #playing = false

    #animationLoop(timestamp)
    // If #playing:
    //   t = #audio.currentTime
    //   frameIdx = binarySearch(#frames, t)
    //   lerp weights between frame[frameIdx] and frame[frameIdx+1]
    //   apply to #mesh.morphTargetInfluences via #morphMap
    // Always: #idlePass() — breathing scale + blink

    #idlePass()
    // Breathing: Math.sin(t * 0.8) * 0.012 scale on Y axis
    // Blink: random interval 2–5s, 120ms close, sets eyeBlinkLeft/Right weights

    destroy()
    // cancelAnimationFrame, renderer.dispose(), geometry/material dispose
}

// Alpine / Livewire glue
window.addEventListener('avatar3d:previewReady', ({ detail }) => {
    window._avatar3d?.loadPreview(detail.audioUrl, detail.blendShapesUrl)
})
window.addEventListener('avatar3d:switchMode', ({ detail }) => { /* swap canvas */ })
```

### morph-map.json format

```json
{
  "eyeBlinkLeft":    0,
  "eyeLookDownLeft": 1,
  "jawOpen":        17,
  "mouthSmileLeft": 23,
  ...
}
```

Keys are Azure's 55 blend shape names (0-indexed position). Values are the corresponding `morphTargetDictionary` index in the loaded MPFB glTF. This file is generated once per character during the Blender export workflow (separate spec).

---

## Avatar Studio Changes

### Greeting Tab — removed

The Greeting tab currently holds: greeting script textarea, voice provider selector, voice name selector, generate greeting button, audio preview player.

These move into the **Settings tab** under a new "Greeting" section header. No functionality is removed — just relocated.

The `AvatarStudio` Livewire component loses the `activeTab === 'greeting'` branch. The blade template loses the Greeting tab button and panel.

---

## LlmService Prompt Addition

Add to the system prompt in `LlmService::generateScript()`:

```
Where it genuinely improves the story, wrap sentences in emotion tags to guide
delivery. Available tags: [serious], [cheerful], [excited], [empathetic],
[whispering], [narrative]. Close each with [/tag]. Use sparingly —
untagged text is delivered in a natural narrative tone. Never invent tags
not listed here.
```

---

## Admin Navigation

Add to the admin sidebar:

```blade
<x-nav-item route="admin.avatar-lab" icon="beaker">
    3D Lab
    <x-badge>Experimental</x-badge>
</x-nav-item>
```

Route definition:

```php
Route::get('/admin/avatar-lab', AvatarLab::class)
    ->name('admin.avatar-lab')
    ->middleware(['auth', 'role:admin']);
```

---

## Environment Variables

```env
AZURE_SPEECH_KEY=
AZURE_SPEECH_REGION=eastus
```

Add to `config/services.php`:

```php
'azure_speech' => [
    'key'    => env('AZURE_SPEECH_KEY'),
    'region' => env('AZURE_SPEECH_REGION', 'eastus'),
    'url'    => env('AZURE_SPEECH_REGION', 'eastus') . '.tts.speech.microsoft.com',
],
```

---

## Error Handling Summary

| Failure point | Behaviour |
|---|---|
| Azure API non-200 | Job fails, `AzureTtsException` logged, Lab shows error message with Azure status code |
| Azure 429 rate limit | Job retried after 60s (Laravel `$retryAfter = 60`) |
| Missing morph target in glTF | Warning logged, that blend shape index skipped silently |
| `.glb` not found for avatar | Lab shows "No 3D character uploaded yet" with upload instructions |
| `blendshapes.json` missing | Player falls back to idle animation only, no lip sync |

---

## Out of Scope (this spec)

- Blender + MPFB character creation workflow (separate spec)
- morph-map.json generation tooling (part of Blender workflow spec)
- Flutter / student-facing 3D playback
- Multiple simultaneous 3D avatars per lesson
- Real-time streaming (pre-rendered assets only in v1)
- SadTalker / fal.ai video generation (existing 2D path, unchanged)

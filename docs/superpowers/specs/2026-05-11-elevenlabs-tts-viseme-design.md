# ElevenLabs TTS + Viseme Fix — Design Spec
**Date:** 2026-05-11  
**Scope:** Learning Portal (`thelearningportal.us`) + TTS Playground (`ttsplayground`)

---

## Problem

1. **Viseme quality**: mouth movements on the Three.js avatar are robotic. Root cause is the timing source — `lipSyncLocal.js` distributes phoneme durations by estimation (text length / total audio duration). Timing is wrong even when morph target names resolve correctly via `visemeAliases`.

2. **TTS provider gap**: ElevenLabs API key exists in `.env` but no integration. Kokoro is a local-only dependency that is being dropped.

---

## Solution Summary

Use ElevenLabs `/v1/text-to-speech/{voice_id}/with-timestamps` as the primary TTS provider. This single endpoint returns both the audio (MP3) and character-level timing data, which replaces the G2P estimator as the lip sync timing source. Pocket TTS (HF Space) becomes the fallback. Kokoro is removed entirely.

---

## Architecture

### Provider Fallback Chain (new)
```
ElevenLabs → Pocket TTS (HF Space) → edge-tts → macOS say
```
Kokoro removed from all code paths.

### New: `ElevenLabsService` (`app/Services/ElevenLabsService.php`)

```
getVoices(): array
  - GET /v1/voices
  - Cache in Laravel cache for 1 hour (cache key: 'elevenlabs_voices')
  - Returns: [voice_id => label_string]
  - label_string built from: "{name} · {accent} {gender}" from voice.labels
  - Also returns: gradient_class per voice (derived from labels, see UI section)

generateWithTimestamps(string $text, string $voiceId, float $stability = 0.5, float $similarity = 0.75): ?array
  - POST /v1/text-to-speech/{voiceId}/with-timestamps
  - Body: {text, model_id: 'eleven_multilingual_v2', voice_settings: {stability, similarity_boost}}
  - Returns: ['audio' => <binary mp3>, 'alignment' => ['characters'=>[], 'character_start_times_seconds'=>[], 'character_end_times_seconds'=>[]]]
  - Returns null on failure

getVoicePreviewUrl(string $voiceId): ?string
  - Returns preview_url from cached voice list (no extra API call)
  - Used by the voice picker to play ElevenLabs' own built-in sample
```

Config binding in `config/services.php`:
```php
'elevenlabs' => [
    'api_key' => env('ELEVENLABS_API_KEY'),
    'base_url' => 'https://api.elevenlabs.io',
],
```

### Updated: `TtsService` (`app/Services/TtsService.php`)

**Remove:**
- `tryKokoro()` method
- `ensureKokoroRunning()`, `isLocalKokoroUrl()`, `kokoroPackageInstalled()`, `kokoroStartCommand()`, `$kokoroStartAttempted` static property
- `KOKORO_START_CMD` env references

**Add:**
- `tryElevenLabs(string $text, string $voiceId, ?array &$characterTimings = null): ?string`
  - Calls `ElevenLabsService::generateWithTimestamps()`
  - Populates `$characterTimings` reference (same pattern as `$wordTimings` in `tryEdgeTts`)
- `tryPocketTts(string $text, string $voiceId = ''): ?string`
  - `POST {POCKET_TTS_URL}/tts` multipart: `{text, voice_id}`
  - Bearer auth with `HF_TOKEN` if set
  - Returns audio binary or null

**`generateAudioRaw()` provider routing:**
```
'elevenlabs' → tryElevenLabs() → tryPocketTts() → tryEdgeTts() → tryMacosTts()
'pocket_tts'  → tryPocketTts() → tryEdgeTts() → tryMacosTts()
'edge_tts'    → tryEdgeTts()   → tryPocketTts() → tryMacosTts()
auto          → tryElevenLabs() → tryPocketTts() → tryEdgeTts() → tryMacosTts()
```

**`$wordTimings` param extended:** renamed to accept either word boundaries (edge-tts format) or character alignment (ElevenLabs format). The param type stays `?array` — callers check for key presence. ElevenLabs populates `character_timings` key; edge-tts populates `word_timings` key.

### New: Pocket TTS env vars (`.env` + `.env.example`)
```env
POCKET_TTS_URL=https://barukasharlot-pocket-tts-server.hf.space
HF_TOKEN=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
```

---

## ElevenLabs Warm-up & Performance

### Singleton HTTP client
`ElevenLabsService` is registered as a **singleton** in `AppServiceProvider`. It holds a single Guzzle client instance configured with:
- `http_version: '1.1'` (keep-alive)
- `connect_timeout: 3`
- `timeout: 45` (TTS generation can take ~5–15 s for long scripts)
- `headers: ['Connection' => 'keep-alive', 'xi-api-key' => $apiKey]`

Re-using the same Guzzle client across requests within a queue worker or during a single web request avoids repeated TCP handshakes to `api.elevenlabs.io`.

### Voice list cache warming
`getVoices()` caches for 1 hour. To ensure the first admin visiting the voice picker never waits:

**`app/Console/Commands/WarmElevenLabsCache.php`** (new Artisan command):
```
php artisan elevenlabs:warm
```
- Calls `ElevenLabsService::getVoices()` — populates the cache
- Exits silently on API failure (non-fatal)
- Scheduled in `routes/console.php` every **50 minutes** (ahead of the 1-hour TTL)

```php
Schedule::command('elevenlabs:warm')->everyFiftyMinutes();
```

### Boot-time warm-up (non-blocking)
In `AppServiceProvider::boot()`, if the voices cache is cold **and** the app is not running in `testing` environment, dispatch a queued job `WarmElevenLabsJob` that calls `getVoices()`. This fires once on first deploy/restart — the voice picker card strip loads instantly for the admin thereafter.

### Request-level timeout strategy
`generateWithTimestamps()` uses a **two-phase timeout**:
1. Connect timeout: 3 s (fail fast if ElevenLabs is unreachable → fall back to Pocket TTS)
2. Transfer timeout: 45 s (generous for long narration scripts)

If the connect timeout fires, `tryElevenLabs()` returns `null` immediately and the fallback chain continues — no blocking.

### Voice preview URLs (zero-cost warm path)
Each voice in the ElevenLabs API response includes a `preview_url` (pre-generated MP3 hosted on ElevenLabs CDN). Clicking ▶ on a voice card plays this URL directly in the browser — **no API call, no quota used**. Only generating a full sample (clicking ▶ on the already-selected card) hits the API. This keeps the browse-and-preview flow instant.

---

## Data Storage

`AvatarVoiceSample.settings_snapshot` (already JSON, no migration needed) gains a new key:

```json
{
  "provider": "elevenlabs",
  "voice_id": "abc123",
  "speed": 1.0,
  "word_timings": [],
  "character_timings": {
    "characters": ["H","e","l","l","o"],
    "starts": [0.0, 0.08, 0.15, 0.19, 0.23],
    "ends":   [0.08, 0.15, 0.19, 0.23, 0.31]
  }
}
```

`AvatarVoiceSample::label()` currently calls `Avatar::kokoroVoices()` — update to use `ElevenLabsService::getVoices()` when provider is elevenlabs, else use `Avatar::edgeTtsVoices()`.

---

## Viseme Fix: Character Alignment Pipeline

### `ttsplayground` (`src/charVisemeMap.js` — new file)
```js
export const charToViseme = {
  a: 'viseme_ah', e: 'viseme_eh', i: 'viseme_iy',
  o: 'viseme_oh', u: 'viseme_uw',
  b: 'viseme_mbp', m: 'viseme_mbp', p: 'viseme_mbp',
  f: 'viseme_fv',  v: 'viseme_fv',
  l: 'viseme_l',   r: 'viseme_er',
  s: 'viseme_s',   z: 'viseme_s',
  // consonants that open the mouth (default to 'viseme_ah' at low intensity)
  t: 'viseme_tt',  d: 'viseme_tt',
  n: 'viseme_nn',  g: 'viseme_kk', k: 'viseme_kk',
  // silence / punctuation → null (no viseme)
  ' ': null, ',': null, '.': null,
};
```

### `src/useLipSyncElevenLabs.js` (new hook)
Accepts ElevenLabs alignment `{characters, starts, ends}`, builds the same `{time, duration, viseme}[]` timeline format that the existing `useFrame` lip sync loop already consumes. No changes to `Avatar.jsx`.

### `App.jsx` routing
When TTS response includes `alignment`, call `useLipSyncElevenLabs`. When not (Pocket TTS, edge-tts), fall back to existing `lipSyncLocal.js`. Condition is just presence of `alignment` key in the API response.

---

## UI: Voice Picker Card Strip

### AvatarStudio provider toggle (updated)
```
[ ★ ElevenLabs ]  [ edge-tts (free) ]  [ Pocket TTS ]
```
- ElevenLabs is default/recommended (star prefix)
- Kokoro button removed

### ElevenLabs voice card strip

Same scroll-snap container as AvatarLab animation clips:
```html
<div class="flex gap-2 pb-2 overflow-x-auto scroll-smooth"
     style="scroll-snap-type:x mandatory; scrollbar-width:none">
  <!-- per voice: -->
  <button wire:click="selectElevenLabsVoice('{{ $voice->id }}')"
          class="shrink-0 w-[72px] rounded-xl p-2 ...">
    <!-- grainy gradient background -->
    <!-- play button top-right -->
    <!-- waveform icon center -->
    <!-- name bottom, truncated -->
  </button>
</div>
```

**Grainy gradient implementation (pure CSS + SVG filter):**

SVG `<feTurbulence>` noise filter defined once in the layout (or inlined in a `<defs>` block in the component):
```html
<svg style="display:none">
  <defs>
    <filter id="grain" x="0%" y="0%" width="100%" height="100%"
            color-interpolation-filters="sRGB">
      <feTurbulence type="fractalNoise" baseFrequency="0.65" numOctaves="3"
                    stitchTiles="stitch" result="noise"/>
      <feColorMatrix type="saturate" values="0" in="noise" result="grey"/>
      <feBlend in="SourceGraphic" in2="grey" mode="overlay" result="blended"/>
      <feComponentTransfer>
        <feFuncA type="linear" slope="0.18"/>
      </feComponentTransfer>
    </filter>
  </defs>
</svg>
```

Each card gets a gradient class (assigned by `ElevenLabsService::getVoices()` based on voice labels) plus `filter: url(#grain)` on the background pseudo-element:

| Gradient class | CSS gradient | Used for |
|---|---|---|
| `vg-navy` | `#0f172a → #1e3a5f` | Male British |
| `vg-indigo` | `#1e1b4b → #312e81` | Male American/neutral |
| `vg-violet` | `#2d1b69 → #4c1d95` | Female any |
| `vg-amber` | `#1c1917 → #78350f` | Non-EN accent / character |
| `vg-teal` | `#0f2027 → #1a3a4a` | Narration / calm |
| `vg-base` | `#0f172a → #1e293b` | Fallback |

**Card states:**
- Default: gradient bg + `border-slate-700/60`
- Hover: `border-indigo-500/50`
- Selected: `border-amber-400` + amber checkmark dot (top-right, replaces play icon dot)
- Playing: `border-indigo-400` + pulsing waveform bars icon (CSS animation)
- Loading: spinner replaces play icon, card opacity 70%

**Play button behaviour:**
- Tapping ▶ on an unselected card plays ElevenLabs' built-in `preview_url` (no API cost, no sample stored). This is a direct `<audio>` element play via Alpine `$el.querySelector('audio').play()`.
- Tapping ▶ on the *selected* card generates a full sample with `generateSample()` (stored, shown in samples list below).

### edge-tts / Pocket TTS card strips
Same 72px card pattern using the static voice lists from `Avatar::edgeTtsVoices()`. Gradient classes assigned by accent (same rules, derived at build time in the static method). No live API call needed.

---

## `Avatar` model cleanup

**Remove:** `kokoroVoices()`, all Kokoro references in docblocks.

**Add:**
- `elevenlabsVoiceGradient(array $labels): string` — maps ElevenLabs voice label metadata → one of the six `vg-*` gradient class names.
- `pocketTtsVoices(): array` — short static list of known Pocket TTS preset IDs (or empty array if the HF Space exposes a `/voices` endpoint — to be confirmed at implementation time).

**Update:** `edgeTtsVoices()` gets gradient classes added per entry (same `vg-*` strings, statically assigned by accent).

---

## `.env` changes

**Remove:** `KOKORO_TTS_URL`, `KOKORO_START_CMD`

**Add:**
```env
ELEVENLABS_API_KEY=426a92ec...        # already present in .env
POCKET_TTS_URL=https://barukasharlot-pocket-tts-server.hf.space
HF_TOKEN=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
```

---

## Testing

- `ElevenLabsServiceTest`: mock HTTP, assert `getVoices()` caches, assert `generateWithTimestamps()` returns expected shape
- `TtsServiceTest`: assert elevenlabs provider hits `ElevenLabsService`, assert fallback chain order (elevenlabs → pocket_tts → edge_tts)
- `AvatarStudioTest` (feature): assert ElevenLabs provider option appears in voice tab, assert Kokoro is absent
- Manual: load AvatarStudio voice tab, confirm card strip renders with grainy gradients, confirm preview_url plays on card click, confirm sample generation stores `character_timings`

---

## Files Changed

### Learning Portal
| File | Action |
|---|---|
| `app/Services/ElevenLabsService.php` | **New** |
| `app/Services/TtsService.php` | Remove Kokoro, add ElevenLabs + PocketTTS methods |
| `app/Models/Avatar.php` | Remove `kokoroVoices()`, add gradient helper, update `edgeTtsVoices()` |
| `app/Models/AvatarVoiceSample.php` | Update `label()` to not hardcode Kokoro |
| `app/Livewire/Admin/AvatarStudio.php` | Add ElevenLabs provider, `loadElevenLabsVoices()`, `selectElevenLabsVoice()`, remove Kokoro refs |
| `resources/views/livewire/admin/avatar-studio.blade.php` | Replace select dropdown with card strip for ElevenLabs; update provider toggle |
| `app/Console/Commands/WarmElevenLabsCache.php` | **New** — `php artisan elevenlabs:warm` |
| `app/Jobs/WarmElevenLabsJob.php` | **New** — queued boot-time warm-up |
| `config/services.php` | Add elevenlabs + pocket_tts entries |
| `.env` + `.env.example` | Swap Kokoro vars for ElevenLabs + PocketTTS |
| `tests/Unit/ElevenLabsServiceTest.php` | **New** |
| `tests/Feature/AvatarStudioTest.php` | Update/extend |

### TTS Playground
| File | Action |
|---|---|
| `src/charVisemeMap.js` | **New** — character→viseme lookup |
| `src/useLipSyncElevenLabs.js` | **New** — alignment timeline builder |
| `src/App.jsx` | Route to ElevenLabs lip sync when alignment present |
| `src/lipSyncLocal.js` | Kept as fallback, no changes |

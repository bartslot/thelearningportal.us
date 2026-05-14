# ElevenLabs TTS + Viseme Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Kokoro with ElevenLabs as the primary TTS provider, add Pocket TTS as fallback, and fix robotic mouth movements by using ElevenLabs' character-level alignment data to drive Three.js visemes.

**Architecture:** `ElevenLabsService` (singleton Guzzle client, 1-hour voice cache) slots into `TtsService`'s provider chain (ElevenLabs → Pocket TTS → edge-tts → macOS say). The `/with-timestamps` endpoint returns both MP3 audio and character timing data in one call, which replaces the G2P estimator in ttsplayground. Kokoro is removed entirely.

**Tech Stack:** Laravel 12, Livewire 3, Alpine.js, Tailwind CSS, DaisyUI 5 (Learning Portal) · React 19, Vite, Three.js, @react-three/fiber (TTS Playground)

---

## File Map

### Learning Portal (`apps/thelearningportal.us/`)

| File | Status | Responsibility |
|---|---|---|
| `config/services.php` | Modify | Add elevenlabs + pocket_tts config entries, remove kokoro |
| `.env` / `.env.example` | Modify | Add ELEVENLABS_API_KEY, POCKET_TTS_URL, HF_TOKEN; remove KOKORO vars |
| `app/Services/ElevenLabsService.php` | **New** | Guzzle singleton, getVoices (cached), generateWithTimestamps, getVoicePreviewUrl |
| `app/Providers/AppServiceProvider.php` | Modify | Register ElevenLabsService as singleton; boot-time cache warm dispatch |
| `app/Console/Commands/WarmElevenLabsCache.php` | **New** | `php artisan elevenlabs:warm` — populates voice cache |
| `app/Jobs/WarmElevenLabsJob.php` | **New** | Queued job wrapping getVoices() for boot-time warm-up |
| `routes/console.php` | Modify | Schedule `elevenlabs:warm` every 50 minutes |
| `app/Services/TtsService.php` | Modify | Remove Kokoro methods, add tryElevenLabs + tryPocketTts, update routing |
| `app/Models/Avatar.php` | Modify | Remove kokoroVoices(), add gradient helper, add edgeTtsVoicesForCards() + pocketTtsVoices() |
| `app/Models/AvatarVoiceSample.php` | Modify | Update label() to read voice_label from settings_snapshot |
| `app/Livewire/Admin/AvatarStudio.php` | Modify | Update voices() computed, defaults, add selectElevenLabsVoice(), remove Kokoro refs |
| `resources/views/livewire/admin/avatar-studio.blade.php` | Modify | Provider toggle (3 buttons), SVG grain filter, card strip replacing select |
| `tests/Unit/ElevenLabsServiceTest.php` | **New** | Mock HTTP tests for getVoices caching + generateWithTimestamps shape |
| `tests/Unit/TtsServiceTest.php` | Modify | Add coverage for elevenlabs + pocket_tts provider routing |

### TTS Playground (`apps/ttsplayground/`)

| File | Status | Responsibility |
|---|---|---|
| `src/charVisemeMap.js` | **New** | Character → viseme lookup table |
| `src/useLipSyncElevenLabs.js` | **New** | Build `{time, duration, viseme}[]` from ElevenLabs character alignment |
| `src/App.jsx` | Modify | Route to ElevenLabs alignment when present, fallback to local G2P |

---

## Task 1: Config + Env

**Files:**
- Modify: `config/services.php`
- Modify: `.env`
- Modify: `.env.example`

- [ ] **Step 1: Update `config/services.php`**

Open `config/services.php`. Replace the `'kokoro'` block and add elevenlabs + update pocket_tts:

```php
// Replace:
'kokoro' => [
    'url' => env('KOKORO_TTS_URL', 'http://localhost:8880'),
],

// With:
'elevenlabs' => [
    'api_key'  => env('ELEVENLABS_API_KEY'),
    'base_url' => 'https://api.elevenlabs.io',
],

'pocket_tts' => [
    'url'      => env('POCKET_TTS_URL', 'https://barukasharlot-pocket-tts-server.hf.space'),
    'hf_token' => env('HF_TOKEN'),
],
```

- [ ] **Step 2: Update `.env` and `.env.example`**

In `.env`, remove `KOKORO_TTS_URL` and add (ELEVENLABS_API_KEY already exists — skip if present):
```env
POCKET_TTS_URL=https://barukasharlot-pocket-tts-server.hf.space
HF_TOKEN=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
```

In `.env.example`, replace:
```env
# Remove: KOKORO_TTS_URL=http://localhost:8880

# Add:
ELEVENLABS_API_KEY=
POCKET_TTS_URL=https://barukasharlot-pocket-tts-server.hf.space
HF_TOKEN=
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "config: swap Kokoro for ElevenLabs + Pocket TTS service entries"
```

---

## Task 2: ElevenLabsService

**Files:**
- Create: `app/Services/ElevenLabsService.php`
- Create: `tests/Unit/ElevenLabsServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ElevenLabsServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ElevenLabsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsServiceTest extends TestCase
{
    public function test_get_voices_returns_mapped_array(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    [
                        'voice_id'    => 'abc123',
                        'name'        => 'Rachel',
                        'preview_url' => 'https://cdn.elevenlabs.io/rachel.mp3',
                        'labels'      => ['accent' => 'american', 'gender' => 'female'],
                    ],
                ],
            ], 200),
        ]);

        Cache::forget('elevenlabs_voices');
        $service = app(ElevenLabsService::class);
        $voices  = $service->getVoices();

        $this->assertCount(1, $voices);
        $this->assertSame('abc123', $voices[0]['id']);
        $this->assertSame('Rachel · american female', $voices[0]['label']);
        $this->assertSame('https://cdn.elevenlabs.io/rachel.mp3', $voices[0]['preview_url']);
        $this->assertStringStartsWith('vg-', $voices[0]['gradient_class']);
    }

    public function test_get_voices_caches_result(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response(['voices' => []], 200),
        ]);

        Cache::forget('elevenlabs_voices');
        $service = app(ElevenLabsService::class);
        $service->getVoices();
        $service->getVoices(); // second call — should NOT make a second HTTP request

        Http::assertSentCount(1);
    }

    public function test_generate_with_timestamps_returns_audio_and_alignment(): void
    {
        $fakeAudio = str_repeat('x', 500);

        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*/with-timestamps' => Http::response([
                'audio_base64'    => base64_encode($fakeAudio),
                'alignment'       => [
                    'characters'                    => ['H', 'i'],
                    'character_start_times_seconds' => [0.0, 0.1],
                    'character_end_times_seconds'   => [0.1, 0.2],
                ],
            ], 200),
        ]);

        $service = app(ElevenLabsService::class);
        $result  = $service->generateWithTimestamps('Hi', 'abc123');

        $this->assertNotNull($result);
        $this->assertSame($fakeAudio, $result['audio']);
        $this->assertArrayHasKey('alignment', $result);
        $this->assertSame(['H', 'i'], $result['alignment']['characters']);
    }

    public function test_generate_with_timestamps_returns_null_on_api_error(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*/with-timestamps' => Http::response([], 500),
        ]);

        $service = app(ElevenLabsService::class);
        $result  = $service->generateWithTimestamps('Hello', 'abc123');

        $this->assertNull($result);
    }

    public function test_get_voice_preview_url_returns_from_cache(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    [
                        'voice_id'    => 'abc123',
                        'name'        => 'Rachel',
                        'preview_url' => 'https://cdn.elevenlabs.io/rachel.mp3',
                        'labels'      => [],
                    ],
                ],
            ], 200),
        ]);

        Cache::forget('elevenlabs_voices');
        $service = app(ElevenLabsService::class);
        $url     = $service->getVoicePreviewUrl('abc123');

        $this->assertSame('https://cdn.elevenlabs.io/rachel.mp3', $url);
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
cd /Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us
php artisan test tests/Unit/ElevenLabsServiceTest.php
```

Expected: FAIL — `ElevenLabsService` class not found.

- [ ] **Step 3: Create `app/Services/ElevenLabsService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ElevenLabsService
{
    private Client $client;
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = (string) config('services.elevenlabs.api_key', '');
        $baseUrl      = (string) config('services.elevenlabs.base_url', 'https://api.elevenlabs.io');

        $this->client = new Client([
            'base_uri'                  => rtrim($baseUrl, '/') . '/',
            RequestOptions::HEADERS     => [
                'xi-api-key'  => $this->apiKey,
                'Connection'  => 'keep-alive',
                'Accept'      => 'application/json',
            ],
            RequestOptions::HTTP_ERRORS => false,
            'http_version'              => '1.1',
            RequestOptions::CONNECT_TIMEOUT => 3,
            RequestOptions::TIMEOUT         => 45,
        ]);
    }

    /**
     * Return the full list of available voices, cached for 1 hour.
     * Each entry: ['id', 'label', 'preview_url', 'gradient_class']
     */
    public function getVoices(): array
    {
        return Cache::remember('elevenlabs_voices', 3600, function () {
            try {
                $response = $this->client->get('v1/voices');

                if ($response->getStatusCode() !== 200) {
                    Log::warning('ElevenLabsService: getVoices failed', ['status' => $response->getStatusCode()]);
                    return [];
                }

                $body   = json_decode((string) $response->getBody(), true);
                $voices = $body['voices'] ?? [];

                return array_values(array_map(fn (array $v) => [
                    'id'             => $v['voice_id'],
                    'label'          => $this->buildLabel($v),
                    'preview_url'    => $v['preview_url'] ?? null,
                    'gradient_class' => $this->gradientClass($v['labels'] ?? []),
                ], $voices));

            } catch (\Throwable $e) {
                Log::warning('ElevenLabsService: getVoices exception: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Generate audio + character-level alignment in one API call.
     * Returns ['audio' => <binary mp3>, 'alignment' => ['characters'=>[], 'character_start_times_seconds'=>[], 'character_end_times_seconds'=>[]]]
     * or null on failure.
     */
    public function generateWithTimestamps(
        string $text,
        string $voiceId,
        float  $stability  = 0.5,
        float  $similarity = 0.75,
    ): ?array {
        try {
            $response = $this->client->post("v1/text-to-speech/{$voiceId}/with-timestamps", [
                RequestOptions::JSON => [
                    'text'           => $text,
                    'model_id'       => 'eleven_multilingual_v2',
                    'voice_settings' => [
                        'stability'        => $stability,
                        'similarity_boost' => $similarity,
                    ],
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::warning('ElevenLabsService: generateWithTimestamps failed', [
                    'status'   => $response->getStatusCode(),
                    'voice_id' => $voiceId,
                ]);
                return null;
            }

            $body = json_decode((string) $response->getBody(), true);

            if (empty($body['audio_base64'])) {
                Log::warning('ElevenLabsService: empty audio_base64 in response');
                return null;
            }

            return [
                'audio'     => base64_decode($body['audio_base64']),
                'alignment' => $body['alignment'] ?? [],
            ];

        } catch (\Throwable $e) {
            Log::error('ElevenLabsService: generateWithTimestamps exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Return the CDN preview_url for a voice ID (reads from the cached voice list).
     */
    public function getVoicePreviewUrl(string $voiceId): ?string
    {
        foreach ($this->getVoices() as $voice) {
            if ($voice['id'] === $voiceId) {
                return $voice['preview_url'] ?? null;
            }
        }
        return null;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildLabel(array $voice): string
    {
        $name   = trim((string) ($voice['name'] ?? ''));
        $labels = $voice['labels'] ?? [];
        $accent = trim((string) ($labels['accent'] ?? ''));
        $gender = trim((string) ($labels['gender'] ?? ''));

        $suffix = trim("{$accent} {$gender}");
        return $suffix ? "{$name} · {$suffix}" : $name;
    }

    private function gradientClass(array $labels): string
    {
        $accent = strtolower($labels['accent'] ?? '');
        $gender = strtolower($labels['gender'] ?? '');

        if (str_contains($accent, 'british') && $gender === 'male') return 'vg-navy';
        if ($gender === 'female')                                     return 'vg-violet';
        if (str_contains($accent, 'american'))                        return 'vg-indigo';
        if (str_contains($accent, 'irish') || str_contains($accent, 'australian')) return 'vg-teal';
        if (in_array($accent, ['spanish', 'french', 'italian', 'german', 'indian'], true)) return 'vg-amber';

        return 'vg-base';
    }
}
```

- [ ] **Step 4: Register as singleton in `app/Providers/AppServiceProvider.php`**

```php
<?php

namespace App\Providers;

use App\Services\ElevenLabsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ElevenLabsService::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole() && ! $this->app->environment('testing')) {
            if (! Cache::has('elevenlabs_voices')) {
                dispatch(new \App\Jobs\WarmElevenLabsJob());
            }
        }
    }
}
```

- [ ] **Step 5: Run tests — expect pass**

```bash
php artisan test tests/Unit/ElevenLabsServiceTest.php
```

Expected: all 5 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/ElevenLabsService.php app/Providers/AppServiceProvider.php tests/Unit/ElevenLabsServiceTest.php
git commit -m "feat: add ElevenLabsService with singleton Guzzle client and voice cache"
```

---

## Task 3: Warm-up Infrastructure

**Files:**
- Create: `app/Jobs/WarmElevenLabsJob.php`
- Create: `app/Console/Commands/WarmElevenLabsCache.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Create `app/Jobs/WarmElevenLabsJob.php`**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ElevenLabsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WarmElevenLabsJob implements ShouldQueue
{
    use Queueable;

    public function handle(ElevenLabsService $service): void
    {
        $service->getVoices();
    }
}
```

- [ ] **Step 2: Create `app/Console/Commands/WarmElevenLabsCache.php`**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ElevenLabsService;
use Illuminate\Console\Command;

class WarmElevenLabsCache extends Command
{
    protected $signature   = 'elevenlabs:warm';
    protected $description = 'Pre-populate the ElevenLabs voice list cache';

    public function handle(ElevenLabsService $service): int
    {
        \Illuminate\Support\Facades\Cache::forget('elevenlabs_voices');

        $voices = $service->getVoices();

        if (empty($voices)) {
            $this->warn('ElevenLabs returned no voices (check API key or connectivity).');
            return self::SUCCESS;
        }

        $this->info('ElevenLabs voice cache warmed: ' . count($voices) . ' voices.');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Schedule the command in `routes/console.php`**

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('elevenlabs:warm')->everyFiftyMinutes();
```

- [ ] **Step 4: Verify the command runs**

```bash
php artisan elevenlabs:warm
```

Expected: `ElevenLabs voice cache warmed: N voices.` (or a warning if key is not set).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/WarmElevenLabsJob.php app/Console/Commands/WarmElevenLabsCache.php routes/console.php
git commit -m "feat: add ElevenLabs warm-up command, job, and 50-minute schedule"
```

---

## Task 4: TtsService — Remove Kokoro, Add ElevenLabs + Pocket TTS

**Files:**
- Modify: `app/Services/TtsService.php`
- Modify: `tests/Unit/TtsServiceTest.php`

- [ ] **Step 1: Write new failing tests**

Append to `tests/Unit/TtsServiceTest.php`:

```php
use App\Services\ElevenLabsService;
use Illuminate\Support\Facades\Http;

public function test_elevenlabs_provider_populates_timing_data(): void
{
    $fakeAudio = str_repeat('a', 500);

    $mock = $this->mock(ElevenLabsService::class);
    $mock->shouldReceive('generateWithTimestamps')
         ->once()
         ->andReturn([
             'audio'     => $fakeAudio,
             'alignment' => [
                 'characters'                    => ['H', 'i'],
                 'character_start_times_seconds' => [0.0, 0.1],
                 'character_end_times_seconds'   => [0.1, 0.2],
             ],
         ]);

    $service    = app(\App\Services\TtsService::class);
    $timingData = null;
    $audio      = $service->generateAudioRaw('Hi', 'abc123', 1.0, 'elevenlabs', $timingData);

    $this->assertSame($fakeAudio, $audio);
    $this->assertArrayHasKey('character_timings', $timingData);
    $this->assertSame(['H', 'i'], $timingData['character_timings']['characters']);
}

public function test_fallback_fires_pocket_tts_when_elevenlabs_fails(): void
{
    $mock = $this->mock(ElevenLabsService::class);
    $mock->shouldReceive('generateWithTimestamps')->once()->andReturn(null);

    Http::fake([
        'barukasharlot-pocket-tts-server.hf.space/tts' => Http::response(str_repeat('b', 500), 200, [
            'Content-Type' => 'audio/wav',
        ]),
    ]);

    $service    = app(\App\Services\TtsService::class);
    $timingData = null;
    $audio      = $service->generateAudioRaw('Hello', 'abc123', 1.0, 'elevenlabs', $timingData);

    $this->assertNotNull($audio);
}
```

- [ ] **Step 2: Run to confirm failure**

```bash
php artisan test tests/Unit/TtsServiceTest.php
```

Expected: FAIL — new test methods reference missing `tryElevenLabs`.

- [ ] **Step 3: Rewrite `generateAudioRaw` and add/remove methods in `TtsService.php`**

**a) Remove these methods entirely:**
- `tryKokoro()`
- `ensureKokoroRunning()`
- `isLocalKokoroUrl()`
- `kokoroPackageInstalled()`
- `kokoroStartCommand()`
- The `private static bool $kokoroStartAttempted` property

**b) Rename the `$wordTimings` parameter to `$timingData` throughout** (it appears in `generateAudioRaw` and `generateAudio` signatures — only `generateAudioRaw` is relevant here).

**c) Replace `generateAudioRaw()`:**

```php
public function generateAudioRaw(
    string  $text,
    string  $voiceId,
    float   $speed    = 1.0,
    string  $provider = 'auto',
    ?array  &$timingData = null,
): ?string {
    $this->generatedAudioExtension = 'mp3';
    $text        = $this->prepareSpeechText($text);
    $timingData  = null;

    if ($provider === 'elevenlabs') {
        return $this->tryElevenLabs($text, $voiceId, $timingData)
            ?? $this->tryPocketTts($text)
            ?? $this->tryEdgeTts($text, $voiceId, $speed)
            ?? $this->tryMacosTts($text);
    }

    if ($provider === 'pocket_tts') {
        return $this->tryPocketTts($text)
            ?? $this->tryEdgeTts($text, $voiceId, $speed)
            ?? $this->tryMacosTts($text);
    }

    if ($provider === 'edge_tts') {
        $wordTimings = null;
        $audio = $this->tryEdgeTts($text, $voiceId, $speed, true, $wordTimings)
            ?? $this->tryPocketTts($text)
            ?? $this->tryMacosTts($text);
        if (is_array($wordTimings)) {
            $timingData = ['word_timings' => $wordTimings];
        }
        return $audio;
    }

    // auto
    return $this->tryElevenLabs($text, $voiceId, $timingData)
        ?? $this->tryPocketTts($text)
        ?? $this->tryEdgeTts($text, $voiceId, $speed)
        ?? $this->tryMacosTts($text);
}
```

**d) Add `tryElevenLabs()`:**

```php
private function tryElevenLabs(string $text, string $voiceId, ?array &$timingData = null): ?string
{
    try {
        $result = app(\App\Services\ElevenLabsService::class)
            ->generateWithTimestamps($text, $voiceId);

        if ($result === null) {
            return null;
        }

        $timingData = ['character_timings' => $result['alignment']];
        $this->generatedAudioExtension = 'mp3';
        Log::info("TtsService: ElevenLabs succeeded for voice {$voiceId}");
        return $result['audio'];

    } catch (\Throwable $e) {
        Log::warning('TtsService::tryElevenLabs failed: ' . $e->getMessage());
        return null;
    }
}
```

**e) Add `tryPocketTts()`:**

```php
private function tryPocketTts(string $text): ?string
{
    $baseUrl = rtrim((string) config('services.pocket_tts.url', ''), '/');
    $token   = (string) config('services.pocket_tts.hf_token', '');

    if (! $baseUrl) {
        return null;
    }

    try {
        $request = Http::timeout(60)
            ->asMultipart();

        if ($token) {
            $request = $request->withToken($token);
        }

        $response = $request->post("{$baseUrl}/tts", [
            ['name' => 'text', 'contents' => $text],
        ]);

        if ($response->successful() && strlen($response->body()) > 100) {
            $this->generatedAudioExtension = 'mp3';
            Log::info('TtsService: Pocket TTS succeeded');
            return $response->body();
        }

        Log::warning('TtsService: Pocket TTS returned non-successful response', [
            'status' => $response->status(),
        ]);
    } catch (\Throwable $e) {
        Log::info('TtsService: Pocket TTS unavailable: ' . $e->getMessage());
    }

    return null;
}
```

**f) Also update `generateAudio()` (lesson pipeline)** — replace the kokoro-first routing:

```php
public function generateAudio(string $text, string $lessonId, string $voice = 'alloy', float $speed = 1.0): ?string
{
    $text = $this->prepareSpeechText($text);
    $this->generatedAudioExtension = 'mp3';
    $timingData = null;

    $audioContent = $this->tryElevenLabs($text, $voice, $timingData)
        ?? $this->tryPocketTts($text)
        ?? $this->tryEdgeTts($text, $voice, $speed)
        ?? $this->tryMacosTts($text);

    if ($audioContent === null) {
        Log::error("TtsService: all providers failed for lesson {$lessonId}");
        return null;
    }

    $audioPath = "lessons/{$lessonId}/audio.{$this->generatedAudioExtension}";
    $this->lessonDisk()->put($audioPath, $audioContent);
    return $audioPath;
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Unit/TtsServiceTest.php
```

Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TtsService.php tests/Unit/TtsServiceTest.php
git commit -m "feat: remove Kokoro, add ElevenLabs + Pocket TTS to TtsService fallback chain"
```

---

## Task 5: Avatar Model — Cleanup + Gradient Helpers

**Files:**
- Modify: `app/Models/Avatar.php`

- [ ] **Step 1: Remove `kokoroVoices()` and add new methods**

Open `app/Models/Avatar.php`.

**Remove the entire `kokoroVoices(): array` method** (lines roughly 243–269 in the current file).

**Add these three methods** after `edgeTtsVoices()`:

```php
/**
 * Map ElevenLabs voice label metadata to one of the six gradient CSS classes.
 */
public static function elevenlabsVoiceGradient(array $labels): string
{
    $accent = strtolower($labels['accent'] ?? '');
    $gender = strtolower($labels['gender'] ?? '');

    if (str_contains($accent, 'british') && $gender === 'male') return 'vg-navy';
    if ($gender === 'female')                                     return 'vg-violet';
    if (str_contains($accent, 'american'))                       return 'vg-indigo';
    if (in_array($accent, ['irish', 'australian'], true))        return 'vg-teal';
    if (in_array($accent, ['spanish', 'french', 'italian', 'german', 'indian'], true)) return 'vg-amber';
    return 'vg-base';
}

/**
 * edge-tts voices as card-strip data: [['id', 'label', 'preview_url', 'gradient_class']]
 */
public static function edgeTtsVoicesForCards(): array
{
    $gradients = [
        'en-GB-' => 'vg-navy',
        'en-US-' => 'vg-indigo',
        'en-IE-' => 'vg-teal',
        'en-AU-' => 'vg-teal',
        'en-CA-' => 'vg-indigo',
        'en-IN-' => 'vg-amber',
        'en-ZA-' => 'vg-amber',
        'en-NG-' => 'vg-amber',
        'es-'    => 'vg-amber',
        'fr-'    => 'vg-violet',
        'it-'    => 'vg-violet',
        'de-'    => 'vg-navy',
    ];

    $cards = [];
    foreach (static::edgeTtsVoices() as $voiceId => $label) {
        $gradientClass = 'vg-base';
        foreach ($gradients as $prefix => $class) {
            if (str_starts_with($voiceId, $prefix)) {
                $gradientClass = $class;
                break;
            }
        }
        $cards[] = [
            'id'             => $voiceId,
            'label'          => $label,
            'preview_url'    => null,
            'gradient_class' => $gradientClass,
        ];
    }
    return $cards;
}

/**
 * Pocket TTS preset voices.
 */
public static function pocketTtsVoices(): array
{
    return [
        ['id' => 'default',  'label' => 'Pocket TTS · Default',  'preview_url' => null, 'gradient_class' => 'vg-teal'],
        ['id' => 'male_1',   'label' => 'Pocket TTS · Male 1',   'preview_url' => null, 'gradient_class' => 'vg-navy'],
        ['id' => 'female_1', 'label' => 'Pocket TTS · Female 1', 'preview_url' => null, 'gradient_class' => 'vg-violet'],
    ];
}
```

- [ ] **Step 2: Verify tests still pass**

```bash
php artisan test
```

Expected: no regressions.

- [ ] **Step 3: Commit**

```bash
git add app/Models/Avatar.php
git commit -m "feat: remove kokoroVoices, add edgeTtsVoicesForCards + pocketTtsVoices + gradient helper"
```

---

## Task 6: AvatarVoiceSample — Fix `label()`

**Files:**
- Modify: `app/Models/AvatarVoiceSample.php`

- [ ] **Step 1: Update `label()`**

Replace the current `label()` method:

```php
// BEFORE:
public function label(): string
{
    $voices = Avatar::kokoroVoices();
    $name   = $voices[$this->voice_id] ?? $this->voice_id;
    return "{$name} · {$this->voice_speed}×";
}

// AFTER:
public function label(): string
{
    $name = $this->settings_snapshot['voice_label'] ?? $this->voice_id;
    return "{$name} · {$this->voice_speed}×";
}
```

This reads `voice_label` from `settings_snapshot` (stored at sample creation time in Task 7), falling back to the raw voice ID.

- [ ] **Step 2: Commit**

```bash
git add app/Models/AvatarVoiceSample.php
git commit -m "fix: AvatarVoiceSample label() reads from settings_snapshot instead of hardcoded Kokoro list"
```

---

## Task 7: AvatarStudio Livewire Backend

**Files:**
- Modify: `app/Livewire/Admin/AvatarStudio.php`

- [ ] **Step 1: Update defaults and imports**

At the top of the class, change defaults:

```php
// BEFORE:
public string $voice_provider = 'edge_tts';
public string $voice_id       = 'es-ES-AlvaroNeural';
// ...
public string $previewVoiceId    = '';
public string $previewProvider   = 'edge_tts';

// AFTER:
public string $voice_provider = 'elevenlabs';
public string $voice_id       = '';
// ...
public string $previewProvider = 'elevenlabs';
```

- [ ] **Step 2: Update `voices()` computed property**

```php
#[Computed]
public function voices(): array
{
    return match ($this->previewProvider) {
        'elevenlabs' => app(\App\Services\ElevenLabsService::class)->getVoices(),
        'edge_tts'   => \App\Models\Avatar::edgeTtsVoicesForCards(),
        'pocket_tts' => \App\Models\Avatar::pocketTtsVoices(),
        default      => [],
    };
}
```

- [ ] **Step 3: Add `selectVoice()` action**

```php
public function selectVoice(string $voiceId): void
{
    $this->previewVoiceId = $voiceId;
}
```

- [ ] **Step 4: Update `generateSample()` to store `voice_label` and `timing_data`**

Find the `AvatarVoiceSample::create([...])` block inside `generateSample()` and update `settings_snapshot`:

```php
// Find the voice label from the current voice list
$voiceLabel = collect($this->voices)
    ->firstWhere('id', $voiceId)['label'] ?? $voiceId;

AvatarVoiceSample::create([
    'avatar_id'       => $this->avatar->id,
    'phrase'          => $phrase,
    'voice_id'        => $voiceId,
    'voice_speed'     => $speed,
    'audio_path'      => $filename,
    'audio_extension' => $ext,
    'settings_snapshot' => [
        'provider'    => $this->previewProvider,
        'voice_id'    => $voiceId,
        'voice_label' => $voiceLabel,
        'speed'       => $speed,
        'timing_data' => is_array($timingData) ? $timingData : [],
    ],
]);
```

Also update the `generateAudioRaw` call in `generateSample()` to use `$timingData` variable name:

```php
$timingData  = null;
$audioContent = $tts->generateAudioRaw($phrase, $voiceId, $speed, $this->previewProvider, $timingData);
```

And in `generateGreeting()`:

```php
$timingData = null;
$audio = $tts->generateAudioRaw(
    $text,
    $this->previewVoiceId,
    $this->previewVoiceSpeed,
    $this->previewProvider,
    $timingData,
);
// ...
$this->greetingWordTimings = is_array($timingData) ? ($timingData['word_timings'] ?? []) : [];
```

- [ ] **Step 5: Remove `generateAllVoiceSamples()` (was Kokoro-specific)**

Delete the `generateAllVoiceSamples()` method entirely — it iterated `kokoroVoices()`.

- [ ] **Step 6: Run all tests**

```bash
php artisan test
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/AvatarStudio.php
git commit -m "feat: update AvatarStudio for ElevenLabs provider with card strip voice selection"
```

---

## Task 8: AvatarStudio Blade — Card Strip UI

**Files:**
- Modify: `resources/views/livewire/admin/avatar-studio.blade.php`

- [ ] **Step 1: Add the grain CSS classes**

In the `<head>` or layout (check `resources/views/components/layouts/app.blade.php` — add `<style>` block there if a `@stack('styles')` exists, otherwise inline in the voice tab section):

```html
<style>
/* ── Voice card gradient + grain ─────────────────────────────── */
.vg-card { position: relative; overflow: hidden; }
.vg-card::before {
    content: '';
    position: absolute; inset: 0;
    filter: url(#grain);
    pointer-events: none;
}
.vg-navy   { background: linear-gradient(135deg, #0f172a, #1e3a5f); }
.vg-indigo { background: linear-gradient(135deg, #1e1b4b, #312e81); }
.vg-violet { background: linear-gradient(135deg, #2d1b69, #4c1d95); }
.vg-amber  { background: linear-gradient(135deg, #1c1917, #78350f); }
.vg-teal   { background: linear-gradient(135deg, #0f2027, #1a3a4a); }
.vg-base   { background: linear-gradient(135deg, #0f172a, #1e293b); }

/* Waveform bars animation */
@keyframes wavebar {
    0%, 100% { transform: scaleY(0.4); }
    50%       { transform: scaleY(1.0); }
}
.wave-bar { animation: wavebar 0.6s ease-in-out infinite; transform-origin: bottom; }
.wave-bar:nth-child(2) { animation-delay: 0.15s; }
.wave-bar:nth-child(3) { animation-delay: 0.3s; }
</style>
```

- [ ] **Step 2: Update the provider toggle (3 buttons)**

In the voice tab, find the provider toggle block and replace:

```blade
{{-- Provider toggle --}}
<div class="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-900/40 p-1 w-fit">
    @foreach(['elevenlabs' => '★ ElevenLabs', 'edge_tts' => 'edge-tts (free)', 'pocket_tts' => 'Pocket TTS'] as $prov => $label)
        <button
            wire:click="$set('previewProvider', '{{ $prov }}')"
            class="rounded-lg px-4 py-2 text-xs font-medium transition-colors
                {{ $previewProvider === $prov
                    ? 'bg-amber-500 text-slate-950'
                    : 'text-slate-400 hover:text-slate-200' }}"
        >{{ $label }}</button>
    @endforeach
</div>
```

- [ ] **Step 3: Add the SVG grain filter definition (once, hidden)**

Directly below the provider toggle:

```html
{{-- SVG grain filter — referenced by CSS filter: url(#grain) on .vg-card::before --}}
<svg style="display:none" aria-hidden="true">
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

- [ ] **Step 4: Replace the voice `<select>` with the card strip**

Remove the `<div class="grid gap-4 sm:grid-cols-2">` block that contains the `<select>` for voices. Replace it with:

```blade
{{-- Voice card strip --}}
<div
    x-data="{
        playingId: null,
        audioEl: null,
        playPreview(voiceId, previewUrl) {
            if (this.audioEl) { this.audioEl.pause(); this.audioEl = null; }
            if (this.playingId === voiceId) { this.playingId = null; return; }
            if (!previewUrl) return;
            this.playingId = voiceId;
            this.audioEl = new Audio(previewUrl);
            this.audioEl.onended = () => { this.playingId = null; };
            this.audioEl.onerror = () => { this.playingId = null; };
            this.audioEl.play();
        }
    }"
    class="space-y-2"
>
    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Voice</p>

    <div
        class="flex gap-2 pb-2 overflow-x-auto scroll-smooth"
        style="scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none;"
    >
        @foreach($this->voices as $voice)
            @php
                $isSelected = $previewVoiceId === $voice['id'];
            @endphp
            <button
                wire:click="selectVoice('{{ $voice['id'] }}')"
                style="scroll-snap-align:start;"
                class="shrink-0 w-[72px] rounded-xl p-2 flex flex-col items-center gap-1 transition-all border vg-card {{ $voice['gradient_class'] }}
                    {{ $isSelected
                        ? 'border-amber-400 shadow-md shadow-amber-500/10'
                        : 'border-slate-700/60 hover:border-indigo-500/50' }}"
            >
                {{-- Top-right: checkmark (selected) or play/waveform (unselected) --}}
                <div class="w-full flex justify-end">
                    @if($isSelected)
                        <div class="w-3.5 h-3.5 rounded-full bg-amber-500 border border-amber-400 flex items-center justify-center shrink-0">
                            <svg class="w-2 h-2 text-slate-950" viewBox="0 0 10 8" fill="none">
                                <path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                    @else
                        <button
                            type="button"
                            @click.stop="playPreview('{{ $voice['id'] }}', '{{ $voice['preview_url'] }}')"
                            class="w-3.5 h-3.5 rounded-full border border-slate-500 bg-slate-800/60 hover:border-indigo-400 flex items-center justify-center shrink-0 transition-colors"
                        >
                            <template x-if="playingId === '{{ $voice['id'] }}'">
                                {{-- Waveform bars when playing --}}
                                <span class="flex items-end gap-[1px] h-2">
                                    <span class="wave-bar w-[2px] bg-indigo-400 h-full rounded-sm"></span>
                                    <span class="wave-bar w-[2px] bg-indigo-400 h-full rounded-sm"></span>
                                    <span class="wave-bar w-[2px] bg-indigo-400 h-full rounded-sm"></span>
                                </span>
                            </template>
                            <template x-if="playingId !== '{{ $voice['id'] }}'">
                                <svg class="w-1.5 h-1.5 text-slate-400" viewBox="0 0 8 8" fill="currentColor">
                                    <polygon points="1,1 7,4 1,7"/>
                                </svg>
                            </template>
                        </button>
                    @endif
                </div>

                {{-- Center icon: speaker wave --}}
                <svg viewBox="0 0 24 24" fill="none" class="w-7 h-7 text-indigo-300/80" stroke="currentColor" stroke-width="1.5">
                    <path d="M9 9v6l-3-2H3V11h3L9 9z" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M13 9.5a4 4 0 010 5M15.5 7.5a7 7 0 010 9" stroke-linecap="round"/>
                </svg>

                {{-- Name --}}
                <span class="text-[10px] text-slate-300 text-center leading-tight w-full truncate px-0.5 relative z-10">
                    {{ explode(' · ', $voice['label'])[0] }}
                </span>
            </button>
        @endforeach
    </div>

    {{-- Speed slider (kept below the strip) --}}
    <div class="pt-2">
        <label class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-slate-400">
            Speed: <span class="text-amber-400">{{ $previewVoiceSpeed }}×</span>
        </label>
        <input
            type="range"
            wire:model.live="previewVoiceSpeed"
            min="0.5" max="1.5" step="0.05"
            class="w-full accent-amber-400"
        >
        <div class="flex justify-between text-xs text-slate-600 mt-1">
            <span>0.5× slow</span><span>1.0× normal</span><span>1.5× fast</span>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Remove the "Generate all voices" batch button**

Delete the entire `<div class="border-t border-slate-800 pt-5">` block that contained `generateAllVoiceSamples` — it referenced Kokoro.

- [ ] **Step 6: Manual smoke test**

```bash
php artisan serve
```

Open `http://localhost:8000/admin/avatars/{id}/studio`, go to Voice Studio tab. Confirm:
- Three provider toggle buttons appear
- ElevenLabs card strip loads (voice cards with gradient + grain backgrounds)
- Clicking ▶ on a card plays the preview audio (no Livewire roundtrip)
- Clicking a card body selects it (amber border + checkmark)
- Speed slider still works

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/admin/avatar-studio.blade.php
git commit -m "feat: voice picker card strip with grainy gradients, ElevenLabs preview_url playback"
```

---

## Task 9: TTS Playground — `charVisemeMap.js`

**Files:**
- Create: `apps/ttsplayground/src/charVisemeMap.js`

- [ ] **Step 1: Create the file**

```js
/**
 * Character-to-viseme lookup for ElevenLabs character-level alignment.
 * Maps individual lowercase characters to the closest viseme name used by
 * Avatar.jsx's visemeAliases resolver. Returns null for silence/punctuation.
 */
export const charToViseme = {
  // Vowels
  a: 'viseme_ah',
  e: 'viseme_eh',
  i: 'viseme_iy',
  o: 'viseme_oh',
  u: 'viseme_uw',

  // Bilabials
  b: 'viseme_mbp',
  m: 'viseme_mbp',
  p: 'viseme_mbp',

  // Labiodentals
  f: 'viseme_fv',
  v: 'viseme_fv',

  // Liquids
  l: 'viseme_l',
  r: 'viseme_er',

  // Sibilants
  s: 'viseme_s',
  z: 'viseme_s',

  // Alveopalatal
  j: 'viseme_ch',

  // Velars
  g: 'viseme_kk',
  k: 'viseme_kk',
  c: 'viseme_kk',
  q: 'viseme_kk',
  x: 'viseme_kk',

  // Alveolars
  n: 'viseme_nn',
  t: 'viseme_tt',
  d: 'viseme_tt',

  // Glides (approximate)
  w: 'viseme_uw',
  y: 'viseme_iy',
  h: 'viseme_ah',

  // Silence / punctuation → null (no mouth movement)
  ' ': null,
  ',': null,
  '.': null,
  '!': null,
  '?': null,
  ':': null,
  ';': null,
  '-': null,
  "'": null,
  '"': null,
};
```

- [ ] **Step 2: Commit**

```bash
cd /Users/bartslot/BartsAutomation/BartsDev/apps/ttsplayground
git add src/charVisemeMap.js
git commit -m "feat: add charVisemeMap for ElevenLabs character-level alignment"
```

---

## Task 10: TTS Playground — `useLipSyncElevenLabs.js`

**Files:**
- Create: `apps/ttsplayground/src/useLipSyncElevenLabs.js`

- [ ] **Step 1: Create the hook**

```js
import { useMemo } from 'react';
import { charToViseme } from './charVisemeMap';

/**
 * Converts ElevenLabs character-level alignment data into the same
 * {time, duration, viseme}[] timeline format consumed by App.jsx's
 * findCurrentViseme() + playAudioWithTimeline().
 *
 * @param {object|null} alignment - ElevenLabs alignment object:
 *   { characters: string[], character_start_times_seconds: number[], character_end_times_seconds: number[] }
 * @returns {{ timeline: Array<{time: number, duration: number, viseme: string}> }}
 */
export function useLipSyncElevenLabs(alignment) {
  const timeline = useMemo(() => {
    if (!alignment) return [];

    const chars  = alignment.characters ?? [];
    const starts = alignment.character_start_times_seconds ?? [];
    const ends   = alignment.character_end_times_seconds ?? [];

    if (!chars.length || chars.length !== starts.length || chars.length !== ends.length) {
      return [];
    }

    const result = [];

    for (let i = 0; i < chars.length; i += 1) {
      const ch      = (chars[i] ?? '').toLowerCase();
      const viseme  = Object.prototype.hasOwnProperty.call(charToViseme, ch)
        ? charToViseme[ch]
        : null;
      const start   = starts[i] ?? 0;
      const end     = ends[i] ?? start;
      const duration = Math.max(0, end - start);

      if (viseme && duration > 0) {
        result.push({ time: start, duration, viseme });
      }
    }

    return result.sort((a, b) => a.time - b.time);
  }, [alignment]);

  return { timeline };
}

/**
 * Non-hook version for use outside React component tree.
 */
export function buildElevenLabsTimeline(alignment) {
  if (!alignment) return [];

  const chars  = alignment.characters ?? [];
  const starts = alignment.character_start_times_seconds ?? [];
  const ends   = alignment.character_end_times_seconds ?? [];

  if (!chars.length || chars.length !== starts.length) return [];

  const result = [];

  for (let i = 0; i < chars.length; i += 1) {
    const ch       = (chars[i] ?? '').toLowerCase();
    const viseme   = Object.prototype.hasOwnProperty.call(charToViseme, ch)
      ? charToViseme[ch]
      : null;
    const start    = starts[i] ?? 0;
    const end      = ends[i] ?? start;
    const duration = Math.max(0, end - start);

    if (viseme && duration > 0) {
      result.push({ time: start, duration, viseme });
    }
  }

  return result.sort((a, b) => a.time - b.time);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/useLipSyncElevenLabs.js
git commit -m "feat: useLipSyncElevenLabs converts character alignment to viseme timeline"
```

---

## Task 11: TTS Playground — `App.jsx` Routing

**Files:**
- Modify: `apps/ttsplayground/src/App.jsx`

- [ ] **Step 1: Import the new builder**

Add to the top of `App.jsx`:

```js
import { buildElevenLabsTimeline } from './useLipSyncElevenLabs';
```

- [ ] **Step 2: Update `bakeSpeech()` to route on alignment presence**

Inside `bakeSpeech()`, find the `playTimeline` block that currently tries Gentle then falls back to `buildLocalVisemeTimeline`. Replace it entirely:

```js
// Inside bakeSpeech(), after getting audioBlob and audioDuration:

let playTimeline = [];

// If the TTS response included ElevenLabs alignment JSON (sent as a
// custom response header or a separate JSON field), use it.
// Pocket TTS returns raw audio only — fall through to local G2P.
const alignmentHeader = response.headers?.get?.('x-elevenlabs-alignment');
if (alignmentHeader) {
  try {
    const alignment = JSON.parse(alignmentHeader);
    playTimeline = buildElevenLabsTimeline(alignment);
  } catch {
    playTimeline = buildLocalVisemeTimeline(message, audioDuration);
  }
} else {
  // Try Gentle (may not be running) then fall back to local G2P
  try {
    const alignForm = new FormData();
    alignForm.append('audio', audioBlob, 'generated.wav');
    alignForm.append('transcript', message);

    const gentleBase = import.meta.env.VITE_GENTLE_URL ?? '/gentle';
    const alignResponse = await fetch(`${gentleBase}/transcriptions?async=false`, {
      method: 'POST',
      body: alignForm,
    });

    if (!alignResponse.ok) throw new Error(`Gentle HTTP ${alignResponse.status}`);

    const alignment = await alignResponse.json();
    playTimeline = buildVisemeTimeline(alignment);
  } catch {
    if (message.trim() === DEFAULT_SAMPLE.trim() && fallbackTimeline.length > 0) {
      playTimeline = fallbackTimeline;
    } else {
      playTimeline = buildLocalVisemeTimeline(message, audioDuration);
    }
  }
}
```

**Note:** The Pocket TTS HF Space server (`pocket-tts-space/app.py`) does not currently return an alignment header — that is fine. The `x-elevenlabs-alignment` header would only be present if a future proxy or server-side endpoint forwards the ElevenLabs alignment data. For now, the ttsplayground continues using Gentle / local G2P for Pocket TTS audio, and the ElevenLabs alignment path is ready for when the app calls ElevenLabs directly.

- [ ] **Step 3: Build and check**

```bash
npm run build
```

Expected: build succeeds with no type errors.

- [ ] **Step 4: Start dev server and smoke test**

```bash
npm run dev
```

Open `http://localhost:5173`. Record a voice, bake speech, confirm lip sync still runs (local G2P fires as before). No regressions.

- [ ] **Step 5: Commit**

```bash
git add src/App.jsx
git commit -m "feat: route App.jsx to ElevenLabs character alignment when header present, fallback to G2P"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| ElevenLabsService singleton + Guzzle keep-alive | Task 2 step 3 |
| `getVoices()` with 1-hour cache | Task 2 step 3 |
| `generateWithTimestamps()` → audio + alignment | Task 2 step 3 |
| `getVoicePreviewUrl()` | Task 2 step 3 |
| Boot-time warm dispatch | Task 2 step 4 |
| `php artisan elevenlabs:warm` command | Task 3 step 2 |
| 50-minute schedule | Task 3 step 3 |
| Kokoro fully removed from TtsService | Task 4 step 3 |
| `tryElevenLabs()` populates character_timings | Task 4 step 3d |
| `tryPocketTts()` with HF_TOKEN | Task 4 step 3e |
| Provider routing (elevenlabs→pocket→edge→macos) | Task 4 step 3c |
| `kokoroVoices()` removed from Avatar | Task 5 step 1 |
| `elevenlabsVoiceGradient()` helper | Task 5 step 1 |
| `edgeTtsVoicesForCards()` with gradient classes | Task 5 step 1 |
| `pocketTtsVoices()` | Task 5 step 1 |
| `label()` reads voice_label from snapshot | Task 6 step 1 |
| `voices()` computed returns card-shape arrays | Task 7 step 2 |
| `selectVoice()` action | Task 7 step 3 |
| `timing_data` stored in settings_snapshot | Task 7 step 4 |
| Provider toggle 3 buttons (remove Kokoro) | Task 8 step 2 |
| SVG grain filter `<feTurbulence>` | Task 8 step 3 |
| Card strip (72px, scroll-snap, gradient bg) | Task 8 step 4 |
| ▶ plays preview_url via Alpine Audio (zero cost) | Task 8 step 4 |
| Selected card: amber border + checkmark | Task 8 step 4 |
| Playing card: waveform bars animation | Task 8 step 4 |
| `charVisemeMap.js` | Task 9 |
| `useLipSyncElevenLabs.js` + `buildElevenLabsTimeline()` | Task 10 |
| `App.jsx` routes to ElevenLabs alignment | Task 11 |
| Tests: ElevenLabsServiceTest | Task 2 step 1 |
| Tests: TtsServiceTest extended | Task 4 step 1 |

No gaps found.

**Type consistency check:**
- `generateAudioRaw($timingData)` renamed consistently in Tasks 4 and 7 ✓
- `buildElevenLabsTimeline` imported in App.jsx (Task 11) and exported in useLipSyncElevenLabs.js (Task 10) ✓
- `voice['id']`, `voice['label']`, `voice['preview_url']`, `voice['gradient_class']` shape used consistently in Tasks 5, 7, 8 ✓
- `selectVoice()` in Task 7 matches `wire:click="selectVoice(...)"` in Task 8 ✓

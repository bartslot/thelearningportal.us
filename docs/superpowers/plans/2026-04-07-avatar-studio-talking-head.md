# Avatar Studio — Talking Head Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a resource-efficient, API-free animated talking avatar — portrait image + audio amplitude JSON → live CSS/canvas animation with mouth, eyes, head movement, and breathing. No GPU, no AI API, runs on Vercel Hobby + Laravel on SiteGround.

**Architecture:** Two Vercel serverless functions handle face landmark detection and audio amplitude extraction. Laravel stores sprite frames + landmarks JSON per avatar. Flutter/browser client animates the portrait using the manifest at playback time.

**Tech Stack:** Laravel 12, Livewire 3, Tailwind CSS v4, Node.js 20 (Vercel functions), face-api.js, node-canvas, audiodecode, HTML5 Canvas / Alpine.js

---

## File Map

### New files
- `vercel-functions/api/detect-landmarks.js` — POST portrait → landmarks + sprite frames
- `vercel-functions/api/audio-manifest.js` — POST audio URL → amplitude samples
- `vercel-functions/package.json` — Node deps (face-api.js, node-canvas, audiodecode)
- `vercel-functions/vercel.json` — Vercel config
- `vercel-functions/.gitignore` — exclude node_modules
- `database/migrations/XXXX_add_sprite_fields_to_avatars_table.php` — new columns
- `app/Jobs/ProcessAvatarPortrait.php` — dispatches to Vercel detect-landmarks
- `app/Enums/SpriteStatus.php` — pending/processing/ready/failed
- `resources/js/avatar-animator.js` — client-side canvas animation engine
- `resources/views/livewire/admin/avatar-studio/portrait-tab.blade.php` — upload UI partial

### Modified files
- `app/Models/Avatar.php` — add sprite fields, portraitUrl helper
- `app/Livewire/Admin/AvatarStudio.php` — add portrait upload + sprite status tab
- `resources/views/livewire/admin/avatar-studio.blade.php` — add Portrait tab
- `app/Services/AvatarService.php` — add `processPortrait()` method
- `config/services.php` — add vercel_functions.url key
- `.env.example` — add VERCEL_FUNCTIONS_URL

---

## Task 1: Migration — add sprite fields to avatars table

**Files:**
- Create: `database/migrations/2026_04_07_000001_add_sprite_fields_to_avatars_table.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->string('portrait_original_path')->nullable()->after('portrait_path');
            $table->json('landmarks_json')->nullable()->after('portrait_original_path');
            $table->string('sprite_status')->default('pending')->after('landmarks_json');
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropColumn(['portrait_original_path', 'landmarks_json', 'sprite_status']);
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_04_07_000001_add_sprite_fields_to_avatars_table` then `Migrated`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_07_000001_add_sprite_fields_to_avatars_table.php
git commit -m "feat: add sprite fields to avatars table"
```

---

## Task 2: SpriteStatus enum

**Files:**
- Create: `app/Enums/SpriteStatus.php`

- [ ] **Step 1: Create enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum SpriteStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Ready      = 'ready';
    case Failed     = 'failed';
}
```

- [ ] **Step 2: Write test**

Create `tests/Unit/Enums/SpriteStatusTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SpriteStatus;
use PHPUnit\Framework\TestCase;

class SpriteStatusTest extends TestCase
{
    public function test_from_string(): void
    {
        $this->assertSame(SpriteStatus::Ready, SpriteStatus::from('ready'));
        $this->assertSame(SpriteStatus::Failed, SpriteStatus::from('failed'));
    }
}
```

- [ ] **Step 3: Run test**

```bash
php artisan test tests/Unit/Enums/SpriteStatusTest.php
```

Expected: 1 test, 2 assertions, PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Enums/SpriteStatus.php tests/Unit/Enums/SpriteStatusTest.php
git commit -m "feat: add SpriteStatus enum"
```

---

## Task 3: Update Avatar model

**Files:**
- Modify: `app/Models/Avatar.php`

- [ ] **Step 1: Read the current model**

Open `app/Models/Avatar.php` and find the `$fillable` array and `$casts` array.

- [ ] **Step 2: Add new fields to fillable and casts**

Add to `$fillable`:
```php
'portrait_original_path',
'landmarks_json',
'sprite_status',
```

Add to `$casts`:
```php
'landmarks_json' => 'array',
'sprite_status'  => \App\Enums\SpriteStatus::class,
```

- [ ] **Step 3: Add spritesReady helper**

Add this method to the model:

```php
public function spritesReady(): bool
{
    return $this->sprite_status === \App\Enums\SpriteStatus::Ready;
}
```

- [ ] **Step 4: Run existing model tests (if any)**

```bash
php artisan test --filter Avatar
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Avatar.php
git commit -m "feat: add sprite fields to Avatar model"
```

---

## Task 4: Vercel functions project scaffold

**Files:**
- Create: `vercel-functions/package.json`
- Create: `vercel-functions/vercel.json`
- Create: `vercel-functions/.gitignore`

- [ ] **Step 1: Create package.json**

```json
{
  "name": "thelearningportal-vercel-functions",
  "version": "1.0.0",
  "private": true,
  "dependencies": {
    "@vladmandic/face-api": "^1.7.13",
    "audiodecode": "^3.0.2",
    "canvas": "^2.11.2",
    "node-fetch": "^3.3.2"
  },
  "engines": {
    "node": ">=20"
  }
}
```

- [ ] **Step 2: Create vercel.json**

```json
{
  "version": 2,
  "functions": {
    "api/*.js": {
      "memory": 1024,
      "maxDuration": 30
    }
  }
}
```

- [ ] **Step 3: Create .gitignore**

```
node_modules/
.vercel/
```

- [ ] **Step 4: Install dependencies**

```bash
cd vercel-functions && npm install
```

Expected: `node_modules/` created, no errors.

- [ ] **Step 5: Commit**

```bash
cd ..
git add vercel-functions/package.json vercel-functions/vercel.json vercel-functions/.gitignore vercel-functions/package-lock.json
git commit -m "feat: scaffold vercel-functions project"
```

---

## Task 5: Vercel audio-manifest function

**Files:**
- Create: `vercel-functions/api/audio-manifest.js`

- [ ] **Step 1: Write the function**

```js
// api/audio-manifest.js
// POST { audio_url: string } → { duration: number, samples: [{t, amp}] }

import audiodecode from 'audiodecode';
import fetch from 'node-fetch';

export default async function handler(req, res) {
    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const { audio_url } = req.body ?? {};
    if (!audio_url || typeof audio_url !== 'string') {
        return res.status(400).json({ error: 'audio_url required' });
    }

    try {
        const response = await fetch(audio_url);
        if (!response.ok) {
            return res.status(422).json({ error: 'Failed to fetch audio' });
        }

        const arrayBuffer = await response.arrayBuffer();
        const audio = await audiodecode(Buffer.from(arrayBuffer));

        const { channelData, sampleRate, duration } = audio;
        const mono = channelData[0]; // use first channel

        const windowMs = 50; // 50ms windows → 20 samples/sec
        const windowSize = Math.floor(sampleRate * windowMs / 1000);
        const samples = [];

        for (let i = 0; i < mono.length; i += windowSize) {
            const window = mono.slice(i, i + windowSize);
            // RMS amplitude
            const rms = Math.sqrt(window.reduce((sum, v) => sum + v * v, 0) / window.length);
            const t = parseFloat((i / sampleRate).toFixed(3));
            const amp = parseFloat(Math.min(rms * 4, 1).toFixed(3)); // scale to 0–1
            samples.push({ t, amp });
        }

        return res.status(200).json({ duration: parseFloat(duration.toFixed(3)), samples });
    } catch (err) {
        console.error('audio-manifest error:', err);
        return res.status(500).json({ error: 'Audio processing failed' });
    }
}
```

- [ ] **Step 2: Manual smoke test (local Vercel dev)**

```bash
cd vercel-functions && npx vercel dev &
curl -X POST http://localhost:3000/api/audio-manifest \
  -H "Content-Type: application/json" \
  -d '{"audio_url":"https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3"}' \
  | head -c 300
```

Expected: JSON with `duration` and `samples` array starting with `[{"t":0,"amp":...`.

- [ ] **Step 3: Commit**

```bash
cd ..
git add vercel-functions/api/audio-manifest.js
git commit -m "feat: add audio-manifest Vercel function"
```

---

## Task 6: Vercel detect-landmarks function

**Files:**
- Create: `vercel-functions/api/detect-landmarks.js`
- Create: `vercel-functions/models/` — face-api.js model weights (tiny_face_detector + face_landmark_68)

- [ ] **Step 1: Download face-api.js model weights**

```bash
cd vercel-functions
mkdir -p models
curl -L https://github.com/vladmandic/face-api/raw/master/model/tiny_face_detector_model-weights_manifest.json \
  -o models/tiny_face_detector_model-weights_manifest.json
curl -L https://github.com/vladmandic/face-api/raw/master/model/tiny_face_detector_model.bin \
  -o models/tiny_face_detector_model.bin
curl -L https://github.com/vladmandic/face-api/raw/master/model/face_landmark_68_model-weights_manifest.json \
  -o models/face_landmark_68_model-weights_manifest.json
curl -L https://github.com/vladmandic/face-api/raw/master/model/face_landmark_68_model.bin \
  -o models/face_landmark_68_model.bin
```

Expected: 4 files in `vercel-functions/models/`.

- [ ] **Step 2: Write the function**

```js
// api/detect-landmarks.js
// POST { portrait_base64: string } → { landmarks, mouth_frames: [base64 x4], eye_frames: { open, closed } }

import * as faceapi from '@vladmandic/face-api';
import { createCanvas, loadImage, Image } from 'canvas';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MODELS_PATH = path.join(__dirname, '..', 'models');

let modelsLoaded = false;

async function ensureModels() {
    if (modelsLoaded) return;
    // Patch face-api to use node-canvas
    faceapi.env.monkeyPatch({ Canvas: createCanvas, Image, ImageData: (await import('canvas')).ImageData });
    await faceapi.nets.tinyFaceDetector.loadFromDisk(MODELS_PATH);
    await faceapi.nets.faceLandmark68Net.loadFromDisk(MODELS_PATH);
    modelsLoaded = true;
}

function boundsFromPoints(points, padX = 10, padY = 10) {
    const xs = points.map(p => p.x);
    const ys = points.map(p => p.y);
    return {
        x: Math.max(0, Math.round(Math.min(...xs)) - padX),
        y: Math.max(0, Math.round(Math.min(...ys)) - padY),
        w: Math.round(Math.max(...xs) - Math.min(...xs)) + padX * 2,
        h: Math.round(Math.max(...ys) - Math.min(...ys)) + padY * 2,
    };
}

function cropRegion(srcCanvas, bounds) {
    const dst = createCanvas(bounds.w, bounds.h);
    const ctx = dst.getContext('2d');
    ctx.drawImage(srcCanvas, bounds.x, bounds.y, bounds.w, bounds.h, 0, 0, bounds.w, bounds.h);
    return dst;
}

// Generate 4 mouth sprite frames by darkening/lightening the crop to simulate open/closed
function generateMouthFrames(mouthCanvas) {
    const frames = [];
    // Frame 0: closed (darken 40%)
    // Frame 1: half (darken 20%)
    // Frame 2: open (original)
    // Frame 3: wide (brighten 10%)
    const adjustments = [-0.4, -0.2, 0, 0.1];

    for (const adj of adjustments) {
        const dst = createCanvas(mouthCanvas.width, mouthCanvas.height);
        const ctx = dst.getContext('2d');
        ctx.drawImage(mouthCanvas, 0, 0);
        if (adj !== 0) {
            const imageData = ctx.getImageData(0, 0, dst.width, dst.height);
            for (let i = 0; i < imageData.data.length; i += 4) {
                imageData.data[i]     = Math.min(255, Math.max(0, imageData.data[i]     + adj * 255));
                imageData.data[i + 1] = Math.min(255, Math.max(0, imageData.data[i + 1] + adj * 255));
                imageData.data[i + 2] = Math.min(255, Math.max(0, imageData.data[i + 2] + adj * 255));
            }
            ctx.putImageData(imageData, 0, 0);
        }
        frames.push(dst.toDataURL('image/png'));
    }
    return frames;
}

// Eye closed frame: draw a thin line over the eye region
function generateEyeClosedFrame(eyeCanvas) {
    const dst = createCanvas(eyeCanvas.width, eyeCanvas.height);
    const ctx = dst.getContext('2d');
    ctx.drawImage(eyeCanvas, 0, 0);
    // Draw a skin-tone bar over the eye
    ctx.fillStyle = 'rgba(210, 170, 130, 0.85)';
    const barH = Math.max(4, Math.round(eyeCanvas.height * 0.45));
    const barY = Math.round((eyeCanvas.height - barH) / 2);
    ctx.fillRect(0, barY, eyeCanvas.width, barH);
    return dst.toDataURL('image/png');
}

export default async function handler(req, res) {
    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const { portrait_base64 } = req.body ?? {};
    if (!portrait_base64 || typeof portrait_base64 !== 'string') {
        return res.status(400).json({ error: 'portrait_base64 required' });
    }

    try {
        await ensureModels();

        const img = await loadImage(portrait_base64.startsWith('data:')
            ? portrait_base64
            : `data:image/jpeg;base64,${portrait_base64}`);

        const canvas = createCanvas(img.width, img.height);
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0);

        const detection = await faceapi
            .detectSingleFace(canvas, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks();

        if (!detection) {
            return res.status(422).json({ error: 'No face detected in portrait' });
        }

        const lm = detection.landmarks;
        const mouthPoints = lm.getMouth();
        const leftEyePoints = lm.getLeftEye();
        const rightEyePoints = lm.getRightEye();

        const mouthBounds     = boundsFromPoints(mouthPoints, 12, 8);
        const leftEyeBounds   = boundsFromPoints(leftEyePoints, 8, 6);
        const rightEyeBounds  = boundsFromPoints(rightEyePoints, 8, 6);
        const faceBox         = detection.detection.box;
        const faceBounds      = {
            x: Math.round(faceBox.x), y: Math.round(faceBox.y),
            w: Math.round(faceBox.width), h: Math.round(faceBox.height),
        };

        const mouthCrop    = cropRegion(canvas, mouthBounds);
        const leftEyeCrop  = cropRegion(canvas, leftEyeBounds);
        const rightEyeCrop = cropRegion(canvas, rightEyeBounds);

        const mouthFrames     = generateMouthFrames(mouthCrop);
        const leftEyeOpen     = leftEyeCrop.toDataURL('image/png');
        const leftEyeClosed   = generateEyeClosedFrame(leftEyeCrop);
        const rightEyeOpen    = rightEyeCrop.toDataURL('image/png');
        const rightEyeClosed  = generateEyeClosedFrame(rightEyeCrop);

        return res.status(200).json({
            landmarks: {
                mouth:       mouthBounds,
                left_eye:    leftEyeBounds,
                right_eye:   rightEyeBounds,
                face_bounds: faceBounds,
            },
            mouth_frames: mouthFrames,
            eye_frames: {
                left_open:    leftEyeOpen,
                left_closed:  leftEyeClosed,
                right_open:   rightEyeOpen,
                right_closed: rightEyeClosed,
            },
        });
    } catch (err) {
        console.error('detect-landmarks error:', err);
        return res.status(500).json({ error: 'Landmark detection failed', detail: err.message });
    }
}
```

- [ ] **Step 3: Manual smoke test**

```bash
cd vercel-functions
# Encode a test portrait to base64
BASE64=$(base64 -i ../public/assets/professor.webp | tr -d '\n')
curl -X POST http://localhost:3000/api/detect-landmarks \
  -H "Content-Type: application/json" \
  -d "{\"portrait_base64\":\"${BASE64}\"}" \
  | python3 -m json.tool | head -40
```

Expected: JSON with `landmarks.mouth`, `landmarks.left_eye`, `landmarks.right_eye`, `mouth_frames` array of 4 base64 strings, `eye_frames` object.

- [ ] **Step 4: Commit**

```bash
cd ..
git add vercel-functions/api/detect-landmarks.js vercel-functions/models/
git commit -m "feat: add detect-landmarks Vercel function with face-api.js"
```

---

## Task 7: ProcessAvatarPortrait job

**Files:**
- Create: `app/Jobs/ProcessAvatarPortrait.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/Jobs/ProcessAvatarPortraitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\SpriteStatus;
use App\Jobs\ProcessAvatarPortrait;
use App\Models\Avatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessAvatarPortraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_sprite_status_to_ready_on_success(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/1/portrait.jpg', 'fake-image-data');

        $avatar = Avatar::factory()->create([
            'portrait_path' => 'avatars/1/portrait.jpg',
            'sprite_status' => SpriteStatus::Pending,
        ]);

        Http::fake([
            config('services.vercel_functions.url') . '/api/detect-landmarks' => Http::response([
                'landmarks' => [
                    'mouth'       => ['x' => 100, 'y' => 200, 'w' => 80, 'h' => 40],
                    'left_eye'    => ['x' => 80,  'y' => 150, 'w' => 40, 'h' => 20],
                    'right_eye'   => ['x' => 180, 'y' => 150, 'w' => 40, 'h' => 20],
                    'face_bounds' => ['x' => 50,  'y' => 100, 'w' => 200,'h' => 250],
                ],
                'mouth_frames' => ['data:image/png;base64,abc', 'data:image/png;base64,def', 'data:image/png;base64,ghi', 'data:image/png;base64,jkl'],
                'eye_frames'   => [
                    'left_open'    => 'data:image/png;base64,lo',
                    'left_closed'  => 'data:image/png;base64,lc',
                    'right_open'   => 'data:image/png;base64,ro',
                    'right_closed' => 'data:image/png;base64,rc',
                ],
            ], 200),
        ]);

        (new ProcessAvatarPortrait($avatar->id))->handle();

        $avatar->refresh();
        $this->assertSame(SpriteStatus::Ready, $avatar->sprite_status);
        $this->assertNotNull($avatar->landmarks_json);
    }

    public function test_sets_sprite_status_to_failed_when_vercel_errors(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/1/portrait.jpg', 'fake-image-data');

        $avatar = Avatar::factory()->create([
            'portrait_path' => 'avatars/1/portrait.jpg',
            'sprite_status' => SpriteStatus::Pending,
        ]);

        Http::fake([
            config('services.vercel_functions.url') . '/api/detect-landmarks' => Http::response(['error' => 'No face detected'], 422),
        ]);

        (new ProcessAvatarPortrait($avatar->id))->handle();

        $avatar->refresh();
        $this->assertSame(SpriteStatus::Failed, $avatar->sprite_status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/Jobs/ProcessAvatarPortraitTest.php
```

Expected: FAIL — `ProcessAvatarPortrait` class not found.

- [ ] **Step 3: Create the job**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SpriteStatus;
use App\Models\Avatar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessAvatarPortrait implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $avatarId) {}

    public function handle(): void
    {
        $avatar = Avatar::findOrFail($this->avatarId);
        $avatar->update(['sprite_status' => SpriteStatus::Processing]);

        $portraitPath = $avatar->portrait_path;

        if (! $portraitPath || ! Storage::disk('public')->exists($portraitPath)) {
            Log::error("ProcessAvatarPortrait: portrait not found for avatar {$this->avatarId}");
            $avatar->update(['sprite_status' => SpriteStatus::Failed]);
            return;
        }

        $imageBytes  = Storage::disk('public')->get($portraitPath);
        $base64      = base64_encode($imageBytes);
        $vercelUrl   = rtrim(config('services.vercel_functions.url'), '/');

        $response = Http::timeout(30)
            ->post("{$vercelUrl}/api/detect-landmarks", [
                'portrait_base64' => $base64,
            ]);

        if (! $response->successful()) {
            Log::error("ProcessAvatarPortrait: detect-landmarks failed", [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
            $avatar->update(['sprite_status' => SpriteStatus::Failed]);
            return;
        }

        $data      = $response->json();
        $landmarks = $data['landmarks'];
        $baseDir   = "avatars/{$this->avatarId}/sprites";

        // Store mouth frames
        foreach ($data['mouth_frames'] as $i => $frame) {
            $this->storeBase64($frame, "{$baseDir}/mouth_{$i}.png");
        }

        // Store eye frames
        foreach (['left_open', 'left_closed', 'right_open', 'right_closed'] as $key) {
            $this->storeBase64($data['eye_frames'][$key], "{$baseDir}/eye_{$key}.png");
        }

        $avatar->update([
            'landmarks_json' => $landmarks,
            'sprite_status'  => SpriteStatus::Ready,
        ]);

        Log::info("ProcessAvatarPortrait: sprites ready for avatar {$this->avatarId}");
    }

    private function storeBase64(string $dataUrl, string $storagePath): void
    {
        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $dataUrl);
        Storage::disk('public')->put($storagePath, base64_decode($base64));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Feature/Jobs/ProcessAvatarPortraitTest.php
```

Expected: 2 tests, PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ProcessAvatarPortrait.php tests/Feature/Jobs/ProcessAvatarPortraitTest.php
git commit -m "feat: add ProcessAvatarPortrait job"
```

---

## Task 8: config/services.php + .env additions

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Add vercel_functions key to config/services.php**

Find the end of `config/services.php` and add:

```php
'vercel_functions' => [
    'url' => env('VERCEL_FUNCTIONS_URL', 'http://localhost:3000'),
],
```

- [ ] **Step 2: Add to .env.example**

```
VERCEL_FUNCTIONS_URL=http://localhost:3000
```

Also add to your local `.env`:
```
VERCEL_FUNCTIONS_URL=http://localhost:3000
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat: add vercel_functions config"
```

---

## Task 9: Avatar Studio — portrait upload UI

**Files:**
- Modify: `app/Livewire/Admin/AvatarStudio.php`
- Create: `resources/views/livewire/admin/avatar-studio/portrait-tab.blade.php`
- Modify: `resources/views/livewire/admin/avatar-studio.blade.php`

- [ ] **Step 1: Add portrait upload to AvatarStudio Livewire component**

Add these properties after `public bool $flashError = false;`:

```php
// ── Portrait upload ───────────────────────────────────────────────────────
#[Validate('nullable|image|max:4096')]
public $portraitUpload = null;

public bool $uploadingPortrait = false;
```

Add `use Livewire\WithFileUploads;` trait to the class.

Add this method to the class:

```php
public function uploadPortrait(): void
{
    $this->validateOnly('portraitUpload', ['portraitUpload' => 'required|image|max:4096']);

    $this->uploadingPortrait = true;

    try {
        $originalPath = $this->portraitUpload->storePubliclyAs(
            "avatars/{$this->avatar->id}",
            'portrait_original.' . $this->portraitUpload->getClientOriginalExtension(),
            'public'
        );

        // Resize and store as portrait.jpg using AvatarService
        $imageBytes  = Storage::disk('public')->get($originalPath);
        $resized     = app(\App\Services\AvatarService::class)->resizePortraitPublic($imageBytes);
        $portraitPath = "avatars/{$this->avatar->id}/portrait.jpg";
        Storage::disk('public')->put($portraitPath, $resized);

        $this->avatar->update([
            'portrait_path'          => $portraitPath,
            'portrait_original_path' => $originalPath,
            'sprite_status'          => \App\Enums\SpriteStatus::Pending,
        ]);

        \App\Jobs\ProcessAvatarPortrait::dispatch($this->avatar->id);

        $this->portraitUpload = null;
        $this->flash('Portrait uploaded! Processing sprites in background...', false);
    } catch (\Throwable $e) {
        Log::error('AvatarStudio: portrait upload failed', ['error' => $e->getMessage()]);
        $this->flash('Upload failed: ' . $e->getMessage(), true);
    } finally {
        $this->uploadingPortrait = false;
    }
}
```

- [ ] **Step 2: Expose resizePortrait as public in AvatarService**

In `app/Services/AvatarService.php`, add a public wrapper method:

```php
public function resizePortraitPublic(string $imageData, int $maxDim = 512): string
{
    return $this->resizePortrait($imageData, $maxDim);
}
```

- [ ] **Step 3: Create portrait-tab.blade.php**

```blade
{{-- resources/views/livewire/admin/avatar-studio/portrait-tab.blade.php --}}
<div class="space-y-6" x-data="{ spriteStatus: '{{ $avatar->sprite_status?->value ?? 'pending' }}' }">

    {{-- Current portrait --}}
    <div class="flex items-start gap-6">
        <img
            src="{{ $avatar->portraitUrl() }}"
            alt="{{ $avatar->name }}"
            class="h-32 w-32 rounded-2xl object-cover border-2 border-amber-500/30"
        >
        <div class="space-y-1">
            <p class="text-sm font-medium text-slate-200">Current portrait</p>
            <p class="text-xs text-slate-500">512 × 512 px recommended. Clear face, neutral background.</p>
            <div class="flex items-center gap-2 mt-2">
                <span class="text-xs px-2 py-0.5 rounded-full border
                    {{ match($avatar->sprite_status?->value) {
                        'ready'      => 'bg-emerald-950/60 border-emerald-700 text-emerald-300',
                        'processing' => 'bg-amber-950/60 border-amber-700 text-amber-300',
                        'failed'     => 'bg-rose-950/60 border-rose-700 text-rose-300',
                        default      => 'bg-slate-900 border-slate-700 text-slate-500',
                    } }}">
                    Sprites: {{ ucfirst($avatar->sprite_status?->value ?? 'pending') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Upload form --}}
    <form wire:submit="uploadPortrait" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">Upload new portrait</label>
            <input
                type="file"
                wire:model="portraitUpload"
                accept="image/*"
                class="block w-full text-sm text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-amber-500/10 file:text-amber-400 hover:file:bg-amber-500/20"
            >
            @error('portraitUpload') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
        </div>

        <button
            type="submit"
            wire:loading.attr="disabled"
            class="px-4 py-2 rounded-xl bg-amber-500 text-slate-900 text-sm font-semibold hover:bg-amber-400 disabled:opacity-50 transition-colors"
        >
            <span wire:loading.remove wire:target="uploadPortrait">Upload & Process</span>
            <span wire:loading wire:target="uploadPortrait">Processing…</span>
        </button>
    </form>

    {{-- Animated preview (shown when sprites are ready) --}}
    @if($avatar->spritesReady())
        <div class="space-y-3">
            <p class="text-sm font-medium text-slate-300">Live preview</p>
            <div
                id="avatar-preview"
                data-portrait="{{ Storage::disk('public')->url($avatar->portrait_path) }}"
                data-landmarks="{{ json_encode($avatar->landmarks_json) }}"
                data-sprites="{{ json_encode([
                    'mouth' => [
                        Storage::disk('public')->url("avatars/{$avatar->id}/sprites/mouth_0.png"),
                        Storage::disk('public')->url("avatars/{$avatar->id}/sprites/mouth_1.png"),
                        Storage::disk('public')->url("avatars/{$avatar->id}/sprites/mouth_2.png"),
                        Storage::disk('public')->url("avatars/{$avatar->id}/sprites/mouth_3.png"),
                    ],
                    'left_eye_open'    => Storage::disk('public')->url("avatars/{$avatar->id}/sprites/eye_left_open.png"),
                    'left_eye_closed'  => Storage::disk('public')->url("avatars/{$avatar->id}/sprites/eye_left_closed.png"),
                    'right_eye_open'   => Storage::disk('public')->url("avatars/{$avatar->id}/sprites/eye_right_open.png"),
                    'right_eye_closed' => Storage::disk('public')->url("avatars/{$avatar->id}/sprites/eye_right_closed.png"),
                ]) }}"
                class="inline-block"
            >
                <canvas id="avatar-canvas" width="256" height="256" class="rounded-2xl border border-slate-700"></canvas>
            </div>
            <p class="text-xs text-slate-500">Preview uses demo amplitude. Connect audio at playback.</p>
        </div>
    @endif
</div>
```

- [ ] **Step 4: Add Portrait tab to avatar-studio.blade.php**

In `resources/views/livewire/admin/avatar-studio.blade.php`, find the tab bar array:

```php
[['greeting', '👋 Greeting'], ['voice', '🎙️ Voice Studio'], ['settings', '⚙️ Settings'], ['samples', '🎧 All samples']]
```

Replace with:

```php
[['portrait', '🖼️ Portrait'], ['greeting', '👋 Greeting'], ['voice', '🎙️ Voice Studio'], ['settings', '⚙️ Settings'], ['samples', '🎧 All samples']]
```

Then add the portrait tab panel after the last existing tab panel:

```blade
<div x-show="activeTab === 'portrait'" x-cloak>
    @include('livewire.admin.avatar-studio.portrait-tab')
</div>
```

- [ ] **Step 5: Run feature test**

```bash
php artisan test --filter AvatarStudio
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Admin/AvatarStudio.php app/Services/AvatarService.php \
        resources/views/livewire/admin/avatar-studio.blade.php \
        resources/views/livewire/admin/avatar-studio/portrait-tab.blade.php
git commit -m "feat: add portrait upload tab to Avatar Studio"
```

---

## Task 10: Client-side avatar animator (Phase 1 — amplitude)

**Files:**
- Create: `resources/js/avatar-animator.js`
- Modify: `resources/js/app.js` — import animator

- [ ] **Step 1: Write avatar-animator.js**

```js
// resources/js/avatar-animator.js
// Animates a portrait canvas using an amplitude manifest.
// Layers: mouth sprites, eye blink, head Perlin drift, breathing CSS.

// Minimal Perlin noise (1D)
function perlin(t) {
    const n = Math.sin(t * 12.9898 + t * 78.233) * 43758.5453;
    return (n - Math.floor(n)) * 2 - 1; // -1 to 1
}

export function initAvatarAnimator(canvasId, previewElId) {
    const previewEl  = document.getElementById(previewElId);
    const canvas     = document.getElementById(canvasId);
    if (!canvas || !previewEl) return;

    const ctx        = canvas.getContext('2d');
    const portrait   = new Image();
    const landmarks  = JSON.parse(previewEl.dataset.landmarks || '{}');
    const spriteDefs = JSON.parse(previewEl.dataset.sprites   || '{}');

    // Load all images
    const mouthImgs = (spriteDefs.mouth || []).map(src => { const i = new Image(); i.src = src; return i; });
    const eyeImgs   = {
        leftOpen:    loadImg(spriteDefs.left_eye_open),
        leftClosed:  loadImg(spriteDefs.left_eye_closed),
        rightOpen:   loadImg(spriteDefs.right_eye_open),
        rightClosed: loadImg(spriteDefs.right_eye_closed),
    };

    portrait.src = previewEl.dataset.portrait;

    function loadImg(src) { if (!src) return null; const i = new Image(); i.src = src; return i; }

    // Animation state
    let samples       = [];      // [{t, amp}] from manifest
    let audioStartTime = null;   // performance.now() when audio started
    let demoMode      = true;    // use synthetic sine wave when no manifest loaded
    let eyeOpen       = true;
    let nextBlinkAt   = Date.now() + randomBlinkInterval();

    function randomBlinkInterval() { return 2000 + Math.random() * 3000; }

    // Public: load manifest and start synced animation
    window.avatarLoadManifest = function(manifestJson, audioStartedAt) {
        samples        = manifestJson.samples;
        audioStartTime = audioStartedAt;
        demoMode       = false;
    };

    function currentAmp() {
        if (demoMode) {
            return (Math.sin(Date.now() / 200) + 1) / 2 * 0.6; // synthetic 0–0.6
        }
        if (!audioStartTime || samples.length === 0) return 0;
        const elapsed = (performance.now() - audioStartTime) / 1000;
        // Binary search nearest sample
        let lo = 0, hi = samples.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (samples[mid].t <= elapsed) lo = mid; else hi = mid - 1;
        }
        return samples[lo].amp;
    }

    function ampToMouthFrame(amp) {
        if (amp < 0.1) return 0;
        if (amp < 0.3) return 1;
        if (amp < 0.6) return 2;
        return 3;
    }

    function draw(ts) {
        const t   = ts / 1000;
        const amp = currentAmp();

        // Head micro-movement
        const dx  = perlin(t * 0.3) * 2;
        const dy  = perlin(t * 0.4 + 10) * 2;
        const rot = perlin(t * 0.2 + 5) * 0.008; // radians

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.save();
        ctx.translate(canvas.width / 2 + dx, canvas.height / 2 + dy);
        ctx.rotate(rot);
        ctx.translate(-canvas.width / 2, -canvas.height / 2);

        // Draw portrait
        if (portrait.complete) {
            ctx.drawImage(portrait, 0, 0, canvas.width, canvas.height);
        }

        // Scale landmarks to canvas size
        const scaleX = canvas.width  / (portrait.naturalWidth  || canvas.width);
        const scaleY = canvas.height / (portrait.naturalHeight || canvas.height);

        // Draw mouth sprite
        const mouthFrame = mouthImgs[ampToMouthFrame(amp)];
        if (mouthFrame?.complete && landmarks.mouth) {
            const m = landmarks.mouth;
            ctx.drawImage(mouthFrame, m.x * scaleX, m.y * scaleY, m.w * scaleX, m.h * scaleY);
        }

        // Eye blink
        const now = Date.now();
        if (now >= nextBlinkAt) {
            eyeOpen = !eyeOpen;
            nextBlinkAt = now + (eyeOpen ? randomBlinkInterval() : 120); // closed for 120ms
        }

        // Draw eye overlays
        if (!eyeOpen) {
            for (const [side, lmKey] of [['left', 'left_eye'], ['right', 'right_eye']]) {
                const img = eyeImgs[side + 'Closed'];
                const lm  = landmarks[lmKey];
                if (img?.complete && lm) {
                    ctx.drawImage(img, lm.x * scaleX, lm.y * scaleY, lm.w * scaleX, lm.h * scaleY);
                }
            }
        }

        ctx.restore();
        requestAnimationFrame(draw);
    }

    portrait.onload = () => requestAnimationFrame(draw);
    if (portrait.complete) requestAnimationFrame(draw);
}
```

- [ ] **Step 2: Import in app.js**

Add to the end of `resources/js/app.js`:

```js
import { initAvatarAnimator } from './avatar-animator.js';

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('avatar-canvas')) {
        initAvatarAnimator('avatar-canvas', 'avatar-preview');
    }
});
```

- [ ] **Step 3: Build assets**

```bash
npm run build
```

Expected: no errors, `public/build/assets/app-*.js` updated.

- [ ] **Step 4: Manual browser test**

1. Open Avatar Studio for any avatar that has `sprite_status = ready`
2. Navigate to the Portrait tab
3. Verify canvas shows portrait with mouth moving in demo sine-wave mode and random eye blinks

- [ ] **Step 5: Commit**

```bash
git add resources/js/avatar-animator.js resources/js/app.js public/build/
git commit -m "feat: add client-side avatar animator with amplitude, blink, head drift"
```

---

## Task 11: Audio manifest API endpoint (Laravel → Vercel proxy)

**Files:**
- Create: `app/Http/Controllers/Api/AudioManifestController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/Api/AudioManifestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AudioManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_manifest_from_vercel(): void
    {
        Http::fake([
            config('services.vercel_functions.url') . '/api/audio-manifest' => Http::response([
                'duration' => 5.0,
                'samples'  => [['t' => 0.0, 'amp' => 0.1]],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/audio-manifest?audio_url=' . urlencode('http://example.com/audio.mp3'));

        $response->assertOk()->assertJsonStructure(['duration', 'samples']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/Api/AudioManifestTest.php
```

Expected: FAIL — 404.

- [ ] **Step 3: Create controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AudioManifestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['audio_url' => 'required|url']);

        $vercelUrl = rtrim(config('services.vercel_functions.url'), '/');

        $response = Http::timeout(15)->post("{$vercelUrl}/api/audio-manifest", [
            'audio_url' => $request->input('audio_url'),
        ]);

        if (! $response->successful()) {
            return response()->json(['error' => 'Manifest generation failed'], 502);
        }

        return response()->json($response->json());
    }
}
```

- [ ] **Step 4: Add route to routes/api.php**

```php
Route::get('/audio-manifest', \App\Http\Controllers\Api\AudioManifestController::class);
```

(Add inside the `v1` group if one exists, otherwise at top level.)

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Feature/Api/AudioManifestTest.php
```

Expected: 1 test, PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/AudioManifestController.php routes/api.php \
        tests/Feature/Api/AudioManifestTest.php
git commit -m "feat: add audio-manifest API endpoint"
```

---

## Task 12: Deploy Vercel functions

**Files:**
- No code changes — deployment only

- [ ] **Step 1: Install Vercel CLI**

```bash
npm install -g vercel
```

- [ ] **Step 2: Login and deploy**

```bash
cd vercel-functions
vercel login
vercel deploy --prod
```

Expected: Vercel outputs a production URL like `https://thelearningportal-functions.vercel.app`.

- [ ] **Step 3: Update .env with production URL**

```
VERCEL_FUNCTIONS_URL=https://thelearningportal-functions.vercel.app
```

- [ ] **Step 4: Smoke test production endpoint**

```bash
curl -X POST https://thelearningportal-functions.vercel.app/api/audio-manifest \
  -H "Content-Type: application/json" \
  -d '{"audio_url":"https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3"}' \
  | python3 -m json.tool | head -20
```

Expected: JSON response with `duration` and `samples`.

- [ ] **Step 5: Commit env update (without secrets)**

```bash
cd ..
git add .env.example
git commit -m "chore: document VERCEL_FUNCTIONS_URL for production"
```

---

## Task 13: End-to-end manual test

- [ ] **Step 1: Start local dev**

```bash
composer dev
cd vercel-functions && npx vercel dev
```

- [ ] **Step 2: Upload Julius Caesar portrait**

1. Open `/admin/avatars/{id}` in browser
2. Go to Portrait tab
3. Upload a clear portrait image of Julius Caesar
4. Confirm flash: "Portrait uploaded! Processing sprites in background…"

- [ ] **Step 3: Verify sprite processing**

```bash
php artisan queue:work --once
```

Expected: Log shows `ProcessAvatarPortrait: sprites ready for avatar X`.

- [ ] **Step 4: Verify sprites on disk**

```bash
ls storage/app/public/avatars/{id}/sprites/
```

Expected: `mouth_0.png mouth_1.png mouth_2.png mouth_3.png eye_left_open.png eye_left_closed.png eye_right_open.png eye_right_closed.png`

- [ ] **Step 5: Verify portrait tab preview**

Reload Avatar Studio → Portrait tab → canvas should show animated Julius Caesar with mouth moving and eyes blinking.

- [ ] **Step 6: Final commit**

```bash
git add -A
git commit -m "feat: avatar studio talking head — Phase 1 complete"
```

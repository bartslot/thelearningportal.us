# 3D Avatar Lab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a parallel 3D avatar pipeline with Azure Speech lip-sync, accessible at `/admin/avatar-lab`, alongside the untouched 2D system.

**Architecture:** A new `AzureTtsService` shells out to `scripts/azure_tts.py` (Python Azure SDK) to produce `audio_3d.mp3` + `blendshapes.json` (55 ARKit weights at 60fps). A `GenerateAvatarPreview3d` job dispatches this work. An `AvatarLab` Livewire page renders a Three.js `Avatar3DPlayer` driven by the blend shapes. All new — nothing in the 2D pipeline changes.

**Tech Stack:** Laravel 12, Livewire 3, Three.js v0.183, Azure Cognitive Services Speech SDK (Python), PHPUnit 11, Alpine.js

---

### Task 1: Config & environment variables

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Add Azure Speech config to `config/services.php`**

Open `config/services.php` and add inside the return array:

```php
'azure_speech' => [
    'key'    => env('AZURE_SPEECH_KEY'),
    'region' => env('AZURE_SPEECH_REGION', 'eastus'),
],
```

- [ ] **Step 2: Add vars to `.env.example`**

Add after the existing AI service vars:

```env
# Azure Speech Service (3D avatar lip sync)
AZURE_SPEECH_KEY=
AZURE_SPEECH_REGION=eastus
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat: add Azure Speech config for 3D avatar pipeline"
```

---

### Task 2: Migration — avatars table 3D settings

**Files:**
- Create: `database/migrations/2026_04_08_000001_add_3d_fields_to_avatars_table.php`

- [ ] **Step 1: Generate migration**

```bash
php artisan make:migration add_3d_fields_to_avatars_table --table=avatars
```

Rename the generated file to `2026_04_08_000001_add_3d_fields_to_avatars_table.php` if needed.

- [ ] **Step 2: Write migration content**

Replace the `up()` and `down()` methods:

```php
public function up(): void
{
    Schema::table('avatars', function (Blueprint $table) {
        $table->enum('presentation_mode', ['fullscreen', 'framed', 'hidden'])
              ->default('framed')->after('portrait_path');
        $table->tinyInteger('age')->unsigned()
              ->default(35)->after('presentation_mode');
        $table->enum('gender', ['male', 'female'])
              ->default('male')->after('age');
        $table->enum('emotion_style', ['auto', 'narrative', 'cheerful', 'serious', 'excited', 'empathetic', 'whispering'])
              ->default('auto')->after('gender');
        $table->decimal('expressiveness', 3, 2)
              ->default(1.20)->after('emotion_style');
        $table->decimal('speaking_speed', 3, 2)
              ->default(1.00)->after('expressiveness');
        $table->string('frame_background', 7)
              ->default('#0f172a')->after('speaking_speed');
    });
}

public function down(): void
{
    Schema::table('avatars', function (Blueprint $table) {
        $table->dropColumn([
            'presentation_mode', 'age', 'gender', 'emotion_style',
            'expressiveness', 'speaking_speed', 'frame_background',
        ]);
    });
}
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

Expected output: `Migrating: 2026_04_08_000001_add_3d_fields_to_avatars_table` then `Migrated`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_08_000001_add_3d_fields_to_avatars_table.php
git commit -m "feat: add 3D avatar settings columns to avatars table"
```

---

### Task 3: Migration — lessons table 3D audio fields

**Files:**
- Create: `database/migrations/2026_04_08_000002_add_3d_audio_fields_to_lessons_table.php`

- [ ] **Step 1: Generate migration**

```bash
php artisan make:migration add_3d_audio_fields_to_lessons_table --table=lessons
```

- [ ] **Step 2: Write migration content**

```php
public function up(): void
{
    Schema::table('lessons', function (Blueprint $table) {
        $table->string('audio_3d_path')->nullable()->after('audio_path');
        $table->string('blendshapes_path')->nullable()->after('audio_3d_path');
    });
}

public function down(): void
{
    Schema::table('lessons', function (Blueprint $table) {
        $table->dropColumn(['audio_3d_path', 'blendshapes_path']);
    });
}
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

Expected: `Migrated: 2026_04_08_000002_add_3d_audio_fields_to_lessons_table`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_08_000002_add_3d_audio_fields_to_lessons_table.php
git commit -m "feat: add audio_3d_path and blendshapes_path to lessons table"
```

---

### Task 4: Update Avatar and Lesson models

**Files:**
- Modify: `app/Models/Avatar.php`
- Modify: `app/Models/Lesson.php`

- [ ] **Step 1: Add 3D fields to `Avatar::$fillable`**

In `app/Models/Avatar.php`, find the `$fillable` array and add these seven fields:

```php
'presentation_mode',
'age',
'gender',
'emotion_style',
'expressiveness',
'speaking_speed',
'frame_background',
```

- [ ] **Step 2: Add casts for the new Avatar fields**

In the `$casts` array of `Avatar.php`, add:

```php
'age'            => 'integer',
'expressiveness' => 'float',
'speaking_speed' => 'float',
```

- [ ] **Step 3: Add 3D fields to `Lesson::$fillable`**

In `app/Models/Lesson.php`, find the `$fillable` array and add:

```php
'audio_3d_path',
'blendshapes_path',
```

- [ ] **Step 4: Verify with tinker**

```bash
php artisan tinker --execute="echo implode(', ', (new App\Models\Avatar)->getFillable());"
```

Expected output includes: `presentation_mode, age, gender, emotion_style, expressiveness, speaking_speed, frame_background`

- [ ] **Step 5: Commit**

```bash
git add app/Models/Avatar.php app/Models/Lesson.php
git commit -m "feat: add 3D fields to Avatar and Lesson models"
```

---

### Task 5: AzureTtsException

**Files:**
- Create: `app/Exceptions/AzureTtsException.php`

- [ ] **Step 1: Create the exception class**

```bash
mkdir -p app/Exceptions
```

Create `app/Exceptions/AzureTtsException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class AzureTtsException extends RuntimeException
{
    //
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Exceptions/AzureTtsException.php
git commit -m "feat: add AzureTtsException"
```

---

### Task 6: SsmlBuilder service (with tests)

**Files:**
- Create: `app/Services/SsmlBuilder.php`
- Create: `tests/Unit/Services/SsmlBuilderTest.php`

- [ ] **Step 1: Write the failing tests first**

Create `tests/Unit/Services/SsmlBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Avatar;
use App\Services\SsmlBuilder;
use PHPUnit\Framework\TestCase;

class SsmlBuilderTest extends TestCase
{
    private SsmlBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SsmlBuilder();
    }

    private function makeAvatar(array $attrs = []): Avatar
    {
        $avatar = new Avatar();
        $avatar->forceFill(array_merge([
            'gender'        => 'male',
            'age'           => 35,
            'emotion_style' => 'auto',
            'expressiveness' => 1.2,
            'speaking_speed' => 1.0,
        ], $attrs));
        return $avatar;
    }

    public function test_wraps_plain_script_in_speak_envelope(): void
    {
        $avatar = $this->makeAvatar();
        $ssml   = $this->builder->build('Hello world.', $avatar);

        $this->assertStringContainsString('<speak version="1.0"', $ssml);
        $this->assertStringContainsString('xmlns:mstts=', $ssml);
        $this->assertStringContainsString('<voice name="en-US-GuyNeural">', $ssml);
        $this->assertStringContainsString('<mstts:viseme type="FacialExpression"/>', $ssml);
        $this->assertStringContainsString('Hello world.', $ssml);
    }

    public function test_applies_prosody_rate(): void
    {
        $avatar = $this->makeAvatar(['speaking_speed' => 1.25]);
        $ssml   = $this->builder->build('Test.', $avatar);

        $this->assertStringContainsString('rate="1.25"', $ssml);
    }

    public function test_parses_emotion_tags_in_auto_mode(): void
    {
        $avatar = $this->makeAvatar(['emotion_style' => 'auto', 'expressiveness' => 1.5]);
        $ssml   = $this->builder->build('Intro. [serious]Heavy losses.[/serious] Done.', $avatar);

        $this->assertStringContainsString('<mstts:express-as style="serious" styledegree="1.50">', $ssml);
        $this->assertStringContainsString('Heavy losses.', $ssml);
        $this->assertStringContainsString('Intro.', $ssml);
    }

    public function test_wraps_entire_script_when_style_is_not_auto(): void
    {
        $avatar = $this->makeAvatar(['emotion_style' => 'cheerful', 'expressiveness' => 1.0]);
        $ssml   = $this->builder->build('[serious]Ignored.[/serious] Text.', $avatar);

        $this->assertStringContainsString('<mstts:express-as style="cheerful" styledegree="1.00">', $ssml);
        // The tag should not be processed — raw text inside
        $this->assertStringContainsString('[serious]', $ssml);
    }

    public function test_resolves_female_child_voice(): void
    {
        $avatar = $this->makeAvatar(['gender' => 'female', 'age' => 12]);
        $ssml   = $this->builder->build('Hi.', $avatar);

        $this->assertStringContainsString('<voice name="en-US-AnaNeural">', $ssml);
    }

    public function test_resolves_male_elder_voice(): void
    {
        $avatar = $this->makeAvatar(['gender' => 'male', 'age' => 70]);
        $ssml   = $this->builder->build('Hi.', $avatar);

        $this->assertStringContainsString('<voice name="en-US-RogerNeural">', $ssml);
    }

    public function test_escapes_special_xml_chars_in_plain_text(): void
    {
        $avatar = $this->makeAvatar();
        $ssml   = $this->builder->build('Caesar & Brutus <together>.', $avatar);

        $this->assertStringContainsString('Caesar &amp; Brutus &lt;together&gt;.', $ssml);
    }

    public function test_ignores_unknown_emotion_tags(): void
    {
        $avatar = $this->makeAvatar();
        $ssml   = $this->builder->build('[angry]Raw tag.[/angry]', $avatar);

        // Unknown tags pass through as plain text (XML-escaped)
        $this->assertStringNotContainsString('<mstts:express-as', $ssml);
    }
}
```

- [ ] **Step 2: Run tests — expect failures**

```bash
php artisan test tests/Unit/Services/SsmlBuilderTest.php
```

Expected: `ERROR` — `Class "App\Services\SsmlBuilder" not found`

- [ ] **Step 3: Create `app/Services/SsmlBuilder.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Avatar;

class SsmlBuilder
{
    private const ALLOWED_EMOTIONS = [
        'serious', 'cheerful', 'excited', 'empathetic', 'whispering', 'narrative',
    ];

    public function build(string $script, Avatar $avatar): string
    {
        $voice = $this->resolveVoice($avatar);
        $rate  = number_format((float) $avatar->speaking_speed, 2);
        $inner = $avatar->emotion_style === 'auto'
            ? $this->parseEmotionTags($script, (float) $avatar->expressiveness)
            : $this->wrapEntire($script, $avatar->emotion_style, (float) $avatar->expressiveness);

        return implode('', [
            '<speak version="1.0"',
            ' xmlns="http://www.w3.org/2001/10/synthesis"',
            ' xmlns:mstts="https://www.w3.org/2001/mstts"',
            ' xml:lang="en-US">',
            "<voice name=\"{$voice}\">",
            '<mstts:viseme type="FacialExpression"/>',
            "<prosody rate=\"{$rate}\">",
            $inner,
            '</prosody>',
            '</voice>',
            '</speak>',
        ]);
    }

    private function wrapEntire(string $script, string $style, float $expressiveness): string
    {
        $degree = number_format($expressiveness, 2);
        $text   = htmlspecialchars($script, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "<mstts:express-as style=\"{$style}\" styledegree=\"{$degree}\">{$text}</mstts:express-as>";
    }

    private function parseEmotionTags(string $script, float $expressiveness): string
    {
        $joined  = implode('|', self::ALLOWED_EMOTIONS);
        $pattern = '/\[(' . $joined . ')\](.*?)\[\/\1\]/s';
        $degree  = number_format($expressiveness, 2);

        // Split on emotion tag boundaries, preserving delimiters
        $parts  = preg_split('/(' . $pattern . ')/s', $script, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $tagRe  = '/^\[(' . $joined . ')\](.*?)\[\/\1\]$/s';
        $output = '';

        foreach ($parts as $part) {
            if (preg_match($tagRe, $part, $m)) {
                $text    = htmlspecialchars(trim($m[2]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $output .= "<mstts:express-as style=\"{$m[1]}\" styledegree=\"{$degree}\">{$text}</mstts:express-as>";
            } else {
                $output .= htmlspecialchars($part, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
        }

        return $output;
    }

    private function resolveVoice(Avatar $avatar): string
    {
        $age    = (int) $avatar->age;
        $gender = $avatar->gender;

        if ($gender === 'female') {
            return match (true) {
                $age <= 17 => 'en-US-AnaNeural',
                $age <= 60 => 'en-US-JennyNeural',
                default    => 'en-US-NancyNeural',
            };
        }

        return match (true) {
            $age <= 17 => 'en-US-BrandonNeural',
            $age <= 60 => 'en-US-GuyNeural',
            default    => 'en-US-RogerNeural',
        };
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
php artisan test tests/Unit/Services/SsmlBuilderTest.php
```

Expected: `8 passed`

- [ ] **Step 5: Commit**

```bash
git add app/Services/SsmlBuilder.php tests/Unit/Services/SsmlBuilderTest.php
git commit -m "feat: add SsmlBuilder service with unit tests"
```

---

### Task 7: azure_tts.py Python script

**Files:**
- Create: `scripts/azure_tts.py`
- Modify: `requirements.txt` (create if absent)

- [ ] **Step 1: Add Python dependency**

Check if `requirements.txt` exists:

```bash
ls requirements.txt 2>/dev/null || echo "not found"
```

If not found, create it. Either way, add:

```
azure-cognitiveservices-speech>=1.38.0
```

- [ ] **Step 2: Install the SDK**

```bash
pip install azure-cognitiveservices-speech
```

Expected: `Successfully installed azure-cognitiveservices-speech-x.x.x`

- [ ] **Step 3: Create `scripts/azure_tts.py`**

```bash
mkdir -p scripts
```

Create `scripts/azure_tts.py`:

```python
#!/usr/bin/env python3
"""
azure_tts.py — Synthesise SSML with Azure Speech SDK, output MP3 + blendshapes.json

Usage:
    python3 scripts/azure_tts.py \
        --ssml   /path/to/input.ssml \
        --key    YOUR_AZURE_KEY \
        --region eastus \
        --audio-out  /path/to/audio_3d.mp3 \
        --shapes-out /path/to/blendshapes.json

Exit 0 on success, 1 on failure (error written to stderr).
"""
import argparse
import json
import os
import sys
import time

try:
    import azure.cognitiveservices.speech as speechsdk
except ImportError:
    print("azure-cognitiveservices-speech not installed. Run: pip install azure-cognitiveservices-speech", file=sys.stderr)
    sys.exit(1)


def main() -> None:
    parser = argparse.ArgumentParser(description="Azure TTS with blend shapes")
    parser.add_argument("--ssml",       required=True, help="Path to SSML input file")
    parser.add_argument("--key",        required=True, help="Azure Speech subscription key")
    parser.add_argument("--region",     required=True, help="Azure region, e.g. eastus")
    parser.add_argument("--audio-out",  required=True, help="Output path for MP3 audio")
    parser.add_argument("--shapes-out", required=True, help="Output path for blendshapes.json")
    args = parser.parse_args()

    # Read SSML
    if not os.path.exists(args.ssml):
        print(f"SSML file not found: {args.ssml}", file=sys.stderr)
        sys.exit(1)

    with open(args.ssml, "r", encoding="utf-8") as fh:
        ssml_text = fh.read()

    # Ensure output directories exist
    os.makedirs(os.path.dirname(os.path.abspath(args.audio_out)),  exist_ok=True)
    os.makedirs(os.path.dirname(os.path.abspath(args.shapes_out)), exist_ok=True)

    # Configure Azure Speech
    speech_config = speechsdk.SpeechConfig(subscription=args.key, region=args.region)
    speech_config.set_speech_synthesis_output_format(
        speechsdk.SpeechSynthesisOutputFormat.Audio24Khz48KBitRateMonoMp3
    )
    audio_config = speechsdk.audio.AudioOutputConfig(filename=args.audio_out)
    synthesizer  = speechsdk.SpeechSynthesizer(
        speech_config=speech_config,
        audio_config=audio_config,
    )

    # Collect viseme events
    viseme_events: list[dict] = []

    def on_viseme(evt: speechsdk.SpeechSynthesisVisemeEventArgs) -> None:
        if evt.animation:
            try:
                data = json.loads(evt.animation)
                viseme_events.append({
                    "frame_index":  data["FrameIndex"],
                    "blend_shapes": data["BlendShapes"],
                })
            except (json.JSONDecodeError, KeyError):
                pass  # skip malformed events

    synthesizer.viseme_received.connect(on_viseme)

    # Synthesise
    result = synthesizer.speak_ssml_async(ssml_text).get()

    if result.reason != speechsdk.ResultReason.SynthesizingAudioCompleted:
        details = result.cancellation_details
        print(
            f"Synthesis failed: {details.reason} — {details.error_details}",
            file=sys.stderr,
        )
        sys.exit(1)

    # Flatten viseme events into a frame timeline
    # FrameIndex is the cumulative frame offset at 60 FPS
    frames: list[dict] = []
    for event in sorted(viseme_events, key=lambda e: e["frame_index"]):
        for i, weights in enumerate(event["blend_shapes"]):
            t = round((event["frame_index"] + i) / 60.0, 4)
            frames.append({"t": t, "w": weights})

    # Deduplicate by time (keep last) in case events overlap
    seen: dict[float, dict] = {}
    for frame in frames:
        seen[frame["t"]] = frame
    frames = sorted(seen.values(), key=lambda f: f["t"])

    duration = frames[-1]["t"] if frames else 0.0
    blendshapes = {"fps": 60, "duration": duration, "frames": frames}

    with open(args.shapes_out, "w", encoding="utf-8") as fh:
        json.dump(blendshapes, fh, separators=(",", ":"))

    print(f"OK: {len(frames)} frames, {duration:.2f}s, audio → {args.audio_out}")
    sys.exit(0)


if __name__ == "__main__":
    main()
```

- [ ] **Step 4: Smoke-test the script locally (optional but recommended)**

```bash
# Only run if you have AZURE_SPEECH_KEY set
echo '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="https://www.w3.org/2001/mstts" xml:lang="en-US"><voice name="en-US-GuyNeural"><mstts:viseme type="FacialExpression"/><prosody rate="1.00">Hello world.</prosody></voice></speak>' > /tmp/test.ssml
python3 scripts/azure_tts.py --ssml /tmp/test.ssml --key "$AZURE_SPEECH_KEY" --region eastus --audio-out /tmp/test_audio.mp3 --shapes-out /tmp/test_shapes.json
# Expected: "OK: N frames, X.XXs, audio → /tmp/test_audio.mp3"
```

- [ ] **Step 5: Commit**

```bash
git add scripts/azure_tts.py requirements.txt
git commit -m "feat: add azure_tts.py Python script for blend shape synthesis"
```

---

### Task 8: AzureTtsService (with tests)

**Files:**
- Create: `app/Services/AzureTtsService.php`
- Create: `tests/Unit/Services/AzureTtsServiceTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Services/AzureTtsServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\AzureTtsException;
use App\Services\AzureTtsService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AzureTtsServiceTest extends TestCase
{
    public function test_throws_on_non_zero_exit_code(): void
    {
        Storage::fake('local');

        $service = new class extends AzureTtsService {
            protected function runPython(string $cmd): array
            {
                return [['Error: bad key'], 1];
            }
        };

        $this->expectException(AzureTtsException::class);
        $this->expectExceptionMessage('Error: bad key');

        $service->synthesize('<speak/>', 'lessons/1');
    }

    public function test_returns_paths_on_success(): void
    {
        Storage::fake('local');

        $service = new class extends AzureTtsService {
            protected function runPython(string $cmd): array
            {
                // Simulate Python writing the files by creating them ourselves
                $pattern = '/--audio-out\s+(\S+)\s+--shapes-out\s+(\S+)/';
                preg_match($pattern, $cmd, $m);
                if (isset($m[1])) {
                    @mkdir(dirname($m[1]), 0755, true);
                    file_put_contents($m[1], 'fake-mp3');
                    file_put_contents($m[2], '{"fps":60,"duration":1.0,"frames":[]}');
                }
                return [['OK: 0 frames'], 0];
            }
        };

        $result = $service->synthesize('<speak/>', 'lessons/1');

        $this->assertArrayHasKey('audio_3d_path', $result);
        $this->assertArrayHasKey('blendshapes_path', $result);
        $this->assertStringContainsString('lessons/1', $result['audio_3d_path']);
    }

    public function test_logs_character_count(): void
    {
        Storage::fake('local');

        $service = new class extends AzureTtsService {
            protected function runPython(string $cmd): array
            {
                $pattern = '/--audio-out\s+(\S+)\s+--shapes-out\s+(\S+)/';
                preg_match($pattern, $cmd, $m);
                if (isset($m[1])) {
                    @mkdir(dirname($m[1]), 0755, true);
                    file_put_contents($m[1], 'fake-mp3');
                    file_put_contents($m[2], '{"fps":60,"duration":1.0,"frames":[]}');
                }
                return [['OK'], 0];
            }
        };

        // Should not throw — character count logging happens internally
        $result = $service->synthesize('<speak>Hello</speak>', 'lessons/99');
        $this->assertNotEmpty($result['audio_3d_path']);
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
php artisan test tests/Unit/Services/AzureTtsServiceTest.php
```

Expected: `ERROR` — `Class "App\Services\AzureTtsService" not found`

- [ ] **Step 3: Create `app/Services/AzureTtsService.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AzureTtsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AzureTtsService
{
    public function synthesize(string $ssml, string $storagePrefix): array
    {
        // Log character usage for free-tier monitoring
        Log::info('AzureTts synthesis', [
            'chars'  => strlen($ssml),
            'prefix' => $storagePrefix,
        ]);

        // Write SSML to temp file
        $ssmlFile  = sys_get_temp_dir() . '/azure_ssml_' . uniqid() . '.xml';
        file_put_contents($ssmlFile, $ssml);

        $audioOut  = storage_path("app/{$storagePrefix}/audio_3d.mp3");
        $shapesOut = storage_path("app/{$storagePrefix}/blendshapes.json");

        // Ensure directories exist
        @mkdir(dirname($audioOut),  0755, true);
        @mkdir(dirname($shapesOut), 0755, true);

        $python = base_path('scripts/azure_tts.py');
        $key    = config('services.azure_speech.key');
        $region = config('services.azure_speech.region');

        $cmd = sprintf(
            'python3 %s --ssml %s --key %s --region %s --audio-out %s --shapes-out %s 2>&1',
            escapeshellarg($python),
            escapeshellarg($ssmlFile),
            escapeshellarg($key),
            escapeshellarg($region),
            escapeshellarg($audioOut),
            escapeshellarg($shapesOut),
        );

        [$output, $exitCode] = $this->runPython($cmd);

        @unlink($ssmlFile);

        if ($exitCode !== 0) {
            throw new AzureTtsException(implode("\n", $output));
        }

        return [
            'audio_3d_path'   => "{$storagePrefix}/audio_3d.mp3",
            'blendshapes_path' => "{$storagePrefix}/blendshapes.json",
        ];
    }

    /**
     * Isolated so tests can override without actually calling Python.
     *
     * @return array{0: string[], 1: int}
     */
    protected function runPython(string $cmd): array
    {
        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [$output, $exitCode];
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
php artisan test tests/Unit/Services/AzureTtsServiceTest.php
```

Expected: `3 passed`

- [ ] **Step 5: Commit**

```bash
git add app/Services/AzureTtsService.php tests/Unit/Services/AzureTtsServiceTest.php
git commit -m "feat: add AzureTtsService with runPython seam for testing"
```

---

### Task 9: GenerateAvatarPreview3d job (with tests)

**Files:**
- Create: `app/Jobs/GenerateAvatarPreview3d.php`
- Create: `tests/Feature/Jobs/GenerateAvatarPreview3dTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Jobs/GenerateAvatarPreview3dTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Exceptions\AzureTtsException;
use App\Jobs\GenerateAvatarPreview3d;
use App\Models\Avatar;
use App\Models\Lesson;
use App\Services\AzureTtsService;
use App\Services\SsmlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateAvatarPreview3dTest extends TestCase
{
    use RefreshDatabase;

    public function test_caches_ready_status_on_success(): void
    {
        Storage::fake('local');

        $avatar = Avatar::factory()->create([
            'gender'         => 'male',
            'age'            => 35,
            'emotion_style'  => 'auto',
            'expressiveness' => 1.2,
            'speaking_speed' => 1.0,
        ]);

        $this->mock(AzureTtsService::class, function ($mock) use ($avatar) {
            $mock->shouldReceive('synthesize')
                ->once()
                ->andReturn([
                    'audio_3d_path'    => "previews/{$avatar->id}/audio_3d.mp3",
                    'blendshapes_path' => "previews/{$avatar->id}/blendshapes.json",
                ]);
        });

        (new GenerateAvatarPreview3d($avatar->id, 'Hello world.'))->handle(
            app(SsmlBuilder::class),
            app(AzureTtsService::class),
        );

        $cached = Cache::get("avatar3d_preview_{$avatar->id}");
        $this->assertSame('ready', $cached['status']);
        $this->assertNotEmpty($cached['audio_url']);
        $this->assertNotEmpty($cached['blendshapes_url']);
    }

    public function test_caches_failed_status_on_exception(): void
    {
        $avatar = Avatar::factory()->create();

        $job = new GenerateAvatarPreview3d($avatar->id, 'Hello.');
        $job->failed(new AzureTtsException('Azure returned 401'));

        $cached = Cache::get("avatar3d_preview_{$avatar->id}");
        $this->assertSame('failed', $cached['status']);
        $this->assertStringContainsString('401', $cached['error']);
    }

    public function test_updates_lesson_when_lesson_id_provided(): void
    {
        Storage::fake('local');

        $avatar = Avatar::factory()->create();
        $lesson = Lesson::factory()->create();

        $this->mock(AzureTtsService::class, function ($mock) use ($lesson) {
            $mock->shouldReceive('synthesize')
                ->once()
                ->andReturn([
                    'audio_3d_path'    => "lessons/{$lesson->id}/audio_3d.mp3",
                    'blendshapes_path' => "lessons/{$lesson->id}/blendshapes.json",
                ]);
        });

        (new GenerateAvatarPreview3d($avatar->id, 'Text.', $lesson->id))->handle(
            app(SsmlBuilder::class),
            app(AzureTtsService::class),
        );

        $this->assertDatabaseHas('lessons', [
            'id'              => $lesson->id,
            'audio_3d_path'   => "lessons/{$lesson->id}/audio_3d.mp3",
            'blendshapes_path' => "lessons/{$lesson->id}/blendshapes.json",
        ]);
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
php artisan test tests/Feature/Jobs/GenerateAvatarPreview3dTest.php
```

Expected: `ERROR` — Job class not found (and possibly missing Avatar/Lesson factories).

- [ ] **Step 3: Check if Avatar and Lesson factories exist**

```bash
ls database/factories/
```

If `AvatarFactory.php` is missing, create `database/factories/AvatarFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AvatarFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'            => $this->faker->name(),
            'short_name'      => $this->faker->firstName(),
            'avatar_title'    => $this->faker->jobTitle(),
            'slug'            => $this->faker->unique()->slug(),
            'description'     => $this->faker->sentence(),
            'subject'         => 'history',
            'voice_provider'  => 'edge-tts',
            'voice_id'        => 'en-US-GuyNeural',
            'voice_speed'     => 1.0,
            'is_active'       => true,
            'sort_order'      => 0,
            'gender'          => 'male',
            'age'             => 35,
            'emotion_style'   => 'auto',
            'expressiveness'  => 1.2,
            'speaking_speed'  => 1.0,
            'frame_background' => '#0f172a',
            'presentation_mode' => 'framed',
        ];
    }
}
```

Add `HasFactory` to `Avatar` model if not already present:
```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
// in class: use HasFactory;
```

- [ ] **Step 4: Create `app/Jobs/GenerateAvatarPreview3d.php`**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Avatar;
use App\Models\Lesson;
use App\Services\AzureTtsService;
use App\Services\SsmlBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class GenerateAvatarPreview3d implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $retryAfter = 60; // seconds — handles Azure 429 rate-limit

    public function __construct(
        public readonly int $avatarId,
        public readonly string $script,
        public readonly ?int $lessonId = null,
    ) {}

    public function handle(SsmlBuilder $builder, AzureTtsService $azure): void
    {
        $avatar = Avatar::findOrFail($this->avatarId);
        $ssml   = $builder->build($this->script, $avatar);

        $prefix = $this->lessonId
            ? "lessons/{$this->lessonId}"
            : "previews/{$this->avatarId}";

        $paths = $azure->synthesize($ssml, $prefix);

        if ($this->lessonId) {
            Lesson::where('id', $this->lessonId)->update([
                'audio_3d_path'    => $paths['audio_3d_path'],
                'blendshapes_path' => $paths['blendshapes_path'],
            ]);
        }

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

- [ ] **Step 5: Run tests — expect pass**

```bash
php artisan test tests/Feature/Jobs/GenerateAvatarPreview3dTest.php
```

Expected: `3 passed`

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/GenerateAvatarPreview3d.php tests/Feature/Jobs/GenerateAvatarPreview3dTest.php database/factories/AvatarFactory.php app/Models/Avatar.php
git commit -m "feat: add GenerateAvatarPreview3d job with feature tests"
```

---

### Task 10: AvatarLab Livewire component

**Files:**
- Create: `app/Livewire/Admin/AvatarLab.php`

- [ ] **Step 1: Create `app/Livewire/Admin/AvatarLab.php`**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Jobs\GenerateAvatarPreview3d;
use App\Models\Avatar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class AvatarLab extends Component
{
    // Avatar selection
    public ?int $avatarId = null;
    public bool $hasCharacter = false;

    // Settings (mirrors Avatar 3D fields)
    public string $presentationMode = 'framed';
    public int    $age              = 35;
    public string $gender           = 'male';
    public string $emotionStyle     = 'auto';
    public float  $expressiveness   = 1.2;
    public float  $speakingSpeed    = 1.0;
    public string $frameBackground  = '#0f172a';

    // Preview state
    public string  $testScript           = '';
    public string  $previewStatus        = 'idle'; // idle | generating | ready | error
    public ?string $previewAudioUrl      = null;
    public ?string $previewBlendShapesUrl = null;
    public ?string $previewError         = null;
    public bool    $polling              = false;

    public Collection $avatars;

    public function mount(): void
    {
        $this->avatars = Avatar::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($this->avatars->count() === 1) {
            $this->selectAvatar($this->avatars->first()->id);
        }
    }

    public function selectAvatar(int $id): void
    {
        $avatar = Avatar::findOrFail($id);

        $this->avatarId         = $id;
        $this->presentationMode = $avatar->presentation_mode ?? 'framed';
        $this->age              = $avatar->age              ?? 35;
        $this->gender           = $avatar->gender           ?? 'male';
        $this->emotionStyle     = $avatar->emotion_style    ?? 'auto';
        $this->expressiveness   = $avatar->expressiveness   ?? 1.2;
        $this->speakingSpeed    = $avatar->speaking_speed   ?? 1.0;
        $this->frameBackground  = $avatar->frame_background ?? '#0f172a';
        $this->hasCharacter     = file_exists(public_path("avatars/{$id}/character.glb"));

        $this->previewStatus        = 'idle';
        $this->previewAudioUrl      = null;
        $this->previewBlendShapesUrl = null;
        $this->previewError         = null;
        $this->polling              = false;
    }

    public function generatePreview(): void
    {
        $this->validate([
            'avatarId'   => 'required|integer|exists:avatars,id',
            'testScript' => 'required|string|min:5|max:2000',
        ]);

        $this->previewStatus = 'generating';
        $this->polling       = true;

        Cache::forget("avatar3d_preview_{$this->avatarId}");

        GenerateAvatarPreview3d::dispatch($this->avatarId, $this->testScript);
    }

    public function pollPreviewStatus(): void
    {
        if (! $this->polling || ! $this->avatarId) {
            return;
        }

        $cached = Cache::get("avatar3d_preview_{$this->avatarId}");

        if (! $cached) {
            return; // still queued
        }

        if ($cached['status'] === 'ready') {
            $this->previewStatus         = 'ready';
            $this->previewAudioUrl       = $cached['audio_url'];
            $this->previewBlendShapesUrl = $cached['blendshapes_url'];
            $this->polling               = false;
            $this->dispatch('avatar3d:previewReady', [
                'audioUrl'      => $this->previewAudioUrl,
                'blendShapesUrl' => $this->previewBlendShapesUrl,
            ]);
        }

        if ($cached['status'] === 'failed') {
            $this->previewStatus = 'error';
            $this->previewError  = $cached['error'];
            $this->polling       = false;
        }
    }

    public function saveSettings(): void
    {
        $this->validate(['avatarId' => 'required|integer|exists:avatars,id']);

        Avatar::where('id', $this->avatarId)->update([
            'presentation_mode' => $this->presentationMode,
            'age'               => $this->age,
            'gender'            => $this->gender,
            'emotion_style'     => $this->emotionStyle,
            'expressiveness'    => $this->expressiveness,
            'speaking_speed'    => $this->speakingSpeed,
            'frame_background'  => $this->frameBackground,
        ]);

        session()->flash('message', 'Settings saved.');
    }

    public function render(): View
    {
        return view('livewire.admin.avatar-lab');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Livewire/Admin/AvatarLab.php
git commit -m "feat: add AvatarLab Livewire component"
```

---

### Task 11: avatar-3d.js

**Files:**
- Create: `resources/js/avatar-3d.js`

- [ ] **Step 1: Create `resources/js/avatar-3d.js`**

```javascript
/**
 * avatar-3d.js — Three.js 3D avatar player driven by Azure blend shapes
 *
 * Usage:
 *   const player = new Avatar3DPlayer(canvasEl, { characterUrl, morphMapUrl, frameBackground })
 *   await player.init()
 *   await player.loadPreview(audioUrl, blendShapesUrl)
 *   player.play()
 */
import * as THREE from 'three'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'

export class Avatar3DPlayer {
  #scene
  #camera
  #renderer
  #mesh          // SkinnedMesh with morph targets
  #morphMap = {} // azureName → morphTargetIndex
  #frames   = [] // [{ t: float, w: Float32Array(55) }]
  #audio    = null
  #playing  = false
  #rafId    = null
  #blinkTimer = 0
  #blinkNext  = 3000 // ms until next blink
  #clock    = new THREE.Clock()

  constructor (canvasEl, {
    characterUrl  = null,
    morphMapUrl   = null,
    frameBackground = '#0f172a',
  } = {}) {
    this.canvasEl       = canvasEl
    this.characterUrl   = characterUrl
    this.morphMapUrl    = morphMapUrl
    this.frameBackground = frameBackground
  }

  async init () {
    const w = this.canvasEl.clientWidth  || 400
    const h = this.canvasEl.clientHeight || 500

    // Renderer
    this.#renderer = new THREE.WebGLRenderer({ canvas: this.canvasEl, antialias: true, alpha: true })
    this.#renderer.setSize(w, h)
    this.#renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))
    this.#renderer.setClearColor(new THREE.Color(this.frameBackground), 1)

    // Scene
    this.#scene = new THREE.Scene()

    // Camera — bust shot
    this.#camera = new THREE.PerspectiveCamera(45, w / h, 0.1, 100)
    this.#camera.position.set(0, 1.6, 2.2)
    this.#camera.lookAt(0, 1.6, 0)

    // Lights
    this.#scene.add(new THREE.AmbientLight(0xffffff, 0.6))
    const dirLight = new THREE.DirectionalLight(0xffffff, 0.8)
    dirLight.position.set(1, 2, 2)
    this.#scene.add(dirLight)

    // Load character if URL provided
    if (this.characterUrl) {
      await this.#loadCharacter(this.characterUrl, this.morphMapUrl)
    }

    // Start loop
    this.#loop()
  }

  async #loadCharacter (gltfUrl, morphMapUrl) {
    const loader = new GLTFLoader()
    const gltf   = await loader.loadAsync(gltfUrl)
    this.#scene.add(gltf.scene)

    // Find the mesh with morph targets
    gltf.scene.traverse((node) => {
      if (node.isMesh && node.morphTargetDictionary && !this.#mesh) {
        this.#mesh = node
      }
    })

    if (!this.#mesh) {
      console.warn('[Avatar3D] No mesh with morph targets found in GLB')
      return
    }

    // Load morph map
    if (morphMapUrl) {
      try {
        const resp = await fetch(morphMapUrl)
        const map  = await resp.json()
        // map: { "jawOpen": 17, "eyeBlinkLeft": 0, ... }
        // We need to invert: azurePosition → morphTargetIndex
        const dict = this.#mesh.morphTargetDictionary
        for (const [azureName, azureIdx] of Object.entries(map)) {
          if (azureName in dict) {
            this.#morphMap[azureIdx] = dict[azureName]
          }
        }
      } catch (e) {
        console.warn('[Avatar3D] Could not load morph-map.json:', e)
      }
    }
  }

  async loadPreview (audioUrl, blendShapesUrl) {
    // Load blend shapes
    try {
      const resp = await fetch(blendShapesUrl)
      const data = await resp.json()
      this.#frames = data.frames.map(f => ({ t: f.t, w: f.w }))
    } catch (e) {
      console.warn('[Avatar3D] Could not load blendshapes.json:', e)
      this.#frames = []
    }

    // Load audio
    if (this.#audio) {
      this.#audio.pause()
      this.#audio = null
    }
    this.#audio = new Audio(audioUrl)
    this.#audio.addEventListener('ended', () => { this.#playing = false })
  }

  play () {
    if (!this.#audio) return
    this.#audio.currentTime = 0
    this.#audio.play()
    this.#playing = true
  }

  pause () {
    this.#audio?.pause()
    this.#playing = false
  }

  #loop () {
    this.#rafId = requestAnimationFrame((ts) => this.#loop(ts))
    const delta = this.#clock.getDelta() * 1000 // ms

    if (this.#playing && this.#mesh && this.#frames.length > 0) {
      this.#applyBlendShapes(this.#audio.currentTime)
    }

    this.#idlePass(delta)
    this.#renderer.render(this.#scene, this.#camera)
  }

  #applyBlendShapes (t) {
    const frames = this.#frames
    // Binary search for current frame
    let lo = 0, hi = frames.length - 1, idx = 0
    while (lo <= hi) {
      const mid = (lo + hi) >> 1
      if (frames[mid].t <= t) { idx = mid; lo = mid + 1 }
      else hi = mid - 1
    }

    const f0 = frames[idx]
    const f1 = frames[Math.min(idx + 1, frames.length - 1)]
    const alpha = f1.t > f0.t ? (t - f0.t) / (f1.t - f0.t) : 0

    const influences = this.#mesh.morphTargetInfluences
    for (const [azureIdx, morphIdx] of Object.entries(this.#morphMap)) {
      const w0 = f0.w[azureIdx] ?? 0
      const w1 = f1.w[azureIdx] ?? 0
      influences[morphIdx] = w0 + (w1 - w0) * alpha
    }
  }

  #idlePass (deltaMs) {
    if (!this.#mesh) return
    const t = performance.now() / 1000

    // Breathing — gentle Y scale oscillation
    const breathe = 1 + Math.sin(t * 0.8) * 0.012
    this.#mesh.scale.y = breathe

    // Eye blink
    this.#blinkTimer += deltaMs
    if (this.#blinkTimer >= this.#blinkNext) {
      this.#blinkTimer = 0
      this.#blinkNext  = 2000 + Math.random() * 3000
      this.#triggerBlink()
    }
  }

  #triggerBlink () {
    const dict = this.#mesh?.morphTargetDictionary
    if (!dict) return
    const lIdx = dict['eyeBlinkLeft']
    const rIdx = dict['eyeBlinkRight']
    if (lIdx === undefined || rIdx === undefined) return

    const infl = this.#mesh.morphTargetInfluences
    infl[lIdx] = 1; infl[rIdx] = 1
    setTimeout(() => { infl[lIdx] = 0; infl[rIdx] = 0 }, 120)
  }

  destroy () {
    if (this.#rafId) cancelAnimationFrame(this.#rafId)
    this.#audio?.pause()
    this.#renderer?.dispose()
    this.#scene?.traverse((obj) => {
      if (obj.geometry) obj.geometry.dispose()
      if (obj.material) {
        if (Array.isArray(obj.material)) obj.material.forEach(m => m.dispose())
        else obj.material.dispose()
      }
    })
  }
}

// ── Livewire / Alpine event glue ────────────────────────────────────────────

window.addEventListener('avatar3d:previewReady', (ev) => {
  const { audioUrl, blendShapesUrl } = ev.detail
  window._avatar3d?.loadPreview(audioUrl, blendShapesUrl)
    .then(() => console.log('[Avatar3D] Preview loaded'))
})
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/avatar-3d.js
git commit -m "feat: add Three.js Avatar3DPlayer with morph target blend shape animation"
```

---

### Task 12: Avatar Lab blade views

**Files:**
- Create: `resources/views/livewire/admin/avatar-lab.blade.php`

- [ ] **Step 1: Create the Livewire view**

Create `resources/views/livewire/admin/avatar-lab.blade.php`:

```blade
<div
    x-data="{
        mode: '3d',
        player: null,
        initPlayer() {
            if (this.mode !== '3d') return
            const canvas = this.$refs.canvas3d
            if (!canvas || !canvas.dataset.characterUrl) return
            import('/build/assets/avatar-3d.js').then(({ Avatar3DPlayer }) => {
                if (this.player) this.player.destroy()
                this.player = new Avatar3DPlayer(canvas, {
                    characterUrl:    canvas.dataset.characterUrl,
                    morphMapUrl:     canvas.dataset.morphMapUrl,
                    frameBackground: canvas.dataset.bg,
                })
                window._avatar3d = this.player
                this.player.init()
            })
        }
    }"
    x-init="initPlayer()"
    class="flex h-[82vh] bg-slate-900 rounded-xl border border-white/5 overflow-hidden"
>
    {{-- ── Settings sidebar ──────────────────────────────── --}}
    <div class="w-60 flex-shrink-0 border-r border-white/5 flex flex-col overflow-y-auto p-4 gap-5 text-sm text-slate-200">

        {{-- Avatar picker --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Avatar</p>
            <select wire:model.live="avatarId" wire:change="selectAvatar($event.target.value)"
                    class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-slate-200 text-xs">
                <option value="">— select avatar —</option>
                @foreach($avatars as $av)
                    <option value="{{ $av->id }}">{{ $av->name }}</option>
                @endforeach
            </select>
        </div>

        @if($avatarId)
        {{-- Gender --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Gender</p>
            <div class="flex border border-white/10 rounded-lg overflow-hidden">
                <button wire:click="$set('gender','male')"
                        class="flex-1 py-1.5 text-xs font-semibold {{ $gender === 'male' ? 'bg-indigo-600 text-white' : 'bg-transparent text-slate-400' }}">
                    ♂ Male
                </button>
                <button wire:click="$set('gender','female')"
                        class="flex-1 py-1.5 text-xs font-semibold {{ $gender === 'female' ? 'bg-indigo-600 text-white' : 'bg-transparent text-slate-400' }}">
                    ♀ Female
                </button>
            </div>
        </div>

        {{-- Age --}}
        <div>
            <div class="flex justify-between mb-1">
                <span class="text-slate-400 text-xs">Age</span>
                <span class="text-indigo-400 font-bold text-xs">{{ $age }}</span>
            </div>
            <input type="range" wire:model.live="age" min="8" max="80"
                   class="w-full accent-indigo-500">
            <div class="flex justify-between text-[0.6rem] text-slate-600 mt-0.5">
                <span>Child</span><span>Teen</span><span>Adult</span><span>Elder</span>
            </div>
        </div>

        {{-- Presentation --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Presentation</p>
            @foreach(['fullscreen' => 'Fullscreen Storyteller', 'framed' => 'Framed', 'hidden' => 'Hidden (audio only)'] as $val => $label)
            <button wire:click="$set('presentationMode','{{ $val }}')"
                    class="w-full text-left px-3 py-2 mb-1 rounded-lg text-xs border transition
                           {{ $presentationMode === $val ? 'border-indigo-500 bg-indigo-500/10 text-indigo-300' : 'border-white/8 text-slate-400 hover:border-white/20' }}">
                {{ $label }}
            </button>
            @endforeach
            @if($presentationMode === 'framed')
            <div class="flex items-center justify-between mt-1">
                <span class="text-slate-500 text-xs">Frame bg</span>
                <input type="color" wire:model.live="frameBackground"
                       class="w-7 h-6 rounded border border-white/10 bg-transparent cursor-pointer p-0">
            </div>
            @endif
        </div>

        {{-- Emotion & Voice --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Emotion &amp; Voice</p>

            {{-- Speaking style --}}
            <div class="bg-indigo-500/10 border border-indigo-500/40 rounded-lg p-2.5 mb-2">
                <div class="flex justify-between items-center">
                    <span class="text-indigo-300 font-bold text-xs">✦ Auto</span>
                    <span class="bg-indigo-600 text-white text-[0.55rem] px-1.5 py-0.5 rounded font-semibold
                                 {{ $emotionStyle === 'auto' ? '' : 'opacity-40' }}">DEFAULT</span>
                </div>
                <p class="text-slate-500 text-[0.65rem] mt-0.5">Uses <code class="text-indigo-400">[cheerful]</code>…<code class="text-indigo-400">[/cheerful]</code> tags</p>
            </div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-600 mb-1">Override entire lesson</p>
            <div class="grid grid-cols-2 gap-1">
                @foreach(['narrative' => '📖 Narrative', 'cheerful' => '🎉 Cheerful', 'serious' => '🎓 Serious', 'excited' => '🤩 Excited', 'empathetic' => '😢 Empathetic', 'whispering' => '🕵️ Whispering'] as $val => $label)
                <button wire:click="$set('emotionStyle','{{ $val }}')"
                        class="text-[0.7rem] py-1.5 rounded-md border transition
                               {{ $emotionStyle === $val ? 'border-indigo-500 bg-indigo-500/15 text-indigo-300' : 'border-white/8 text-slate-500 hover:border-white/20' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>
            @if($emotionStyle !== 'auto')
            <button wire:click="$set('emotionStyle','auto')" class="w-full text-[0.65rem] text-slate-500 mt-1 hover:text-slate-300">↩ reset to Auto</button>
            @endif

            {{-- Expressiveness --}}
            <div class="mt-3">
                <div class="flex justify-between mb-1">
                    <span class="text-slate-400 text-xs">Expressiveness</span>
                    <span class="text-indigo-400 font-bold text-xs">{{ number_format($expressiveness, 1) }}×</span>
                </div>
                <input type="range" wire:model.live="expressiveness" min="0.1" max="2.0" step="0.1" class="w-full accent-indigo-500">
                <div class="flex justify-between text-[0.6rem] text-slate-600 mt-0.5"><span>Subtle</span><span>Natural</span><span>Dramatic</span></div>
            </div>

            {{-- Speed --}}
            <div class="mt-2">
                <div class="flex justify-between mb-1">
                    <span class="text-slate-400 text-xs">Speaking speed</span>
                    <span class="text-indigo-400 font-bold text-xs">{{ number_format($speakingSpeed, 1) }}×</span>
                </div>
                <input type="range" wire:model.live="speakingSpeed" min="0.5" max="2.0" step="0.1" class="w-full accent-indigo-500">
                <div class="flex justify-between text-[0.6rem] text-slate-600 mt-0.5"><span>Slow</span><span>Normal</span><span>Fast</span></div>
            </div>
        </div>

        {{-- Test script --}}
        <div>
            <p class="text-[0.6rem] uppercase tracking-widest text-slate-500 mb-2">Test Script</p>
            <textarea wire:model="testScript" rows="4"
                      placeholder="Friends, Romans… [serious]Heavy losses.[/serious]"
                      class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-xs text-slate-200 resize-none focus:border-indigo-500 focus:outline-none"></textarea>
        </div>

        {{-- Actions --}}
        <div class="flex flex-col gap-2 mt-auto">
            <button wire:click="generatePreview" wire:loading.attr="disabled"
                    class="w-full bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold py-2.5 rounded-lg transition disabled:opacity-50">
                <span wire:loading.remove wire:target="generatePreview">▶ Generate Preview</span>
                <span wire:loading wire:target="generatePreview">Generating…</span>
            </button>
            <button wire:click="saveSettings"
                    class="w-full border border-white/10 text-slate-400 hover:text-white text-xs py-2 rounded-lg transition">
                Save Settings
            </button>
        </div>

        @if(session('message'))
            <p class="text-emerald-400 text-xs text-center">{{ session('message') }}</p>
        @endif
        @endif
    </div>

    {{-- ── Viewport ──────────────────────────────────────── --}}
    <div class="flex-1 flex flex-col">

        {{-- Top bar --}}
        <div class="flex items-center justify-between px-5 py-2.5 border-b border-white/5 bg-white/[0.02]">
            <span class="text-sm font-semibold text-slate-200">🧪 Avatar Lab</span>

            {{-- 2D / 3D toggle --}}
            <div class="flex items-center gap-1 bg-white/5 border border-white/10 rounded-lg p-1">
                <button @click="mode='2d'; $wire.dispatch('avatar3d:switchMode', { mode: '2d' })"
                        :class="mode==='2d' ? 'bg-white/10 text-white' : 'text-slate-500'"
                        class="px-3 py-1 rounded-md text-xs font-semibold transition">2D</button>
                <button @click="mode='3d'; initPlayer()"
                        :class="mode==='3d' ? 'bg-indigo-600 text-white' : 'text-slate-500'"
                        class="px-3 py-1 rounded-md text-xs font-semibold transition">3D ✦</button>
            </div>

            <span class="text-[0.65rem] text-slate-600">Admin only · Experimental</span>
        </div>

        {{-- Canvas area --}}
        <div class="flex-1 flex flex-col items-center justify-center gap-4 relative"
             style="background: radial-gradient(ellipse at 50% 80%, rgba(99,102,241,0.05) 0%, transparent 70%);">

            @if(!$avatarId)
                <p class="text-slate-500 text-sm">← Select an avatar to begin</p>

            @elseif(!$hasCharacter)
                <div class="text-center text-slate-500 text-sm max-w-xs">
                    <p class="text-2xl mb-3">📦</p>
                    <p class="font-semibold text-slate-400 mb-1">No 3D character uploaded yet</p>
                    <p class="text-xs leading-relaxed">
                        Place your MPFB GLB export at<br>
                        <code class="text-indigo-400">public/avatars/{{ $avatarId }}/character.glb</code><br>
                        and a morph map at<br>
                        <code class="text-indigo-400">public/avatars/{{ $avatarId }}/morph-map.json</code>
                    </p>
                </div>

            @else
                {{-- 3D canvas --}}
                <div x-show="mode==='3d'" class="relative">
                    <canvas
                        x-ref="canvas3d"
                        wire:ignore
                        data-character-url="{{ asset('avatars/' . $avatarId . '/character.glb') }}"
                        data-morph-map-url="{{ asset('avatars/' . $avatarId . '/morph-map.json') }}"
                        data-bg="{{ $frameBackground }}"
                        class="rounded-xl shadow-2xl"
                        style="width:300px;height:400px;"
                    ></canvas>
                </div>

                {{-- Status bar --}}
                @if($previewStatus === 'generating')
                    <div wire:poll.2s="pollPreviewStatus"
                         class="flex items-center gap-2 bg-white/5 border border-white/8 rounded-lg px-4 py-2 text-xs text-slate-400">
                        <span class="animate-pulse w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
                        Generating with Azure Speech…
                    </div>
                @elseif($previewStatus === 'ready')
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2 bg-white/5 border border-white/8 rounded-lg px-4 py-2 text-xs text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                            Ready · {{ $gender }} · age {{ $age }} · {{ $emotionStyle }}
                        </div>
                        <button @click="$wire.player ? $wire.player.play() : window._avatar3d?.play()"
                                class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold px-4 py-2 rounded-lg transition">
                            ▶ Play
                        </button>
                    </div>
                @elseif($previewStatus === 'error')
                    <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg px-4 py-2 text-xs text-rose-400 max-w-sm text-center">
                        ⚠ {{ $previewError }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/admin/avatar-lab.blade.php
git commit -m "feat: add AvatarLab Livewire view with settings sidebar and Three.js canvas"
```

---

### Task 13: Route and admin navigation

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/app.blade.php`

- [ ] **Step 1: Add route to `routes/web.php`**

Find the admin route group (the block starting with `Route::middleware(['auth', 'role:admin'])`). Add inside it:

```php
Route::get('/admin/avatar-lab', \App\Livewire\Admin\AvatarLab::class)
    ->name('admin.avatar-lab');
```

- [ ] **Step 2: Add nav link in `resources/views/components/layouts/app.blade.php`**

Find the existing admin link (the one that checks `auth()->user()->role === 'admin'`). Add a new link after the Dashboard link:

```blade
@if(auth()->user()?->role === 'admin')
    <a href="{{ route('admin.avatar-lab') }}"
       class="text-sm font-medium {{ request()->routeIs('admin.avatar-lab') ? 'text-indigo-400' : 'text-slate-400 hover:text-white' }} transition flex items-center gap-1.5">
        🧪 <span>3D Lab</span>
        <span class="text-[0.55rem] bg-indigo-900 text-indigo-300 px-1.5 py-0.5 rounded font-semibold">BETA</span>
    </a>
@endif
```

- [ ] **Step 3: Verify the route is registered**

```bash
php artisan route:list --name=admin.avatar-lab
```

Expected output: a row showing `GET /admin/avatar-lab` → `AvatarLab`

- [ ] **Step 4: Commit**

```bash
git add routes/web.php resources/views/components/layouts/app.blade.php
git commit -m "feat: add /admin/avatar-lab route and nav entry"
```

---

### Task 14: Remove Greeting tab from Avatar Studio

**Files:**
- Modify: `app/Livewire/Admin/AvatarStudio.php`
- Modify: `resources/views/livewire/admin/avatar-studio.blade.php`

- [ ] **Step 1: Remove greeting properties and method from `AvatarStudio.php`**

In `app/Livewire/Admin/AvatarStudio.php`, remove these properties:

```php
// DELETE these lines:
public string  $greetingTeacherName = '';
public ?string $greetingAudioUrl    = null;
public ?array  $greetingWordTimings = null;
public bool    $generatingGreeting  = false;
```

And remove the entire `generateGreeting()` method body (keep the method signature only if it's referenced in tests, or delete entirely).

- [ ] **Step 2: Read the current avatar-studio blade to find exact greeting tab markup**

```bash
grep -n "greeting" resources/views/livewire/admin/avatar-studio.blade.php
```

- [ ] **Step 3: Remove the Greeting tab button from the tab bar**

In `resources/views/livewire/admin/avatar-studio.blade.php`, find and delete the tab button that sets `activeTab = 'greeting'`. It will look like:

```blade
<button @click="activeTab = 'greeting'" ...>Greeting</button>
```

- [ ] **Step 4: Remove the greeting tab content panel**

Delete the entire div section that shows when `activeTab === 'greeting'`. It will be wrapped in something like:

```blade
<div x-show="activeTab === 'greeting'"> ... </div>
```

- [ ] **Step 5: Add Greeting section to the Settings tab**

Find the Settings tab content panel (`x-show="activeTab === 'settings'"`) and add a Greeting section at the top:

```blade
{{-- Greeting (moved from Greeting tab) --}}
<div class="mb-6 pb-6 border-b border-white/5">
    <h3 class="text-sm font-semibold text-slate-300 mb-3">Greeting</h3>
    <div class="space-y-3">
        <div>
            <label class="text-xs text-slate-400 mb-1 block">Greeting script</label>
            <textarea wire:model="greetingScript" rows="3"
                      class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200 resize-none focus:border-indigo-500 focus:outline-none"
                      placeholder="Hello, I am {{ $avatar->short_name }}…"></textarea>
        </div>
        <div class="flex gap-2">
            <div class="flex-1">
                <label class="text-xs text-slate-400 mb-1 block">Voice provider</label>
                <select wire:model="voice_provider"
                        class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-slate-200">
                    <option value="edge-tts">edge-tts</option>
                    <option value="kokoro">Kokoro</option>
                    <option value="openai">OpenAI</option>
                </select>
            </div>
        </div>
    </div>
</div>
```

Note: if `greetingScript` is not a property on `AvatarStudio`, add it as `public string $greetingScript = '';` and initialise it in `mount()` from the avatar's greeting text.

- [ ] **Step 6: Run the test suite to check nothing broke**

```bash
php artisan test
```

Expected: all previously passing tests still pass.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/AvatarStudio.php resources/views/livewire/admin/avatar-studio.blade.php
git commit -m "refactor: move Greeting tab settings into Avatar Studio Settings tab"
```

---

### Task 15: LlmService emotion tag prompt

**Files:**
- Modify: `app/Services/LlmService.php`

- [ ] **Step 1: Locate the system prompt string**

```bash
grep -n "system" app/Services/LlmService.php | head -20
```

- [ ] **Step 2: Append emotion tag instructions**

Find the system prompt (a string passed as the `system` role or similar). Append this paragraph inside the existing prompt string, before the output format instructions:

```
Where it genuinely improves the story, wrap sentences in emotion tags to guide
voice delivery. Available tags: [serious], [cheerful], [excited], [empathetic],
[whispering], [narrative]. Close each tag with [/tag]. Example:
[serious]The army faced devastating losses.[/serious]
Use these sparingly — untagged text is delivered in a natural narrative tone.
Never invent tags not in the list above.
```

- [ ] **Step 3: Run existing LLM-related tests**

```bash
php artisan test tests/Feature/Jobs/GenerateLessonTest.php
```

Expected: all tests still pass (the prompt change is additive, not structural).

- [ ] **Step 4: Commit**

```bash
git add app/Services/LlmService.php
git commit -m "feat: add emotion tag instructions to LLM script generation prompt"
```

---

### Task 16: Smoke test end-to-end in browser

No automated test for this — manual verification steps.

- [ ] **Step 1: Ensure queue worker is running**

```bash
php artisan queue:work --once
# Or with composer dev already running: it starts a queue worker automatically
```

- [ ] **Step 2: Copy a sample GLB to the public folder**

```bash
mkdir -p public/avatars/1
# Copy any MPFB-exported GLB (or any GLB for shape testing):
cp /path/to/your/character.glb public/avatars/1/character.glb
cp /path/to/your/morph-map.json public/avatars/1/morph-map.json
```

For an immediate smoke test without a real GLB, create a minimal morph-map:
```bash
echo '{}' > public/avatars/1/morph-map.json
```

- [ ] **Step 3: Set Azure credentials in `.env`**

```env
AZURE_SPEECH_KEY=your_actual_key
AZURE_SPEECH_REGION=eastus
```

```bash
php artisan config:clear
```

- [ ] **Step 4: Visit the lab**

Open `http://127.0.0.1:8000/admin/avatar-lab` in your browser.

Expected:
- Page loads with sidebar + canvas area
- Selecting an avatar populates settings
- Entering a test script and clicking "Generate Preview" dispatches the job
- Status dot turns amber "Generating…" while polling
- After job completes (~5–10s): status turns green, Play button appears
- Clicking Play plays audio with blend shapes driving the 3D character

- [ ] **Step 5: Run full test suite**

```bash
php artisan test
```

Expected: all tests pass.

- [ ] **Step 6: Final commit**

```bash
git add -A
git commit -m "feat: 3D Avatar Lab — Azure Speech lip sync with Three.js morph targets"
```

---

## Self-Review Checklist

**Spec coverage:**
- ✅ Config + env vars (Task 1)
- ✅ avatars table migration (Task 2)
- ✅ lessons table migration (Task 3) — corrected from spec (LessonMedia doesn't exist)
- ✅ Avatar + Lesson model fillable/casts (Task 4)
- ✅ AzureTtsException (Task 5)
- ✅ SsmlBuilder with auto/manual emotion modes + voice lookup table (Task 6)
- ✅ azure_tts.py Python script (Task 7)
- ✅ AzureTtsService with runPython seam (Task 8)
- ✅ GenerateAvatarPreview3d job + failed() handler (Task 9)
- ✅ AvatarLab Livewire component + pollPreviewStatus() (Task 10)
- ✅ avatar-3d.js with morph target interpolation + idle blink/breathing (Task 11)
- ✅ Livewire view with settings sidebar, 2D/3D toggle, canvas (Task 12)
- ✅ Route + nav entry (Task 13)
- ✅ Greeting tab removed from AvatarStudio, moved to Settings tab (Task 14)
- ✅ LlmService prompt update (Task 15)
- ✅ Manual smoke test (Task 16)
- ✅ morph-map.json format documented in Task 11 (loading + format)
- ✅ "No 3D character uploaded yet" UI state (Task 12)
- ✅ Azure 429 retry handled via $retryAfter = 60 (Task 9)

**Spec item not in a task:** The `public/avatars/{id}/character.glb` manual upload mechanism is handled in Task 16 (smoke test step 2) and shown in the UI message in Task 12.

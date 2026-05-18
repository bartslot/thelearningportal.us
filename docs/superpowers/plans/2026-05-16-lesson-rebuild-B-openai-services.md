# Plan B — OpenAI Services + DocumentExtractor

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three new services that the generation pipeline (Plan C) will depend on: `OpenAiLlmService` (JSON + text completions), `OpenAiImageService` (DALL·E 3 with style templates and safety guardrails), and `DocumentExtractor` (PDF + DOCX → text).

**Architecture:** Plain Laravel service classes under `app/Services/`. HTTP calls via `Illuminate\Support\Facades\Http` so they're trivially fakeable in tests. Style templates and the safety guardrail live in constants on `OpenAiImageService`. `DocumentExtractor` thin-wraps the `smalot/pdfparser` and `phpoffice/phpword` packages.

**Tech Stack:** Laravel 12, `Http` facade, `smalot/pdfparser`, `phpoffice/phpword`.

**Spec:** `docs/superpowers/specs/2026-05-16-lesson-creation-rebuild-design.md` (§3.3, §5.2, §5.3).

---

## File Structure

```
config/services.php                                    MODIFY (openai block)
app/Services/
  OpenAiLlmService.php                                 NEW
  OpenAiImageService.php                               NEW
  DocumentExtractor.php                                NEW
  Support/ImageStyleTemplate.php                       NEW (style + guardrail strings)
  Support/GradeBandStyleRecommender.php                NEW
tests/Unit/Services/
  OpenAiLlmServiceTest.php                             NEW
  OpenAiImageServiceTest.php                           NEW
  DocumentExtractorTest.php                            NEW
  GradeBandStyleRecommenderTest.php                    NEW
tests/Fixtures/documents/
  sample.pdf                                           NEW (committed fixture)
  sample.docx                                          NEW (committed fixture)
composer.json                                          MODIFY (require smalot/pdfparser, phpoffice/phpword)
.env.example                                           MODIFY (OPENAI_MODEL, OPENAI_IMAGE_MODEL)
```

---

## Task 1: Install PDF + DOCX libraries

- [ ] **Step 1: Install packages**

```bash
composer require smalot/pdfparser phpoffice/phpword
```
Expected: both added to `composer.json` `require` section.

- [ ] **Step 2: Verify vendor dirs**

```bash
ls vendor/smalot/pdfparser vendor/phpoffice/phpword | head
```
Expected: each lists source files.

- [ ] **Step 3: Commit `composer.json` + `composer.lock`**

```bash
git add composer.json composer.lock
git commit -m "chore: add pdfparser + phpword for document extraction"
```

---

## Task 2: OpenAI config block

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Append to `config/services.php` array**

```php
'openai' => [
    'api_key'       => env('OPENAI_API_KEY'),
    'organization'  => env('OPENAI_ORGANIZATION'),
    'model'         => env('OPENAI_MODEL', 'gpt-4o-mini'),
    'image_model'   => env('OPENAI_IMAGE_MODEL', 'dall-e-3'),
    'image_size'    => env('OPENAI_IMAGE_SIZE', '1792x1024'),
    'base_url'      => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'timeout'       => (int) env('OPENAI_TIMEOUT', 60),
],
```

- [ ] **Step 2: Append to `.env.example`**

```
OPENAI_API_KEY=
OPENAI_ORGANIZATION=
OPENAI_MODEL=gpt-4o-mini
OPENAI_IMAGE_MODEL=dall-e-3
OPENAI_IMAGE_SIZE=1792x1024
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_TIMEOUT=60
```

- [ ] **Step 3: Commit**

```bash
git add config/services.php .env.example
git commit -m "chore: add openai config + env defaults"
```

---

## Task 3: `ImageStyleTemplate` support class

**Files:**
- Create: `app/Services/Support/ImageStyleTemplate.php`
- Test: covered indirectly by `OpenAiImageServiceTest`; no separate test file.

- [ ] **Step 1: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services\Support;

final class ImageStyleTemplate
{
    public const SAFETY_GUARDRAIL = 'no children, no real living people, no readable text in image';
    public const PANORAMIC_HINT   = '2:1 wide aspect, panoramic composition, ambient periphery';
    public const GAME_HINT        = 'battle/scene illustration, dim mid-tones suitable for overlaid UI, no clutter';

    private const STYLES = [
        'realistic' => 'photographic, period-accurate, natural light, shallow DOF, people in period clothing',
        'sketched'  => 'pencil ink sketch, hatching, period dress, sepia tones',
        'painted'   => 'oil painting, romantic era, visible brushstrokes, dramatic light',
        'cinematic' => 'film still, anamorphic, dusk lighting, color graded',
        'comic'     => 'bold ink outlines, flat color panels, halftone shading',
        'animation' => 'stylized 3D animation, soft lighting, expressive characters',
    ];

    public static function build(string $seedPrompt, string $style, bool $isGame = false): string
    {
        $styleClause = self::STYLES[$style] ?? self::STYLES['realistic'];
        $parts       = [
            trim($seedPrompt),
            $styleClause,
            self::PANORAMIC_HINT,
            $isGame ? self::GAME_HINT : null,
            self::SAFETY_GUARDRAIL,
        ];

        return implode('. ', array_filter($parts));
    }

    /** @return string[] */
    public static function styles(): array
    {
        return array_keys(self::STYLES);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Support/ImageStyleTemplate.php
git commit -m "feat(services): image style template + safety guardrail"
```

---

## Task 4: `GradeBandStyleRecommender`

**Files:**
- Create: `app/Services/Support/GradeBandStyleRecommender.php`
- Test: `tests/Unit/Services/GradeBandStyleRecommenderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\Support\GradeBandStyleRecommender;

it('recommends animation for kindergarten through grade 3', function (string $grade): void {
    expect(GradeBandStyleRecommender::recommend($grade))->toContain('animation');
})->with(['K', 'kindergarten', '1st grade', '2nd grade', '3rd grade']);

it('recommends comic or sketched for grades 4-6', function (string $grade): void {
    $rec = GradeBandStyleRecommender::recommend($grade);
    expect($rec)->toContain('comic')->or->toContain('sketched');
})->with(['4th grade', '5th grade', '6th grade']);

it('recommends painted or cinematic for grades 7-9', function (string $grade): void {
    $rec = GradeBandStyleRecommender::recommend($grade);
    expect($rec)->toContain('painted')->or->toContain('cinematic');
})->with(['7th grade', '8th grade', '9th grade']);

it('recommends realistic or cinematic for grades 10-12', function (string $grade): void {
    $rec = GradeBandStyleRecommender::recommend($grade);
    expect($rec)->toContain('realistic')->or->toContain('cinematic');
})->with(['10th grade', '11th grade', '12th grade']);

it('falls back to realistic for unknown grade strings', function (): void {
    expect(GradeBandStyleRecommender::recommend('adult'))->toBe(['realistic']);
});
```

- [ ] **Step 2: Run, confirm fail**

```bash
./vendor/bin/pest tests/Unit/Services/GradeBandStyleRecommenderTest.php
```

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services\Support;

final class GradeBandStyleRecommender
{
    /** @return string[] */
    public static function recommend(string $gradeLevel): array
    {
        $grade = self::extractGradeNumber($gradeLevel);

        return match (true) {
            $grade === null                        => ['realistic'],
            $grade <= 3                            => ['animation'],
            $grade >= 4  && $grade <= 6            => ['comic', 'sketched'],
            $grade >= 7  && $grade <= 9            => ['painted', 'cinematic'],
            $grade >= 10                           => ['realistic', 'cinematic'],
            default                                => ['realistic'],
        };
    }

    private static function extractGradeNumber(string $input): ?int
    {
        $lower = strtolower(trim($input));
        if ($lower === 'k' || str_starts_with($lower, 'kindergarten')) {
            return 0;
        }
        if (preg_match('/(\d+)/', $lower, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
./vendor/bin/pest tests/Unit/Services/GradeBandStyleRecommenderTest.php
git add app/Services/Support/GradeBandStyleRecommender.php \
        tests/Unit/Services/GradeBandStyleRecommenderTest.php
git commit -m "feat(services): grade-band style recommender"
```

---

## Task 5: `OpenAiLlmService`

**Files:**
- Create: `app/Services/OpenAiLlmService.php`
- Test: `tests/Unit/Services/OpenAiLlmServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\OpenAiLlmService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.openai', [
        'api_key'    => 'test-key',
        'model'      => 'gpt-4o-mini',
        'base_url'   => 'https://api.openai.com/v1',
        'timeout'    => 30,
    ]);
});

it('returns parsed JSON when called with json mode', function (): void {
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'title'         => 'Napoleonic Campaigns',
                    'scene_briefs'  => [
                        ['order' => 1, 'kind' => 'narration', 'year' => '1810', 'location' => 'Paris', 'beat' => 'intro'],
                    ],
                ])],
            ]],
        ], 200),
    ]);

    $result = app(OpenAiLlmService::class)->json(
        system: 'You are a curriculum writer.',
        user:   'Outline a lesson on the Napoleonic Campaigns.',
    );

    expect($result)->toBeArray()
        ->and($result['title'])->toBe('Napoleonic Campaigns')
        ->and($result['scene_briefs'][0]['year'])->toBe('1810');
});

it('returns a string when called with text mode', function (): void {
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'In 1810, Napoleon ruled France.']]],
        ], 200),
    ]);

    $text = app(OpenAiLlmService::class)->text(
        system: 'You write tight, vivid history.',
        user:   'Write one paragraph for Scene 1.',
    );

    expect($text)->toBe('In 1810, Napoleon ruled France.');
});

it('throws when the response status is 5xx', function (): void {
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response('boom', 503),
    ]);

    app(OpenAiLlmService::class)->text(system: 's', user: 'u');
})->throws(\RuntimeException::class);
```

- [ ] **Step 2: Run, confirm fail**

```bash
./vendor/bin/pest tests/Unit/Services/OpenAiLlmServiceTest.php
```

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiLlmService
{
    public function __construct(
        private readonly ?string $apiKey  = null,
        private readonly ?string $model   = null,
        private readonly ?string $baseUrl = null,
        private readonly ?int    $timeout = null,
    ) {}

    public function json(string $system, string $user, ?string $model = null): array
    {
        $raw = $this->call($system, $user, $model, jsonMode: true);
        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI returned non-JSON despite json_object response_format.');
        }
        return $parsed;
    }

    public function text(string $system, string $user, ?string $model = null): string
    {
        return $this->call($system, $user, $model, jsonMode: false);
    }

    private function call(string $system, string $user, ?string $model, bool $jsonMode): string
    {
        $payload = [
            'model'    => $model ?? $this->model ?? config('services.openai.model'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.7,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $base    = $this->baseUrl ?? config('services.openai.base_url');
        $key     = $this->apiKey  ?? config('services.openai.api_key');
        $timeout = $this->timeout ?? (int) config('services.openai.timeout', 60);

        $response = Http::withToken($key)
            ->timeout($timeout)
            ->retry(times: 3, sleepMilliseconds: 1000, when: fn ($e, $req) => true)
            ->post(rtrim($base, '/') . '/chat/completions', $payload);

        if (! $response->successful()) {
            Log::warning('[OpenAiLlmService] non-2xx', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException("OpenAI API returned {$response->status()}");
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new RuntimeException('OpenAI returned an empty completion.');
        }

        return $content;
    }
}
```

- [ ] **Step 4: Pass + commit**

```bash
./vendor/bin/pest tests/Unit/Services/OpenAiLlmServiceTest.php
git add app/Services/OpenAiLlmService.php tests/Unit/Services/OpenAiLlmServiceTest.php
git commit -m "feat(services): OpenAiLlmService"
```

---

## Task 6: `OpenAiImageService`

**Files:**
- Create: `app/Services/OpenAiImageService.php`
- Test: `tests/Unit/Services/OpenAiImageServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\OpenAiImageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set('services.openai', [
        'api_key'     => 'test-key',
        'image_model' => 'dall-e-3',
        'image_size'  => '1792x1024',
        'base_url'    => 'https://api.openai.com/v1',
        'timeout'     => 60,
    ]);
    Storage::fake('public');
});

it('generates an image, downloads it, and stores it on the public disk', function (): void {
    Http::fake([
        'https://api.openai.com/v1/images/generations' => Http::response([
            'data' => [['url' => 'https://example.com/dalle.png']],
        ], 200),
        'https://example.com/dalle.png' => Http::response('PNGDATA', 200),
    ]);

    $svc = app(OpenAiImageService::class);
    $path = $svc->generate(
        seedPrompt:  'Napoleonic Paris street at dusk',
        style:       'painted',
        destination: 'lessons/1/scenes/9/skybox.png',
    );

    expect($path)->toBe('lessons/1/scenes/9/skybox.png');
    Storage::disk('public')->assertExists($path);
    expect(Storage::disk('public')->get($path))->toBe('PNGDATA');
});

it('includes the safety guardrail and style clause in the request', function (): void {
    Http::fake([
        'https://api.openai.com/v1/images/generations' => Http::response([
            'data' => [['url' => 'https://example.com/x.png']],
        ], 200),
        'https://example.com/x.png' => Http::response('X', 200),
    ]);

    app(OpenAiImageService::class)->generate(
        seedPrompt:  'Battle of Waterloo',
        style:       'cinematic',
        destination: 'lessons/1/scenes/2/skybox.png',
        isGame:      true,
    );

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/images/generations')) {
            return false;
        }
        $prompt = $request->data()['prompt'] ?? '';
        return str_contains($prompt, 'no children')
            && str_contains($prompt, 'film still')
            && str_contains($prompt, 'battle/scene illustration');
    });
});
```

- [ ] **Step 2: Run, confirm fail**

```bash
./vendor/bin/pest tests/Unit/Services/OpenAiImageServiceTest.php
```

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Support\ImageStyleTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAiImageService
{
    public function generate(
        string $seedPrompt,
        string $style,
        string $destination,
        bool   $isGame = false,
    ): string {
        $prompt = ImageStyleTemplate::build($seedPrompt, $style, $isGame);

        $base = (string) config('services.openai.base_url');
        $key  = (string) config('services.openai.api_key');

        $response = Http::withToken($key)
            ->timeout((int) config('services.openai.timeout', 60))
            ->retry(times: 3, sleepMilliseconds: 2000, when: fn ($e, $req) => true)
            ->post(rtrim($base, '/') . '/images/generations', [
                'model'  => config('services.openai.image_model'),
                'prompt' => $prompt,
                'size'   => config('services.openai.image_size'),
                'n'      => 1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Image API returned {$response->status()}: {$response->body()}");
        }

        $url = $response->json('data.0.url');
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Image API returned no URL.');
        }

        $binary = Http::timeout(30)->get($url);
        if (! $binary->successful()) {
            throw new RuntimeException("Failed to download generated image: {$binary->status()}");
        }

        Storage::disk('public')->put($destination, $binary->body());

        return $destination;
    }
}
```

- [ ] **Step 4: Pass + commit**

```bash
./vendor/bin/pest tests/Unit/Services/OpenAiImageServiceTest.php
git add app/Services/OpenAiImageService.php tests/Unit/Services/OpenAiImageServiceTest.php
git commit -m "feat(services): OpenAiImageService"
```

---

## Task 7: `DocumentExtractor`

**Files:**
- Create: `app/Services/DocumentExtractor.php`
- Test: `tests/Unit/Services/DocumentExtractorTest.php`
- Fixtures: `tests/Fixtures/documents/sample.pdf`, `tests/Fixtures/documents/sample.docx`

- [ ] **Step 1: Add fixture files**

Create `tests/Fixtures/documents/` directory.

For the PDF fixture, generate with `pandoc` (or any tool):
```bash
mkdir -p tests/Fixtures/documents
printf "Napoleon was born in 1769 on the island of Corsica.\n" > /tmp/sample.txt
pandoc /tmp/sample.txt -o tests/Fixtures/documents/sample.pdf
pandoc /tmp/sample.txt -o tests/Fixtures/documents/sample.docx
```
Expected: `ls tests/Fixtures/documents/` shows `sample.pdf`, `sample.docx`.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\DocumentExtractor;
use Illuminate\Http\UploadedFile;

it('extracts text from a PDF', function (): void {
    $path = base_path('tests/Fixtures/documents/sample.pdf');
    $text = app(DocumentExtractor::class)->extractFromPath($path);

    expect($text)->toContain('Napoleon')->and($text)->toContain('1769');
});

it('extracts text from a DOCX', function (): void {
    $path = base_path('tests/Fixtures/documents/sample.docx');
    $text = app(DocumentExtractor::class)->extractFromPath($path);

    expect($text)->toContain('Napoleon');
});

it('extracts text from an UploadedFile', function (): void {
    $path   = base_path('tests/Fixtures/documents/sample.pdf');
    $upload = new UploadedFile($path, 'sample.pdf', 'application/pdf', null, true);

    expect(app(DocumentExtractor::class)->extract($upload))->toContain('Napoleon');
});

it('throws for unsupported extensions', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'doc') . '.xls';
    file_put_contents($tmp, 'fake');

    app(DocumentExtractor::class)->extractFromPath($tmp);
})->throws(\InvalidArgumentException::class);
```

- [ ] **Step 3: Run, confirm fail**

```bash
./vendor/bin/pest tests/Unit/Services/DocumentExtractorTest.php
```

- [ ] **Step 4: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentExtractor
{
    public function extract(UploadedFile $file): string
    {
        return $this->extractFromPath($file->getRealPath() ?: $file->getPathname(), $file->getClientOriginalExtension());
    }

    public function extractFromPath(string $path, ?string $extensionOverride = null): string
    {
        $ext = strtolower($extensionOverride ?: pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'  => $this->extractPdf($path),
            'docx' => $this->extractDocx($path),
            default => throw new InvalidArgumentException("Unsupported document type: .{$ext}"),
        };
    }

    private function extractPdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf    = $parser->parseFile($path);
        return $this->normalize($pdf->getText());
    }

    private function extractDocx(string $path): string
    {
        $reader = IOFactory::createReader('Word2007');
        $phpWord = $reader->load($path);

        $buffer = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $buffer[] = $this->extractElementText($element);
            }
        }

        return $this->normalize(implode("\n", $buffer));
    }

    private function extractElementText(mixed $element): string
    {
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            if (is_string($text)) {
                return $text;
            }
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->extractElementText($child);
            }
            return implode(' ', $parts);
        }

        return '';
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
```

- [ ] **Step 5: Pass + commit**

```bash
./vendor/bin/pest tests/Unit/Services/DocumentExtractorTest.php
git add app/Services/DocumentExtractor.php \
        tests/Unit/Services/DocumentExtractorTest.php \
        tests/Fixtures/documents/sample.pdf \
        tests/Fixtures/documents/sample.docx
git commit -m "feat(services): DocumentExtractor for PDF + DOCX"
```

---

## Task 8: Run the full suite

- [ ] **Step 1:** `composer test`
- [ ] **Step 2:** confirm no regressions.

Done. Plan C consumes these services.

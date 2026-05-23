<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Support\ImageStyleTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAiImageService
{
    /** Pixel-channel value below which we treat a pixel as "black bar" filler.
     *  Raised to 28 to catch the dark-grey gradient bands OpenAI sometimes generates
     *  at the letterbox boundary (not just pure #000000). */
    private const BLACK_BAR_THRESHOLD = 28;

    /** Fraction of a row that must be near-black before we trim it. */
    private const BLACK_BAR_ROW_RATIO = 0.95;

    /**
     * Generate an image from a fully pre-built prompt (after historical validation).
     * Use this when the caller has already assembled the prompt via ImageStyleTemplate.
     */
    public function generateFromPrompt(
        string $prompt,
        string $destination,
        bool   $isGame = false,
    ): string {
        if ($isGame) {
            $prompt .= '. ' . ImageStyleTemplate::GAME_HINT;
        }

        $bytes = $this->requestOne($prompt, (string) config('services.openai.image_size', '1536x1024'));
        $bytes = $this->trimBlackBars($bytes);
        $bytes = $this->cropTo2x1($bytes);
        $bytes = $this->upscaleWithUpscayl($bytes);

        Storage::disk('public')->put($destination, $bytes);

        return $destination;
    }

    public function generate(
        string $seedPrompt,
        string $style,
        string $destination,
        bool   $isGame = false,
    ): string {
        $prompt = ImageStyleTemplate::build($seedPrompt, $style, $isGame);

        // Single API call — we ask for letterbox black bars so the panorama itself
        // is the desired 2:1 band inside whatever frame gpt-image-1 supports
        // (1024 square or 1536×1024). The post-processor then trims the bars and
        // (optionally) center-crops to a clean 2:1.
        $bytes = $this->requestOne($prompt, (string) config('services.openai.image_size', '1536x1024'));
        $bytes = $this->trimBlackBars($bytes);
        $bytes = $this->cropTo2x1($bytes);
        $bytes = $this->upscaleWithUpscayl($bytes);

        Storage::disk('public')->put($destination, $bytes);

        return $destination;
    }

    /**
     * Enhance an already-stored skybox using local Upscayl (Real-ESRGAN).
     * Called by EnhanceSkyboxImage job — takes raw image bytes and returns
     * enhanced bytes ready to overwrite the stored file.
     */
    public function enhance(string $bytes): string
    {
        $result = $this->upscaleWithUpscayl($bytes, scale: 4);
        if ($result === $bytes) {
            throw new \RuntimeException('Upscayl enhance failed — upscayl-bin returned original bytes.');
        }
        return $result;
    }

    /**
     * Pick the best Upscayl model for the given style + scene hint text.
     *
     * | Style / content                | Model               | Reason                         |
     * |--------------------------------|---------------------|--------------------------------|
     * | comic, animation, sketched     | digital-art-4x      | tuned for line-art / flat color|
     * | painted                        | high-fidelity-4x    | preserves painterly texture    |
     * | nature keywords in prompt      | high-fidelity-4x    | organic detail (grass, water)  |
     * | building/architecture keywords | ultrasharp-4x       | crisp edges, stone, brick      |
     * | everything else                | ultrasharp-4x       | safe default for photos/film   |
     */
    public static function selectUpscaylModel(string $style, string $hint = ''): string
    {
        if (in_array($style, ['comic', 'animation', 'sketched'], true)) {
            return 'digital-art-4x';
        }

        if ($style === 'painted') {
            return 'high-fidelity-4x';
        }

        $hint = mb_strtolower($hint);

        $natureKeywords = ['forest', 'ocean', 'sea', 'river', 'lake', 'field', 'jungle',
            'mountain', 'meadow', 'valley', 'grass', 'tree', 'wilderness', 'countryside',
            'desert', 'savanna', 'marsh', 'swamp', 'cliff', 'beach', 'coast'];
        foreach ($natureKeywords as $kw) {
            if (str_contains($hint, $kw)) {
                return 'high-fidelity-4x';
            }
        }

        $buildingKeywords = ['city', 'palace', 'temple', 'forum', 'castle', 'colosseum',
            'street', 'building', 'arch', 'column', 'senate', 'market', 'square',
            'acropolis', 'amphitheater', 'basilica', 'aqueduct', 'tower', 'wall',
            'monument', 'cathedral', 'citadel', 'fortress', 'ruins'];
        foreach ($buildingKeywords as $kw) {
            if (str_contains($hint, $kw)) {
                return 'ultrasharp-4x';
            }
        }

        return 'ultrasharp-4x';
    }

    /**
     * Public entry-point for the UpscayleSceneImage job — runs Upscayl with the
     * given model and returns enhanced bytes (or original on failure).
     */
    public function upscaleLocal(string $bytes, string $modelName): string
    {
        return $this->upscaleWithUpscayl($bytes, scale: 2, modelName: $modelName);
    }

    /**
     * Upscale via local Upscayl CLI (Real-ESRGAN). Falls through gracefully —
     * returns original bytes on any failure so the lesson still works.
     */
    private function upscaleWithUpscayl(string $bytes, int $scale = 2, string $modelName = ''): string
    {
        if (! (bool) config('services.upscayl.enabled', true)) {
            return $bytes;
        }

        $bin        = (string) config('services.upscayl.bin', '/Applications/Upscayl.app/Contents/Resources/bin/upscayl-bin');
        $modelPath  = (string) config('services.upscayl.model_path', '/Applications/Upscayl.app/Contents/Resources/models');
        $modelName  = $modelName !== '' ? $modelName : (string) config('services.upscayl.model', 'upscayl-standard-4x');

        if (! is_executable($bin)) {
            Log::warning('[upscayl] binary not found or not executable: ' . $bin);
            return $bytes;
        }

        try {
            $tmpIn  = tempnam(sys_get_temp_dir(), 'upscayl_in_') . '.webp';
            $tmpOut = tempnam(sys_get_temp_dir(), 'upscayl_out_') . '.webp';

            file_put_contents($tmpIn, $bytes);

            $cmd = sprintf(
                '%s -i %s -o %s -m %s -n %s -z 4 -s %d -f webp 2>&1',
                escapeshellarg($bin),
                escapeshellarg($tmpIn),
                escapeshellarg($tmpOut),
                escapeshellarg($modelPath),
                escapeshellarg($modelName),
                $scale,
            );

            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || ! file_exists($tmpOut) || filesize($tmpOut) === 0) {
                Log::warning('[upscayl] failed (exit ' . $exitCode . '): ' . implode(' ', $output));
                return $bytes;
            }

            $upscaled = file_get_contents($tmpOut);
            return ($upscaled !== false && $upscaled !== '') ? $upscaled : $bytes;
        } catch (\Throwable $e) {
            Log::warning('[upscayl] exception: ' . $e->getMessage());
            return $bytes;
        } finally {
            isset($tmpIn)  && file_exists($tmpIn)  && unlink($tmpIn);
            isset($tmpOut) && file_exists($tmpOut) && unlink($tmpOut);
        }
    }

    private function reencodeWebp(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return $bytes;
        }
        $out = $this->encodeWebp($img);
        imagedestroy($img);
        return $out;
    }

    /**
     * Call the OpenAI images/generations endpoint once and return the raw image bytes.
     */
    private function requestOne(string $prompt, string $size): string
    {
        $base = (string) config('services.openai.base_url');
        $key  = (string) config('services.openai.api_key');

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('services.openai.timeout', 60))
                ->retry(times: 3, sleepMilliseconds: 2000, when: fn ($e, $req) => true, throw: false)
                ->post(rtrim($base, '/') . '/images/generations', [
                    'model'              => config('services.openai.image_model'),
                    'prompt'             => $prompt,
                    'size'               => $size,
                    'output_format'      => (string) config('services.openai.image_format', 'webp'),
                    'output_compression' => (int) config('services.openai.image_compression', 50),
                    'n'                  => 1,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Image API request failed: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Image API returned {$response->status()}: {$response->body()}");
        }

        $payload = $response->json('data.0') ?? [];

        if (is_string($payload['b64_json'] ?? null) && $payload['b64_json'] !== '') {
            $bytes = base64_decode($payload['b64_json'], true);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }
        if (is_string($payload['url'] ?? null) && $payload['url'] !== '') {
            $binary = Http::timeout(30)->get($payload['url']);
            if (! $binary->successful()) {
                throw new RuntimeException("Failed to download generated image: {$binary->status()}");
            }
            return $binary->body();
        }

        throw new RuntimeException('Image API returned neither url nor b64_json.');
    }

    /**
     * Detect and crop solid-black letterbox bars on the top and bottom (and, defensively,
     * left/right) of the returned image. The model is asked to letterbox to 2:1; this
     * trims whatever bars actually came back.
     */
    /**
     * Remove black bars and crop to 2:1 — usable by any job that downloads an
     * AI-generated panorama (OpenAI or fal.ai).
     */
    public function cleanPanorama(string $bytes): string
    {
        $bytes = $this->trimBlackBars($bytes);
        $bytes = $this->cropTo2x1($bytes);
        return $bytes;
    }

    private function trimBlackBars(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return $bytes;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        $top    = 0;
        $bottom = $h - 1;
        $left   = 0;
        $right  = $w - 1;

        while ($top    < $bottom && $this->isBlackRow($img,    $top,    $w))    $top++;
        while ($bottom > $top    && $this->isBlackRow($img,    $bottom, $w))    $bottom--;
        while ($left   < $right  && $this->isBlackColumn($img, $left,   $h))    $left++;
        while ($right  > $left   && $this->isBlackColumn($img, $right,  $h))    $right--;

        $newW = $right  - $left + 1;
        $newH = $bottom - $top  + 1;

        // No meaningful trim → leave the bytes alone.
        if ($newW >= $w && $newH >= $h) {
            imagedestroy($img);
            return $bytes;
        }
        if ($newW <= 0 || $newH <= 0) {
            imagedestroy($img);
            return $bytes;
        }

        $cropped = imagecrop($img, [
            'x'      => $left,
            'y'      => $top,
            'width'  => $newW,
            'height' => $newH,
        ]);
        imagedestroy($img);

        if ($cropped === false) {
            return $bytes;
        }

        $out = $this->encodeWebp($cropped);
        imagedestroy($cropped);
        return $out !== '' ? $out : $bytes;
    }

    private function isBlackRow(\GdImage $img, int $y, int $w): bool
    {
        $step      = max(1, (int) ($w / 64));      // sample ~64 columns
        $samples   = 0;
        $blackCnt  = 0;
        for ($x = 0; $x < $w; $x += $step) {
            $samples++;
            if ($this->isBlackPixel($img, $x, $y)) $blackCnt++;
        }
        return $samples > 0 && ($blackCnt / $samples) >= self::BLACK_BAR_ROW_RATIO;
    }

    private function isBlackColumn(\GdImage $img, int $x, int $h): bool
    {
        $step      = max(1, (int) ($h / 64));
        $samples   = 0;
        $blackCnt  = 0;
        for ($y = 0; $y < $h; $y += $step) {
            $samples++;
            if ($this->isBlackPixel($img, $x, $y)) $blackCnt++;
        }
        return $samples > 0 && ($blackCnt / $samples) >= self::BLACK_BAR_ROW_RATIO;
    }

    private function isBlackPixel(\GdImage $img, int $x, int $y): bool
    {
        $rgb = imagecolorat($img, $x, $y);
        $r   = ($rgb >> 16) & 0xFF;
        $g   = ($rgb >> 8)  & 0xFF;
        $b   =  $rgb        & 0xFF;
        return max($r, $g, $b) <= self::BLACK_BAR_THRESHOLD;
    }

    private function cropTo2x1(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return $bytes;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $targetH = (int) round($w / 2);

        if ($h <= $targetH) {
            imagedestroy($img);
            return $bytes;
        }

        $cropped = imagecrop($img, [
            'x'      => 0,
            'y'      => (int) round(($h - $targetH) / 2),
            'width'  => $w,
            'height' => $targetH,
        ]);
        imagedestroy($img);

        if ($cropped === false) {
            return $bytes;
        }

        $out = $this->encodeWebp($cropped);
        imagedestroy($cropped);
        return $out !== '' ? $out : $bytes;
    }

    private function encodeWebp(\GdImage $img): string
    {
        $quality = (int) config('services.openai.image_compression', 50);
        ob_start();
        imagewebp($img, null, $quality);
        return (string) ob_get_clean();
    }
}

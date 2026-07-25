<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Models\Scene;
use App\Services\OpenAiImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bring every scene background up to stage resolution.
 *
 * A scene image sourced from a Wikipedia/Commons *thumbnail* can be as small as 250px wide — on a
 * 1920px stage that reads as a blurry mess. This pass fixes both halves of that problem:
 *   1. re-fetch the FULL-RESOLUTION original from Commons when the stored file is undersized, then
 *   2. run the local Upscayl model on anything still below the target width.
 *
 * Idempotent: a file already at/above the target width is left alone, so it is safe to re-run.
 */
class EnhanceSceneImages extends Command
{
    protected $signature = 'lessons:enhance-images
        {--lesson= : Only this lesson id or lesson_code (default: every lesson)}
        {--min=1600 : Target minimum width in pixels}
        {--model= : Upscayl model override (default: services.upscayl.model)}
        {--dry : Report what would change without writing}';

    protected $description = 'Re-fetch full-resolution originals and Upscayl-sharpen undersized scene backgrounds';

    public function handle(OpenAiImageService $images): int
    {
        $min = max(320, (int) $this->option('min'));
        $model = (string) ($this->option('model') ?: config('services.upscayl.model', 'upscayl-standard-4x'));
        $dry = (bool) $this->option('dry');

        $scenes = $this->targetScenes();
        if ($scenes->isEmpty()) {
            $this->warn('No scenes with a stored background image.');

            return self::SUCCESS;
        }

        $this->line("Checking {$scenes->count()} scene image(s) against a {$min}px target…");
        $upscaled = 0;
        $refetched = 0;
        $skipped = 0;

        foreach ($scenes as $scene) {
            $path = (string) $scene->image_path;
            if (! Storage::disk('public')->exists($path)) {
                $this->line("  scene {$scene->id}: file missing ({$path})");

                continue;
            }

            $bytes = Storage::disk('public')->get($path);
            [$w, $h] = $this->dimensions($bytes);
            if ($w >= $min) {
                $skipped++;

                continue;
            }

            $this->line("  scene {$scene->id}: {$w}x{$h} — below target");
            if ($dry) {
                continue;
            }

            // 1) A bigger ORIGINAL always beats an upscale — try Commons at full width first.
            $better = $this->fetchFullResolution($scene, $min);
            if ($better !== null) {
                [$bw] = $this->dimensions($better);
                if ($bw > $w) {
                    $bytes = $better;
                    $w = $bw;
                    $refetched++;
                    $this->line("    ↳ re-fetched original at {$bw}px");
                }
            }

            // 2) Still short of the target → local Upscayl pass. One pass is 2×, so a very small
            //    source (a 250px thumbnail) needs several; cap the passes so a model that refuses
            //    to grow an image can't spin forever.
            $passes = 0;
            while ($w < $min && $passes < 3) {
                $passes++;
                try {
                    $enhanced = $images->upscaleLocal($bytes, $model);
                    [$ew, $eh] = $this->dimensions($enhanced);
                    if ($ew <= $w) {
                        $this->warn("    ↳ upscayl pass {$passes} did not grow the image — stopping");
                        break;
                    }
                    $bytes = $enhanced;
                    $w = $ew;
                    $upscaled++;
                    $this->line("    ↳ upscayl {$model} pass {$passes}: {$ew}x{$eh}");
                } catch (Throwable $e) {
                    $this->warn("    ↳ upscayl failed: ".$e->getMessage());
                    break;
                }
            }

            Storage::disk('public')->put($path, $bytes);
            $scene->touch();   // bust the ?v= cache-buster the payload appends
        }

        $this->info("Done — {$refetched} re-fetched, {$upscaled} upscaled, {$skipped} already sharp.");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,Scene> */
    private function targetScenes(): \Illuminate\Support\Collection
    {
        $query = Scene::query()->whereNotNull('image_path');

        if ($ref = $this->option('lesson')) {
            $lesson = is_numeric($ref)
                ? Lesson::find((int) $ref)
                : Lesson::where('lesson_code', (string) $ref)->first();
            if (! $lesson) {
                $this->error("Lesson '{$ref}' not found.");

                return collect();
            }
            $query->where('lesson_id', $lesson->id);
        }

        return $query->orderBy('lesson_id')->orderBy('order')->get();
    }

    /**
     * Ask Commons for the widest rendering of this scene's source file. The stored path tells us
     * nothing about the origin, so we re-derive it from the scene's own image_prompt/location via
     * the article the lesson was built from — callers that know the file can pass it in the config.
     */
    private function fetchFullResolution(Scene $scene, int $min): ?string
    {
        $source = $scene->config['image_source_url'] ?? null;
        if (! is_string($source) || $source === '') {
            return null;
        }

        // Special:FilePath honours ?width= and serves the original when it is smaller.
        $url = str_contains($source, 'Special:FilePath')
            ? $source.(str_contains($source, '?') ? '&' : '?')."width={$min}"
            : $source;

        try {
            $res = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'LearningPortal/1.0 (thelearningportal.us; scene imagery)',
            ])->timeout(30)->get($url);

            return ($res->successful() && $res->body() !== '') ? $res->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0:int,1:int} */
    private function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        return [(int) ($info[0] ?? 0), (int) ($info[1] ?? 0)];
    }
}

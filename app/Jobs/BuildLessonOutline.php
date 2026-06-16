<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Services\LessonOutlinePrompt;
use App\Services\OpenAiLlmService;
use App\Services\WikipediaService;
use App\Services\WorldHistoryService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildLessonOutline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $lessonId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(OpenAiLlmService $llm): void
    {
        $lesson = Lesson::with('source', 'strategyGame')->findOrFail($this->lessonId);

        try {
            // ── Step 1: Fetch internet sources (worldhistory.org → wikipedia fallback) ──
            $source = $lesson->source;
            if ($source && in_array($source->kind, ['internet', 'wikipedia', 'both'], true)
                && (string) $source->extracted_text === '') {

                $lesson->update(['status' => LessonStatus::FetchingSources]);

                // A1: if the lesson came from the curated catalog it carries the EXACT Wikipedia
                // article URL — fetch that page directly (no slug-guessing, no disambiguation drift).
                $text = null;
                $fromWH = false;
                if (filled($lesson->wikipedia_source)) {
                    $text = app(WikipediaService::class)->fetchByUrl($lesson->wikipedia_source);
                    if ($text !== null) {
                        Log::info("BuildLessonOutline #{$lesson->id}: fetched exact catalog article {$lesson->wikipedia_source}");
                    }
                }

                // Try World History Encyclopedia next — richer, more educational content
                $worldHistory = app(WorldHistoryService::class);
                if ($text === null) {
                    $text = $worldHistory->fetchFacts($lesson->topic);
                    $fromWH = $text !== null;
                }

                if ($fromWH) {
                    Log::info("BuildLessonOutline #{$lesson->id}: fetched from worldhistory.org");

                    // Try to grab the article's hero image while we have the HTML cached
                    $hero = $worldHistory->fetchHeroImage($lesson->id);
                    if ($hero) {
                        $source->update([
                            'hero_image_url' => $hero['url'],
                            'hero_image_path' => $hero['path'],
                        ]);
                        Log::info(sprintf(
                            'BuildLessonOutline #{%d}: hero image saved — colorful=%s (%dx%d)',
                            $lesson->id, $hero['colorful'] ? 'yes' : 'no', $hero['width'], $hero['height'],
                        ));
                    }
                } elseif ($text === null) {
                    // Fallback to Wikipedia by topic name (only when nothing fetched yet)
                    $text = app(WikipediaService::class)->fetchFacts($lesson->topic) ?? '';
                    Log::info("BuildLessonOutline #{$lesson->id}: fell back to Wikipedia");
                }
                $text ??= '';

                // For 'both' mode the document text was already extracted and stored
                $combined = $source->kind === 'both'
                    ? trim($source->extracted_text."\n\n".$text)
                    : $text;

                $source->update(['extracted_text' => $combined, 'wikipedia_topic' => $lesson->topic]);
            }

            // Title-screen background: download the Wikipedia lead image (full-res) for catalog
            // lessons. Hotlink-safe from upload.wikimedia.org; stored locally for offline playback.
            if (filled($lesson->wikipedia_source) && empty($lesson->title_bg_path)) {
                $imgUrl = app(WikipediaService::class)->fetchLeadImageUrl($lesson->wikipedia_source);
                if ($imgUrl) {
                    try {
                        $bytes = \Illuminate\Support\Facades\Http::timeout(20)
                            ->withUserAgent('TheLearningPortal/1.0 (https://thelearningportal.us; bartslot@gmail.com)')
                            ->get($imgUrl)->body();
                        if ($bytes !== '') {
                            $ext = pathinfo(parse_url($imgUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
                            $path = "lessons/{$lesson->id}/title-bg.{$ext}";
                            \Illuminate\Support\Facades\Storage::disk('public')->put($path, $bytes);
                            $lesson->update(['title_bg_path' => $path]);
                            Log::info("BuildLessonOutline #{$lesson->id}: title background saved from {$imgUrl}");
                        }
                    } catch (Throwable $e) {
                        Log::warning("BuildLessonOutline #{$lesson->id}: title bg download failed — {$e->getMessage()}");
                    }
                }
            }

            $lesson->update(['status' => LessonStatus::Outlining]);

            // Defensive: previous attempts (manual retry, queue retry, user clicked Generate twice)
            // may have left Scene rows around. The (lesson_id, order) unique index would then trip
            // on re-insert. Wipe and rebuild from scratch.
            $lesson->scenes()->delete();

            $lesson->refresh();
            $sourceText = (string) ($lesson->source?->extracted_text ?? '');

            $hasGame = (bool) $lesson->include_game;
            $outline = $llm->json(
                system: LessonOutlinePrompt::system($hasGame),
                user: LessonOutlinePrompt::user($lesson, $sourceText),
            );

            $lesson->update([
                'title' => $outline['title'] ?? $lesson->topic,
                'outline' => $outline,
                'status' => LessonStatus::ScenesGenerating,
            ]);

            // Ignore any "order" the LLM emitted — assign sequential 1-based positions so
            // we never trip the (lesson_id, order) unique index when the model repeats numbers.
            $scenes = [];
            foreach (($outline['scene_briefs'] ?? []) as $idx => $brief) {
                $scenes[] = Scene::create([
                    'lesson_id' => $lesson->id,
                    'order' => $idx + 1,
                    'kind' => $brief['kind'] ?? 'narration',
                    'year' => $brief['year'] ?? null,
                    'location' => $brief['location'] ?? null,
                    'image_prompt' => $brief['image_prompt_seed'] ?? null,
                    'image_style' => $lesson->image_style,
                    'game_segment_index' => $brief['game_segment_index'] ?? null,
                    'game_type' => ($brief['kind'] ?? null) === 'game' ? ($lesson->game_type ?? 'strategy') : null,
                    'quiz_question_count' => ($brief['kind'] ?? null) === 'game' && $lesson->game_type === 'quiz'
                        ? ($lesson->quiz_question_count ?? 4)
                        : null,
                    'quiz_timing' => ($brief['kind'] ?? null) === 'game' && $lesson->game_type === 'quiz'
                        ? ($lesson->quiz_timing ?? 'after')
                        : null,
                    'strategy_game_id' => ($brief['kind'] ?? null) === 'game' && ($lesson->game_type ?? null) === 'strategy'
                        ? $lesson->strategy_game_id
                        : null,
                    'team_count' => ($brief['kind'] ?? null) === 'game' && ($lesson->game_type ?? null) === 'strategy'
                        ? $lesson->team_count
                        : null,
                    'status' => 'pending',
                ]);
            }

            // If we have a colorful hero image from worldhistory.org, pre-assign it to
            // scene 1 so AI image generation is skipped for that scene.
            $heroPath = null;
            $source = $lesson->fresh()->source;
            if ($source?->hero_image_path) {
                // Re-check colorfulness by reading stored metadata via a quick saturation test
                $bytes = \Illuminate\Support\Facades\Storage::disk('public')->get($source->hero_image_path);
                if ($bytes) {
                    $img = @imagecreatefromstring($bytes);
                    if ($img) {
                        // Quick sample: 5×5 grid
                        $w = imagesx($img);
                        $h = imagesy($img);
                        $sat = 0;
                        $n = 0;
                        foreach (range(0, 4) as $ix) {
                            foreach (range(0, 4) as $iy) {
                                $px = imagecolorat($img, (int) ($w * ($ix + 0.5) / 5), (int) ($h * ($iy + 0.5) / 5));
                                $r = ($px >> 16) & 0xFF;
                                $g = ($px >> 8) & 0xFF;
                                $b = $px & 0xFF;
                                $mx = max($r, $g, $b);
                                $mn = min($r, $g, $b);
                                $d = 255 - abs($mx + $mn - 255);
                                $sat += $d > 0 ? ($mx - $mn) / $d * 255 : 0;
                                $n++;
                            }
                        }
                        imagedestroy($img);
                        if ($n > 0 && ($sat / $n) >= 40) {
                            $heroPath = $source->hero_image_path;
                        }
                    }
                }
            }

            $jobs = [];
            foreach ($scenes as $idx => $scene) {
                // Attach hero image to scene 1 (skip AI image gen for it)
                if ($idx === 0 && $heroPath) {
                    $scene->update(['image_path' => $heroPath]);
                    $jobs[] = new GenerateSceneScript($scene->id);
                    $jobs[] = new GenerateSceneAudio($scene->id);
                    // No GenerateSceneImage — image already set
                } else {
                    $jobs[] = new GenerateSceneScript($scene->id);
                    $jobs[] = new GenerateSceneImage($scene->id);
                    $jobs[] = new GenerateSceneAudio($scene->id);
                }
            }

            Bus::batch($jobs)
                ->name("lesson:{$lesson->id}:scenes")
                ->then(fn (Batch $batch) => GenerateLessonQuiz::dispatch($lesson->id))
                ->dispatch();
        } catch (Throwable $e) {
            $lesson->update([
                'status' => LessonStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

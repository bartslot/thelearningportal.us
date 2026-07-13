<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\Scene;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Assembles per-scene shots from a story's pre-generated asset pack (E3b Segment B).
 *
 * A lesson grounded in a catalog story WITH an asset pack never calls image generation:
 * ONE cheap LLM call maps each narration scene to a pack background tag (+ optionally a
 * hero pose when the protagonist is present in the beat), and the shot rows are written
 * directly. Everything the LLM returns is whitelisted against the pack — an unknown tag
 * falls back to cycling the backgrounds in order, an unknown pose to no hero. The LLM
 * call itself is best-effort: on any failure the mapping degrades to pure round-robin
 * and the pipeline continues (a pack lesson must never fail over art direction).
 *
 * Shot shape (single shot per scene, v1): image_path mirrors bg_path so every existing
 * consumer (cards, PDF, flat-player fallback) keeps working; bg_path/hero_path are the
 * Segment C parallax layers; anchor_sentence stays null — the player's even-split
 * fallback already handles unanchored shots. upscale_status is 'done' because pack
 * images are already full-res (Upscayl must not run on them).
 */
final class StoryPackShots
{
    private const SCRIPT_EXCERPT_CHARS = 280;

    public function __construct(private readonly OpenAiLlmService $llm) {}

    /**
     * Write pack-sourced shots onto every given narration scene.
     *
     * @param  Collection<int, Scene>  $scenes  narration scenes of the lesson, in order
     * @return int number of scenes visualized from the pack
     */
    public function assign(Lesson $lesson, Collection $scenes): int
    {
        $assets = (array) ($lesson->story?->assets ?? []);
        $backgrounds = $this->usableEntries($assets['backgrounds'] ?? null, 'tag');
        if ($backgrounds === [] || $scenes->isEmpty()) {
            return 0;
        }
        $heroes = $this->usableEntries($assets['hero'] ?? null, 'pose');

        $assignments = $this->mapScenes($lesson, $scenes, $backgrounds, $heroes);

        $bgByTag = array_column($backgrounds, null, 'tag');
        $heroByPose = array_column($heroes, null, 'pose');

        foreach ($scenes->values() as $index => $scene) {
            $choice = $assignments[$scene->order] ?? [];

            // Whitelist: unknown/absent tag → deterministic round-robin over the pack.
            $background = $bgByTag[$choice['bg_tag'] ?? ''] ?? $backgrounds[$index % count($backgrounds)];
            // Whitelist: unknown/absent pose → no hero layer.
            $hero = $heroByPose[$choice['hero_pose'] ?? ''] ?? null;

            $scene->update([
                'shots' => [[
                    'order' => 0,
                    'image_path' => $background['image_path'],
                    'bg_path' => $background['image_path'],
                    'hero_path' => $hero['image_path'] ?? null,
                    'anchor_sentence' => null,
                ]],
                'image_path' => $background['image_path'],
                'upscale_status' => 'done',
            ]);
        }

        return $scenes->count();
    }

    /**
     * ONE LLM call matching scenes to pack assets. NEVER fails the pipeline: any error
     * or garbage shape degrades to an empty mapping (= round-robin in assign()).
     *
     * @param  Collection<int, Scene>  $scenes
     * @param  list<array{tag: string, lighting?: string, image_path: string}>  $backgrounds
     * @param  list<array{pose: string, image_path: string}>  $heroes
     * @return array<int, array{bg_tag: string, hero_pose: ?string}> keyed by scene order
     */
    private function mapScenes(Lesson $lesson, Collection $scenes, array $backgrounds, array $heroes): array
    {
        try {
            $result = $this->llm->json(
                system: $this->systemPrompt(),
                user: $this->userPrompt($lesson, $scenes, $backgrounds, $heroes),
            );
        } catch (\Throwable $e) {
            Log::warning("StoryPackShots lesson {$lesson->id}: mapping LLM failed — falling back to round-robin. {$e->getMessage()}");

            return [];
        }

        $mapped = [];
        foreach ((array) ($result['scenes'] ?? []) as $entry) {
            if (! is_array($entry) || ! isset($entry['order'])) {
                continue;
            }
            $mapped[(int) $entry['order']] = [
                'bg_tag' => is_string($entry['bg_tag'] ?? null) ? trim($entry['bg_tag']) : '',
                'hero_pose' => is_string($entry['hero_pose'] ?? null) ? trim($entry['hero_pose']) : null,
            ];
        }

        return $mapped;
    }

    /**
     * Keep only pack entries that can actually render: a key + an image file.
     *
     * @return list<array<string, mixed>>
     */
    private function usableEntries(mixed $raw, string $key): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            fn ($entry) => is_array($entry)
                && is_string($entry[$key] ?? null) && trim($entry[$key]) !== ''
                && is_string($entry['image_path'] ?? null) && trim($entry['image_path']) !== '',
        ));
    }

    private function systemPrompt(): string
    {
        return <<<'SYS'
You are matching pre-approved story artwork to lesson scenes. For each scene pick the
background tag whose setting and lighting best fit the scene's beat and script, and a
hero pose ONLY when the protagonist is physically present and acting in that beat —
otherwise hero_pose must be null. Use only the tags and poses provided.

Return ONLY JSON:
{ "scenes": [ { "order": int, "bg_tag": string, "hero_pose": string | null } ] }
SYS;
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  list<array<string, mixed>>  $heroes
     */
    private function userPrompt(Lesson $lesson, Collection $scenes, array $backgrounds, array $heroes): string
    {
        $backgroundLines = implode("\n", array_map(
            fn (array $bg) => sprintf('- %s (%s lighting)', $bg['tag'], $bg['lighting'] ?? 'day'),
            $backgrounds,
        ));
        $poseLines = $heroes === []
            ? '(none available — hero_pose must always be null)'
            : implode("\n", array_map(fn (array $hero) => "- {$hero['pose']}", $heroes));

        $sceneLines = $scenes->values()->map(function (Scene $scene): string {
            $brief = ShotListPrompt::briefFor($scene);
            $setting = trim(implode(' ', array_filter([
                $scene->year !== null ? (string) $scene->year : null,
                $scene->location,
            ])));
            $excerpt = \Illuminate\Support\Str::limit(
                trim(preg_replace('/\s+/u', ' ', (string) $scene->script_segment) ?? ''),
                self::SCRIPT_EXCERPT_CHARS,
            );

            return sprintf(
                "Scene %d — beat: %s%s\nScript: \"%s\"",
                $scene->order,
                (string) ($brief['beat'] ?? 'narration'),
                $setting !== '' ? ", setting: {$setting}" : '',
                $excerpt,
            );
        })->implode("\n\n");

        $protagonist = $lesson->story?->protagonist_name ?: 'the protagonist';

        return <<<USR
Protagonist (the hero artwork depicts): {$protagonist}

Available background tags:
{$backgroundLines}

Available hero poses:
{$poseLines}

Scenes:
{$sceneLines}

Produce the mapping JSON now — one entry per scene, every order exactly once.
USR;
    }
}

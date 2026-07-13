<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Story;
use App\Services\Support\ImageStyleTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One-time visual asset pack for a catalog story (E3b Segment A).
 *
 * Founder economics: the story catalog is deliberately LIMITED, so generate visuals
 * ONCE per story with the good model (gpt-image-1), human-review them in StoryReview,
 * and every lesson built from the story consumes the pack at $0 marginal image cost.
 *
 * A pack = N tagged background plates (1536x1024, ink style, day/dusk/night lighting)
 * + 4 full-figure protagonist poses on TRUE transparent backgrounds (1024x1536).
 * All-or-nothing: any failure cleans up partial files and leaves story.assets untouched.
 */
final class StoryAssetPackGenerator
{
    public const STYLE = 'ink';

    public const HERO_POSE_COUNT = 4;

    private const MIN_BACKGROUNDS = 4;

    private const MAX_BACKGROUNDS = 8;

    private const MIN_HERO_POSES = 3;

    private const MAX_HERO_POSES = 5;

    private const LIGHTING = ['day', 'dusk', 'night'];

    private const MAX_TAG_LENGTH = 32;

    private const BACKGROUND_SIZE = '1536x1024';

    private const HERO_SIZE = '1024x1536';

    public function __construct(
        private readonly OpenAiLlmService $llm,
        private readonly OpenAiImageService $images,
    ) {}

    /**
     * Build the full pack and return the assets array — the CALLER persists it.
     * Throws on any failure after removing every partially written file.
     *
     * @return array{backgrounds: list<array{tag: string, lighting: string, image_path: string}>, hero: list<array{pose: string, image_path: string}>, generated_at: string, style: string}
     */
    public function generate(Story $story, int $backgrounds = 6): array
    {
        $count = max(self::MIN_BACKGROUNDS, min(self::MAX_BACKGROUNDS, $backgrounds));
        $plan = $this->planAssets($story, $count);

        // Fresh batch dir per generation so a --force regenerate never overwrites the
        // live pack's files mid-flight, and failure cleanup can't nuke the old pack.
        $batchDir = sprintf('stories/%d/assets/%s', $story->id, now()->format('YmdHis').'-'.Str::lower(Str::random(6)));
        $written = [];

        try {
            $backgroundEntries = [];
            foreach ($plan['backgrounds'] as $background) {
                $path = "{$batchDir}/bg_{$background['tag']}.png";
                $bytes = $this->images->generateStillPngBytes(
                    $this->backgroundPrompt($background),
                    self::BACKGROUND_SIZE,
                    false,
                );
                Storage::disk('public')->put($path, $bytes);
                $written[] = $path;
                $backgroundEntries[] = [
                    'tag' => $background['tag'],
                    'lighting' => $background['lighting'],
                    'image_path' => $path,
                ];
            }

            $heroEntries = [];
            foreach ($plan['hero_poses'] as $pose) {
                $path = "{$batchDir}/hero_{$pose['pose']}.png";
                $bytes = $this->images->generateStillPngBytes(
                    $this->heroPrompt($story, $pose),
                    self::HERO_SIZE,
                    true,
                );
                Storage::disk('public')->put($path, $bytes);
                $written[] = $path;
                $heroEntries[] = [
                    'pose' => $pose['pose'],
                    'image_path' => $path,
                ];
            }
        } catch (\Throwable $e) {
            $this->cleanUp($written, $batchDir);

            throw new RuntimeException(
                "Asset pack generation failed for story #{$story->id} ({$story->slug}): {$e->getMessage()}",
                previous: $e,
            );
        }

        return [
            'backgrounds' => $backgroundEntries,
            'hero' => $heroEntries,
            'generated_at' => now()->toIso8601String(),
            'style' => self::STYLE,
        ];
    }

    /**
     * ONE LLM call proposing the pack contents; everything is whitelisted/clamped
     * server-side — never trust tag strings or counts coming back from the model.
     *
     * @return array{backgrounds: list<array{tag: string, description: string, lighting: string}>, hero_poses: list<array{pose: string, description: string}>}
     */
    private function planAssets(Story $story, int $count): array
    {
        $result = $this->llm->json(
            system: $this->planSystemPrompt($count),
            user: $this->planUserPrompt($story),
        );

        $backgrounds = $this->clampBackgrounds($result['backgrounds'] ?? [], $count);
        if ($backgrounds === []) {
            throw new RuntimeException('LLM returned no usable backgrounds for the asset pack.');
        }

        $poses = $this->clampHeroPoses($result['hero_poses'] ?? []);
        if (count($poses) < self::MIN_HERO_POSES) {
            throw new RuntimeException('LLM returned fewer than '.self::MIN_HERO_POSES.' usable hero poses.');
        }

        return ['backgrounds' => $backgrounds, 'hero_poses' => $poses];
    }

    /** @return list<array{tag: string, description: string, lighting: string}> */
    private function clampBackgrounds(mixed $raw, int $count): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clamped = [];
        $seenTags = [];
        foreach (array_values($raw) as $index => $entry) {
            if (count($clamped) >= $count) {
                break;
            }
            $description = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';
            if ($description === '') {
                continue;
            }

            $tag = $this->snakeTag($entry['tag'] ?? null, 'scene_'.($index + 1));
            while (in_array($tag, $seenTags, true)) {
                $tag = Str::limit($tag, self::MAX_TAG_LENGTH - 2, '').'_'.($index + 1);
            }
            $seenTags[] = $tag;

            $lighting = is_string($entry['lighting'] ?? null) ? strtolower(trim($entry['lighting'])) : '';

            $clamped[] = [
                'tag' => $tag,
                'description' => $description,
                'lighting' => in_array($lighting, self::LIGHTING, true) ? $lighting : 'day',
            ];
        }

        return $clamped;
    }

    /** @return list<array{pose: string, description: string}> */
    private function clampHeroPoses(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clamped = [];
        $seenPoses = [];
        foreach (array_values($raw) as $index => $entry) {
            if (count($clamped) >= self::MAX_HERO_POSES) {
                break;
            }
            $description = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';
            if ($description === '') {
                continue;
            }

            $pose = $this->snakeTag($entry['pose'] ?? null, 'pose_'.($index + 1));
            while (in_array($pose, $seenPoses, true)) {
                $pose = Str::limit($pose, self::MAX_TAG_LENGTH - 2, '').'_'.($index + 1);
            }
            $seenPoses[] = $pose;

            $clamped[] = ['pose' => $pose, 'description' => $description];
        }

        return $clamped;
    }

    /** Force any LLM-proposed key into a safe snake_case tag ≤32 chars. */
    private function snakeTag(mixed $raw, string $fallback): string
    {
        $tag = is_string($raw) ? Str::snake(trim($raw)) : '';
        $tag = (string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($tag));
        $tag = trim((string) preg_replace('/_+/', '_', $tag), '_');
        $tag = rtrim(substr($tag, 0, self::MAX_TAG_LENGTH), '_');

        return $tag !== '' ? $tag : $fallback;
    }

    private function backgroundPrompt(array $background): string
    {
        $seed = $background['description']
            .', '.$background['lighting'].' lighting'
            .', consistent palette across a series — one plate of a matched background set for the same story'
            .', empty stage: no people, no figures';

        return ImageStyleTemplate::build($seed, self::STYLE);
    }

    private function heroPrompt(Story $story, array $pose): string
    {
        $protagonist = $story->protagonist_name ?: 'the story protagonist';
        $era = $story->era_start !== null
            ? sprintf('era %s%d', $story->era_start < 0 ? 'BC ' : 'AD ', abs($story->era_start))
            : null;

        $parts = array_filter([
            "Full-figure illustration of {$protagonist}, period-accurate dress and props"
                .($era ? " ({$era}".($story->region ? ", {$story->region}" : '').')' : ($story->region ? " ({$story->region})" : '')),
            'Pose: '.$pose['description'],
            ImageStyleTemplate::styleClause(self::STYLE),
            'single isolated figure, full body visible head to feet, centered',
            'isolated figure, no ground shadow, no background, no scenery, no frame',
            'consistent character design across a series of poses',
            'no readable text, no logos, no modern objects, no anachronisms',
        ]);

        return implode('. ', $parts);
    }

    private function planSystemPrompt(int $count): string
    {
        $poses = self::HERO_POSE_COUNT;

        return <<<SYS
You are an art director planning a reusable visual asset pack for a K-12 history story.
Propose distinct background settings that cover the story's narrative arc (opening, rising
action, climax, resolution) and a small set of protagonist poses that can be reused across
scenes. Only use settings and details supported by the provided story facts — never invent
places or objects, and never include anything anachronistic for the era.

Return ONLY JSON:
{
  "backgrounds": [{ "tag": string (short snake_case key, e.g. "city_square"), "description": string (one vivid sentence of the setting, NO people), "lighting": "day" | "dusk" | "night" }],
  "hero_poses": [{ "pose": string (short snake_case key, e.g. "standing_calm", "pointing", "walking"), "description": string (one sentence describing body language, NO setting) }]
}
Exactly {$count} backgrounds (each a DISTINCT setting) and exactly {$poses} hero_poses.
SYS;
    }

    private function planUserPrompt(Story $story): string
    {
        $objectives = collect($story->learning_objectives ?? [])
            ->map(fn (array $objective) => '- '.($objective['text'] ?? ''))
            ->implode("\n");
        $facts = collect($story->key_facts ?? [])
            ->map(fn (array $fact) => '- '.($fact['fact'] ?? ''))
            ->implode("\n");

        return <<<USR
Story title: {$story->title}
Subtitle: {$story->subtitle}
Protagonist: {$story->protagonist_name}
Era: {$story->era_start} to {$story->era_end}, region: {$story->region}

Learning objectives:
{$objectives}

Key facts:
{$facts}

Plan the asset pack JSON now.
USR;
    }

    /** @param list<string> $written */
    private function cleanUp(array $written, string $batchDir): void
    {
        $disk = Storage::disk('public');
        foreach ($written as $path) {
            $disk->delete($path);
        }
        $disk->deleteDirectory($batchDir);
    }
}

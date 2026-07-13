<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Story;
use App\Services\StoryAssetPackGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generate the one-time visual asset pack for a catalog story (E3b-A):
 * tagged ink background plates + transparent full-figure hero poses,
 * human-reviewed afterwards in /admin/stories.
 *
 *   php artisan story:pack horatius-at-the-bridge
 *   php artisan story:pack 3 --backgrounds=8 --force
 */
class GenerateStoryAssetPack extends Command
{
    /** Rough one-time cost per gpt-image-1 still at pack sizes (USD). */
    private const ESTIMATED_COST_PER_IMAGE = 0.10;

    protected $signature = 'story:pack
        {story         : Story id or slug}
        {--backgrounds=6 : Number of background plates (clamped 4-8)}
        {--force       : Regenerate even if the story already has an asset pack}';

    protected $description = 'Generate a story\'s one-time asset pack: ink backgrounds + transparent hero poses.';

    public function handle(StoryAssetPackGenerator $generator): int
    {
        $ref = (string) $this->argument('story');
        $story = ctype_digit($ref) ? Story::find((int) $ref) : Story::where('slug', $ref)->first();

        if (! $story) {
            $this->error("Story '{$ref}' not found.");

            return self::FAILURE;
        }

        if (! empty($story->assets) && ! $this->option('force')) {
            $this->warn("Story '{$story->slug}' already has an asset pack — use --force to regenerate.");

            return self::FAILURE;
        }

        try {
            $assets = $generator->generate($story, (int) $this->option('backgrounds'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $story->update(['assets' => $assets]);
        $this->pruneStaleBatches($story, $assets);

        $rows = [];
        foreach ($assets['backgrounds'] as $background) {
            $rows[] = ['background', $background['tag'], $background['lighting'], $background['image_path']];
        }
        foreach ($assets['hero'] as $pose) {
            $rows[] = ['hero pose', $pose['pose'], '—', $pose['image_path']];
        }

        $this->info("Asset pack generated for '{$story->slug}' (style: {$assets['style']}).");
        $this->table(['Type', 'Key', 'Lighting', 'Path'], $rows);

        $imageCount = count($rows);
        $estimate = number_format($imageCount * self::ESTIMATED_COST_PER_IMAGE, 2);
        $this->line("~\${$estimate} one-time ({$imageCount} gpt-image-1 stills × ~\$".number_format(self::ESTIMATED_COST_PER_IMAGE, 2).') — $0 marginal per lesson.');
        $this->line('Review it in /admin/stories before publishing.');

        return self::SUCCESS;
    }

    /** Delete previous batch directories no longer referenced by the persisted pack. */
    private function pruneStaleBatches(Story $story, array $assets): void
    {
        $liveDirs = collect($assets['backgrounds'])->pluck('image_path')
            ->merge(collect($assets['hero'])->pluck('image_path'))
            ->map(fn (string $path) => Str::beforeLast($path, '/'))
            ->unique();

        $disk = Storage::disk('public');
        foreach ($disk->directories("stories/{$story->id}/assets") as $dir) {
            if (! $liveDirs->contains($dir)) {
                $disk->deleteDirectory($dir);
            }
        }
    }
}

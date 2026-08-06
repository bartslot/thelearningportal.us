<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Narrator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The narrators we actually ship, as opposed to the demo cast DemoNarratorSeeder invents.
 *
 * DemoNarratorSeeder generates a roster with made-up names and de-duplicates slugs in a loop, so
 * running it twice produces "ron-slot-2". That is fine for filling a picker on a fresh database and
 * useless for a real person who has to exist, once, with the same slug, on every environment.
 *
 * Keyed on slug and idempotent, so this is safe to run on production on every deploy. It does NOT
 * touch a narrator's media: the portrait and introduction video are files, and files do not travel
 * in a database row. Deploy copies them (scripts/deploy-prod.sh excludes storage/, so they go with
 * scripts/sync-narrator-media.sh) and this seeder points at them, warning if they are not there yet
 * rather than silently creating a narrator with a broken portrait.
 */
class NarratorSeeder extends Seeder
{
    /**
     * @var list<array<string,mixed>>
     */
    private const NARRATORS = [
        [
            'slug' => 'ron-slot',
            'name' => 'Ron Slot',
            'short_name' => 'Ron',
            'avatar_title' => 'verteller',
            'description' => 'Nederlandse verteller voor de geschiedenislessen van The Learning Portal.',
            'subject' => 'history',
            // Ron's own cloned voice. Narration falls back to a stock Dutch voice without it, which
            // is the failure that sounds fine and is wrong.
            'voice_provider' => 'elevenlabs',
            'voice_id' => 'Gwv6uO8RNVuOAN68JgUb',
            'voice_speed' => 0.95,
            'voice_pitch' => 1.0,
            'presentation_mode' => 'framed',
            'is_active' => true,
            'portrait_path' => 'avatars/portraits/ron-slot.jpg',
            'intro_video_path' => 'avatar-introductions/ron-slot/introduction.mp4',
        ],
    ];

    public function run(): void
    {
        foreach (self::NARRATORS as $narrator) {
            $existing = Narrator::withTrashed()->firstWhere('slug', $narrator['slug']);

            if ($existing?->trashed()) {
                $existing->restore();
            }

            $existing = Narrator::updateOrCreate(
                ['slug' => $narrator['slug']],
                $narrator + ['sort_order' => $existing?->sort_order ?? ((int) Narrator::max('sort_order') + 1)],
            );

            foreach (['portrait_path', 'intro_video_path'] as $field) {
                $path = $narrator[$field];
                if ($path && ! Storage::disk('public')->exists($path)) {
                    $this->command?->warn("{$existing->name}: {$field} points at {$path}, which is not on this disk yet — run scripts/sync-narrator-media.sh.");
                }
            }

            $this->command?->info("{$existing->name} ({$existing->slug}) — {$existing->voice_provider} {$existing->voice_id}");
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

class SlideshowAndIntelDropBackfill
{
    /**
     * Idempotent backfill: convert legacy slideshow_images + intel_drop_* into Scene rows.
     */
    public function backfill(): void
    {
        DB::transaction(function (): void {
            $lessons = DB::table('lessons')
                ->whereNotNull('slideshow_images')
                ->orWhere('intel_drop_enabled', 1)
                ->get();

            foreach ($lessons as $lesson) {
                $alreadyHasScenes = DB::table('scenes')
                    ->where('lesson_id', $lesson->id)
                    ->exists();
                if ($alreadyHasScenes) {
                    continue;
                }

                $order = 1;

                $slideshow = json_decode((string) $lesson->slideshow_images, true) ?: [];
                foreach ($slideshow as $entry) {
                    DB::table('scenes')->insert([
                        'lesson_id'   => $lesson->id,
                        'order'       => $order++,
                        'kind'        => 'narration',
                        'image_path'  => $entry['url'] ?? null,
                        'image_style' => 'realistic',
                        'status'      => 'ready',
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }

                if (! empty($lesson->intel_drop_enabled)) {
                    DB::table('lessons')
                        ->where('id', $lesson->id)
                        ->update(['game_split_count' => 2]);

                    DB::table('scenes')->insert([
                        'lesson_id'      => $lesson->id,
                        'order'          => $order++,
                        'kind'           => 'narration',
                        'script_segment' => $lesson->intel_drop_script ?? '',
                        'status'         => 'ready',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }
            }
        });
    }
}

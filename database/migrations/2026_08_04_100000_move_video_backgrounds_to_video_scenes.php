<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A film is no longer a background you hang behind a narrated scene — it is its own scene kind,
 * with its own tile in Add a scene and its own inspector. Scenes that were authored the old way
 * carry their video in config.bg_embed, so they become video scenes here rather than being left
 * as narration scenes whose stage nothing in the editor knew how to draw.
 *
 * 3D (Sketchfab) embeds stay backgrounds — they sit BEHIND a narrated scene, which is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->embedScenes() as $scene) {
            $config = json_decode((string) $scene->config, true) ?: [];
            if (($config['bg_embed']['kind'] ?? null) !== 'video') {
                continue;
            }
            DB::table('scenes')->where('id', $scene->id)->update([
                'kind' => 'video',
                'scene_view' => 'embed',
                // Nothing is generated for a video scene, so it must not sit 'pending' forever:
                // the publish gate demands every scene be ready and there is no button to press.
                'status' => 'ready',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('scenes')->where('kind', 'video')->update(['kind' => 'narration']);
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function embedScenes()
    {
        return DB::table('scenes')
            ->select('id', 'config')
            ->whereNotNull('config')
            ->get();
    }
};

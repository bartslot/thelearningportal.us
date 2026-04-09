<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AnimationClip;
use Illuminate\Database\Seeder;

class AnimationClipSeeder extends Seeder
{
    public function run(): void
    {
        $base = 'avatars/animations/walking_talking';

        $clips = [];

        // 138_01–06: Marching
        foreach (range(1, 6) as $n) {
            $id      = '138_' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $clips[] = ['clip_id' => $id, 'name' => 'Marching', 'category' => 'idle', 'fbx_path' => "{$base}/{$id}.fbx"];
        }

        // 138_07: Casual Walk
        $clips[] = ['clip_id' => '138_07', 'name' => 'Casual Walk', 'category' => 'walk', 'fbx_path' => "{$base}/138_07.fbx"];

        // 138_08–10: Marching
        foreach (range(8, 10) as $n) {
            $id      = '138_' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $clips[] = ['clip_id' => $id, 'name' => 'Marching', 'category' => 'idle', 'fbx_path' => "{$base}/{$id}.fbx"];
        }

        // 138_11–55: Story / Walk & Talk
        foreach (range(11, 55) as $n) {
            $id      = '138_' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $clips[] = ['clip_id' => $id, 'name' => 'Story', 'category' => 'gesture', 'fbx_path' => "{$base}/{$id}.fbx"];
        }

        foreach ($clips as $clip) {
            AnimationClip::updateOrCreate(
                ['clip_id' => $clip['clip_id']],
                array_merge($clip, [
                    'source'   => 'cmu_138',
                    'glb_path' => null,
                    'status'   => 'pending',
                ])
            );
        }
    }
}

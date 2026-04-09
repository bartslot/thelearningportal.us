<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AnimationClipFactory extends Factory
{
    public function definition(): array
    {
        static $counter = 10;
        $counter++;
        $id = '138_' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);

        return [
            'clip_id'          => $id,
            'name'             => 'Story',
            'category'         => 'gesture',
            'source'           => 'cmu_138',
            'fbx_path'         => "avatars/animations/walking_talking/{$id}.fbx",
            'glb_path'         => null,
            'status'           => 'pending',
            'conversion_error' => null,
        ];
    }
}

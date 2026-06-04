<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $clips = [
            [
                'name' => 'M_Walk_Strafe_Right_002',
                'category' => 'introduction',
                'gender' => 'masculine',
                'fbx_path' => 'avatars/animation-library/masculine/fbx/locomotion/M_Walk_Strafe_Right_002.fbx',
                'glb_path' => 'avatars/animation-library/masculine/glb/locomotion/M_Walk_Strafe_Right_002.glb',
                'sort_order' => 0,
                'speed' => 1.0,
                'expressiveness' => 1.0,
            ],
            [
                'name' => 'Stop Walking',
                'category' => 'introduction',
                'gender' => 'masculine',
                'fbx_path' => 'avatars/animation-library/walking/Stop Walking.fbx',
                'glb_path' => null,
                'sort_order' => 1,
                'speed' => 1.0,
                'expressiveness' => 1.0,
            ],
        ];

        foreach ($clips as $clip) {
            $exists = DB::table('animation_clips')
                ->where('name', $clip['name'])
                ->where('category', 'introduction')
                ->exists();

            if (! $exists) {
                DB::table('animation_clips')->insert(array_merge($clip, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        DB::table('animation_clips')
            ->where('category', 'introduction')
            ->whereIn('name', ['M_Walk_Strafe_Right_002', 'Stop Walking'])
            ->delete();
    }
};

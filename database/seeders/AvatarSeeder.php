<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Avatar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AvatarSeeder extends Seeder
{
    public function run(): void
    {
        // Hard-delete all existing avatars (including soft-deleted)
        DB::statement('DELETE FROM avatars');
        DB::statement('DELETE FROM sqlite_sequence WHERE name = "avatars"');

        $avatarDir = public_path('avatars');
        $inserted  = 0;

        // Scan numbered subfolders 1–99 for avatarinfo.json
        for ($i = 1; $i <= 99; $i++) {
            $jsonPath = "{$avatarDir}/{$i}/avatarinfo.json";

            if (! file_exists($jsonPath)) {
                continue;
            }

            $info = json_decode(file_get_contents($jsonPath), true);

            if (! $info) {
                continue;
            }

            $name = $info['name'] ?? "Avatar {$i}";
            $slug = Str::slug($name) ?: "avatar-{$i}";

            // Ensure slug uniqueness
            $baseSlug = $slug;
            $suffix   = 1;
            while (Avatar::withTrashed()->where('slug', $slug)->exists()) {
                $slug = "{$baseSlug}-{$suffix}";
                $suffix++;
            }

            Avatar::create([
                'id'               => $i,
                'name'             => $name,
                'slug'             => $slug,
                'short_name'       => $name,
                'description'      => null,
                'portrait_path'    => "avatars/{$i}/thumbnail.webp",
                'subject'          => 'all',
                'gender'           => $info['gender'] ?? 'male',
                'age'              => $info['age']    ?? 30,
                'skin_tone'        => $info['skinTone']  ?? null,
                'body_type'        => $info['bodyType']  ?? null,
                'source_id'        => $info['sourceId']  ?? null,
                'voice_provider'   => 'elevenlabs',
                'voice_id'         => '',
                'voice_speed'      => 1.0,
                'voice_pitch'      => 1.0,
                'voice_settings'   => '[]',
                'is_active'        => true,
                'sort_order'       => $i,
                'sprite_status'    => 'pending',
                'presentation_mode'=> 'framed',
                'emotion_style'    => 'auto',
                'expressiveness'   => 1.2,
                'speaking_speed'   => 1.0,
                'frame_background' => '#0f172a',
            ]);

            $inserted++;
        }

        $this->command?->info("Seeded {$inserted} rpme avatars.");
    }
}

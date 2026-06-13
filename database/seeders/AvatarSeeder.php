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
        // Reset the auto-increment counter — sqlite_sequence is SQLite-only and errors on Postgres.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DELETE FROM sqlite_sequence WHERE name = "avatars"');
        }

        $avatarDir = public_path('avatars');
        $inserted = 0;

        // Only these avatars are offered for lesson creation; the rest are seeded but inactive.
        $activeIds = [1, 4]; // Napoleon, Joan of Arc

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
            $suffix = 1;
            while (Avatar::withTrashed()->where('slug', $slug)->exists()) {
                $slug = "{$baseSlug}-{$suffix}";
                $suffix++;
            }

            Avatar::create([
                'id' => $i,
                'name' => $name,
                'slug' => $slug,
                'short_name' => $name,
                'description' => null,
                'portrait_path' => "avatars/{$i}/thumbnail.webp",
                'subject' => 'all',
                'gender' => $info['gender'] ?? 'male',
                'age' => $info['age'] ?? 30,
                'skin_tone' => $info['skinTone'] ?? null,
                'body_type' => $info['bodyType'] ?? null,
                'source_id' => $info['sourceId'] ?? null,
                'voice_provider' => 'elevenlabs',
                'voice_id' => '',
                'voice_speed' => 1.0,
                'voice_pitch' => 1.0,
                'voice_settings' => '[]',
                'is_active' => in_array($i, $activeIds, true),
                'sort_order' => $i,
                'sprite_status' => 'pending',
                'presentation_mode' => 'framed',
                'emotion_style' => 'auto',
                'expressiveness' => 1.2,
                'speaking_speed' => 1.0,
                'frame_background' => '#0f172a',
            ]);

            $inserted++;
        }

        // Avatars were inserted with explicit ids; advance the Postgres sequence so future
        // auto-increment inserts (admin Avatar Lab) don't collide.
        if (DB::getDriverName() === 'pgsql' && $inserted > 0) {
            DB::statement("SELECT setval(pg_get_serial_sequence('avatars', 'id'), (SELECT MAX(id) FROM avatars))");
        }

        $this->command?->info("Seeded {$inserted} avatars.");
    }
}

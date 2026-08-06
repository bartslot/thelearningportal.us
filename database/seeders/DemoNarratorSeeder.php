<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Narrator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The bundled demo cast, built by scanning public/avatars/{1..99}/avatarinfo.json.
 *
 * This is what fills the narrator picker on a fresh development database. It is NOT the seeder for
 * the narrators we actually ship: that is NarratorSeeder, which is keyed on slug and idempotent.
 *
 * Never run this against production. The first thing it does is DELETE FROM avatars, which takes
 * the real narrators with it, soft-deleted rows included.
 */
class DemoNarratorSeeder extends Seeder
{
    public function run(): void
    {
        // Hard-delete every existing narrator (including soft-deleted)
        DB::statement('DELETE FROM avatars');
        // Reset the auto-increment counter — sqlite_sequence is SQLite-only and errors on Postgres.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DELETE FROM sqlite_sequence WHERE name = "avatars"');
        }

        $avatarDir = public_path('avatars');
        $inserted = 0;

        // Only these narrators are offered for lesson creation; the rest are seeded but inactive.
        $activeIds = [31, 1, 4]; // Julian (default), Napoleon, Joan of Arc

        // Julian is the default narrator: force the lowest sort_order so he sorts first
        // everywhere active narrators are ordered (wizard pre-selection, picker, narrator card).
        $sortOverrides = [31 => 0];

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

            $name = $info['name'] ?? "Narrator {$i}";
            $slug = Str::slug($name) ?: "avatar-{$i}";

            // Ensure slug uniqueness
            $baseSlug = $slug;
            $suffix = 1;
            while (Narrator::withTrashed()->where('slug', $slug)->exists()) {
                $slug = "{$baseSlug}-{$suffix}";
                $suffix++;
            }

            Narrator::create([
                'id' => $i,
                'name' => $name,
                'slug' => $slug,
                'short_name' => $name,
                'description' => null,
                'portrait_path' => "avatars/{$i}/thumbnail.webp",
                'intro_video_path' => file_exists("{$avatarDir}/{$i}/welcome.mp4")
                    ? "avatars/{$i}/welcome.mp4"
                    : null,
                'subject' => 'all',
                'gender' => $info['gender'] ?? 'male',
                'age' => $info['age'] ?? 30,
                'skin_tone' => $info['skinTone'] ?? null,
                'body_type' => $info['bodyType'] ?? null,
                'source_id' => $info['sourceId'] ?? null,
                'voice_provider' => 'elevenlabs',
                'voice_id' => $info['voiceId'] ?? '',
                'voice_speed' => 1.0,
                'voice_pitch' => 1.0,
                'voice_settings' => '[]',
                'is_active' => in_array($i, $activeIds, true),
                'sort_order' => $sortOverrides[$i] ?? $i,
                'presentation_mode' => 'framed',
                'emotion_style' => 'auto',
                'expressiveness' => 1.2,
                'speaking_speed' => 1.0,
                'frame_background' => '#0f172a',
            ]);

            $inserted++;
        }

        // Narrators were inserted with explicit ids; advance the Postgres sequence so future
        // auto-increment inserts (admin Narrator Lab) don't collide.
        if (DB::getDriverName() === 'pgsql' && $inserted > 0) {
            DB::statement("SELECT setval(pg_get_serial_sequence('avatars', 'id'), (SELECT MAX(id) FROM avatars))");
        }

        $this->command?->info("Seeded {$inserted} narrators.");
    }
}

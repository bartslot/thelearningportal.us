<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Avatar;
use Illuminate\Database\Seeder;

class AvatarSeeder extends Seeder
{
    public function run(): void
    {
        // ── The Professor ─────────────────────────────────────────────────────
        // A distinguished Spanish professor who lectures in English.
        // Portrait: public/assets/professor.webp (already in the project).
        // Default voice: bm_george (British male, distinguished) — use the
        // Avatar Studio admin panel to audition voices and switch.

        Avatar::updateOrCreate(
            ['slug' => 'professor'],
            [
                'name'           => 'The Professor',
                'short_name'     => 'The Professor',     // used in greeting: "I am The Professor"
                'avatar_title'   => 'History Professor', // used in greeting: "a History Professor here at..."
                'description'    => 'A distinguished Spanish professor who brings history to life in English. Warm, authoritative, and deeply passionate about his subject.',
                'portrait_path'  => 'assets/professor.webp',
                'subject'        => 'all',

                // ── Voice settings ────────────────────────────────────────────
                // Primary: edge-tts es-ES-AlvaroNeural = Spanish male voice
                // that speaks English with a natural Spanish accent (FREE).
                // Switch to kokoro/bm_george for fully offline operation.
                'voice_provider' => 'edge_tts',
                'voice_id'       => 'es-ES-AlvaroNeural',
                'voice_speed'    => 0.92,
                'voice_pitch'    => 1.0,
                'voice_settings' => [],

                'is_active'      => true,
                'sort_order'     => 0,
            ]
        );
    }
}

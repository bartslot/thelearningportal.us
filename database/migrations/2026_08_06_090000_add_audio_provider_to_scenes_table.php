<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHICH voice actually spoke a scene, not just which one was asked for.
 *
 * TtsService falls through a chain of providers, and every rung fails silently: a lesson could
 * name a cloned ElevenLabs narrator and be read out by the macOS `say` robot with nothing, in
 * the database or on screen, saying so. That is how a whole Dutch lesson shipped as "narrated
 * in Ron Slot's own voice" while actually being machine-read.
 *
 * Sibling of audio_locale, which records the same kind of fact about language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            // 'elevenlabs' | 'azure' | 'piper' | 'pocket_tts' | 'edge_tts' | 'macos_say' | 'openai'
            $table->string('audio_provider', 32)->nullable()->after('audio_locale');
            // The provider's own voice id, so "which Ron?" is answerable without guessing.
            $table->string('audio_voice', 128)->nullable()->after('audio_provider');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn(['audio_provider', 'audio_voice']);
        });
    }
};

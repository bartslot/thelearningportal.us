<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatars', function (Blueprint $table): void {
            $table->id();

            // Identity
            $table->string('name');                          // "Professor Alejandro"
            $table->string('slug')->unique();                // "professor-alejandro"
            $table->text('description')->nullable();         // shown to teacher when picking
            $table->string('portrait_path')->nullable();     // public/assets/professor.webp or storage path
            $table->string('subject')->default('all');       // history | science | all (for filtering)

            // Voice provider
            $table->string('voice_provider')->default('kokoro'); // kokoro | openai | elevenlabs
            $table->string('voice_id')->default('bm_george');    // kokoro speaker id or EL voice id
            $table->float('voice_speed')->default(0.95);         // 0.5 – 2.0
            $table->float('voice_pitch')->default(1.0);          // provider-dependent
            $table->json('voice_settings')->nullable();          // extra provider-specific settings

            // Status
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('avatar_voice_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('avatar_id')->constrained()->cascadeOnDelete();

            $table->string('phrase');                        // the sample text that was spoken
            $table->string('voice_id');                      // the voice used for this sample
            $table->float('voice_speed')->default(0.95);
            $table->string('audio_path')->nullable();        // storage path to the MP3
            $table->string('audio_extension')->default('mp3');
            $table->json('settings_snapshot')->nullable();   // full settings at generation time

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_voice_samples');
        Schema::dropIfExists('avatars');
    }
};

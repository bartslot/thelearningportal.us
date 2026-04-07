<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();

            // Content
            $table->string('title');
            $table->string('topic');                          // e.g. "Julius Caesar"
            $table->string('subject')->default('history');    // history, science, etc.
            $table->string('grade_level');                    // e.g. "9th grade"
            $table->enum('tone', ['serious', 'dramatic', 'playful', 'inspiring'])->default('dramatic');
            $table->string('historical_figure')->nullable();  // e.g. "Julius Caesar"
            $table->text('wikipedia_source')->nullable();     // raw Wikipedia extract used
            $table->longText('script')->nullable();           // AI-generated narration script

            // Generation status
            $table->enum('status', ['pending', 'generating', 'ready', 'failed', 'published'])->default('pending');
            $table->string('error_message')->nullable();
            $table->unsignedTinyInteger('generation_attempts')->default(0);

            // Media paths (relative to storage disk)
            $table->string('portrait_path')->nullable();      // source image for avatar
            $table->string('audio_path')->nullable();         // TTS narration
            $table->string('video_path')->nullable();         // final avatar video

            // Duration in seconds (set after video generation)
            $table->unsignedSmallInteger('duration_seconds')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'status']);
            $table->index(['subject', 'grade_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};

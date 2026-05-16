<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->enum('kind', ['narration', 'game'])->default('narration');

            $table->string('year')->nullable();
            $table->string('location')->nullable();
            $table->text('script_segment')->nullable();

            $table->text('image_prompt')->nullable();
            $table->string('image_path')->nullable();
            $table->enum('image_style', [
                'realistic', 'sketched', 'painted', 'cinematic', 'comic', 'animation',
            ])->nullable();

            $table->foreignId('animation_clip_id')
                ->nullable()
                ->constrained('animation_clips')
                ->nullOnDelete();

            $table->string('audio_path')->nullable();
            $table->json('audio_alignment')->nullable();
            $table->string('audio_script_hash', 64)->nullable();

            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedTinyInteger('game_segment_index')->nullable();

            $table->enum('status', ['pending', 'generating', 'ready', 'failed'])
                ->default('pending');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['lesson_id', 'order']);
            $table->index(['lesson_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();

            // Video progress
            $table->unsignedSmallInteger('watch_seconds')->default(0);
            $table->boolean('video_completed')->default(false);

            // Quiz results
            $table->json('answers')->nullable();          // {question_id: chosen_index, ...}
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('max_score')->default(0);
            $table->timestamp('quiz_completed_at')->nullable();

            // Overall
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->unique(['student_id', 'lesson_id']);
            $table->index(['student_id', 'completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_progress');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_score_id')->constrained()->cascadeOnDelete();
            // Best-effort link only: questions are deleted/recreated on regeneration,
            // so every reporting read uses the SNAPSHOT columns below, never a join.
            $table->foreignId('quiz_question_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('question_order');
            $table->text('question_text');
            $table->text('chosen_text');
            $table->text('correct_text');
            $table->boolean('was_correct');
            $table->unsignedInteger('response_ms')->nullable();   // null for paper imports
            $table->boolean('asks_ahead')->default(false);
            $table->timestamps();

            $table->index('quiz_score_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_answers');
    }
};

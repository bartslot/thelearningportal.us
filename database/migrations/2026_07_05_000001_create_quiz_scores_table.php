<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            // Kahoot-style: the public player has no student accounts — players join the
            // leaderboard under a nickname entered on the score screen.
            $table->string('nickname', 24);
            $table->unsignedInteger('score');
            $table->unsignedTinyInteger('correct');
            $table->unsignedTinyInteger('total');
            $table->timestamps();

            $table->index(['lesson_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_scores');
    }
};

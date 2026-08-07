<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which quiz scene a score came from.
 *
 * A lesson can hold several quiz scenes — the Canon lessons open with "Before we start" and close
 * with "What did you learn?" — and each one submitted its own score under the same nickname. Without
 * knowing which scene a row belongs to there is no way to tell "this child answered the second
 * quiz" from "this child replayed the first one", so a leaderboard either double-counts a replay or
 * refuses a legitimate second quiz.
 *
 * Nullable on purpose: scores recorded before this column existed have no scene, and a lesson whose
 * quiz is not scene-scoped still submits without one. Those keep the old one-row-per-player
 * behaviour rather than being guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_scores', function (Blueprint $table) {
            $table->foreignId('quiz_scene_id')->nullable()->after('lesson_id')
                ->constrained('scenes')->nullOnDelete();

            // The board groups by player within a lesson, and the writer looks a player's row up by
            // exactly this shape.
            $table->index(['lesson_id', 'quiz_scene_id']);
        });
    }

    public function down(): void
    {
        Schema::table('quiz_scores', function (Blueprint $table) {
            $table->dropIndex(['lesson_id', 'quiz_scene_id']);
            $table->dropConstrainedForeignId('quiz_scene_id');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a scene in the rail is now undoable, which means the row has to survive the delete.
 *
 * A hard delete also took the scene's quiz questions with it in spirit: quiz_questions.scene_id is
 * nullOnDelete, so its questions silently fell back into the lesson-wide pool with no way to tell
 * which scene they came from. Soft-deleting keeps that link intact, so a restore is exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};

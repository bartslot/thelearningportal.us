<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            // The quiz segment this question belongs to. Lessons run chronologically, so a
            // mid-lesson quiz may only test scenes the student has already seen — each quiz
            // scene gets its own question set. Null = legacy lesson-level pool.
            $table->foreignId('scene_id')->nullable()->after('lesson_id')
                ->constrained('scenes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scene_id');
        });
    }
};

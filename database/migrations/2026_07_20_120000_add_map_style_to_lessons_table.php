<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lesson-wide default map palette for every map block in the lesson (soft-atlas / antique /
 * pen-ink / night). A map block may still override it via scene config.map_style; null here
 * means the app default (soft-atlas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('map_style', 20)->nullable()->after('quiz_timing');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('map_style');
        });
    }
};

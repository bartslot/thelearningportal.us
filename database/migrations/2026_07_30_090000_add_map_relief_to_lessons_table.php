<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lesson-wide 3D terrain relief for every map in the lesson: the height map is draped over the
 * ground so mountains and valleys stand up, and the voyage rides over them.
 *
 * One number rather than a flag plus a strength: 0 = flat (the default, and what every existing
 * lesson keeps), 1 = true-to-life height, higher = dramatised. Ranges (a lesson map is wide, so
 * real elevation is barely visible at 1) are the teacher's to choose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->float('map_relief')->default(0)->after('map_style');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('map_relief');
        });
    }
};

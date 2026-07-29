<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this lesson starts with narration subtitles showing.
 *
 * A teacher's call rather than a student preference: a class with hard-of-hearing pupils, or one
 * following the lesson in a second language, wants them from the first scene rather than after
 * someone finds the button. Students can still toggle them during playback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->boolean('subtitles')->default(false)->after('background_music');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('subtitles');
        });
    }
};

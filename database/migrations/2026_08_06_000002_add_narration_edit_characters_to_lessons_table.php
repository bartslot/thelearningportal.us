<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many characters of narration a teacher has re-written on this lesson.
 *
 * Editing a script is the one action in the app that turns a keystroke into an ElevenLabs invoice,
 * so a lesson meters it (App\Support\NarrationBudget). Only the CHANGE counts, not the script's
 * length — the generated script is already paid for.
 *
 * Lives on the lesson rather than the teacher because the allowance is per lesson: 1000 characters
 * included, more when the teacher has bought credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedInteger('narration_edit_characters')->default(0)->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('narration_edit_characters');
        });
    }
};

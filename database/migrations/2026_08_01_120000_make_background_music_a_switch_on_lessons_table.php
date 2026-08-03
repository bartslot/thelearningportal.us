<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Background music becomes a plain on/off switch.
 *
 * The column used to hold one of six track ids ('default', 'track2', …), all of which pointed at
 * the same file that was never shipped — the picker chose between six copies of nothing. There is
 * one soundtrack now (config/audio.php), the player shuffles it, and the only decision left to the
 * teacher is whether a class hears it at all. Off unless they ask for it, because most lessons are
 * watched on a classroom speaker where a music bed under the narration is the last thing wanted.
 *
 * No data is lost: every row's value is null (the picker never worked, so nothing selected it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('background_music');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->boolean('background_music')->default(false)->after('wizard_step');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('background_music');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->string('background_music')->nullable()->after('wizard_step');
        });
    }
};

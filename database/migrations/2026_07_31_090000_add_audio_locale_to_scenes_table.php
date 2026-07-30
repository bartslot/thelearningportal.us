<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the language a scene's narration was actually generated in.
 *
 * Without this, the "is this narrated in the wrong language" sweep had to INFER the voice from the
 * teacher's profile, which flagged 128 correct scenes as broken because they were narrated with an
 * explicit voice override. Storing the fact makes the check exact instead of a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->string('audio_locale', 8)->nullable()->after('audio_script_hash');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('audio_locale');
        });
    }
};

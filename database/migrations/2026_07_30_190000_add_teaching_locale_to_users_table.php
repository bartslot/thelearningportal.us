<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split "what language does the app speak to me in" from "what language do my students learn in".
 *
 * users.locale answered both, so a Dutch teacher writing an English lesson had it narrated by a
 * Dutch voice. Nullable on purpose: null means "same as my interface language", which is true for
 * almost everyone, so nobody has to set anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('teaching_locale', 8)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('teaching_locale');
        });
    }
};

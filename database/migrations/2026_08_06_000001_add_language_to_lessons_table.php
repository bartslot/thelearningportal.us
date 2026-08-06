<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What language a lesson is written and narrated in.
 *
 * Until now, nothing recorded this. The app has had five UI languages for a while, but a lesson was
 * just a row whose scripts happened to be in some language, and no code could tell which. That is
 * how a Dutch teacher on the landing page was handed "The Silk Road" in English: the demo picker
 * had a country map, ran out of matches, and fell through to "the newest playable lesson" — which
 * cannot be wrong about language when language is not a thing it knows about.
 *
 * Defaults to 'en' because every lesson written before this migration is English except the Dutch
 * and French ones, which the accompanying seeder/spec pass sets explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('language', 5)->default('en')->after('subject');
            $table->index('language');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['language']);
            $table->dropColumn('language');
        });
    }
};

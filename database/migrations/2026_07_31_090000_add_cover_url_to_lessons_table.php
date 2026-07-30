<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a lesson's card thumbnail is hosted.
 *
 * app:generate-lesson-covers renders a small WebP poster and, when Cloudinary is configured,
 * uploads it to the CDN. The delivery URL is stored here so cards link straight to the CDN
 * instead of streaming a multi-megabyte scene background off our own box. Null means the
 * upload never happened (no CDN configured, or it failed), and the local cover is served.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('cover_url')->nullable()->after('poster_image');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('cover_url');
        });
    }
};

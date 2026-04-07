<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->json('slideshow_images')
                  ->nullable()
                  ->after('video_path')
                  ->comment('Array of {url,thumb,title,attribution,source,link} objects from Europeana/Wikimedia');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('slideshow_images');
        });
    }
};

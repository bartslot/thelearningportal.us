<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // Teacher-chosen poster/cover image (full URL or storage path). When null the lesson
            // auto-picks a representative image already loaded in it — see Lesson::posterUrl().
            $table->string('poster_image')->nullable()->after('title_bg_path');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('poster_image');
        });
    }
};

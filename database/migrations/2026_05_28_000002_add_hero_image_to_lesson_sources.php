<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->string('hero_image_path')->nullable()->after('source_url');
            $table->string('hero_image_url')->nullable()->after('hero_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sources', function (Blueprint $table) {
            $table->dropColumn(['hero_image_path', 'hero_image_url']);
        });
    }
};

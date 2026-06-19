<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the 2D talking-portrait sprite/lip-sync pipeline. The avatar's display image
 * (portrait_path) stays — only the sprite/landmark fields go. Reverses
 * 2026_04_07_000001_add_sprite_fields_to_avatars_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropColumn(['portrait_original_path', 'landmarks_json', 'sprite_status']);
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->string('portrait_original_path')->nullable()->after('portrait_path');
            $table->json('landmarks_json')->nullable()->after('portrait_original_path');
            $table->string('sprite_status')->default('pending')->after('landmarks_json');
        });
    }
};

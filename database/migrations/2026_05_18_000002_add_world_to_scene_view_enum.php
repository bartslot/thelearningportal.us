<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite cannot ALTER a CHECK constraint, so we copy values, drop, and re-add.
        DB::statement('ALTER TABLE scenes RENAME COLUMN scene_view TO scene_view_old');

        Schema::table('scenes', function (Blueprint $table) {
            $table->enum('scene_view', ['skybox', 'slideshow', 'world'])
                ->default('skybox')
                ->after('scene_view_old');
        });

        DB::statement("UPDATE scenes SET scene_view = scene_view_old WHERE scene_view_old IN ('skybox','slideshow','world')");
        DB::statement("UPDATE scenes SET scene_view = 'skybox' WHERE scene_view_old NOT IN ('skybox','slideshow','world')");
        DB::statement('ALTER TABLE scenes DROP COLUMN scene_view_old');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE scenes RENAME COLUMN scene_view TO scene_view_old');

        Schema::table('scenes', function (Blueprint $table) {
            $table->enum('scene_view', ['skybox', 'slideshow'])
                ->default('skybox')
                ->after('scene_view_old');
        });

        DB::statement("UPDATE scenes SET scene_view = CASE WHEN scene_view_old IN ('skybox','slideshow') THEN scene_view_old ELSE 'skybox' END");
        DB::statement('ALTER TABLE scenes DROP COLUMN scene_view_old');
    }
};

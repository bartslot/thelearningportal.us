<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The default scene render is now the flat Ken Burns slide (2D), not the 3D skybox sphere.
    // Skybox becomes an opt-in per scene. New scenes default to 'slideshow'.
    public function up(): void
    {
        DB::statement("ALTER TABLE scenes ALTER COLUMN scene_view SET DEFAULT 'slideshow'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE scenes ALTER COLUMN scene_view SET DEFAULT 'skybox'");
    }
};

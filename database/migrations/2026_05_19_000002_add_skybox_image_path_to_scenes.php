<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            // Separate path for the equirectangular panorama (used by Skybox view).
            // image_path remains the flat 2D image used by Slideshow view.
            $table->string('skybox_image_path')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('skybox_image_path');
        });
    }
};

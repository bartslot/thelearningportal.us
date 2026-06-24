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
            // Holds the 4 candidate equirectangular panorama paths until the teacher picks one.
            // Once a candidate is chosen it is moved into skybox_image_path and this is cleared.
            $table->json('skybox_candidates')->nullable()->after('skybox_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('skybox_candidates');
        });
    }
};

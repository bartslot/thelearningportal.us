<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->enum('scene_view', ['skybox', 'slideshow'])
                ->default('skybox')
                ->after('background_color');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn('scene_view');
        });
    }
};

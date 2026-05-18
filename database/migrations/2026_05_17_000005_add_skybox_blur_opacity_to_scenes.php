<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->float('skybox_blur')->default(0.5)->after('image_style');
            $table->float('skybox_opacity')->default(1.0)->after('skybox_blur');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn(['skybox_blur', 'skybox_opacity']);
        });
    }
};

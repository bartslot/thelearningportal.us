<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->string('background_color', 7)->default('#000000')->after('skybox_opacity');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn('background_color');
        });
    }
};

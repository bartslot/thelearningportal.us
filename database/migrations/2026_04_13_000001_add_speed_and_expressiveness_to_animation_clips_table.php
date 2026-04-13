<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animation_clips', function (Blueprint $table) {
            $table->float('speed', 4, 2)->default(1.00)->after('sort_order');
            $table->float('expressiveness', 4, 2)->default(1.00)->after('speed');
        });
    }

    public function down(): void
    {
        Schema::table('animation_clips', function (Blueprint $table) {
            $table->dropColumn(['speed', 'expressiveness']);
        });
    }
};

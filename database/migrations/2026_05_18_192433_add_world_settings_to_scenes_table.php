<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->float('world_y_offset')->default(0)->after('world_semantics');
            $table->float('world_scale')->default(1)->after('world_y_offset');
            $table->float('world_char_scale')->default(0.53)->after('world_scale');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn(['world_y_offset', 'world_scale', 'world_char_scale']);
        });
    }
};

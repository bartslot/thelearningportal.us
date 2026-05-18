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
            $table->string('world_labs_status')->nullable()->after('status');
            $table->string('world_pano_path')->nullable()->after('world_labs_status');
            $table->string('world_spz_path')->nullable()->after('world_pano_path');
            $table->string('world_glb_path')->nullable()->after('world_spz_path');
            $table->string('world_labs_operation_id')->nullable()->after('world_glb_path');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn([
                'world_labs_status',
                'world_pano_path',
                'world_spz_path',
                'world_glb_path',
                'world_labs_operation_id',
            ]);
        });
    }
};

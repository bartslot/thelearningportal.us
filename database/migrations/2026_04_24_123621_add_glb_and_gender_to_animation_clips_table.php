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
            $table->string('slug')->nullable()->after('name');
            $table->string('category')->default('idle')->change(); // expand beyond old enum
            $table->string('gender')->default('masculine')->after('category');
            $table->string('glb_path')->nullable()->after('fbx_path');
            $table->string('thumbnail_path')->nullable()->after('glb_path');
        });
    }

    public function down(): void
    {
        Schema::table('animation_clips', function (Blueprint $table) {
            $table->dropColumn(['slug', 'gender', 'glb_path', 'thumbnail_path']);
        });
    }
};

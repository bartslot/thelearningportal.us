<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animation_clips', function (Blueprint $table) {
            $table->string('fbx_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('animation_clips', function (Blueprint $table) {
            $table->string('fbx_path')->nullable(false)->change();
        });
    }
};

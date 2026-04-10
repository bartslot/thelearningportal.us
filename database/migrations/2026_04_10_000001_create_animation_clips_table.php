<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animation_clips', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('category', ['idle', 'presenting', 'greeting']);
            $table->string('fbx_path');
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animation_clips');
    }
};

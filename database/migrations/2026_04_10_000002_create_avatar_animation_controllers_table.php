<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatar_animation_controllers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avatar_id')->constrained()->cascadeOnDelete();
            $table->json('controller');
            $table->timestamps();
            $table->unique('avatar_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_animation_controllers');
    }
};

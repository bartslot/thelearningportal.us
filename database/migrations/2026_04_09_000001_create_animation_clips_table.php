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
            $table->string('clip_id')->unique();          // "138_14"
            $table->string('name');                        // "Story"
            $table->string('category');                    // walk|idle|gesture
            $table->string('source')->default('cmu_138'); // source library identifier
            $table->string('fbx_path');                    // avatars/animations/walking_talking/138_14.fbx
            $table->string('glb_path')->nullable();        // avatars/animations/walking_talking/138_14.glb
            $table->enum('status', ['pending', 'converting', 'ready', 'failed'])->default('pending');
            $table->text('conversion_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animation_clips');
    }
};

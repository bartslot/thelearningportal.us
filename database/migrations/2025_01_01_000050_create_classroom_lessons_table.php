<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Assign lessons to classrooms
        Schema::create('classroom_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('due_at')->nullable();
            $table->unique(['classroom_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_lessons');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('amber');
            $table->timestamps();
        });

        Schema::create('lesson_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('lesson_teams')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('student_name')->nullable(); // for anonymous / walk-in students
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_team_members');
        Schema::dropIfExists('lesson_teams');
    }
};

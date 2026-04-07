<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('join_code', 8)->unique();
            $table->string('grade_level')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('classroom_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();
            $table->unique(['classroom_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_student');
        Schema::dropIfExists('classrooms');
    }
};

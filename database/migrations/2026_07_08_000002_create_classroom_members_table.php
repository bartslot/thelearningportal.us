<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_members', function (Blueprint $table): void {
            // Account-less roster entry ("Emma V." — first name + last initial).
            // Deliberately NOT a users row: pilot schools do no account provisioning.
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 40);
            $table->timestamps();
        });
        // Uniqueness on the NORMALIZED name so "Emma V." and "emma v." collide.
        DB::statement('CREATE UNIQUE INDEX classroom_members_unique_name
            ON classroom_members (classroom_id, lower(display_name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_members');
    }
};

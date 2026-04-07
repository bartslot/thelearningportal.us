<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->foreignId('avatar_id')
                  ->nullable()
                  ->after('teacher_id')
                  ->constrained()
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropForeignIdFor(\App\Models\Avatar::class);
            $table->dropColumn('avatar_id');
        });
    }
};

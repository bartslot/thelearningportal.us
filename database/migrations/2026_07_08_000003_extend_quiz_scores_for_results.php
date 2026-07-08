<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_scores', function (Blueprint $table): void {
            $table->foreignId('classroom_member_id')->nullable()->after('nickname')
                ->constrained('classroom_members')->nullOnDelete();
            $table->string('source', 10)->default('web')->after('integrity');   // web | paper
        });
    }

    public function down(): void
    {
        Schema::table('quiz_scores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('classroom_member_id');
            $table->dropColumn('source');
        });
    }
};

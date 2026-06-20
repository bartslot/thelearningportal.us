<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            // Branching-story "lite": null = an ordinary linear scene. The two option scenes of one
            // reconverging choice share a branch_group and are tagged option_a / option_b.
            $table->unsignedInteger('branch_group')->nullable()->after('game_segment_index');
            $table->string('branch_role')->nullable()->after('branch_group');
            $table->string('branch_choice_label')->nullable()->after('branch_role');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table): void {
            $table->dropColumn(['branch_group', 'branch_role', 'branch_choice_label']);
        });
    }
};

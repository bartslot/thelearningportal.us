<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            // Short chapter name (Micrio-style serial tour), auto-derived from the narration
            // and editable per scene. Null until named; the model falls back to a label.
            $table->string('chapter_name')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('chapter_name');
        });
    }
};

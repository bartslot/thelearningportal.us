<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Throwaway accounts behind the landing-page "Configure" demo. A visitor with no account gets a
 * real teacher row and a private copy of the demo lesson, so the actual wizard works unmodified.
 * The flag is what keeps them fenced in (RestrictGuestDemo) and what demo:prune-guests sweeps up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest_demo')->default(false)->after('role');
            $table->index(['is_guest_demo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_guest_demo', 'created_at']);
            $table->dropColumn('is_guest_demo');
        });
    }
};

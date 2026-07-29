<?php

declare(strict_types=1);

use App\Enums\OnboardingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resumable onboarding: `onboarding_status` says whether the tour still opens by itself, and
     * `onboarding_step` is the slide the account last reached, so leaving halfway and coming back
     * picks up where it stopped instead of starting over.
     *
     * Accounts already stamped by the previous migration count as completed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('onboarding_status', 16)
                ->default(OnboardingStatus::Pending->value)
                ->after('onboarded_at');
            $table->unsignedSmallInteger('onboarding_step')->default(0)->after('onboarding_status');
        });

        DB::table('users')
            ->whereNotNull('onboarded_at')
            ->update(['onboarding_status' => OnboardingStatus::Completed->value]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['onboarding_status', 'onboarding_step']);
        });
    }
};

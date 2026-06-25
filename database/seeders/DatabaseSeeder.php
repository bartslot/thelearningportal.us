<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ── Admin / super admin ───────────────────────────────────────────────
        // firstOrCreate: safe to re-run, won't reset passwords on existing accounts.
        User::firstOrCreate(
            ['email' => 'bartslot@gmail.com'],
            [
                'name'               => 'Bart Slot',
                'role'               => 'admin',
                'password'           => bcrypt('password'),
                'email_verified_at'  => now(),
            ]
        );

        // ── Demo teacher ──────────────────────────────────────────────────────
        User::firstOrCreate(
            ['email' => 'teacher@example.com'],
            [
                'name'               => 'Test Teacher',
                'role'               => 'teacher',
                'password'           => bcrypt('password'),
                'email_verified_at'  => now(),
            ]
        );

        // ── Demo student ──────────────────────────────────────────────────────
        User::firstOrCreate(
            ['email' => 'student@example.com'],
            [
                'name'               => 'Test Student',
                'role'               => 'student',
                'password'           => bcrypt('password'),
                'email_verified_at'  => now(),
            ]
        );

        // ── Avatars ───────────────────────────────────────────────────────────
        $this->call(AvatarSeeder::class);

        // ── Strategy games ────────────────────────────────────────────────────
        $this->call(StrategyGameSeeder::class);

        // ── French Revolution demo lesson ─────────────────────────────────────
        $this->call(FrenchRevolutionDemoSeeder::class);

        // ── Curated historical city names ─────────────────────────────────────
        // Run after `php artisan app:import-cities` so most rows match an imported
        // city; any curated city NE lacks is created as a coordinate-less stub.
        $this->call(HistoricalCityNamesSeeder::class);
    }
}

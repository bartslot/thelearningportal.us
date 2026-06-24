<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Create or update a teacher account. Idempotent — safe to re-run. The password is passed as an
 * argument (never committed); the User model's `hashed` cast hashes it on save.
 *
 *   php artisan app:create-teacher hello@example.com 'the-password' --name="Bart Slot"
 */
class CreateTeacher extends Command
{
    protected $signature = 'app:create-teacher {email} {password} {--name=Teacher}';

    protected $description = 'Create or update a teacher account (idempotent)';

    public function handle(): int
    {
        $user = User::updateOrCreate(
            ['email' => mb_strtolower(trim((string) $this->argument('email')))],
            [
                'name'              => (string) $this->option('name'),
                'password'          => (string) $this->argument('password'),
                'role'              => 'teacher',
                'email_verified_at' => now(),
            ],
        );

        $this->info("✓ Teacher ready: {$user->email} (id {$user->id}, role {$user->role})");

        return self::SUCCESS;
    }
}

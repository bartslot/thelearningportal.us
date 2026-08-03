<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sweep up the throwaway accounts behind the landing-page Configure demo.
 *
 * Deleting the account takes its lesson copies with it (lessons.teacher_id cascades), and the
 * copies never owned their media, so nothing a real lesson depends on is touched.
 */
final class PruneGuestDemos extends Command
{
    protected $signature = 'demo:prune-guests {--hours= : Override the age cutoff}';

    protected $description = 'Delete landing-page guest demo accounts and their lesson copies';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('demo.guest_ttl_hours'));
        $cutoff = now()->subHours(max(1, $hours));

        $guests = User::query()
            ->where('is_guest_demo', true)
            ->where('created_at', '<', $cutoff)
            ->get();

        // forceDelete, not delete: users soft-delete, and a soft-deleted row keeps its lessons
        // (and the guest's e-mail) around for ever. There is nothing here worth keeping.
        $guests->each(fn (User $guest) => $guest->forceDelete());

        $this->info("Pruned {$guests->count()} guest demo account(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}

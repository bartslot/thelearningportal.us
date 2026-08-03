<?php

declare(strict_types=1);

namespace App\Livewire\Wizard\Concerns;

/**
 * The wizard is open to anonymous visitors through the landing-page demo
 * (App\Services\GuestDemoSession). Editing costs nothing, but every "generate" button behind it
 * spends real money at fal.ai, Azure or OpenAI — so those calls, and only those, refuse a guest.
 *
 * Guard the dispatch, not the button: the button is Livewire, and a Livewire method is reachable
 * by anyone who can open the component.
 */
trait BlocksGuestDemoSpending
{
    /** True when the caller is a demo guest, in which case a toast has already been sent. */
    protected function guestDemoCannotGenerate(): bool
    {
        if (! auth()->user()?->isGuestDemo()) {
            return false;
        }

        $this->dispatch(
            'toast',
            type: 'info',
            message: __('Generating new media needs an account. Everything else on this screen is yours to play with.'),
        );

        return true;
    }
}

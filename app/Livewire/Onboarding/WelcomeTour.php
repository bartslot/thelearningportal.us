<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\OnboardingStatus;
use App\Models\User;
use App\Support\HelpGuide;
use Livewire\Component;

/**
 * The welcome tour a new account walks through once: a spotlight that highlights real parts of the
 * workspace, one step at a time.
 *
 * Division of labour: Alpine owns everything that must feel instant (which step, where the card
 * sits, focus) — see resources/js/onboarding/tour.js. This component owns state that must survive a
 * closed tab: which step was reached, and whether the tour is still owed to this account.
 *
 * Leaving halfway is normal, so progress is written as the teacher moves and the tour resumes on
 * that step next time. Closing it early (Skip, Esc, backdrop) marks it dismissed: it stops opening
 * by itself, and the help centre can start it again.
 */
class WelcomeTour extends Component
{
    public bool $visible = false;

    /** Step the tour opens on — the furthest one this account reached. */
    public int $startStep = 0;

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $this->visible = $user->onboardingStatus()->opensAutomatically();
        $this->startStep = $this->clampStep($user->onboarding_step ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function steps(): array
    {
        return HelpGuide::tour();
    }

    /**
     * Remember how far the teacher got. Called as they move through the tour, so it has to be cheap
     * and forgiving: an out-of-range index is clamped rather than rejected.
     */
    public function recordStep(int $step): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->onboardingStatus()->isFinished()) {
            return;
        }

        $user->forceFill([
            'onboarding_status' => OnboardingStatus::Active,
            'onboarding_step' => $this->clampStep($step),
        ])->save();
    }

    public function finish(int $step = 0): void
    {
        $this->close(OnboardingStatus::Completed, $step);

        $this->dispatch('toast', type: 'success', message: __('You are all set. The question mark in the top bar reopens this any time.'));
    }

    public function dismiss(int $step = 0): void
    {
        $this->close(OnboardingStatus::Dismissed, $step);
    }

    /**
     * Close the tour and follow a step's call to action. The route comes from the tour data, not
     * from the browser, so the client cannot pick the destination.
     */
    public function finishAnd(string $stepKey): void
    {
        $steps = HelpGuide::tour();
        $index = array_search($stepKey, array_column($steps, 'key'), true);

        $this->close(OnboardingStatus::Completed, $index === false ? 0 : $index);

        if ($index !== false && $steps[$index]['cta']) {
            $this->redirectRoute($steps[$index]['cta']['route']);
        }
    }

    /** Close the tour and open the matching help-centre section. Unknown ids fall back to the top. */
    public function openHelp(string $topic): void
    {
        $this->close(OnboardingStatus::Dismissed, $this->startStep);

        $known = in_array($topic, array_column(HelpGuide::topics(), 'id'), true);

        $this->redirect(route('help.index').($known ? '#'.$topic : ''));
    }

    private function close(OnboardingStatus $status, int $step): void
    {
        $this->visible = false;

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        // Idempotent: a second close (double click, Esc racing a button) must not rewrite the
        // timestamp or downgrade a completed tour to dismissed.
        if ($user->onboardingStatus()->isFinished()) {
            return;
        }

        $user->forceFill([
            'onboarding_status' => $status,
            'onboarding_step' => $this->clampStep($step),
            'onboarded_at' => now(),
        ])->save();
    }

    private function clampStep(int $step): int
    {
        return max(0, min($step, count(HelpGuide::tour()) - 1));
    }

    public function render()
    {
        return view('livewire.onboarding.welcome-tour');
    }
}

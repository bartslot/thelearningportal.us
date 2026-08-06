{{--
    Buy narration credits. Reached from the wizard when a lesson's free editing allowance runs out.

    Nothing on this screen grants credit. Returning from Stripe only means the teacher came back,
    so the state of the payment comes from the ledger (BuyCredits::checkoutState) and the page waits
    for the webhook rather than congratulating anyone early.
--}}
<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">

    {{-- Back to where they came from, top-left, per the app's navigation convention. --}}
    @if ($returnLesson)
        <a href="{{ route('teacher.lessons.wizard', $returnLesson) }}" wire:navigate
           class="btn btn-ghost btn-sm -ml-2 mb-4 gap-1.5">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            {{ __('Back to the lesson') }}
        </a>
    @endif

    <h1 class="text-2xl font-bold text-base-content">{{ __('Narration credits') }}</h1>
    <p class="mt-1 text-sm text-base-content/60">
        {{ __('Editing a script has the scene read aloud again, and that is charged by the character. Every lesson includes :count characters of editing. Credits cover anything past that, on any of your lessons.', ['count' => number_format($freePerLesson)]) }}
    </p>

    {{-- ── What just happened, if anything ───────────────────────────────────── --}}

    @if ($checkoutState === 'cancelled')
        <div class="alert alert-info mt-6 text-sm" role="status">
            {{ __('No payment was taken. You can start again whenever you like.') }}
        </div>
    @elseif ($checkoutState === 'waiting')
        {{-- Polls the ledger rather than assuming: the webhook writes the credit, not this page. --}}
        <div class="alert mt-6 text-sm" role="status" wire:poll.4s="refreshBalance">
            <span class="loading loading-spinner loading-sm"></span>
            {{ __('Thank you. Your payment is being confirmed and your credits will appear here in a moment.') }}
        </div>
    @elseif ($checkoutState === 'arrived')
        <div class="alert alert-success mt-6 text-sm" role="status">
            {{ __('Your credits are ready. Head back to your lesson and keep editing.') }}
        </div>
    @elseif ($checkoutState === 'delayed')
        {{-- Deliberately covers both endings. We genuinely do not know which this is: an iDEAL
             transfer still clearing and one the bank refused look identical from here, and telling
             someone whose payment failed that "your credits are on their way" is worse than saying
             nothing. --}}
        <div class="alert alert-warning mt-6 text-sm" role="status">
            {{ __('We have not had confirmation of your payment yet. If the money left your account, your credits appear here by themselves and you should not pay again. If your bank turned the payment down, nothing was taken and you can try once more.') }}
        </div>
    @endif

    {{-- ── Balance ───────────────────────────────────────────────────────────── --}}

    <div class="card mt-6 border border-base-300 bg-base-200">
        <div class="card-body gap-3">
            <div class="flex flex-row items-center justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-wider text-base-content/50">{{ __('Your balance') }}</p>
                    <p class="mt-1 text-3xl font-bold text-base-content">
                        {{ number_format($balance) }}
                        <span class="text-base font-normal text-base-content/60">{{ __('characters') }}</span>
                    </p>
                </div>
                @if ($balance > 0)
                    <span class="badge badge-success badge-outline">{{ __('Ready to spend') }}</span>
                @endif
            </div>

            {{-- Said before it happens, not after. Credit vanishing unannounced is a refund request. --}}
            @if ($balance > 0 && $nextExpiry)
                <p class="text-sm text-base-content/60">
                    {{ __('Your credits run out on :date.', ['date' => $nextExpiry->isoFormat('D MMMM YYYY')]) }}
                </p>
            @endif

            @if ($expiredCharacters > 0)
                <p class="text-sm text-warning">
                    {{ __(':count characters have already run out and can no longer be spent.', ['count' => number_format($expiredCharacters)]) }}
                </p>
            @endif
        </div>
    </div>

    {{-- ── The one thing there is to buy ─────────────────────────────────────── --}}

    <div class="card mt-4 border border-base-300 bg-base-200">
        <div class="card-body gap-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="card-title text-base-content">
                    {{ __(':count characters of script editing', ['count' => number_format($packCharacters)]) }}
                </h2>
                <p class="text-right">
                    <span class="block text-2xl font-bold text-base-content">{{ $packPrice }}</span>
                    <span class="block text-xs text-base-content/60">{{ __(':price excl. VAT', ['price' => $packPriceNet]) }}</span>
                </p>
            </div>

            <ul class="space-y-1.5 text-sm text-base-content/70">
                <li class="flex gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                    {{ __('Spendable across any of your lessons, not tied to one.') }}
                </li>
                <li class="flex gap-2">
                    @if ($packExpiryDays)
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-base-content/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        {{ __('Valid for :days days from the day you buy them.', ['days' => $packExpiryDays]) }}
                    @else
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        {{ __('Credits do not expire.') }}
                    @endif
                </li>
                <li class="flex gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                    {{ __('Only the part of a script you actually change is charged.') }}
                </li>
            </ul>

            <div class="card-actions items-center justify-between">
                <p class="text-xs text-base-content/50">
                    {{ __('VAT included. Pay by card or iDEAL.') }}
                </p>
                <button type="button" class="btn btn-primary" wire:click="buy" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="buy">{{ __('Buy credits') }}</span>
                    <span wire:loading wire:target="buy" class="flex items-center gap-2">
                        <span class="loading loading-spinner loading-xs"></span>
                        {{ __('Opening payment screen') }}
                    </span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── Where the credits went ────────────────────────────────────────────── --}}

    @if ($history->isNotEmpty())
        <h2 class="mt-8 text-sm font-semibold uppercase tracking-wider text-base-content/50">
            {{ __('Recent activity') }}
        </h2>
        <div class="mt-2 overflow-x-auto">
            <table class="table table-sm">
                <tbody>
                    @foreach ($history as $entry)
                        <tr>
                            <td class="text-base-content/60">{{ $entry->created_at?->isoFormat('D MMM YYYY') }}</td>
                            <td class="text-base-content">
                                {{ $entry->reason->label() }}
                                @if ($entry->lesson)
                                    <span class="text-base-content/50">{{ $entry->lesson->title }}</span>
                                @endif
                            </td>
                            <td class="text-right font-medium {{ $entry->characters > 0 ? 'text-success' : 'text-base-content/70' }}">
                                {{ $entry->characters > 0 ? '+' : '' }}{{ number_format($entry->characters) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

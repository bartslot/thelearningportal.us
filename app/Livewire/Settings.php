<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Facades\App;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Self-service account settings. For now this is the UI-language switcher (English / Nederlands) —
 * teachers like Leonie can flip their own locale without an admin. Saving updates users.locale, which
 * the SetLocale middleware applies on every subsequent request; the full redirect re-renders the
 * whole page (nav included) in the new language.
 */
#[Title('Account Settings')]
class Settings extends Component
{
    /** Must stay in step with SetLocale::SUPPORTED. */
    private const LOCALES = ['en' => 'English', 'nl' => 'Nederlands'];

    #[Validate('required|in:en,nl')]
    public string $locale = 'en';

    public function mount(): void
    {
        $this->locale = auth()->user()->locale ?? 'en';
    }

    /** @return array<string,string> */
    public function localeOptions(): array
    {
        return self::LOCALES;
    }

    public function save(): void
    {
        $this->validate();

        auth()->user()->update(['locale' => $this->locale]);
        App::setLocale($this->locale);

        session()->flash('saved', true);

        // Full redirect (not wire:navigate) so SetLocale re-runs and every component — including the
        // nav outside this component — re-renders in the chosen language.
        $this->redirectRoute('settings.index');
    }

    public function render()
    {
        return view('livewire.settings');
    }
}

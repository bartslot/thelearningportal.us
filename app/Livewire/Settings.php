<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Support\Locales;
use Illuminate\Support\Facades\App;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Self-service account settings. For now this is the UI-language switcher — teachers like Leonie
 * can flip their own locale without an admin. Saving updates users.locale, which the SetLocale
 * middleware applies on every subsequent request; the full redirect re-renders the whole page (nav
 * included) in the new language.
 *
 * The language list lives in App\Support\Locales.
 */
#[Title('Account Settings')]
class Settings extends Component
{
    public string $locale = 'en';

    /** The language this teacher's lessons are written and narrated in. */
    public string $teaching_locale = 'en';

    public function mount(): void
    {
        $this->locale = auth()->user()->locale ?? 'en';
        $this->teaching_locale = auth()->user()->teachingLocale();
    }

    public function save(): void
    {
        // Rule derived from Locales rather than a #[Validate] literal, so adding a language cannot
        // leave the switcher accepting a value the app has no translations for.
        $this->validate([
            'locale' => ['required', Locales::validationRule()],
            'teaching_locale' => ['required', Locales::validationRule()],
        ]);

        auth()->user()->update([
            'locale' => $this->locale,
            'teaching_locale' => $this->teaching_locale,
        ]);
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

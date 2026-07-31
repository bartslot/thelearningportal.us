<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Support\NarrationVoice;
use App\Services\Support\ScriptLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A teacher's interface language and the language they teach in are two different questions.
 * Conflating them narrated a Dutch teacher's English lesson with a Dutch voice.
 */
class TeachingLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_teaching_locale_defaults_to_the_interface_language(): void
    {
        $u = User::factory()->create(['locale' => 'nl', 'teaching_locale' => null]);

        $this->assertSame('nl', $u->teachingLocale());
    }

    public function test_a_dutch_teacher_can_teach_in_english(): void
    {
        $u = User::factory()->create(['locale' => 'nl', 'teaching_locale' => 'en']);

        $this->assertSame('en', $u->teachingLocale());
        $this->assertSame('en-GB-Ollie:DragonHDLatestNeural', NarrationVoice::azure($u->teachingLocale()));
    }

    public function test_the_script_detector_is_the_safety_net_not_the_teacher_locale(): void
    {
        // English script, Dutch fallback: the text must win.
        $english = 'Freedom is within our grasp, a voice shouts, echoing through the great hall '
            .'of the palace and out over the crowd that was waiting below for the news.';
        $this->assertSame('en', ScriptLanguage::detect($english, 'nl'));

        $dutch = 'De olifanten kwamen eerst en de bergen gaven niet om hen, want het was een '
            .'lange tocht naar het zuiden die niet op tijd voorbij was.';
        $this->assertSame('nl', ScriptLanguage::detect($dutch, 'en'));
    }

    public function test_a_script_too_short_to_judge_keeps_the_fallback(): void
    {
        $this->assertSame('nl', ScriptLanguage::detect('Rome.', 'nl'));
        $this->assertSame('en', ScriptLanguage::detect(null, 'en'));
    }

    public function test_a_teacher_can_save_both_languages_from_settings(): void
    {
        $u = User::factory()->create(['role' => 'teacher', 'locale' => 'nl', 'teaching_locale' => null]);

        \Livewire\Livewire::actingAs($u)
            ->test(\App\Livewire\Settings::class)
            ->set('locale', 'nl')
            ->set('teaching_locale', 'en')
            ->call('save');

        $u->refresh();
        $this->assertSame('nl', $u->locale);
        $this->assertSame('en', $u->teaching_locale);
    }

    public function test_every_shipped_language_has_a_native_voice(): void
    {
        // An unmapped language silently falls back to an AMERICAN voice, which is how a British
        // narration turned into a US accent. Every language we ship must be mapped explicitly.
        foreach (\App\Support\Locales::codes() as $code) {
            $voice = NarrationVoice::azure($code);
            $this->assertStringStartsWith(
                $code === 'en' ? 'en-GB-' : substr($code, 0, 2).'-',
                $voice,
                "Locale '{$code}' falls back to '{$voice}' instead of a native voice.",
            );
        }
    }
}

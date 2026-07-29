<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Console\Commands\LangAudit;
use App\Livewire\Settings;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LocaleSwitchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_ships_the_five_pilot_languages(): void
    {
        $this->assertSame(['en', 'nl', 'de', 'fr', 'it'], Locales::codes());
    }

    /** Every language except the English source needs a translation file to load. */
    public function test_each_language_has_a_translation_file(): void
    {
        foreach (Locales::codes() as $code) {
            if ($code === 'en') {
                continue;
            }

            $this->assertFileExists(lang_path($code.'.json'));
        }
    }

    public function test_a_teacher_can_switch_to_any_shipped_language(): void
    {
        $teacher = User::factory()->teacher()->create(['locale' => 'en']);

        foreach (Locales::codes() as $code) {
            Livewire::actingAs($teacher)
                ->test(Settings::class)
                ->set('locale', $code)
                ->call('save')
                ->assertHasNoErrors();

            $this->assertSame($code, $teacher->fresh()->locale);
        }
    }

    public function test_an_unshipped_language_is_rejected(): void
    {
        $teacher = User::factory()->teacher()->create(['locale' => 'en']);

        Livewire::actingAs($teacher)
            ->test(Settings::class)
            ->set('locale', 'es')
            ->call('save')
            ->assertHasErrors('locale');

        $this->assertSame('en', $teacher->fresh()->locale);
    }

    public function test_the_middleware_serves_the_help_page_in_the_teachers_language(): void
    {
        $teacher = User::factory()->teacher()->create(['locale' => 'de']);

        $this->actingAs($teacher)
            ->get(route('help.index'))
            ->assertOk()
            ->assertSee(__('How the portal works', locale: 'de'));
    }

    /**
     * Choosing German has to mean German everywhere, not German chrome over an English page. This
     * walks the whole translatable catalogue — the same one `php artisan lang:audit` reports on —
     * and fails with the exact strings a language is still missing.
     */
    public function test_every_shipped_language_covers_the_whole_interface(): void
    {
        $catalogue = array_keys(app(LangAudit::class)->catalogue());

        $this->assertGreaterThan(500, count($catalogue), 'The string extractor found suspiciously little.');

        foreach (Locales::codes() as $code) {
            if ($code === 'en') {
                continue; // English is the source: the key is the translation.
            }

            $translations = json_decode((string) file_get_contents(lang_path($code.'.json')), true);
            $missing = array_values(array_diff($catalogue, array_keys($translations)));

            $this->assertSame(
                [],
                array_slice($missing, 0, 15),
                "lang/{$code}.json is missing ".count($missing).' strings. Run: php artisan lang:audit --locale='.$code,
            );
        }
    }
}

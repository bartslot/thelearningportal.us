<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Support\DemoLesson;
use App\Support\VisitorCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hero shows a lesson from the visitor's own curriculum: a German teacher meets a German
 * lesson, a French one meets the Revolution. Getting the country wrong must cost nothing, so
 * every path falls through to the configured default and then to any playable lesson.
 */
class DemoLessonByCountryTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(string $title): Lesson
    {
        $teacher = User::firstOrCreate(
            ['email' => 'owner@example.com'],
            ['name' => 'Owner', 'password' => 'secret-password', 'role' => 'teacher'],
        );

        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title' => $title,
            'topic' => $title,
            'subject' => 'history',
            'grade_level' => '7',
            'status' => LessonStatus::Published,
        ]);

        Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'ready',
            'script' => 'A scene.',
            'audio_path' => "lessons/{$lesson->id}/1.mp3",
        ]);

        DemoLesson::forget();

        return $lesson->refresh();
    }

    private function withCountry(string $country): void
    {
        // Cloudflare-style header: the most trustworthy signal, and the one a CDN gives us free.
        request()->headers->set('CF-IPCountry', $country);
        DemoLesson::forget();
    }

    public function test_a_german_visitor_gets_the_german_lesson(): void
    {
        $dutch = $this->lesson('De reis van Abel Tasman');
        $german = $this->lesson('Anne Frank and the Secret Annex');

        $this->withCountry('DE');

        $this->assertSame($german->id, DemoLesson::resolve()?->id);
        $this->assertNotSame($dutch->id, DemoLesson::resolve()?->id);
    }

    public function test_a_french_visitor_gets_the_revolution(): void
    {
        $this->lesson('De reis van Abel Tasman');
        $french = $this->lesson('La Révolution française et l\'ascension de Napoléon');

        $this->withCountry('FR');

        $this->assertSame($french->id, DemoLesson::resolve()?->id);
    }

    public function test_a_country_with_no_entry_gets_the_configured_default(): void
    {
        $dutch = $this->lesson('De reis van Abel Tasman');
        $this->lesson('Anne Frank and the Secret Annex');

        $this->withCountry('JP');

        $this->assertSame($dutch->id, DemoLesson::resolve()?->id);
    }

    public function test_a_country_whose_lesson_does_not_exist_yet_falls_through(): void
    {
        // Listing a country before its lesson has been written must not empty the hero.
        $dutch = $this->lesson('De reis van Abel Tasman');

        $this->withCountry('DE');

        $this->assertSame($dutch->id, DemoLesson::resolve()?->id);
    }

    public function test_the_typed_goal_always_describes_the_lesson_that_won(): void
    {
        $this->lesson('De reis van Abel Tasman');
        $this->lesson('The Colosseum');

        $this->withCountry('IT');
        $this->assertSame('The Colosseum, and the Rome that filled it', DemoLesson::goal());

        $this->withCountry('NL');
        $this->assertSame('Abel Tasman en de reis van 1642', DemoLesson::goal());
    }

    public function test_the_goal_never_promises_a_lesson_the_visitor_will_not_get(): void
    {
        // Italy is configured and so is the default, but only the Dutch lesson exists — so the
        // words must follow the lesson that actually resolved, not the one Italy was promised.
        $this->lesson('De reis van Abel Tasman');

        $this->withCountry('IT');

        $this->assertSame('De reis van Abel Tasman', DemoLesson::goal());
    }

    public function test_each_country_is_cached_separately(): void
    {
        $dutch = $this->lesson('De reis van Abel Tasman');
        $italian = $this->lesson('The Colosseum');

        $this->withCountry('IT');
        $this->assertSame($italian->id, DemoLesson::resolve()?->id);

        // No forget() here: a second country must not be served the first one's cached pick.
        request()->headers->set('CF-IPCountry', 'NL');
        $this->assertSame($dutch->id, DemoLesson::resolve()?->id);
    }

    public function test_country_comes_from_the_header_first_then_the_browser_region(): void
    {
        request()->headers->set('CF-IPCountry', 'CH');
        $this->assertSame('CH', VisitorCountry::code(request()));

        request()->headers->remove('CF-IPCountry');
        request()->headers->set('Accept-Language', 'de-AT,de;q=0.9,en;q=0.8');
        $this->assertSame('AT', VisitorCountry::code(request()));
    }

    public function test_a_placeholder_country_header_is_ignored(): void
    {
        // Cloudflare sends XX when it cannot tell; treating that as a country would be a lie.
        request()->headers->set('CF-IPCountry', 'XX');
        request()->headers->set('Accept-Language', 'fr-BE,fr;q=0.9');

        $this->assertSame('BE', VisitorCountry::code(request()));
    }

    public function test_a_language_without_a_region_falls_back_to_the_locale(): void
    {
        request()->headers->remove('CF-IPCountry');
        request()->headers->set('Accept-Language', 'de');
        app()->setLocale('de');

        $this->assertSame('DE', VisitorCountry::code(request()));
    }
}

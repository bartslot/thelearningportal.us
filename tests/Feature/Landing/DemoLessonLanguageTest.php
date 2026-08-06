<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Support\DemoLesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landing page must never offer a visitor a lesson in the wrong language.
 *
 * On production the demo picker was keyed by country with "the newest playable lesson" underneath.
 * Neither Tasman edition had been deployed, so every country fell through that fallback and Dutch
 * teachers were handed "The Silk Road" — in English. The picker could not have known better:
 * lessons carried no language at all until this change.
 *
 * The rule is now: your own language, or English. Never a third one.
 */
class DemoLessonLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(string $title, string $language): Lesson
    {
        $lesson = Lesson::create([
            'teacher_id' => User::factory()->create()->id,
            'title' => $title,
            'topic' => $title,
            'subject' => 'history',
            'language' => $language,
            'grade_level' => '7',
            'status' => 'published',
            'lesson_code' => strtoupper(substr(md5($title.$language), 0, 6)),
        ]);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'ready']);

        return $lesson;
    }

    /** The three editions config/demo.php names, so the configured path is the one under test. */
    private function seedCanon(): void
    {
        $this->lesson('De reis van Abel Tasman', 'nl');
        $this->lesson('Abel Tasman: two voyages to the unknown south', 'en');
        $this->lesson("La Révolution française et l'ascension de Napoléon", 'fr');
    }

    private function visit(array $headers = []): ?Lesson
    {
        DemoLesson::forget();
        $request = \Illuminate\Http\Request::create('/', 'GET', [], [], [], []);
        foreach ($headers as $k => $v) {
            $request->headers->set($k, $v);
        }

        return DemoLesson::resolve($request);
    }

    public function test_a_dutch_browser_gets_the_dutch_lesson(): void
    {
        $this->seedCanon();

        $lesson = $this->visit(['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.8']);

        $this->assertSame('De reis van Abel Tasman', $lesson?->title);
        $this->assertSame('nl', $lesson?->language);
    }

    public function test_a_teacher_in_the_netherlands_gets_dutch_even_with_an_english_laptop(): void
    {
        // The signal that matters is where they teach, not how their OS shipped.
        $this->seedCanon();

        $lesson = $this->visit(['Accept-Language' => 'en-US,en;q=0.9', 'CF-IPCountry' => 'NL']);

        $this->assertSame('nl', $lesson?->language, 'a Dutch teacher was handed an English lesson');
    }

    public function test_a_french_browser_anywhere_in_the_world_gets_the_french_lesson(): void
    {
        $this->seedCanon();

        $lesson = $this->visit(['Accept-Language' => 'fr-CA,fr;q=0.9', 'CF-IPCountry' => 'CA']);

        $this->assertSame('fr', $lesson?->language);
    }

    public function test_a_visitor_in_france_gets_the_french_lesson_whatever_their_browser_says(): void
    {
        $this->seedCanon();

        $lesson = $this->visit(['Accept-Language' => 'en-GB,en;q=0.9', 'CF-IPCountry' => 'FR']);

        $this->assertSame("La Révolution française et l'ascension de Napoléon", $lesson?->title);
    }

    public function test_the_rest_of_the_world_gets_english_tasman(): void
    {
        $this->seedCanon();

        $lesson = $this->visit(['Accept-Language' => 'pt-BR,pt;q=0.9', 'CF-IPCountry' => 'BR']);

        $this->assertSame('Abel Tasman: two voyages to the unknown south', $lesson?->title);
        $this->assertSame('en', $lesson?->language);
    }

    public function test_a_language_with_no_lesson_falls_back_to_english_not_to_another_language(): void
    {
        // German has no lesson. The Dutch one must not stand in for it just because it exists.
        $this->seedCanon();

        $lesson = $this->visit(['Accept-Language' => 'de-DE,de;q=0.9', 'CF-IPCountry' => 'DE']);

        $this->assertSame('en', $lesson?->language, 'a German visitor was given a non-English foreign lesson');
    }

    public function test_a_dutch_visitor_is_never_handed_an_unrelated_english_lesson(): void
    {
        // Exactly production's shape: plenty of English lessons, no Dutch one, and one of the
        // English ones is the newest — which is how The Silk Road won.
        $this->lesson('Abel Tasman: two voyages to the unknown south', 'en');
        $this->lesson('The Silk Road', 'en');

        $lesson = $this->visit(['Accept-Language' => 'nl-NL,nl;q=0.9', 'CF-IPCountry' => 'NL']);

        $this->assertNotSame('The Silk Road', $lesson?->title, 'the newest-lesson fallback is back');
        $this->assertSame('Abel Tasman: two voyages to the unknown south', $lesson?->title);
    }

    public function test_a_title_match_in_the_wrong_language_is_not_accepted(): void
    {
        // Someone renames the English lesson to the Dutch title. The column is the source of truth,
        // so this must NOT satisfy a Dutch visitor.
        $this->lesson('De reis van Abel Tasman', 'en');
        $this->lesson('Abel Tasman: two voyages to the unknown south', 'en');

        $lesson = $this->visit(['Accept-Language' => 'nl-NL,nl;q=0.9']);

        $this->assertSame('en', $lesson?->language);
        $this->assertSame('Abel Tasman: two voyages to the unknown south', $lesson?->title);
    }

    public function test_the_goal_line_describes_the_lesson_that_actually_won(): void
    {
        // Nothing on screen may promise a lesson the visitor is not about to get.
        $this->lesson('Abel Tasman: two voyages to the unknown south', 'en');

        DemoLesson::forget();
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'nl-NL,nl;q=0.9');

        $this->assertSame(
            'Abel Tasman, and the two voyages that mapped the south',
            DemoLesson::goal($request),
            'the hero typed a Dutch goal over an English lesson',
        );
    }
}

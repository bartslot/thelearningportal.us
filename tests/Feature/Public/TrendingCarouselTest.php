<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Support\TrendingTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The "Trending history topics" cards on the landing page used to be five posters that led nowhere.
 * They now link to real composed lessons, matched by title, and this guards both halves of that:
 * a card links when its lesson exists, and degrades to a plain unlinked card when it does not
 * (so an unbuilt topic can never produce a dead link on the busiest page on the site).
 */
class TrendingCarouselTest extends TestCase
{
    use RefreshDatabase;

    private function publishedLesson(string $title): Lesson
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title' => $title,
            'topic' => $title,
            'subject' => 'history',
            'grade_level' => '7',
            'status' => 'published',
            'lesson_code' => strtoupper(substr(md5($title), 0, 6)),
        ]);

        // The landing query only surfaces lessons that actually play, i.e. have narrated audio.
        Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'ready',
            'config' => [],
            'audio_path' => "lessons/{$lesson->id}/scenes/1/narration.mp3",
        ]);

        return $lesson;
    }

    public function test_a_trending_card_links_to_its_lesson(): void
    {
        Cache::flush();
        $lesson = $this->publishedLesson('The Punic Wars');

        $this->get('/')
            ->assertOk()
            ->assertSee(route('lesson.play', $lesson->lesson_code), false);
    }

    public function test_a_topic_with_no_lesson_still_renders_but_is_not_linked(): void
    {
        Cache::flush();
        // Only one of the five topics exists; the other four must not produce links.
        $this->publishedLesson('The Punic Wars');

        $response = $this->get('/')->assertOk();

        // The poster and its title are still on the page …
        $response->assertSee('Gladiators');
        // … but nothing links to a lesson for it, because none was built.
        $response->assertDontSee('aria-label="Play the lesson Gladiators"', false);
    }

    public function test_a_lesson_that_is_not_published_does_not_get_linked(): void
    {
        Cache::flush();
        $lesson = $this->publishedLesson('Gladiators');
        $lesson->update(['status' => 'draft']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee(route('lesson.play', $lesson->lesson_code), false);
    }

    public function test_every_card_points_at_a_real_lesson_spec(): void
    {
        // This is the check that was missing when two cards silently stopped linking: the card
        // headline ("Colosseum") is not always the lesson title ("The Colosseum"), so the LESSON
        // side of each pair is what has to exist in resources/lessons.
        $specTitles = collect(glob(resource_path('lessons/*.php')))
            ->map(fn (string $path) => require $path)
            ->pluck('title')
            ->all();

        foreach (TrendingTopics::cards() as $card) {
            $this->assertContains(
                $card['lesson'],
                $specTitles,
                "Card '{$card['title']}' points at lesson '{$card['lesson']}', which has no spec in resources/lessons.",
            );
        }
    }

    public function test_every_card_links_once_all_five_lessons_exist(): void
    {
        Cache::flush();
        foreach (TrendingTopics::cards() as $card) {
            $this->publishedLesson($card['lesson']);
        }

        $response = $this->get('/')->assertOk();

        foreach (TrendingTopics::cards() as $card) {
            $response->assertSee('aria-label="Play the lesson '.$card['title'].'"', false);
        }
    }

    public function test_each_card_poster_image_exists(): void
    {
        foreach (TrendingTopics::cards() as $card) {
            $this->assertFileExists(
                public_path($card['image']),
                "Poster for '{$card['title']}' is missing from public/.",
            );
        }
    }
}

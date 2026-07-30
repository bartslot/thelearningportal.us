<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Support\Locales;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoPagesTest extends TestCase
{
    use RefreshDatabase;

    private function publishedLesson(array $attributes = []): Lesson
    {
        $lesson = Lesson::factory()->for(User::factory()->teacher(), 'teacher')->create($attributes + [
            'title' => 'Anne Frank and the Secret Annex',
            'topic' => 'Anne Frank',
            'status' => LessonStatus::Published,
            'grade_level' => 'Age 12',
            'duration_seconds' => 720,
        ]);

        // Only lessons with narrated scenes are public — that is what makes them playable.
        Scene::create([
            'lesson_id' => $lesson->id,
            'order' => 1,
            'kind' => 'narration',
            'status' => 'ready',
            'audio_path' => "lessons/{$lesson->id}/scenes/1/audio.mp3",
            'config' => [],
        ]);

        return $lesson->refresh();
    }

    public function test_the_lesson_library_lists_published_lessons(): void
    {
        $lesson = $this->publishedLesson();

        $this->get(route('public.lessons'))
            ->assertOk()
            ->assertSee($lesson->title)
            ->assertSee(Seo::lessonSlug($lesson));
    }

    public function test_a_lesson_that_is_not_public_stays_out_of_the_library(): void
    {
        $draft = Lesson::factory()->for(User::factory()->teacher(), 'teacher')->create([
            'title' => 'Unfinished Draft Lesson',
            'status' => LessonStatus::Draft,
        ]);

        $this->get(route('public.lessons'))->assertOk()->assertDontSee($draft->title);
        $this->get(route('public.lesson', ['slug' => Seo::lessonSlug($draft)]))->assertNotFound();
    }

    public function test_a_lesson_page_carries_the_tags_a_search_engine_needs(): void
    {
        $lesson = $this->publishedLesson();
        $slug = Seo::lessonSlug($lesson);
        $url = route('public.lesson', ['slug' => $slug]);

        $response = $this->get($url)->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$url.'"', escape: false);
        $response->assertSee('property="og:title"', escape: false);
        $response->assertSee('property="og:image"', escape: false);
        $response->assertSee('name="twitter:card"', escape: false);
        $response->assertSee('"@type":"LearningResource"', escape: false);
        $response->assertSee('"timeRequired":"PT12M"', escape: false);
        $response->assertSee('name="robots" content="index, follow', escape: false);
    }

    public function test_every_language_gets_its_own_url_and_the_full_hreflang_set(): void
    {
        $lesson = $this->publishedLesson();
        $slug = Seo::lessonSlug($lesson);

        foreach (Locales::codes() as $code) {
            $response = $this->get(Seo::url('lesson', ['slug' => $slug], $code))->assertOk();

            // Each page points at itself as canonical and lists all five languages plus x-default.
            $response->assertSee('<link rel="canonical" href="'.Seo::url('lesson', ['slug' => $slug], $code).'"', escape: false);

            foreach (Locales::codes() as $other) {
                $response->assertSee(
                    'hreflang="'.Locales::region($other).'" href="'.Seo::url('lesson', ['slug' => $slug], $other).'"',
                    escape: false,
                );
            }

            $response->assertSee('hreflang="x-default"', escape: false);
        }
    }

    public function test_a_localised_page_renders_in_the_url_language_whoever_is_reading(): void
    {
        $this->publishedLesson();

        // A signed-in Dutch teacher must still get German at a /de/ URL: that is the page Google
        // indexed, so that is the page the reader has to see.
        $teacher = User::factory()->teacher()->create(['locale' => 'nl']);

        $this->actingAs($teacher)
            ->get(Seo::url('lessons', [], 'de'))
            ->assertOk()
            ->assertSee(__('Lesson library', [], 'de'))
            ->assertSee('lang="de-DE"', escape: false);
    }

    public function test_a_wrong_slug_redirects_to_the_canonical_one(): void
    {
        $lesson = $this->publishedLesson();

        $this->get(route('public.lesson', ['slug' => 'some-old-title-'.strtolower($lesson->lesson_code)]))
            ->assertRedirect(route('public.lesson', ['slug' => Seo::lessonSlug($lesson)]));
    }

    public function test_the_sitemap_lists_every_page_in_every_language(): void
    {
        $lesson = $this->publishedLesson();

        $response = $this->get(route('sitemap'))->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = $response->getContent();

        // Well-formed, or a crawler discards the whole file.
        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($xml));

        foreach (Locales::codes() as $code) {
            $this->assertStringContainsString(
                '<loc>'.Seo::url('lesson', ['slug' => Seo::lessonSlug($lesson)], $code).'</loc>',
                $xml,
            );
        }
    }

    public function test_robots_points_crawlers_at_the_sitemap_and_away_from_the_app(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Sitemap: https://history.thelearningportal.us/sitemap.xml', $robots);
        $this->assertStringContainsString('Disallow: /teacher/', $robots);
    }

    public function test_the_locale_prefix_cannot_swallow_the_app_routes(): void
    {
        // 'teacher' and 'login' are not languages; they must reach their own routes.
        $this->get('/login')->assertOk();
        $this->get('/es/history-lessons')->assertNotFound();
    }
}

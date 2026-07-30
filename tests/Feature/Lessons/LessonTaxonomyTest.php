<?php

declare(strict_types=1);

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\LessonTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vocabulary every filter, shelf and export pack agrees on.
 *
 * The facets are DERIVED, so these cover the derivation: a lesson that has never been tagged by
 * hand still has to land in the right period, region and format.
 */
final class LessonTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private function taxonomy(): LessonTaxonomy
    {
        return app(LessonTaxonomy::class);
    }

    private function lesson(array $attributes = []): Lesson
    {
        return Lesson::factory()->create($attributes + [
            'teacher_id' => User::factory()->create()->id,
            'subject' => 'history',
        ]);
    }

    public function test_a_lesson_is_placed_in_the_period_its_year_falls_in(): void
    {
        $lesson = $this->lesson(['title' => 'De eerste reis van Columbus (1492)', 'era' => null]);

        $this->assertSame('cities-states', $this->taxonomy()->period($lesson));
    }

    public function test_a_year_before_christ_reads_as_a_negative_year(): void
    {
        $lesson = $this->lesson(['title' => 'The battle of Marathon, 490 BC', 'era' => null]);

        $this->assertSame('greeks-romans', $this->taxonomy()->period($lesson));
    }

    public function test_a_lesson_that_names_no_year_has_no_period_rather_than_a_guess(): void
    {
        $lesson = $this->lesson(['title' => 'Marco Polo', 'topic' => 'Marco Polo', 'era' => null]);

        $this->assertNull($this->taxonomy()->period($lesson));
    }

    public function test_a_voyage_takes_its_period_from_the_earliest_stop_it_sails_from(): void
    {
        $lesson = $this->lesson(['title' => 'Marco Polo', 'topic' => 'Marco Polo', 'era' => null]);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 2, 'kind' => 'voyage', 'year' => 1295]);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'voyage', 'year' => 1271]);

        $this->assertSame('cities-states', $this->taxonomy()->period($lesson->fresh()));
    }

    public function test_a_lesson_spanning_continents_is_filed_under_every_one_of_them(): void
    {
        $lesson = $this->lesson([
            'title' => 'Vasco da Gama: the sea road to India',
            'topic' => 'Portugal, the Cape and India',
        ]);

        $regions = $this->taxonomy()->regions($lesson);

        $this->assertContains('europe', $regions);
        $this->assertContains('asia', $regions);
        $this->assertContains('africa', $regions);
    }

    public function test_a_place_that_only_a_scene_names_still_files_the_lesson(): void
    {
        $lesson = $this->lesson(['title' => 'A journey', 'topic' => 'A journey', 'region' => null]);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'voyage', 'location' => 'Batavia']);

        $this->assertContains('asia', $this->taxonomy()->regions($lesson->fresh()));
    }

    public function test_the_format_says_what_the_lesson_actually_contains(): void
    {
        $lesson = $this->lesson(['include_game' => false, 'quiz_question_count' => 0]);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration']);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 2, 'kind' => 'voyage']);

        $formats = $this->taxonomy()->formats($lesson->fresh());

        $this->assertContains('narrated-story', $formats);
        $this->assertContains('voyage', $formats);
        $this->assertNotContains('quiz', $formats);
    }

    public function test_a_quiz_counts_whether_it_is_a_scene_or_a_lesson_level_question_set(): void
    {
        $sceneQuiz = $this->lesson(['quiz_question_count' => 0]);
        Scene::create(['lesson_id' => $sceneQuiz->id, 'order' => 1, 'kind' => 'game']);

        $lessonQuiz = $this->lesson(['quiz_question_count' => 4]);

        $this->assertContains('quiz', $this->taxonomy()->formats($sceneQuiz->fresh()));
        $this->assertContains('quiz', $this->taxonomy()->formats($lessonQuiz->fresh()));
    }

    public function test_a_class_game_is_gamified_but_a_plain_quiz_is_not(): void
    {
        $gamified = $this->lesson(['include_game' => true, 'game_type' => 'story_game']);
        $quizOnly = $this->lesson(['include_game' => true, 'game_type' => 'quiz']);

        $this->assertContains('class-game', $this->taxonomy()->formats($gamified->fresh()));
        $this->assertNotContains('class-game', $this->taxonomy()->formats($quizOnly->fresh()));
    }

    public function test_interactivity_separates_watching_from_doing(): void
    {
        $taxonomy = $this->taxonomy();

        $this->assertSame('expositive', $taxonomy->interactivity(['narrated-story']));
        $this->assertSame('active', $taxonomy->interactivity(['quiz']));
        $this->assertSame('mixed', $taxonomy->interactivity(['narrated-story', 'quiz']));
    }

    public function test_free_text_grade_levels_land_in_one_band(): void
    {
        $taxonomy = $this->taxonomy();

        $this->assertSame('primary-upper', $taxonomy->gradeBand($this->lesson(['grade_level' => '6'])));
        $this->assertSame('primary-upper', $taxonomy->gradeBand($this->lesson(['grade_level' => 'groep 7'])));
        $this->assertSame('primary-upper', $taxonomy->gradeBand($this->lesson(['grade_level' => 'Age 10'])));
        $this->assertSame('primary-upper', $taxonomy->gradeBand($this->lesson(['grade_level' => 'K-7'])));
        $this->assertSame('secondary-lower', $taxonomy->gradeBand($this->lesson(['grade_level' => 'grade 9'])));
        $this->assertNull($taxonomy->gradeBand($this->lesson(['grade_level' => ''])));
    }

    public function test_focus_tags_come_back_as_slugs_without_duplicates(): void
    {
        $lesson = $this->lesson(['focus_tags' => ['Exploration', 'exploration', 'Trade Routes', '']]);

        $this->assertSame(['exploration', 'trade-routes'], $this->taxonomy()->tags($lesson));
    }

    public function test_every_facet_key_a_lesson_gets_exists_in_the_vocabulary(): void
    {
        $lesson = $this->lesson(['title' => 'The VOC in Batavia, 1602', 'grade_level' => '6']);
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration']);

        $facets = $this->taxonomy()->facets($lesson->fresh());

        $this->assertArrayHasKey($facets['subject'], config('lesson-taxonomy.subjects'));
        $this->assertArrayHasKey($facets['period'], config('lesson-taxonomy.periods'));
        $this->assertArrayHasKey($facets['grade_band'], config('lesson-taxonomy.grade_bands'));
        $this->assertArrayHasKey($facets['interactivity'], config('lesson-taxonomy.interactivity'));

        foreach ($facets['regions'] as $region) {
            $this->assertArrayHasKey($region, config('lesson-taxonomy.regions'));
        }
        foreach ($facets['formats'] as $format) {
            $this->assertArrayHasKey($format, config('lesson-taxonomy.formats'));
        }
    }
}

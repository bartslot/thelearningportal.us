<?php

declare(strict_types=1);

namespace Tests\Feature\Llm;

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use App\Services\LessonScriptPrompt;
use App\Services\SceneScriptPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The source article has to come before anything that changes between calls.
 *
 * Providers cache on a shared prefix, and a lesson sends the same article once per
 * scene plus again on every rewrite. With the article last, the prefix diverges before
 * it is ever reached and every call pays for it in full — which is most of the cost of
 * a lesson. This is invisible at runtime, so it needs a test to hold it in place.
 */
class CacheablePromptOrderTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'In 1602 the VOC was founded in Amsterdam as the first joint-stock company.';

    private function lesson(): Lesson
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        return Lesson::factory()->create([
            'teacher_id' => $teacher->id,
            'topic' => 'VOC',
            'grade_level' => 'groep 7',
            'tone' => 'adventurous',
            'outline' => [
                'learning_objectives' => [['id' => 'LO1', 'text' => 'Explain why the VOC was founded']],
                'scene_briefs' => [['beat' => 'The charter is signed', 'historicalFacts' => ['founded 1602']]],
            ],
        ]);
    }

    public function test_the_scene_prompt_leads_with_the_source(): void
    {
        $lesson = $this->lesson();
        $scene = Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'pending', 'year' => 1602, 'location' => 'Amsterdam']);

        $prompt = SceneScriptPrompt::user($scene->load('lesson'), self::SOURCE, 'adventurous', 'groep 7');

        $this->assertLessThan(
            strpos($prompt, 'Scene 1'),
            strpos($prompt, self::SOURCE),
            'the article must precede the per-scene brief, or no scene after the first reuses it',
        );
    }

    /** Every scene in a lesson must produce the same leading block, byte for byte. */
    public function test_two_scenes_of_one_lesson_share_a_prefix_containing_the_whole_source(): void
    {
        $lesson = $this->lesson();
        $one = Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration', 'status' => 'pending']);
        $two = Scene::create(['lesson_id' => $lesson->id, 'order' => 2, 'kind' => 'narration', 'status' => 'pending']);

        $a = SceneScriptPrompt::user($one->load('lesson'), self::SOURCE, 'adventurous', 'groep 7');
        $b = SceneScriptPrompt::user($two->load('lesson'), self::SOURCE, 'adventurous', 'groep 7');

        $shared = substr($a, 0, strspn($a ^ $b, "\0"));

        $this->assertStringContainsString(self::SOURCE, $shared);
    }

    /** The rewrite after a rejected draft differs only in its notes, which come last. */
    public function test_a_critique_rewrite_keeps_the_source_inside_the_shared_prefix(): void
    {
        $lesson = $this->lesson();
        $scenes = new Collection([new Scene(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration'])]);
        $briefs = $lesson->outline['scene_briefs'];

        $first = LessonScriptPrompt::user($lesson, $scenes, $briefs, self::SOURCE);
        $rewrite = LessonScriptPrompt::user($lesson, $scenes, $briefs, self::SOURCE, ['Scene 1 invents a quote']);

        $shared = substr($first, 0, strspn($first ^ $rewrite, "\0"));

        $this->assertStringContainsString(self::SOURCE, $shared);
        $this->assertStringContainsString('Scene 1 invents a quote', $rewrite);
    }
}

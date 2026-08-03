<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue manifest the Flutter app syncs against, and that a lesson migration reads.
 *
 * The contract worth protecting is the sync one: a client must be able to ask "what changed since
 * X", learn what to drop, and pay nothing when nothing has.
 */
final class CatalogManifestTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(array $attributes = []): Lesson
    {
        $lesson = Lesson::factory()->create($attributes + [
            'teacher_id' => User::factory()->create()->id,
            'status' => LessonStatus::Published,
            'subject' => 'history',
        ]);
        // Every lesson a class can open has at least one scene, and the catalogue now says so
        // (see CatalogEmptyLessonTest). These tests are about what the manifest REPORTS, so they
        // need a lesson that would legitimately be in it.
        Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration']);

        return $lesson->refresh();
    }

    private function url(array $query = []): string
    {
        return route('api.v1.catalog.manifest', $query);
    }

    public function test_the_manifest_is_public_and_lists_published_lessons(): void
    {
        $published = $this->lesson(['title' => 'The VOC']);
        $this->lesson(['status' => LessonStatus::Configuring, 'title' => 'A draft']);

        $response = $this->getJson($this->url())->assertOk();

        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('lessons.0.code', $published->lesson_code);
        $this->assertSame(['The VOC'], array_column($response->json('lessons'), 'title'));
    }

    public function test_a_draft_is_never_exposed(): void
    {
        $this->lesson(['status' => LessonStatus::Configuring, 'title' => 'Unfinished business']);

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonMissing(['title' => 'Unfinished business']);
    }

    public function test_each_entry_carries_what_a_client_needs_to_list_and_filter(): void
    {
        $lesson = $this->lesson(['title' => 'The VOC in Batavia, 1602', 'grade_level' => '6']);

        $entry = $this->getJson($this->url())->assertOk()->json('lessons.0');

        $this->assertSame($lesson->lesson_code, $entry['code']);
        $this->assertNotEmpty($entry['version']);
        $this->assertStringContainsString($lesson->lesson_code, $entry['play_url']);
        $this->assertArrayHasKey('cover', $entry['media']);
        $this->assertSame('history', $entry['taxonomy']['subject']);
        $this->assertContains('narrated-story', $entry['taxonomy']['formats']);
        $this->assertSame(1, $entry['counts']['scenes']);
    }

    public function test_the_vocabulary_explains_the_taxonomy_keys_a_lesson_uses(): void
    {
        $lesson = $this->lesson(['title' => 'Rome, 80 AD']);

        $body = $this->getJson($this->url())->assertOk()->json();
        $entry = $body['lessons'][0];

        foreach ($entry['taxonomy']['formats'] as $format) {
            $this->assertArrayHasKey($format, $body['vocabulary']['formats']);
        }
        $this->assertArrayHasKey($entry['taxonomy']['subject'], $body['vocabulary']['subjects']);
    }

    public function test_a_since_query_returns_only_what_changed_after_it(): void
    {
        $old = $this->lesson(['title' => 'Long settled']);
        $old->forceFill(['updated_at' => now()->subWeek()])->saveQuietly();

        $fresh = $this->lesson(['title' => 'Just edited']);

        $response = $this->getJson($this->url(['since' => now()->subDay()->toIso8601String()]))->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame($fresh->lesson_code, $response->json('lessons.0.code'));
    }

    public function test_a_lesson_that_was_deleted_is_reported_so_a_client_can_prune_it(): void
    {
        $gone = $this->lesson(['title' => 'Withdrawn']);
        $gone->delete();

        $response = $this->getJson($this->url(['since' => now()->subDay()->toIso8601String()]))->assertOk();

        $this->assertContains($gone->lesson_code, $response->json('removed'));
        $this->assertNotContains($gone->lesson_code, array_column($response->json('lessons'), 'code'));
    }

    public function test_a_lesson_that_was_unpublished_is_reported_too(): void
    {
        $lesson = $this->lesson(['title' => 'Pulled back to drafts']);
        $lesson->update(['status' => LessonStatus::Configuring]);

        $response = $this->getJson($this->url(['since' => now()->subDay()->toIso8601String()]))->assertOk();

        $this->assertContains($lesson->lesson_code, $response->json('removed'));
    }

    public function test_a_first_sync_has_nothing_to_prune(): void
    {
        $gone = $this->lesson();
        $gone->delete();

        $this->getJson($this->url())->assertOk()->assertJsonPath('removed', []);
    }

    public function test_an_unchanged_catalogue_costs_a_304_and_no_body(): void
    {
        $this->lesson();

        $etag = $this->getJson($this->url())->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson($this->url())
            ->assertStatus(304)
            ->assertNoContent(304);
    }

    public function test_editing_a_lesson_changes_its_version_token(): void
    {
        $lesson = $this->lesson(['title' => 'Before']);

        $before = $this->getJson($this->url())->json('lessons.0.version');

        $lesson->update(['title' => 'After']);

        $this->assertNotSame($before, $this->getJson($this->url())->json('lessons.0.version'));
    }

    public function test_editing_only_a_scene_still_changes_the_version_token(): void
    {
        $lesson = $this->lesson();
        $scene = $lesson->scenes()->first();

        $before = $this->getJson($this->url())->json('lessons.0.version');

        $scene->update(['location' => 'Batavia']);

        $this->assertNotSame($before, $this->getJson($this->url())->json('lessons.0.version'));
    }

    public function test_a_malformed_since_is_a_json_validation_error_not_a_redirect(): void
    {
        $this->get($this->url(['since' => 'notadate']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('since');
    }
}

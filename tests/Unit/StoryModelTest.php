<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\StorySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeStory(array $attributes = []): Story
    {
        return Story::create(array_merge([
            'slug' => 'test-story',
            'title' => 'Test Story',
        ], $attributes));
    }

    public function test_review_state_machine_allows_the_publish_path_and_blocks_shortcuts(): void
    {
        $story = $this->makeStory();

        // draft → published is forbidden: a human review step is mandatory.
        try {
            $story->transitionTo(StoryStatus::Published);
            $this->fail('draft → published must throw');
        } catch (\DomainException) {
            // expected
        }

        $story->transitionTo(StoryStatus::Reviewed, reviewerId: null);
        $story->transitionTo(StoryStatus::Published);
        $this->assertSame(StoryStatus::Published, $story->fresh()->status);

        // published → reviewed (unpublish for corrections) is allowed.
        $story->transitionTo(StoryStatus::Reviewed);
        $this->assertSame(StoryStatus::Reviewed, $story->fresh()->status);
    }

    public function test_published_scope_only_returns_published_stories(): void
    {
        $this->makeStory(['slug' => 'draft-story', 'status' => 'draft']);
        $this->makeStory(['slug' => 'published-story', 'status' => 'published']);

        $this->assertSame(['published-story'], Story::published()->pluck('slug')->all());
    }

    public function test_combined_source_text_concatenates_excerpts_in_order(): void
    {
        $story = $this->makeStory();
        StorySource::create(['story_id' => $story->id, 'origin' => 'gutenberg', 'title' => 'B', 'excerpt' => 'Second part.', 'order' => 2]);
        StorySource::create(['story_id' => $story->id, 'origin' => 'gutenberg', 'title' => 'A', 'excerpt' => 'First part.', 'order' => 1]);

        $this->assertSame("First part.\n\nSecond part.", $story->fresh()->combinedSourceText());
    }
}

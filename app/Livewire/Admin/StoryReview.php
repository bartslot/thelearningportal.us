<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\StoryAssetPackGenerator;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Minimal review queue for the story catalog: inspect a draft's objectives/sources,
 * flip draft → reviewed → published. Publishing is what makes a story visible to
 * teachers in the wizard — nothing reaches a classroom without a human OK.
 */
class StoryReview extends Component
{
    public ?int $openStoryId = null;

    public string $statusFilter = 'all';

    #[Computed]
    public function stories()
    {
        return Story::query()
            ->withCount('sources', 'lessons')
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 WHEN 'reviewed' THEN 1 ELSE 2 END")
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function openStory(): ?Story
    {
        return $this->openStoryId ? Story::with('sources')->find($this->openStoryId) : null;
    }

    public function toggle(int $storyId): void
    {
        $this->openStoryId = $this->openStoryId === $storyId ? null : $storyId;
    }

    public function transition(int $storyId, string $target): void
    {
        $story = Story::findOrFail($storyId);
        $status = StoryStatus::tryFrom($target);

        if (! $status) {
            return;
        }

        try {
            $story->transitionTo($status, auth()->id());
        } catch (\DomainException $e) {
            $this->addError('transition', $e->getMessage());
        }
    }

    /**
     * Generate (or with $force, regenerate) the story's one-time visual asset pack.
     * Synchronous by design — this is a rare admin action reviewed on the spot; the
     * button disables via wire:loading, and the !$force early-return absorbs a
     * double-submit on first generation.
     */
    public function generatePack(int $storyId, bool $force = false): void
    {
        abort_unless(auth()->user()?->isAdmin() === true, 403);

        $story = Story::findOrFail($storyId);

        if (! empty($story->assets) && ! $force) {
            return; // pack already exists — double-click / stale-UI guard
        }

        try {
            $assets = app(StoryAssetPackGenerator::class)->generate($story);
            $story->update(['assets' => $assets]);
        } catch (\Throwable $e) {
            $this->addError('assetPack', 'Asset pack generation failed: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.admin.story-review')
            ->layout('components.layouts.app', ['title' => 'Story Catalog Review']);
    }
}

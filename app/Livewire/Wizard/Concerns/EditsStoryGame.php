<?php

declare(strict_types=1);

namespace App\Livewire\Wizard\Concerns;

use App\Models\Scene;

/**
 * Story-game (spel-verhaal) authoring for the Step 3 inspector: the per-option
 * "Game effects" panel (meter deltas, game-master consequence line, historical note,
 * choice label) and the lesson-level "Class meters" panel (rename label/icon/start).
 *
 * Everything is scoped to the mounted lesson (Step 3's mount() already aborts 403 for
 * non-owners; scene lookups go through $this->lesson->scenes()). Meter KEYS are immutable
 * in v1 — the LLM invents them and the player + branch_effects deltas reference them.
 * Plan: docs/superpowers/plans/2026-07-09-game-story-lesson.md (Segment 4).
 */
trait EditsStoryGame
{
    /** Matches BuildLessonOutline::METER_DELTA_LIMIT — a single choice may swing a meter ±25. */
    private const STORY_DELTA_LIMIT = 25;

    private const STORY_LINE_MAX = 240;

    private const STORY_CHOICE_LABEL_MAX = 60;

    private const STORY_METER_LABEL_MAX = 24;

    /** Single emoji — some (flags, skin tones) span several code points. */
    private const STORY_METER_ICON_MAX = 8;

    private const STORY_METER_START_MIN = 0;

    private const STORY_METER_START_MAX = 100;

    private const STORY_OPTION_ROLES = ['option_a', 'option_b'];

    /** @var array{deltas?: array<string,int>, consequence_line?: string, historical_note?: string, branch_choice_label?: string} */
    public array $storyEffectsDraft = [];

    /** The option scene the effects draft was loaded for — guards status-poll re-selects. */
    public ?int $storyEffectsSceneId = null;

    /** @var list<array{key: string, label: string, icon: string, start: int}> */
    public array $metersDraft = [];

    /** Lazy-load the meters draft once the lesson is mounted (runs before every render). */
    public function renderingEditsStoryGame(mixed $view = null, mixed $data = null): void
    {
        if (isset($this->lesson) && $this->isStoryGame() && $this->metersDraft === []) {
            $this->loadMetersDraft();
        }
    }

    public function showsMetersPanel(): bool
    {
        return $this->isStoryGame() && $this->storyMeters() !== [];
    }

    public function showsGameEffectsPanel(?Scene $scene): bool
    {
        return $scene !== null
            && $this->isStoryGame()
            && in_array($scene->branch_role, self::STORY_OPTION_ROLES, true);
    }

    /**
     * The lesson's meters as generated (key/label/icon/start). Malformed entries are
     * skipped so a hand-edited game_config can never break the inspector.
     *
     * @return list<array{key: string, label: string, icon: string, start: int}>
     */
    public function storyMeters(): array
    {
        $meters = ($this->lesson->game_config ?? [])['meters'] ?? [];

        return collect(is_array($meters) ? $meters : [])
            ->filter(fn ($m) => is_array($m) && is_string($m['key'] ?? null) && trim($m['key']) !== '')
            ->map(fn (array $m) => [
                'key' => trim($m['key']),
                'label' => trim((string) ($m['label'] ?? $m['key'])),
                'icon' => trim((string) ($m['icon'] ?? '')),
                'start' => (int) ($m['start'] ?? self::STORY_METER_START_MIN),
            ])
            ->values()
            ->all();
    }

    /**
     * Keep the drafts in step with the selected scene. Only reloads on a real scene
     * switch — a status-poll re-select of the same scene keeps unsaved edits intact.
     */
    public function syncStoryGameDraftFor(Scene $scene): void
    {
        if (! $this->isStoryGame()) {
            return;
        }

        if (! in_array($scene->branch_role, self::STORY_OPTION_ROLES, true)) {
            $this->storyEffectsSceneId = null;
            $this->storyEffectsDraft = [];

            return;
        }

        if ($this->storyEffectsSceneId === $scene->id && $this->storyEffectsDraft !== []) {
            return;
        }

        $this->storyEffectsSceneId = $scene->id;
        $this->loadStoryEffectsDraft($scene);
    }

    /** Blade entry point: autosave the effects draft for the option scene it was loaded for. */
    public function saveStoryEffectsDraft(): void
    {
        if (! $this->storyEffectsSceneId) {
            return;
        }
        $this->saveBranchEffects($this->storyEffectsSceneId, $this->storyEffectsDraft);
    }

    /**
     * Persist a branch option's game effects into scene.config['branch_effects'],
     * PRESERVING every other config key (texts, annotations, …). Deltas are clamped to
     * ±STORY_DELTA_LIMIT and restricted to the lesson's meter keys; strings are trimmed,
     * tag-stripped and capped — the payload arrives from the browser and is never trusted.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveBranchEffects(int $sceneId, array $payload): void
    {
        if (! $this->isStoryGame()) {
            return;
        }

        $scene = $this->lesson->scenes()->findOrFail($sceneId);
        if (! in_array($scene->branch_role, self::STORY_OPTION_ROLES, true)) {
            return;
        }

        $rawDeltas = is_array($payload['deltas'] ?? null) ? $payload['deltas'] : [];
        $deltas = [];
        foreach (array_column($this->storyMeters(), 'key') as $key) {
            $value = is_numeric($rawDeltas[$key] ?? null) ? (int) $rawDeltas[$key] : 0;
            $deltas[$key] = max(-self::STORY_DELTA_LIMIT, min(self::STORY_DELTA_LIMIT, $value));
        }

        $effects = [
            'deltas' => $deltas,
            'consequence_line' => $this->cleanStoryText($payload['consequence_line'] ?? '', self::STORY_LINE_MAX),
            'historical_note' => $this->cleanStoryText($payload['historical_note'] ?? '', self::STORY_LINE_MAX),
        ];
        $choiceLabel = $this->cleanStoryText($payload['branch_choice_label'] ?? '', self::STORY_CHOICE_LABEL_MAX);

        $scene->config = array_merge($scene->config ?? [], ['branch_effects' => $effects]);
        $scene->branch_choice_label = $choiceLabel !== '' ? $choiceLabel : null;
        $scene->save();

        // Keep the inspector snapshot in step — saveSelected() persists the whole config
        // from the snapshot, so a stale copy would clobber what we just wrote.
        if ($this->selectedSceneId === $sceneId && $this->selectedScene !== null) {
            $this->selectedScene['config'] = $scene->config;
        }
        // Reflect server-side clamps/trims back into the draft so the UI shows what stuck.
        if ($this->storyEffectsSceneId === $sceneId) {
            $this->storyEffectsDraft = $effects + ['branch_choice_label' => $choiceLabel];
        }
    }

    /** Blade entry point: autosave the meters draft. */
    public function saveMetersFromDraft(): void
    {
        $this->saveMeters($this->metersDraft);
    }

    /**
     * Persist the lesson-level meters (label/icon/start only — keys immutable, no
     * add/remove in v1). Rows match by position; a blank label or icon keeps the old
     * value. Every other game_config key (roles, fail_threshold, print_pack) survives.
     *
     * @param  array<int, mixed>  $payload
     */
    public function saveMeters(array $payload): void
    {
        if (! $this->isStoryGame()) {
            return;
        }

        $existing = $this->storyMeters();
        if ($existing === []) {
            return;
        }

        $clean = [];
        foreach ($existing as $i => $meter) {
            $row = is_array($payload[$i] ?? null) ? $payload[$i] : [];
            $label = $this->cleanStoryText($row['label'] ?? '', self::STORY_METER_LABEL_MAX);
            $icon = $this->cleanStoryText($row['icon'] ?? '', self::STORY_METER_ICON_MAX);
            $start = is_numeric($row['start'] ?? null)
                ? max(self::STORY_METER_START_MIN, min(self::STORY_METER_START_MAX, (int) $row['start']))
                : $meter['start'];

            $clean[] = [
                'key' => $meter['key'],
                'label' => $label !== '' ? $label : $meter['label'],
                'icon' => $icon !== '' ? $icon : $meter['icon'],
                'start' => $start,
            ];
        }

        $this->lesson->update([
            'game_config' => array_merge($this->lesson->game_config ?? [], ['meters' => $clean]),
        ]);
        $this->lesson->refresh();
        $this->loadMetersDraft();
    }

    private function isStoryGame(): bool
    {
        return $this->lesson->game_type === 'story_game';
    }

    private function loadStoryEffectsDraft(Scene $scene): void
    {
        $effects = ($scene->config ?? [])['branch_effects'] ?? [];
        $deltas = [];
        foreach (array_column($this->storyMeters(), 'key') as $key) {
            $deltas[$key] = (int) ($effects['deltas'][$key] ?? 0);
        }

        $this->storyEffectsDraft = [
            'deltas' => $deltas,
            'consequence_line' => (string) ($effects['consequence_line'] ?? ''),
            'historical_note' => (string) ($effects['historical_note'] ?? ''),
            'branch_choice_label' => (string) ($scene->branch_choice_label ?? ''),
        ];
    }

    private function loadMetersDraft(): void
    {
        $this->metersDraft = $this->storyMeters();
    }

    private function cleanStoryText(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $max);
    }
}

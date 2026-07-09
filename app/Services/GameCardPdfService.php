<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\Scene;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Renders the printable classroom game pack (spelpakket) for a story-game lesson
 * via dompdf: team role cards, manschappen tokens, event cards, a meter poster and
 * generic team badges. Physical companion to the digital spel-verhaal.
 *
 * Roles are per TEAM (never per individual student — Alfonso pilot feedback).
 *
 * Accepts both persisted lessons (relations queried) and in-memory lessons with
 * relations attached through setRelation(), mirroring LessonPdfService.
 */
class GameCardPdfService
{
    private const TOKEN_COUNT = 20;

    private const METER_STEPS = 10;

    /** Generic team badge palette (hex — dompdf has no theme tokens). */
    private const TEAM_BADGES = [
        ['name' => 'Amber',   'color' => '#f59e0b'],
        ['name' => 'Sky',     'color' => '#0ea5e9'],
        ['name' => 'Emerald', 'color' => '#10b981'],
        ['name' => 'Rose',    'color' => '#f43f5e'],
        ['name' => 'Violet',  'color' => '#8b5cf6'],
        ['name' => 'Orange',  'color' => '#f97316'],
    ];

    private const EVENT_EXCERPT_CHARS = 200;

    /**
     * Full game pack PDF.
     *
     * @return string raw PDF bytes
     */
    public function pack(Lesson $lesson): string
    {
        return Pdf::loadView('pdf.game-pack.pack', [
            'lesson' => $lesson,
            'roles' => $this->roles($lesson),
            'meters' => $this->meters($lesson),
            'events' => $this->events($lesson),
            'tokens' => range(1, self::TOKEN_COUNT),
            'teamBadges' => self::TEAM_BADGES,
            'meterSteps' => self::METER_STEPS,
            'generatedAt' => now(),
        ])->output();
    }

    /**
     * Team role cards from game_config.roles (one card per role — held by a team, not a child).
     *
     * @return list<array{title: string, flavor: string, power: string}>
     */
    private function roles(Lesson $lesson): array
    {
        return collect($lesson->game_config['roles'] ?? [])
            ->filter(fn ($role): bool => is_array($role) && filled($role['title'] ?? null))
            ->map(fn (array $role): array => [
                'title' => trim((string) $role['title']),
                'flavor' => trim((string) ($role['flavor'] ?? '')),
                'power' => trim((string) ($role['power'] ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * Meters from game_config.meters (label/icon/start), for the tracker poster.
     *
     * @return list<array{label: string, icon: string, start: int}>
     */
    private function meters(Lesson $lesson): array
    {
        return collect($lesson->game_config['meters'] ?? [])
            ->filter(fn ($meter): bool => is_array($meter) && filled($meter['label'] ?? null))
            ->map(fn (array $meter): array => [
                'label' => trim((string) $meter['label']),
                'icon' => trim((string) ($meter['icon'] ?? '')),
                'start' => max(0, min(100, (int) ($meter['start'] ?? 0))),
            ])
            ->values()
            ->all();
    }

    /**
     * One event card per branch group: the question scene's script excerpt plus
     * both options' choice labels (for offline play without a screen).
     *
     * @return list<array{group: int, question: string, options: list<string>}>
     */
    private function events(Lesson $lesson): array
    {
        return $this->branchScenes($lesson)
            ->groupBy(fn (Scene $scene): int => (int) $scene->branch_group)
            ->sortKeys()
            ->map(function (Collection $group, int $number): array {
                $question = $group->firstWhere('branch_role', 'question');
                $options = $group
                    ->filter(fn (Scene $scene): bool => in_array($scene->branch_role, ['option_a', 'option_b'], true))
                    ->sortBy('branch_role')
                    ->map(fn (Scene $scene): string => trim((string) $scene->branch_choice_label))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'group' => $number,
                    'question' => $this->excerpt($question?->script_segment),
                    'options' => $options,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Branch-carrying scenes (question + options), loaded relation-safe.
     *
     * @return Collection<int, Scene>
     */
    private function branchScenes(Lesson $lesson): Collection
    {
        $scenes = $lesson->relationLoaded('scenes')
            ? $lesson->scenes
            : $lesson->scenes()->get();

        return $scenes
            ->filter(fn (Scene $scene): bool => $scene->branch_group !== null && filled($scene->branch_role))
            ->values();
    }

    private function excerpt(?string $text): string
    {
        $clean = trim((string) $text);

        return mb_strlen($clean) > self::EVENT_EXCERPT_CHARS
            ? mb_substr($clean, 0, self::EVENT_EXCERPT_CHARS).'…'
            : $clean;
    }
}

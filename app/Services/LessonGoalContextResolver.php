<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Support\HistoryTaxonomy;
use App\Services\Support\HistoryTopics;

final class LessonGoalContextResolver
{
    /**
     * @return array{
     *     canonical_subject: ?string,
     *     description: ?string,
     *     region: ?string,
     *     region_label: ?string,
     *     era: ?string,
     *     period_start: ?int,
     *     period_end: ?int
     * }
     */
    public function resolve(string $goal, ?string $parsedTopic): array
    {
        $knownSubject = $this->knownSubject($goal.' '.($parsedTopic ?? ''));
        if ($knownSubject !== null) {
            return $knownSubject;
        }

        foreach (array_filter([$parsedTopic, $goal]) as $query) {
            $match = HistoryTopics::search($query)[0] ?? null;
            if ($match === null) {
                continue;
            }

            [$periodStart, $periodEnd] = $this->yearsFromEra($match['era']);

            return [
                'canonical_subject' => $match['topic'],
                'description' => null,
                'region' => $match['region'],
                'region_label' => $this->regionLabel($match['region']),
                'era' => $match['era'],
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ];
        }

        return [
            'canonical_subject' => $parsedTopic,
            'description' => null,
            'region' => null,
            'region_label' => null,
            'era' => null,
            'period_start' => null,
            'period_end' => null,
        ];
    }

    /**
     * @return array{
     *     canonical_subject: string,
     *     description: string,
     *     region: string,
     *     region_label: string,
     *     era: string,
     *     period_start: int,
     *     period_end: int
     * }|null
     */
    private function knownSubject(string $text): ?array
    {
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        $isEightyYearsWar = preg_match(
            '/\b(?:(?:80|eighty)\s+years?\s+war|tachtigjarige\s+oorlog)\b/u',
            $normalized,
        ) === 1;

        if (! $isEightyYearsWar) {
            return null;
        }

        return [
            'canonical_subject' => "The Eighty Years' War",
            'description' => 'the Dutch revolt against Spanish rule',
            'region' => 'netherlands',
            'region_label' => 'the Netherlands',
            'era' => 'Opstand & Tachtigjarige Oorlog (1568–1648)',
            'period_start' => 1568,
            'period_end' => 1648,
        ];
    }

    /** @return array{0: ?int, 1: ?int} */
    private function yearsFromEra(string $era): array
    {
        if (preg_match('/\((-?\d{1,4})\s*[–-]\s*(-?\d{1,4})\)/u', $era, $matches) !== 1) {
            return [null, null];
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function regionLabel(string $regionValue): string
    {
        foreach (HistoryTaxonomy::regions() as $region) {
            if ($region['value'] === $regionValue) {
                return $region['label'];
            }
        }

        return ucfirst(str_replace('-', ' ', $regionValue));
    }
}

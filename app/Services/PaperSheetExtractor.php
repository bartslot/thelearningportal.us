<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Support\NameMatcher;

/**
 * Reads photographed answer sheets with the vision model and prepares review rows.
 * Synchronous by design for v1 (a class's photos take seconds); wrap in a queued
 * job later if uploads grow. Every row is teacher-editable before import — the
 * model proposes, the teacher disposes.
 */
final class PaperSheetExtractor
{
    public function __construct(private readonly OpenAiLlmService $llm) {}

    /**
     * @param  list<string>  $imageDataUrls  base64 data URLs of the uploaded photos
     * @param  list<string>  $roster  classroom member display names for fuzzy matching
     * @return list<array{raw_name: string, matched_name: ?string, answers: list<?string>}>
     */
    public function extract(array $imageDataUrls, int $questionCount, array $roster): array
    {
        $rows = [];
        foreach ($imageDataUrls as $dataUrl) {
            $raw = $this->llm->describeImage($dataUrl, $this->instruction($questionCount));
            $parsed = json_decode((string) preg_replace('/^```(?:json)?|```$/m', '', trim($raw)), true);
            foreach ((array) ($parsed['sheets'] ?? []) as $sheet) {
                if (! is_array($sheet)) {
                    continue;
                }
                $answers = array_map(
                    fn ($a) => in_array($a, ['A', 'B', 'C', 'D'], true) ? $a : null,
                    array_pad(array_slice((array) ($sheet['answers'] ?? []), 0, $questionCount), $questionCount, null),
                );
                $rawName = trim((string) ($sheet['name'] ?? ''));
                $rows[] = [
                    'raw_name' => $rawName,
                    'matched_name' => NameMatcher::match($rawName, $roster),
                    'answers' => $answers,
                ];
            }
        }

        return $rows;
    }

    private function instruction(int $questionCount): string
    {
        return "These are filled-in quiz answer sheets. Each sheet has a handwritten name (top right) "
            ."and {$questionCount} questions, each with bubbles A B C D — the filled/circled bubble is "
            ."the answer. Return ONLY JSON: {\"sheets\":[{\"name\":\"...\",\"answers\":[\"A\"|\"B\"|\"C\"|\"D\"|null,...]}]} "
            ."with exactly {$questionCount} answers per sheet (null when unreadable/blank). "
            .'Multiple sheets may appear in one photo — return each separately.';
    }
}

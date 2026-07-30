<?php

declare(strict_types=1);

namespace App\Services\Support;

final class ToneRecommender
{
    /**
     * All available tones with icon component name, label, and description.
     *
     * @return array<string, array{icon: string, label: string, description: string}>
     */
    public static function tones(): array
    {
        return [
            'storytelling' => [
                'icon'        => 'book-open',
                'label'       => __('Storytelling'),
                'description' => __('Narrative-driven with characters and plot. Makes history feel like a film.'),
            ],
            'dramatic' => [
                'icon'        => 'megaphone',
                'label'       => __('Dramatic'),
                'description' => __('Epic, vivid, high-stakes delivery. Brings battles and turning points to life.'),
            ],
            'humorous' => [
                'icon'        => 'face-smile',
                'label'       => __('Humorous'),
                'description' => __('Light and fun. Uses wit and playful comparisons to make facts stick.'),
            ],
            'socratic' => [
                'icon'        => 'magnifying-glass',
                'label'       => __('Socratic'),
                'description' => __('Question-led inquiry. The avatar poses questions to spark critical thinking.'),
            ],
            'inspiring' => [
                'icon'        => 'sparkles',
                'label'       => __('Inspiring'),
                'description' => __('Motivational and uplifting. Focuses on legacy, courage, and lessons for today.'),
            ],
            'academic' => [
                'icon'        => 'academic-cap',
                'label'       => __('Academic'),
                'description' => __('Formal and precise. Best for older students or exam prep contexts.'),
            ],
            'casual' => [
                'icon'        => 'chat-bubble-left-right',
                'label'       => __('Casual'),
                'description' => __('Friendly and conversational. Feels like a knowledgeable friend explaining history.'),
            ],
        ];
    }

    /**
     * Returns tone keys recommended for a given grade level string (e.g. "Age 10").
     *
     * @return string[]
     */
    public static function recommend(string $gradeLevel): array
    {
        $age = self::extractAge($gradeLevel);

        return match (true) {
            $age === null         => ['storytelling'],
            $age <= 8             => ['storytelling', 'humorous', 'inspiring'],
            $age >= 9 && $age <= 12 => ['storytelling', 'dramatic'],
            $age >= 13 && $age <= 17 => ['dramatic', 'socratic', 'inspiring'],
            $age >= 18            => ['academic', 'socratic', 'dramatic'],
            default               => ['storytelling'],
        };
    }

    private static function extractAge(string $input): ?int
    {
        if (preg_match('/(\d+)/', $input, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Support;

final class GradeBandStyleRecommender
{
    /** @return string[] */
    public static function recommend(string $gradeLevel): array
    {
        $grade = self::extractGradeNumber($gradeLevel);

        return match (true) {
            $grade === null                        => ['realistic'],
            $grade <= 3                            => ['animation'],
            $grade >= 4  && $grade <= 6            => ['comic', 'sketched'],
            $grade >= 7  && $grade <= 9            => ['painted', 'cinematic'],
            $grade >= 10                           => ['realistic', 'cinematic'],
            default                                => ['realistic'],
        };
    }

    private static function extractGradeNumber(string $input): ?int
    {
        $lower = strtolower(trim($input));
        if ($lower === 'k' || str_starts_with($lower, 'kindergarten')) {
            return 0;
        }
        if (preg_match('/(\d+)/', $lower, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Support\GradeBandStyleRecommender;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GradeBandStyleRecommenderTest extends TestCase
{
    #[DataProvider('earlyGrades')]
    public function test_recommends_animation_for_kindergarten_through_grade_3(string $grade): void
    {
        $this->assertContains('animation', GradeBandStyleRecommender::recommend($grade));
    }

    public static function earlyGrades(): array
    {
        return [['K'], ['kindergarten'], ['1st grade'], ['2nd grade'], ['3rd grade']];
    }

    #[DataProvider('middleGrades')]
    public function test_recommends_comic_or_sketched_for_grades_4_6(string $grade): void
    {
        $rec = GradeBandStyleRecommender::recommend($grade);
        $this->assertTrue(in_array('comic', $rec, true) || in_array('sketched', $rec, true));
    }

    public static function middleGrades(): array
    {
        return [['4th grade'], ['5th grade'], ['6th grade']];
    }

    #[DataProvider('upperGrades')]
    public function test_recommends_painted_or_cinematic_for_grades_7_9(string $grade): void
    {
        $rec = GradeBandStyleRecommender::recommend($grade);
        $this->assertTrue(in_array('painted', $rec, true) || in_array('cinematic', $rec, true));
    }

    public static function upperGrades(): array
    {
        return [['7th grade'], ['8th grade'], ['9th grade']];
    }

    #[DataProvider('highGrades')]
    public function test_recommends_realistic_or_cinematic_for_grades_10_12(string $grade): void
    {
        $rec = GradeBandStyleRecommender::recommend($grade);
        $this->assertTrue(in_array('realistic', $rec, true) || in_array('cinematic', $rec, true));
    }

    public static function highGrades(): array
    {
        return [['10th grade'], ['11th grade'], ['12th grade']];
    }

    public function test_falls_back_to_realistic_for_unknown_grade_strings(): void
    {
        $this->assertSame(['realistic'], GradeBandStyleRecommender::recommend('adult'));
    }
}

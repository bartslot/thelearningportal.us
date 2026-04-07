<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LessonStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lesson>
 */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'teacher_id'        => User::factory()->teacher(),
            'title'             => null,
            'topic'             => fake()->randomElement(['Julius Caesar', 'World War II', 'The French Revolution', 'Ancient Egypt']),
            'subject'           => 'history',
            'grade_level'       => fake()->randomElement(['6th grade', '7th grade', '8th grade', '9th grade', '10th grade']),
            'tone'              => fake()->randomElement(['calm and reflective', 'urgent and direct', 'dramatic', null]),
            'details'           => null,
            'historical_figure' => null,
            'status'            => LessonStatus::Pending,
            'generation_attempts' => 0,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LessonStatus::Ready,
            'script' => fake()->paragraphs(3, true),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LessonStatus::Ready,
            'script' => fake()->paragraphs(3, true),
        ])->afterCreating(function (\App\Models\Lesson $lesson) {
            $lesson->update(['status' => LessonStatus::Published]);
        });
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'        => LessonStatus::Failed,
            'error_message' => 'LLM failed to generate script',
        ]);
    }

    public function withQuiz(int $count = 4): static
    {
        return $this->afterCreating(function (\App\Models\Lesson $lesson) use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $lesson->quizQuestions()->create([
                    'order'         => $i,
                    'question'      => fake()->sentence() . '?',
                    'options'       => ['Option A', 'Option B', 'Option C', 'Option D'],
                    'correct_index' => 0,
                    'explanation'   => fake()->sentence(),
                    'points'        => 10,
                ]);
            }
        });
    }
}

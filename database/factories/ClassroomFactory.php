<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Classroom>
 */
class ClassroomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'teacher_id'  => User::factory()->teacher(),
            'name'        => fake()->randomElement(['Grade 8 History', 'Period 3', 'AP History', 'World History']) . ' ' . fake()->year(),
            'grade_level' => fake()->randomElement(['6', '7', '8', '9', '10']),
            'is_active'   => true,
        ];
    }
}

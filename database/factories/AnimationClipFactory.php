<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AnimationClipFactory extends Factory
{
    public function definition(): array
    {
        $category = $this->faker->randomElement(['idle', 'presenting', 'greeting']);

        return [
            'name'       => $this->faker->words(2, true),
            'category'   => $category,
            'fbx_path'   => "avatars/animations/{$category}/test.fbx",
            'sort_order' => 0,
        ];
    }

    public function idle(): static
    {
        return $this->state(['category' => 'idle']);
    }

    public function presenting(): static
    {
        return $this->state(['category' => 'presenting']);
    }

    public function greeting(): static
    {
        return $this->state(['category' => 'greeting']);
    }
}

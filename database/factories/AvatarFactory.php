<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Avatar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Avatar>
 */
class AvatarFactory extends Factory
{
    protected $model = Avatar::class;

    public function definition(): array
    {
        return [
            'name'              => $this->faker->name(),
            'short_name'        => $this->faker->firstName(),
            'avatar_title'      => 'Professor',
            'slug'              => $this->faker->unique()->slug(),
            'description'       => $this->faker->sentence(),
            'portrait_path'     => null,
            'subject'           => 'history',
            'voice_provider'    => 'kokoro',
            'voice_id'          => 'bm_george',
            'voice_speed'       => 1.0,
            'voice_pitch'       => 1.0,
            'is_active'         => true,
            'sort_order'        => 0,
            'presentation_mode' => 'framed',
            'age'               => 35,
            'gender'            => 'male',
            'emotion_style'     => 'auto',
            'expressiveness'    => 1.2,
            'speaking_speed'    => 1.0,
            'frame_background'  => '#0f172a',
        ];
    }
}

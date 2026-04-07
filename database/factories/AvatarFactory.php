<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SpriteStatus;
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
            'name'          => $this->faker->name(),
            'short_name'    => $this->faker->firstName(),
            'avatar_title'  => 'Professor',
            'slug'          => $this->faker->unique()->slug(),
            'description'   => $this->faker->sentence(),
            'portrait_path' => null,
            'subject'       => 'history',
            'voice_provider'=> 'kokoro',
            'voice_id'      => 'bm_george',
            'voice_speed'   => 1.0,
            'voice_pitch'   => 1.0,
            'is_active'     => true,
            'sort_order'    => 0,
            'sprite_status' => SpriteStatus::Pending,
        ];
    }
}

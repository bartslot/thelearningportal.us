<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SvgAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SvgAsset>
 */
class SvgAssetFactory extends Factory
{
    protected $model = SvgAsset::class;

    public function definition(): array
    {
        $title = fake()->words(2, true);

        return [
            'user_id' => User::factory(),
            'source' => 'commons',
            'source_ref' => ucfirst($title).'.svg',
            'source_url' => 'https://commons.wikimedia.org/wiki/File:'.ucfirst($title).'.svg',
            'title' => ucfirst($title),
            'license' => fake()->randomElement(['Public domain', 'CC0', 'CC BY-SA 4.0']),
            'attribution' => fake()->optional()->name(),
            'svg_path' => 'svg-assets/1/'.fake()->uuid().'.svg',
            'width' => 512,
            'height' => 512,
            'view_box' => '0 0 512 512',
        ];
    }
}

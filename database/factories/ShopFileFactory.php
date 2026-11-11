<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\ShopFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopFile>
 */
class ShopFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sha = hash('sha256', fake()->uuid());

        return [
            'shop_id' => Shop::factory(),
            'media_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'kind' => 'preview',
            'sha256' => $sha,
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'path' => "shop-files/test/{$sha}.jpg",
        ];
    }
}

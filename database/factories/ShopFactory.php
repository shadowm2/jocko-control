<?php

namespace Database\Factories;

use App\Enums\ShopStatus;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'owner_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('09#########'),
            'address' => fake()->address(),
            'timezone' => 'Asia/Tehran',
            'status' => ShopStatus::Active,
            'notes' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => ShopStatus::Suspended]);
    }
}

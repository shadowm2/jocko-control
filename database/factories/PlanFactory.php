<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'price' => fake()->numberBetween(1, 50) * 100_000,
            'billing_days' => 30,
            'max_devices' => 3,
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(['max_devices' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

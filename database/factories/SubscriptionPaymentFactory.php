<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->subDays(30)->startOfDay();

        return [
            'shop_id' => Shop::factory(),
            'amount' => 1_000_000,
            'period_start' => $start->toDateString(),
            'period_end' => $start->addDays(29)->toDateString(),
            'note' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ShopDevice;
use App\Models\SyncEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncEvent>
 */
class SyncEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = ['status' => 'received', 'total' => fake()->numberBetween(1, 1000)];

        return [
            'event_id' => fake()->uuid(),
            'device_id' => ShopDevice::factory(),
            'shop_id' => fn (array $attributes) => ShopDevice::findOrFail($attributes['device_id'])->shop_id,
            'event_type' => 'repair_order.updated',
            'aggregate_type' => 'repair_order',
            'aggregate_id' => fake()->uuid(),
            'base_version' => null,
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'occurred_at' => now()->subMinute(),
            'received_at' => now(),
        ];
    }
}

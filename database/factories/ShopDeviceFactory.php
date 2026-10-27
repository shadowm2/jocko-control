<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\Shop;
use App\Models\ShopDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopDevice>
 */
class ShopDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'name' => 'Device '.fake()->unique()->numerify('###'),
            'app_version' => null,
            'status' => DeviceStatus::Active,
            'last_seen_at' => null,
            'last_sync_at' => null,
            'last_sync_error' => null,
            'metadata' => null,
        ];
    }

    public function seenAt(\DateTimeInterface|string $when): static
    {
        return $this->state(['last_seen_at' => $when]);
    }

    public function revoked(): static
    {
        return $this->state(['status' => DeviceStatus::Revoked, 'revoked_at' => now()]);
    }

    public function withSyncError(string $error = 'Local database locked'): static
    {
        return $this->state([
            'last_sync_error' => $error,
            'last_sync_error_at' => now(),
        ]);
    }
}

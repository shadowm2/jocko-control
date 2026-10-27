<?php

namespace Tests\Concerns;

use App\Models\Shop;
use App\Models\ShopDevice;
use App\Services\DeviceManagementService;
use App\Services\IssuedDevice;
use Illuminate\Support\Str;

trait CreatesDevices
{
    /**
     * Create a device with a real Sanctum token.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function issueDevice(?Shop $shop = null, array $attributes = []): IssuedDevice
    {
        $device = ShopDevice::factory()
            ->for($shop ?? Shop::factory()->create())
            ->create($attributes);

        $token = $device->createToken('device-access', [DeviceManagementService::ABILITY]);

        return new IssuedDevice($device, $token->plainTextToken);
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(IssuedDevice $issued): array
    {
        return ['Authorization' => 'Bearer '.$issued->plainTextToken, 'Accept' => 'application/json'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'repair_order.created',
            'aggregate_type' => 'repair_order',
            'aggregate_id' => (string) Str::uuid(),
            'base_version' => 0,
            'payload' => ['customer' => 'Ali', 'total' => 1250000],
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ], $overrides);
    }
}

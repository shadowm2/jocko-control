<?php

namespace Database\Factories;

use App\Enums\CommandStatus;
use App\Enums\CommandType;
use App\Models\RemoteCommand;
use App\Models\ShopDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemoteCommand>
 */
class RemoteCommandFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => ShopDevice::factory(),
            'shop_id' => fn (array $attributes) => ShopDevice::findOrFail($attributes['device_id'])->shop_id,
            'command_type' => CommandType::SyncNow,
            'payload' => null,
            'requested_by' => null,
            'status' => CommandStatus::Queued,
            'requested_at' => now(),
            'expires_at' => now()->addDay(),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => CommandStatus::Completed,
            'completed_at' => now(),
            'result' => ['ok' => true],
        ]);
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ShopDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShopDevice
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'app_version' => $this->app_version,
            'database_schema_version' => $this->database_schema_version,
            'shop' => [
                'id' => $this->shop->id,
                'name' => $this->shop->name,
                'slug' => $this->shop->slug,
                'timezone' => $this->shop->timezone,
            ],
            'config' => [
                'heartbeat_interval_seconds' => config('jocko.api.heartbeat_interval_seconds'),
                'command_poll_interval_seconds' => config('jocko.api.command_poll_interval_seconds'),
                'max_events_per_batch' => config('jocko.sync.max_events_per_batch'),
                'max_event_payload_bytes' => config('jocko.sync.max_payload_bytes'),
                'pictures' => ($this->shop?->keepsOriginalPictures() ?? true) ? 'original' : 'preview',
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }
}

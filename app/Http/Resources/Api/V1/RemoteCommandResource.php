<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RemoteCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RemoteCommand
 */
class RemoteCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->command_type->value,
            'payload' => $this->payload ?? (object) [],
            'status' => $this->status->value,
            'requested_at' => $this->requested_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}

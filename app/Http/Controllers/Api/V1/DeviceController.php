<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\HeartbeatRequest;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Models\ShopDevice;
use App\Services\DeviceManagementService;
use App\Services\RemoteCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceManagementService $devices,
        private readonly RemoteCommandService $commands,
    ) {}

    public function me(Request $request): DeviceResource
    {
        return new DeviceResource($this->device($request)->load('shop'));
    }

    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $device = $this->devices->recordHeartbeat($this->device($request), $request->validated());

        return response()->json(['data' => [
            'received_at' => $device->last_seen_at->toIso8601String(),
            'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            'pending_commands' => $this->commands->pendingCount($device),
        ]]);
    }

    private function device(Request $request): ShopDevice
    {
        /** @var ShopDevice */
        return $request->user();
    }
}

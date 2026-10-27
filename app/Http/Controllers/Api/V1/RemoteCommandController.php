<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcknowledgeCommandRequest;
use App\Http\Resources\Api\V1\RemoteCommandResource;
use App\Models\ShopDevice;
use App\Services\RemoteCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RemoteCommandController extends Controller
{
    public function __construct(private readonly RemoteCommandService $commands) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('jocko.commands.max_deliver_per_poll')],
        ])['limit'] ?? (int) config('jocko.commands.default_deliver_per_poll');

        /** @var ShopDevice $device */
        $device = $request->user();

        return RemoteCommandResource::collection($this->commands->deliverable($device, (int) $limit));
    }

    public function acknowledge(AcknowledgeCommandRequest $request, string $command): JsonResponse
    {
        /** @var ShopDevice $device */
        $device = $request->user();

        $acknowledgement = $this->commands->acknowledge(
            $device,
            $command,
            $request->commandStatus(),
            $request->resultPayload(),
        );

        return response()->json([
            'data' => [
                'id' => $acknowledgement->command->id,
                'status' => $acknowledgement->command->status->value,
                'completed_at' => $acknowledgement->command->completed_at?->toIso8601String(),
                'already_acknowledged' => $acknowledgement->alreadyAcknowledged,
            ],
        ]);
    }
}

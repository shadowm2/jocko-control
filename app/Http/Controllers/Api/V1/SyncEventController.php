<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PushEventsRequest;
use App\Models\ShopDevice;
use App\Services\SyncEventIngestionService;
use Illuminate\Http\JsonResponse;

class SyncEventController extends Controller
{
    public function __construct(private readonly SyncEventIngestionService $ingestion) {}

    public function store(PushEventsRequest $request): JsonResponse
    {
        /** @var ShopDevice $device */
        $device = $request->user();

        $result = $this->ingestion->ingest($device, $request->events());

        return response()->json([
            'data' => $result->toArray(),
            'meta' => [
                'received' => count($request->events()),
                'accepted' => $result->acceptedCount(),
                'already_accepted' => count($result->alreadyAccepted),
                'rejected' => count($result->rejected),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreFileRequest;
use App\Models\ShopDevice;
use App\Services\ShopFileStore;
use Illuminate\Http\JsonResponse;

class FileController extends Controller
{
    public function __construct(private readonly ShopFileStore $files) {}

    public function store(StoreFileRequest $request): JsonResponse
    {
        /** @var ShopDevice $device */
        $device = $request->user();

        // The shop comes from the authenticated device, never from the request.
        $result = $this->files->store(
            $device->shop_id,
            (int) $request->validated('media_id'),
            (string) $request->validated('sha256'),
            $request->file('file'),
        );

        return response()->json(['data' => [
            'media_id' => $result['file']->media_id,
            'sha256' => $result['file']->sha256,
            'status' => $result['stored'] ? 'stored' : 'already_stored',
        ]], $result['stored'] ? 201 : 200);
    }
}

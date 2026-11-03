<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\ShopFile;
use App\Services\ShopFileStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopFileController extends Controller
{
    /**
     * A shop's preview image, for signed-in administrators only (route middleware).
     * The browser must treat it as an image and nothing else.
     */
    public function show(Shop $shop, int $media): StreamedResponse
    {
        $file = ShopFile::query()->where('shop_id', $shop->getKey())->where('media_id', $media)->firstOrFail();

        abort_unless(Storage::disk(ShopFileStore::DISK)->exists($file->path), 404);

        return Storage::disk(ShopFileStore::DISK)->response($file->path, null, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => '"'.$file->sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}

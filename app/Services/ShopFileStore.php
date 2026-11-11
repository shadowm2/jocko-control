<?php

namespace App\Services;

use App\Models\ShopFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the preview images shops send for their `media` records.
 *
 * Files are private: they sit on the local (non-public) disk, named by shop
 * and content hash, and are only ever served to signed-in administrators
 * (ShopFileController). A shop never chooses a path, and one shop can neither
 * read nor replace another shop's file.
 */
class ShopFileStore
{
    public const DISK = 'local';

    /**
     * @return array{file: ShopFile, stored: bool} stored is false when the same bytes were already there
     */
    public function store(string $shopId, int $mediaId, string $sha256, UploadedFile $upload, string $kind = ShopFile::PREVIEW): array
    {
        $existing = ShopFile::query()->where('shop_id', $shopId)->where('media_id', $mediaId)->first();

        if ($existing !== null && $existing->sha256 === $sha256 && Storage::disk(self::DISK)->exists($existing->path)) {
            if ($existing->kind !== $kind) {
                $existing->update(['kind' => $kind]);
            }

            return ['file' => $existing, 'stored' => false];
        }

        [$mime, $extension] = match ($upload->getMimeType()) {
            'image/png' => ['image/png', 'png'],
            'image/webp' => ['image/webp', 'webp'],
            default => ['image/jpeg', 'jpg'],
        };

        $path = Storage::disk(self::DISK)->putFileAs("shop-files/{$shopId}", $upload, "{$sha256}.{$extension}");

        $file = ShopFile::query()->updateOrCreate(
            ['shop_id' => $shopId, 'media_id' => $mediaId],
            ['kind' => $kind, 'sha256' => $sha256, 'mime_type' => $mime, 'size' => $upload->getSize(), 'path' => $path],
        );

        // The image was replaced: drop the old bytes unless another record shares them.
        if ($existing !== null && $existing->path !== $path) {
            $this->deleteBytes($existing->path);
        }

        return ['file' => $file, 'stored' => true];
    }

    /**
     * The shop deleted the image: forget the preview too.
     */
    public function forget(string $shopId, int $mediaId): void
    {
        $file = ShopFile::query()->where('shop_id', $shopId)->where('media_id', $mediaId)->first();

        if ($file === null) {
            return;
        }

        $file->delete();
        $this->deleteBytes($file->path);
    }

    private function deleteBytes(string $path): void
    {
        if (! ShopFile::query()->where('path', $path)->exists()) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}

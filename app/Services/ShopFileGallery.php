<?php

namespace App\Services;

use App\Models\ShopFile;
use App\Models\ShopRecord;

/**
 * Finds the pictures a shop attached to its records: the `media` records that
 * point at them (`owner_type` + `owner_id`) which also have a preview here.
 */
class ShopFileGallery
{
    /**
     * @param  list<int>  $localIds  ids of records of $entity
     * @return array<int, list<int>> record local id => ids of its pictures, in the shop's order
     */
    public function forRecords(string $shopId, string $entity, array $localIds): array
    {
        if ($localIds === [] || $entity === 'media') {
            return [];
        }

        $media = ShopRecord::query()
            ->forShop($shopId)
            ->ofEntity('media')
            ->alive()
            ->whereIn('parent_id', $localIds)
            ->where('data->owner_type', $entity)
            ->get();

        if ($media->isEmpty()) {
            return [];
        }

        $available = array_flip($this->available($shopId, array_values($media->map(fn (ShopRecord $picture): int => $picture->local_id)->all())));

        $sorted = $media->sortBy(fn (ShopRecord $picture): array => [(int) $picture->field('order'), $picture->local_id]);

        $gallery = [];

        foreach ($sorted as $picture) {
            $owner = $picture->field('owner_id');

            if (is_int($owner) && isset($available[$picture->local_id])) {
                $gallery[$owner][] = $picture->local_id;
            }
        }

        return $gallery;
    }

    /**
     * @param  list<int>  $mediaIds
     * @return list<int> those of them that have a preview here
     */
    public function available(string $shopId, array $mediaIds): array
    {
        return array_values(ShopFile::query()->where('shop_id', $shopId)->whereIn('media_id', $mediaIds)->get()->map(fn (ShopFile $file): int => $file->media_id)->all());
    }

    /**
     * Which of these pictures are only a downsized preview here, not the original.
     *
     * @param  list<int>  $mediaIds
     * @return list<int>
     */
    public function previews(string $shopId, array $mediaIds): array
    {
        $ids = ShopFile::query()->where('shop_id', $shopId)->whereIn('media_id', $mediaIds)->where('kind', '!=', ShopFile::ORIGINAL)->pluck('media_id');

        return array_values(array_map('intval', $ids->all()));
    }

    public function has(string $shopId, int $mediaId): bool
    {
        return ShopFile::query()->where('shop_id', $shopId)->where('media_id', $mediaId)->exists();
    }
}

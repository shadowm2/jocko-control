<?php

namespace App\Services;

use App\Models\ShopRecord;

/**
 * Resolves the foreign keys of mirrored records to the records they point at,
 * with one query per referenced entity instead of one per cell.
 */
class ShopRecordReferences
{
    public function __construct(private readonly ShopDataCatalog $catalog) {}

    /**
     * @param  iterable<ShopRecord>  $records  all of the same entity
     * @return array<string, array<int, ShopRecord>> entity => local id => record (id, entity, local_id, label, removed_at)
     */
    public function resolve(string $shopId, string $entity, iterable $records): array
    {
        $wanted = [];

        foreach ($records as $record) {
            foreach (array_keys($this->catalog->fields($entity)) as $field) {
                $target = $this->catalog->referencedEntity($entity, $field);
                $value = $record->field($field);

                if ($target !== null && is_int($value)) {
                    $wanted[$target][$value] = $value;
                }
            }
        }

        $resolved = [];

        foreach ($wanted as $target => $localIds) {
            $resolved[$target] = ShopRecord::query()
                ->forShop($shopId)
                ->ofEntity($target)
                ->whereIn('local_id', array_values($localIds))
                ->get(['id', 'entity', 'local_id', 'label', 'removed_at'])
                ->keyBy('local_id')
                ->all();
        }

        return $resolved;
    }
}

<?php

namespace App\Livewire\Shops\Data;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\RefreshesLive;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Services\ShopDataCatalog;
use App\Services\ShopFileGallery;
use App\Services\ShopRecordReferences;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only detail of one mirrored record with the records that belong to it.
 */
class Show extends Component
{
    use AuthorizesAdmin, RefreshesLive;

    private const CHILD_LIMIT = 100;

    #[Locked]
    public string $shopId;

    #[Locked]
    public string $entity;

    #[Locked]
    public int $recordId;

    public function mount(Shop $shop, string $entity, ShopRecord $record, ShopDataCatalog $catalog): void
    {
        abort_unless($catalog->has($entity), 404);
        abort_unless($record->shop_id === $shop->getKey() && $record->entity === $entity, 404);

        $this->shopId = $shop->getKey();
        $this->entity = $entity;
        $this->recordId = $record->getKey();
    }

    public function render(ShopDataCatalog $catalog, ShopRecordReferences $references, ShopFileGallery $gallery): View
    {
        $record = ShopRecord::query()->forShop($this->shopId)->findOrFail($this->recordId);

        $children = [];

        foreach ($catalog->children($this->entity) as $childEntity => $foreignKey) {
            $query = ShopRecord::query()
                ->forShop($this->shopId)
                ->ofEntity($childEntity)
                ->alive()
                ->when(
                    $catalog->parentField($childEntity) === $foreignKey,
                    fn ($query) => $query->where('parent_id', $record->local_id),
                    fn ($query) => $query->where('data->'.$foreignKey, $record->local_id),
                );

            $rows = (clone $query)->orderBy('local_id')->limit(self::CHILD_LIMIT)->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $children[$childEntity] = [
                'rows' => $rows,
                'total' => (clone $query)->count(),
                'columns' => array_values(array_diff($catalog->columns($childEntity), [$foreignKey])),
                'references' => $references->resolve($this->shopId, $childEntity, $rows),
            ];
        }

        return view('livewire.shops.data.show', [
            'shop' => Shop::query()->findOrFail($this->shopId),
            'record' => $record,
            'fields' => array_keys($catalog->fields($this->entity)),
            'references' => $references->resolve($this->shopId, $this->entity, [$record]),
            'children' => $children,
            'pictures' => $this->entity === 'media'
                ? ($gallery->has($this->shopId, $record->local_id) ? [$record->local_id] : [])
                : ($gallery->forRecords($this->shopId, $this->entity, [$record->local_id])[$record->local_id] ?? []),
            'summary' => $this->entity === 'order' ? $this->orderSummary($record) : null,
        ])->layout('components.layouts.app', ['title' => $record->label]);
    }

    /**
     * What the customer owes, from the mirrored items and payments.
     *
     * @return array{total: float, paid: float, balance: float}
     */
    private function orderSummary(ShopRecord $order): array
    {
        $sum = fn (string $entity): float => (float) ShopRecord::query()
            ->forShop($this->shopId)
            ->ofEntity($entity)
            ->alive()
            ->where('parent_id', $order->local_id)
            ->sum('amount');

        $total = $sum('order_item');
        $paid = $sum('payment');

        return ['total' => $total, 'paid' => $paid, 'balance' => $total - $paid];
    }
}

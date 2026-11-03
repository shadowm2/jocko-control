<?php

namespace App\Livewire\Shops\Data;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\RefreshesLive;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Services\ShopDataCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only entry point to a shop's mirrored data: what exists, per entity.
 */
class Overview extends Component
{
    use AuthorizesAdmin, RefreshesLive;

    #[Locked]
    public string $shopId;

    public function mount(Shop $shop): void
    {
        $this->shopId = $shop->getKey();
    }

    #[Computed]
    public function shop(): Shop
    {
        return Shop::query()->findOrFail($this->shopId);
    }

    public function render(ShopDataCatalog $catalog): View
    {
        $counts = ShopRecord::query()
            ->forShop($this->shopId)
            ->alive()
            ->selectRaw('entity, count(*) as total')
            ->groupBy('entity')
            ->pluck('total', 'entity');

        $lastUpdate = ShopRecord::query()->forShop($this->shopId)->max('source_occurred_at');

        // Per entity: how many were deleted in the shop, and when one last changed.
        $details = ShopRecord::query()
            ->forShop($this->shopId)
            ->selectRaw('entity, sum(removed_at is not null) as removed, max(source_occurred_at) as last_at')
            ->groupBy('entity')
            ->get()
            ->mapWithKeys(fn (ShopRecord $row): array => [(string) $row->getAttribute('entity') => [
                'removed' => (int) $row->getAttribute('removed'),
                'last_at' => $row->getAttribute('last_at') ? Carbon::parse((string) $row->getAttribute('last_at'), 'UTC') : null,
            ]]);

        $latest = ShopRecord::query()
            ->forShop($this->shopId)
            ->orderByDesc('source_occurred_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return view('livewire.shops.data.overview', [
            'shop' => $this->shop,
            'groups' => $catalog->groups(),
            'counts' => $counts,
            'total' => $counts->sum(),
            'details' => $details,
            'latest' => $latest,
            'busiest' => max(1, (int) $counts->max()),
            'lastUpdate' => $lastUpdate ? Carbon::parse($lastUpdate, 'UTC') : null,
        ])->layout('components.layouts.app', ['title' => __('ui.shop_data.title')]);
    }
}

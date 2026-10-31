<?php

namespace App\Livewire\Shops;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Services\ShopDashboardMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A shop's dashboard as the shop itself shows it, read-only.
 */
class Dashboard extends Component
{
    use AuthorizesAdmin;

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

    public function render(ShopDashboardMetrics $metrics): View
    {
        $lastUpdate = ShopRecord::query()->forShop($this->shopId)->max('source_occurred_at');

        return view('livewire.shops.dashboard', [
            'shop' => $this->shop,
            'lastUpdate' => $lastUpdate ? Carbon::parse($lastUpdate, 'UTC') : null,
            ...$metrics->for($this->shop),
        ])->layout('components.layouts.app', ['title' => __('ui.shop_dashboard.title')]);
    }
}

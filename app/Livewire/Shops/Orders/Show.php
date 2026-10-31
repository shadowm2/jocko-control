<?php

namespace App\Livewire\Shops\Orders;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Services\ShopOrders;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One order laid out like the shop's invoice, read-only.
 */
class Show extends Component
{
    use AuthorizesAdmin;

    #[Locked]
    public string $shopId;

    #[Locked]
    public int $recordId;

    public function mount(Shop $shop, ShopRecord $record): void
    {
        abort_unless($record->shop_id === $shop->getKey() && $record->entity === 'order', 404);

        $this->shopId = $shop->getKey();
        $this->recordId = $record->getKey();
    }

    public function render(ShopOrders $orders): View
    {
        $shop = Shop::query()->findOrFail($this->shopId);
        $order = ShopRecord::query()->forShop($shop)->ofEntity('order')->findOrFail($this->recordId);

        return view('livewire.shops.orders.show', [
            'shop' => $shop,
            'invoice' => $orders->invoice($shop, $order),
            'order' => $order,
        ])->layout('components.layouts.app', ['title' => $order->label]);
    }
}

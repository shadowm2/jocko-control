<?php

namespace App\Livewire\Shops\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\RefreshesLive;
use App\Models\Shop;
use App\Services\ShopOrders;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A shop's orders with customer, car, total and payment status, read-only.
 */
class Index extends Component
{
    use AuthorizesAdmin, RefreshesLive, WithPagination;

    #[Locked]
    public string $shopId;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $paymentStatus = '';

    #[Url(except: '')]
    public string $createdFrom = '';

    #[Url(except: '')]
    public string $createdTo = '';

    public function mount(Shop $shop): void
    {
        $this->shopId = $shop->getKey();
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'paymentStatus', 'createdFrom', 'createdTo');
        $this->resetPage();
    }

    public function render(ShopOrders $orders): View
    {
        $shop = Shop::query()->findOrFail($this->shopId);

        // simplePaginate avoids a COUNT(*) over every order of a busy shop.
        $page = $orders->query($shop, [
            'search' => $this->search,
            'status' => $this->status,
            'payment_status' => $this->paymentStatus,
            'created_from' => $this->createdFrom,
            'created_to' => $this->createdTo,
        ])->simplePaginate(25);

        return view('livewire.shops.orders.index', [
            'shop' => $shop,
            'page' => $page,
            'rows' => $orders->rows($shop, $page->items()),
            'statuses' => OrderStatus::cases(),
            'paymentStatuses' => PaymentStatus::cases(),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->paymentStatus !== '' || $this->createdFrom !== '' || $this->createdTo !== '',
        ])->layout('components.layouts.app', ['title' => __('ui.shop_orders.title')]);
    }
}

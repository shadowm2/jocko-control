<?php

namespace App\Livewire\Shops;

use App\Enums\DeviceStatus;
use App\Events\ShopChanged;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\RefreshesLive;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Models\SyncEvent;
use App\Services\ShopDataCatalog;
use App\Services\ShopManagementService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesAdmin, RefreshesLive;

    #[Locked]
    public string $shopId;

    /**
     * @return list<string>
     */
    protected function liveScopes(): array
    {
        return [ShopChanged::DATA, ShopChanged::COMMANDS];
    }

    public function mount(Shop $shop): void
    {
        $this->shopId = $shop->getKey();
    }

    #[Computed]
    public function shop(): Shop
    {
        return Shop::query()->findOrFail($this->shopId);
    }

    #[On('devices-changed')]
    #[On('commands-changed')]
    public function refresh(): void
    {
        unset($this->shop);
    }

    public function suspend(ShopManagementService $shops): void
    {
        $shops->suspend($this->shop, auth()->user());

        unset($this->shop);
        Flux::modal('suspend-shop')->close();
        Flux::toast(text: __('ui.shops.suspended_done'), variant: 'warning');
        $this->dispatch('shop-status-changed');
    }

    public function reactivate(ShopManagementService $shops): void
    {
        $shops->reactivate($this->shop, auth()->user());

        unset($this->shop);
        Flux::modal('reactivate-shop')->close();
        Flux::toast(text: __('ui.shops.reactivated_done'), variant: 'success');
        $this->dispatch('shop-status-changed');
    }

    public function allowRestore(ShopManagementService $shops): void
    {
        $admin = auth()->user();
        abort_if($admin === null, 403);

        $shops->allowRestore($this->shop(), $admin);

        unset($this->shop);
        Flux::modal('allow-restore')->close();
        Flux::toast(text: __('ui.shops.restore_allowed_done'), variant: 'success');
    }

    public function render(ShopDataCatalog $catalog): View
    {
        $shop = $this->shop;

        $lastSyncAt = $shop->devices()->max('last_sync_at');

        $events = SyncEvent::query()
            ->where('shop_id', $shop->getKey())
            ->with('device:id,name')
            ->orderByDesc('received_at')->orderByDesc('id')
            ->limit(10)
            ->get();

        // The mirrored record each event is about, when there is one to open.
        $records = ShopRecord::query()
            ->forShop($shop)
            ->whereIn('aggregate_id', $events->pluck('aggregate_id'))
            ->get(['id', 'entity', 'aggregate_id'])
            ->filter(fn (ShopRecord $record): bool => $catalog->has($record->entity))
            ->keyBy('aggregate_id');

        return view('livewire.shops.show', [
            'shop' => $shop->loadMissing('plan'),
            'activeDevices' => $shop->devices()->where('status', DeviceStatus::Active->value)->count(),
            'lastSyncAt' => $lastSyncAt ? Carbon::parse($lastSyncAt, 'UTC') : null,
            'events' => $events,
            'eventRecords' => $records,
            'audit' => AuditLog::query()
                ->where('shop_id', $shop->getKey())
                ->with(['user:id,name', 'device:id,name'])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(10)
                ->get(),
        ])->layout('components.layouts.app', ['title' => $shop->name]);
    }
}

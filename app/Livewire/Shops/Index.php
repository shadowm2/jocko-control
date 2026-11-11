<?php

namespace App\Livewire\Shops;

use App\Enums\DeviceStatus;
use App\Enums\ShopStatus;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\Shop;
use App\Models\ShopDevice;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use AuthorizesAdmin, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function render(): View
    {
        $cutoff = ShopDevice::onlineCutoff();
        $status = ShopStatus::tryFrom($this->status);

        $shops = Shop::query()
            ->search($this->search)
            ->with('plan:id,name')
            ->when($status, fn (Builder $query) => $query->where('status', $status->value))
            ->withCount([
                'devices as active_devices_count' => fn (Builder $query) => $query->where('status', DeviceStatus::Active->value),
                'devices as online_devices_count' => fn (Builder $query) => $query
                    ->where('status', DeviceStatus::Active->value)
                    ->where('last_seen_at', '>=', $cutoff),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);

        return view('livewire.shops.index', ['shops' => $shops])
            ->layout('components.layouts.app', ['title' => __('ui.shops.title')]);
    }
}

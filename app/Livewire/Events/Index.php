<?php

namespace App\Livewire\Events;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\Shop;
use App\Models\SyncEvent;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use AuthorizesAdmin, WithPagination;

    #[Url(except: '')]
    public string $shop = '';

    #[Url(except: '')]
    public string $search = '';

    #[Locked]
    public ?int $viewingId = null;

    public function updatedShop(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('shop', 'search');
        $this->resetPage();
    }

    public function view(int $eventId): void
    {
        $this->viewingId = SyncEvent::query()->findOrFail($eventId)->getKey();
        Flux::modal('event-payload')->show();
    }

    public function render(): View
    {
        $search = trim($this->search);

        // simplePaginate avoids a COUNT(*) over a table that grows without bound.
        $events = SyncEvent::query()
            ->with(['shop:id,name', 'device:id,name'])
            ->when($this->shop !== '', fn (Builder $query) => $query->where('shop_id', $this->shop))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('event_type', 'like', addcslashes($search, '\\%_').'%')
                    ->orWhere('event_id', $search)
                    ->orWhere('aggregate_id', $search);
            }))
            ->orderByDesc('received_at')->orderByDesc('id')
            ->simplePaginate(25);

        return view('livewire.events.index', [
            'events' => $events,
            'shops' => Shop::query()->orderBy('name')->get(['id', 'name']),
            'viewing' => $this->viewingId ? SyncEvent::query()->with(['shop:id,name', 'device:id,name'])->find($this->viewingId) : null,
        ])->layout('components.layouts.app', ['title' => __('ui.events.title')]);
    }
}

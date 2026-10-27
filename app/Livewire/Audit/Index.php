<?php

namespace App\Livewire\Audit;

use App\Enums\AuditAction;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\AuditLog;
use App\Models\Shop;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use AuthorizesAdmin, WithPagination;

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $shop = '';

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedShop(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('action', 'shop');
        $this->resetPage();
    }

    public function render(): View
    {
        $action = AuditAction::tryFrom($this->action);

        $logs = AuditLog::query()
            ->with(['user:id,name', 'shop:id,name', 'device:id,name'])
            ->when($action, fn (Builder $query) => $query->where('action', $action->value))
            ->when($this->shop !== '', fn (Builder $query) => $query->where('shop_id', $this->shop))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate(30);

        return view('livewire.audit.index', [
            'logs' => $logs,
            'shops' => Shop::query()->orderBy('name')->get(['id', 'name']),
        ])->layout('components.layouts.app', ['title' => __('ui.audit.title')]);
    }
}

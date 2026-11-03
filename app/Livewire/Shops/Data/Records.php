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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only, searchable list of one entity of a shop.
 */
class Records extends Component
{
    use AuthorizesAdmin, RefreshesLive, WithPagination;

    #[Locked]
    public string $shopId;

    #[Locked]
    public string $entity;

    #[Url(except: '')]
    public string $search = '';

    /** @var 'alive'|'removed'|'all' */
    #[Url(except: 'alive')]
    public string $scope = 'alive';

    public function mount(Shop $shop, string $entity, ShopDataCatalog $catalog): void
    {
        abort_unless($catalog->has($entity), 404);

        $this->shopId = $shop->getKey();
        $this->entity = $entity;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedScope(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'scope');
        $this->resetPage();
    }

    public function render(ShopDataCatalog $catalog, ShopRecordReferences $references, ShopFileGallery $gallery): View
    {
        $search = trim($this->search);

        $records = ShopRecord::query()
            ->forShop($this->shopId)
            ->ofEntity($this->entity)
            ->when($this->scope === 'alive', fn (Builder $query) => $query->alive())
            ->when($this->scope === 'removed', fn (Builder $query) => $query->whereNotNull('removed_at'))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('label', 'like', '%'.addcslashes($search, '\\%_').'%');

                if (ctype_digit($search)) {
                    $query->orWhere('local_id', (int) $search);
                }
            }))
            ->orderByDesc('local_id')
            ->simplePaginate(25);

        return view('livewire.shops.data.records', [
            'shop' => Shop::query()->findOrFail($this->shopId),
            'columns' => $catalog->columns($this->entity),
            'records' => $records,
            'references' => $references->resolve($this->shopId, $this->entity, $records),
            'thumbnails' => $this->thumbnails($gallery, $records),
        ])->layout('components.layouts.app', ['title' => __('ui.shop_data.entities.'.$this->entity)]);
    }

    /**
     * The first picture of each listed record, as record local id => media id.
     *
     * @param  Paginator<int, ShopRecord>  $records
     * @return array<int, int>
     */
    private function thumbnails(ShopFileGallery $gallery, Paginator $records): array
    {
        $ids = array_values($records->getCollection()->map(fn (ShopRecord $record): int => $record->local_id)->all());

        if ($this->entity === 'media') {
            $available = $gallery->available($this->shopId, $ids);

            return array_combine($available, $available) ?: [];
        }

        return array_map(fn (array $pictures): int => $pictures[0], $gallery->forRecords($this->shopId, $this->entity, $ids));
    }
}

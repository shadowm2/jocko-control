@php use App\Support\Format; @endphp

<div class="space-y-6">
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2" :href="route('shops.data', $shop)" wire:navigate>{{ __('ui.shop_data.title') }} — {{ $shop->name }}</flux:button>
        <flux:heading size="xl" level="1">{{ __('ui.shop_data.entities.'.$entity) }}</flux:heading>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-64 flex-1">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('ui.shop_data.search_placeholder')" clearable />
        </div>
        <div class="w-48">
            <flux:select wire:model.live="scope">
                <flux:select.option value="alive">{{ __('ui.shop_data.scope.alive') }}</flux:select.option>
                <flux:select.option value="removed">{{ __('ui.shop_data.scope.removed') }}</flux:select.option>
                <flux:select.option value="all">{{ __('ui.shop_data.scope.all') }}</flux:select.option>
            </flux:select>
        </div>
        @if ($search !== '' || $scope !== 'alive')
            <flux:button variant="ghost" wire:click="resetFilters">{{ __('ui.common.reset') }}</flux:button>
        @endif
    </div>

    <flux:card class="overflow-x-auto p-0!" wire:loading.class="opacity-60" wire:target="search,scope,nextPage,previousPage">
        @if ($records->isEmpty())
            <div class="px-6 py-14 text-center">
                <flux:icon.inbox class="mx-auto mb-3 size-10 text-zinc-300" />
                <flux:heading>{{ __('ui.shop_data.empty_entity') }}</flux:heading>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ui.shop_data.record') }}</flux:table.column>
                    @foreach ($columns as $column)
                        <flux:table.column>{{ \Illuminate\Support\Facades\Lang::has('ui.shop_data.fields.'.$column) ? __('ui.shop_data.fields.'.$column) : $column }}</flux:table.column>
                    @endforeach
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($records as $record)
                        <flux:table.row :key="$record->id">
                            <flux:table.cell variant="strong">
                                <a class="hover:underline" href="{{ route('shops.data.show', [$shop, $entity, $record]) }}" wire:navigate>{{ $record->label }}</a>
                                @if ($record->removed_at)
                                    <flux:badge size="sm" color="red" class="ms-1">{{ __('ui.shop_data.removed') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            @foreach ($columns as $column)
                                <flux:table.cell><x-shop-value :entity="$entity" :field="$column" :data="$record->data" :shop="$shop" :references="$references" /></flux:table.cell>
                            @endforeach
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" :href="route('shops.data.show', [$shop, $entity, $record])" wire:navigate>{{ __('ui.common.details') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    {{ $records->links() }}
</div>

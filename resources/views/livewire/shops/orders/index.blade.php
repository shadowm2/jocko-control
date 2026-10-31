@php use App\Support\Format; @endphp

<div class="space-y-6" wire:poll.60s.visible>
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2" :href="route('shops.index')" wire:navigate>{{ __('ui.shops.title') }}</flux:button>
        <flux:heading size="xl" level="1">{{ $shop->name }}</flux:heading>
        <flux:subheading>{{ __('ui.shop_orders.subtitle') }}</flux:subheading>
    </div>

    <x-shop-tabs :shop="$shop" current="orders" />

    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-64 flex-1">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('ui.shop_orders.search_placeholder')" clearable />
        </div>
        <div class="w-44">
            <flux:select wire:model.live="status">
                <flux:select.option value="">{{ __('ui.shop_orders.status') }}: {{ __('ui.common.all') }}</flux:select.option>
                @foreach ($statuses as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-44">
            <flux:select wire:model.live="paymentStatus">
                <flux:select.option value="">{{ __('ui.shop_orders.payment') }}: {{ __('ui.common.all') }}</flux:select.option>
                @foreach ($paymentStatuses as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-40">
            <flux:input type="date" wire:model.live="createdFrom" :label="__('ui.shop_orders.created_from')" dir="ltr" />
        </div>
        <div class="w-40">
            <flux:input type="date" wire:model.live="createdTo" :label="__('ui.shop_orders.created_to')" dir="ltr" />
        </div>
        @if ($filtered)
            <flux:button variant="ghost" wire:click="resetFilters">{{ __('ui.common.reset') }}</flux:button>
        @endif
    </div>

    <flux:card class="table-card overflow-x-auto p-0!" wire:loading.class="opacity-60" wire:target="search,status,paymentStatus,createdFrom,createdTo,nextPage,previousPage">
        @if ($rows === [])
            <div class="px-6 py-14 text-center">
                <flux:icon.shopping-bag class="mx-auto mb-3 size-10 text-zinc-300" />
                <flux:heading>{{ $filtered ? __('ui.shop_orders.empty_filtered') : __('ui.shop_orders.empty') }}</flux:heading>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ui.shop_orders.number') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shop_orders.customer') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shop_orders.car') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shop_orders.warehouse') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shop_orders.date') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shop_orders.items_and_total') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shop_orders.status') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shop_orders.payment') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($rows as $row)
                        <flux:table.row :key="$row['record']->id">
                            <flux:table.cell variant="strong">
                                <a class="hover:underline" dir="ltr" href="{{ route('shops.orders.show', [$shop, $row['record']]) }}" wire:navigate>{{ $row['number'] }}</a>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $row['customer'] ?? __('ui.common.none') }}
                                @if ($row['mobile'])
                                    <div class="text-xs text-zinc-500" dir="ltr">{{ $row['mobile'] }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $row['car'] ?? __('ui.common.none') }}</flux:table.cell>
                            <flux:table.cell>{{ $row['warehouse'] ?? __('ui.common.none') }}</flux:table.cell>
                            <flux:table.cell><x-datetime :value="$row['date']" /></flux:table.cell>
                            <flux:table.cell>
                                <div class="tabular-nums">{{ Format::number($row['total']) }}</div>
                                <div class="text-xs text-zinc-500">{{ __('ui.shop_orders.lines', ['count' => Format::number($row['lines'])]) }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($row['status'])
                                    <flux:badge size="sm" :color="$row['status']->color()">{{ $row['status']->label() }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$row['payment']->color()">{{ $row['payment']->label() }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    {{ $page->links() }}
</div>

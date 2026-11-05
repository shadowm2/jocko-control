@php
    use App\Enums\OrderStatus;
    use App\Support\Format;

    $stats = [
        [
            'label' => __('ui.shop_dashboard.total_orders'),
            'value' => Format::number($orderCount),
            'hint' => Format::number($statusCounts[OrderStatus::Pending->value] ?? 0).' '.OrderStatus::Pending->label(),
            'icon' => 'shopping-bag',
            'href' => route('shops.orders', $shop),
        ],
        [
            'label' => __('ui.shop_dashboard.order_value'),
            'value' => Format::number($orderValue),
            'hint' => __('ui.shop_dashboard.excluding_cancelled'),
            'icon' => 'banknotes',
            'href' => route('shops.orders', $shop),
        ],
        [
            'label' => __('ui.shop_dashboard.open_purchases'),
            'value' => Format::number($openPurchases),
            'hint' => __('ui.shop_dashboard.ordered_or_partial'),
            'icon' => 'shopping-cart',
            'href' => route('shops.data.entity', [$shop, 'purchase']),
        ],
        [
            'label' => __('ui.shop_dashboard.customers'),
            'value' => Format::number($customerCount),
            'hint' => __('ui.shop_dashboard.registered_customers'),
            'icon' => 'users',
            'href' => route('shops.data.entity', [$shop, 'user']),
        ],
    ];

    $barColors = [
        OrderStatus::Pending->value => 'bg-zinc-400',
        OrderStatus::InProgress->value => 'bg-amber-500',
        OrderStatus::Completed->value => 'bg-green-500',
        OrderStatus::Cancelled->value => 'bg-red-500',
    ];
@endphp

<div class="space-y-6" wire:poll.60s.visible>
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2" :href="route('shops.index')" wire:navigate>{{ __('ui.shops.title') }}</flux:button>
        <flux:heading size="xl" level="1">{{ $shop->name }}</flux:heading>
        <flux:subheading>
            {{ __('ui.shop_dashboard.subtitle') }}
            @if ($lastUpdate)
                · {{ __('ui.shop_dashboard.last_update') }}: <x-datetime :value="$lastUpdate" :ago="true" />
            @endif
        </flux:subheading>
    </div>

    <x-shop-tabs :shop="$shop" current="dashboard" />

    <flux:callout icon="eye" :text="__('ui.shop_data.read_only_notice')" />

    @if ($lastUpdate === null)
        <flux:card class="px-6 py-14 text-center">
            <flux:icon.circle-stack class="mx-auto mb-3 size-10 text-zinc-300" />
            <flux:heading>{{ __('ui.shop_data.empty') }}</flux:heading>
            <flux:text class="mt-1">{{ __('ui.shop_data.empty_hint') }}</flux:text>
        </flux:card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $stat)
                <a href="{{ $stat['href'] }}" wire:navigate class="group min-w-0">
                    <flux:card class="h-full space-y-2 transition group-hover:bg-zinc-50 dark:group-hover:bg-zinc-700/50">
                        <div class="flex items-center justify-between text-zinc-500">
                            <flux:text>{{ $stat['label'] }}</flux:text>
                            <flux:icon :name="$stat['icon']" class="size-5" />
                        </div>
                        <div class="text-2xl font-semibold tabular-nums" data-stat="{{ $loop->index }}">{{ $stat['value'] }}</div>
                        <flux:text size="sm">{{ $stat['hint'] }}</flux:text>
                    </flux:card>
                </a>
            @endforeach
        </div>

        @if (array_sum($monthSales) + array_sum($monthPurchases) > 0)
            <div class="grid gap-4 xl:grid-cols-2">
                <flux:card class="min-w-0 space-y-3">
                    <div>
                        <flux:heading>{{ __('ui.shop_dashboard.sales_vs_purchases') }}</flux:heading>
                        <flux:text size="sm">{{ __('ui.shop_dashboard.last_6_months') }}</flux:text>
                    </div>
                    <x-bar-chart :labels="$monthLabels" :series="[__('ui.shop_dashboard.sales') => $monthSales, __('ui.shop_dashboard.purchases') => $monthPurchases]" />
                </flux:card>

                <flux:card class="min-w-0 space-y-3">
                    <div>
                        <flux:heading>{{ __('ui.shop_dashboard.daily_sales') }}</flux:heading>
                        <flux:text size="sm">{{ __('ui.shop_dashboard.last_30_days') }}</flux:text>
                    </div>
                    <x-bar-chart :labels="$dayLabels" :series="[__('ui.shop_dashboard.sales') => $daySales]" :label-every="5" />
                </flux:card>
            </div>
        @endif

        @if ($orderCount)
            <flux:card class="space-y-3">
                <flux:heading>{{ __('ui.shop_dashboard.orders_by_status') }}</flux:heading>
                <div class="flex h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                    @foreach (OrderStatus::cases() as $status)
                        @if ($count = $statusCounts[$status->value] ?? 0)
                            <div class="{{ $barColors[$status->value] }}" style="width: {{ $count / $orderCount * 100 }}%" title="{{ $status->label() }}"></div>
                        @endif
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (OrderStatus::cases() as $status)
                        <div class="flex items-center gap-2">
                            <flux:badge :color="$status->color()" size="sm">{{ $status->label() }}</flux:badge>
                            <span class="tabular-nums">{{ Format::number($statusCounts[$status->value] ?? 0) }}</span>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        <div class="grid gap-4 xl:grid-cols-3">
            <flux:card class="min-w-0 space-y-3 xl:col-span-2">
                <div class="flex items-center justify-between">
                    <flux:heading>{{ __('ui.shop_dashboard.recent_orders') }}</flux:heading>
                    <flux:button :href="route('shops.orders', $shop)" size="sm" variant="ghost" wire:navigate>{{ __('ui.shop_dashboard.view_all') }}</flux:button>
                </div>

                @forelse ($recentOrders as $row)
                    <a href="{{ route('shops.orders.show', [$shop, $row['record']]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5" data-recent-order>
                        <div class="min-w-0">
                            <div class="flex gap-1 font-medium"><span class="shrink-0" dir="ltr">{{ $row['number'] }}</span><span class="truncate">· {{ $row['customer'] }}</span></div>
                            <flux:text size="sm" class="truncate">{{ $row['car'] }} · {{ $row['warehouse'] }}</flux:text>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="text-sm tabular-nums">{{ Format::number($row['total']) }}</span>
                            @if ($row['status'])
                                <flux:badge :color="$row['status']->color()" size="sm">{{ $row['status']->label() }}</flux:badge>
                            @endif
                        </div>
                    </a>
                @empty
                    <flux:text class="py-8 text-center">{{ __('ui.shop_dashboard.no_orders') }}</flux:text>
                @endforelse
            </flux:card>

            <flux:card class="min-w-0 space-y-3">
                <div class="flex items-center justify-between">
                    <flux:heading>
                        {{ __('ui.shop_dashboard.low_stock') }}
                        @if ($lowStockCount)
                            <flux:badge color="red" size="sm" class="ms-1">{{ Format::number($lowStockCount) }}</flux:badge>
                        @endif
                    </flux:heading>
                    <flux:button :href="route('shops.data.entity', [$shop, 'warehouse_item'])" size="sm" variant="ghost" wire:navigate>{{ __('ui.shop_dashboard.view_all') }}</flux:button>
                </div>

                @forelse ($lowStock as $stock)
                    <a href="{{ route('shops.data.show', [$shop, 'warehouse_item', $stock['record']]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $stock['item'] }}</div>
                            <flux:text size="sm" class="truncate">{{ $stock['warehouse'] }}</flux:text>
                        </div>
                        <div class="shrink-0 text-end text-sm tabular-nums">
                            <div class="font-medium text-red-600 dark:text-red-400">{{ Format::number((float) $stock['quantity']) }}</div>
                            <flux:text size="sm">{{ __('ui.shop_dashboard.minimum') }}: {{ Format::number((float) $stock['minimum']) }}</flux:text>
                        </div>
                    </a>
                @empty
                    <flux:text class="py-8 text-center">{{ __('ui.shop_dashboard.well_stocked') }}</flux:text>
                @endforelse
            </flux:card>
        </div>

        <flux:card class="space-y-3">
            <div class="flex items-center justify-between">
                <flux:heading>{{ __('ui.shop_dashboard.recent_purchases') }}</flux:heading>
                <flux:button :href="route('shops.data.entity', [$shop, 'purchase'])" size="sm" variant="ghost" wire:navigate>{{ __('ui.shop_dashboard.view_all') }}</flux:button>
            </div>

            @forelse ($recentPurchases as $purchase)
                <a href="{{ route('shops.data.show', [$shop, 'purchase', $purchase['record']]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5">
                    <div class="min-w-0">
                        <div class="flex gap-1 font-medium"><span class="shrink-0" dir="ltr">{{ $purchase['number'] }}</span><span class="truncate">· {{ $purchase['supplier'] }}</span></div>
                        <flux:text size="sm" class="truncate">{{ $purchase['warehouse'] }}</flux:text>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-sm tabular-nums">{{ Format::number($purchase['total']) }}</span>
                        <flux:badge size="sm">{{ \Illuminate\Support\Facades\Lang::has('ui.shop_data.values.purchase.status.'.$purchase['record']->field('status')) ? __('ui.shop_data.values.purchase.status.'.$purchase['record']->field('status')) : $purchase['record']->field('status') }}</flux:badge>
                    </div>
                </a>
            @empty
                <flux:text class="py-8 text-center">{{ __('ui.shop_dashboard.no_purchases') }}</flux:text>
            @endforelse
        </flux:card>

        <flux:card class="space-y-3" data-cheques>
            <div class="flex items-center justify-between">
                <flux:heading>
                    {{ __('ui.shop_dashboard.cheques') }}
                    @if ($overdueChequeCount)
                        <flux:badge color="red" size="sm" class="ms-1">{{ Format::number($overdueChequeCount) }} {{ __('ui.shop_dashboard.overdue') }}</flux:badge>
                    @endif
                    <flux:text size="sm" class="font-normal">
                        {{ __('ui.shop_dashboard.cheques_pending', ['count' => Format::number($pendingChequeCount), 'total' => Format::number($pendingChequeTotal)]) }}
                    </flux:text>
                </flux:heading>
                <flux:button :href="route('shops.data.entity', [$shop, 'payment'])" size="sm" variant="ghost" wire:navigate>{{ __('ui.shop_dashboard.view_all') }}</flux:button>
            </div>

            @forelse ($cheques as $cheque)
                <a href="{{ route('shops.data.show', [$shop, 'payment', $cheque['record']]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5" data-cheque>
                    <div class="min-w-0">
                        <div class="truncate font-medium" dir="ltr">{{ $cheque['number'] ?? '#'.$cheque['record']->local_id }}</div>
                        <flux:text size="sm" class="truncate">{{ $cheque['bank'] }}</flux:text>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-sm tabular-nums">{{ Format::number($cheque['amount']) }}</span>
                        <flux:badge :color="$cheque['overdue'] ? 'red' : 'zinc'" size="sm">{{ Format::date($cheque['due']) }}</flux:badge>
                    </div>
                </a>
            @empty
                <flux:text class="py-8 text-center">{{ __('ui.shop_dashboard.no_cheques') }}</flux:text>
            @endforelse
        </flux:card>

        <flux:card class="space-y-3" data-cash-differences>
            <div class="flex items-center justify-between">
                <flux:heading>
                    {{ __('ui.shop_dashboard.cash_differences') }}
                    @if ($cashDifferenceCount)
                        <flux:badge color="red" size="sm" class="ms-1">{{ Format::number($cashDifferenceCount) }}</flux:badge>
                    @endif
                    <flux:text size="sm" class="font-normal">{{ __('ui.shop_dashboard.last_30_days') }}</flux:text>
                </flux:heading>
                <flux:button :href="route('shops.data.entity', [$shop, 'cash_close'])" size="sm" variant="ghost" wire:navigate>{{ __('ui.shop_dashboard.view_all') }}</flux:button>
            </div>

            @forelse ($cashDifferences as $close)
                <a href="{{ route('shops.data.show', [$shop, 'cash_close', $close['record']]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5">
                    <span class="font-medium">{{ Format::date($close['record']->dated_at) }}</span>
                    <span @class(['text-sm font-medium tabular-nums', 'text-red-600 dark:text-red-400' => $close['difference'] < 0, 'text-amber-600 dark:text-amber-400' => $close['difference'] > 0]) dir="ltr">{{ $close['difference'] > 0 ? '+' : '' }}{{ Format::number($close['difference']) }}</span>
                </a>
            @empty
                <flux:text class="py-8 text-center">{{ $cashCloseCount ? __('ui.shop_dashboard.cash_balanced') : __('ui.shop_dashboard.no_cash_closes') }}</flux:text>
            @endforelse
        </flux:card>
    @endif
</div>

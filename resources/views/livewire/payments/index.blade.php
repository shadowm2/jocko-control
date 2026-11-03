@php
    use App\Support\Format;

    $methodLabel = fn (string $method): string => \Illuminate\Support\Facades\Lang::has("ui.shop_data.values.payment.method.{$method}")
        ? __("ui.shop_data.values.payment.method.{$method}")
        : ($method === '' ? __('ui.common.none') : $method);
@endphp

<div class="space-y-8" wire:poll.60s.visible>
    <div>
        <flux:heading size="xl" level="1">{{ __('ui.payments.title') }}</flux:heading>
        <flux:subheading>{{ __('ui.payments.subtitle') }}</flux:subheading>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        @foreach (\App\Services\PaymentsOverview::WINDOWS as $name)
            <flux:card class="space-y-3" data-window="{{ $name }}">
                <div class="text-sm text-zinc-500">{{ __('ui.payments.window.'.$name) }}</div>
                <div class="text-3xl font-semibold tabular-nums" data-total="{{ $totals[$name] }}">{{ Format::number($totals[$name]) }}</div>
                <div class="space-y-1 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    @forelse ($byMethod[$name] as $method => $amount)
                        <div class="flex items-center justify-between text-sm" data-method="{{ $method }}">
                            <span class="text-zinc-500">{{ $methodLabel($method) }}</span>
                            <span class="tabular-nums">{{ Format::number($amount) }}</span>
                        </div>
                    @empty
                        <flux:text size="sm" class="text-zinc-500">{{ __('ui.payments.none') }}</flux:text>
                    @endforelse
                </div>
            </flux:card>
        @endforeach
    </div>

    <flux:card class="table-card overflow-x-auto p-0!">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('ui.common.shop') }}</flux:table.column>
                <flux:table.column>{{ __('ui.payments.window.today') }}</flux:table.column>
                <flux:table.column>{{ __('ui.payments.window.month') }}</flux:table.column>
                <flux:table.column>{{ __('ui.payments.window.days30') }}</flux:table.column>
                <flux:table.column>{{ __('ui.payments.month_by_method') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($shops as $row)
                    <flux:table.row :key="$row['shop']->getKey()" data-shop-row="{{ $row['shop']->getKey() }}">
                        <flux:table.cell variant="strong">
                            <a class="hover:underline" href="{{ route('shops.orders', $row['shop']) }}" wire:navigate>{{ $row['shop']->name }}</a>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums" data-today="{{ $row['today'] }}">{{ Format::number($row['today']) }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums" data-month="{{ $row['month'] }}">{{ Format::number($row['month']) }}</flux:table.cell>
                        <flux:table.cell class="tabular-nums">{{ Format::number($row['days30']) }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($row['methods'] as $method => $amount)
                                    <flux:badge size="sm">{{ $methodLabel($method) }} · {{ Format::number($amount) }}</flux:badge>
                                @empty
                                    <span class="text-zinc-400">—</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:text size="sm" class="text-zinc-500">{{ __('ui.payments.hint') }}</flux:text>
</div>

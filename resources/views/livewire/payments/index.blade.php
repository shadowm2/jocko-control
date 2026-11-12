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

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <flux:card class="space-y-3" data-cheques-due>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ __('ui.payments.cheques_due') }}</flux:heading>
                <div class="flex gap-2">
                    <flux:badge size="sm">{{ __('ui.payments.cheques_pending', ['count' => Format::number($cheques['count']), 'total' => Format::number($cheques['total'])]) }}</flux:badge>
                    @if ($cheques['overdue_count'] > 0)
                        <flux:badge size="sm" color="red" data-overdue-cheques="{{ $cheques['overdue_count'] }}">{{ __('ui.payments.cheques_overdue', ['count' => Format::number($cheques['overdue_count']), 'total' => Format::number($cheques['overdue_total'])]) }}</flux:badge>
                    @endif
                </div>
            </div>
            @forelse ($cheques['cheques'] as $cheque)
                <a wire:key="cheque-{{ $cheque['record']->id }}" href="{{ route('shops.data.show', [$cheque['shop'], 'payment', $cheque['record']]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5" data-cheque>
                    <div class="min-w-0 text-sm">
                        <div class="truncate font-medium">{{ $cheque['shop']->name }} <span class="text-zinc-500" dir="ltr">· {{ $cheque['number'] ?? '#'.$cheque['record']->local_id }}{{ $cheque['bank'] ? ' · '.$cheque['bank'] : '' }}</span></div>
                        <div class="text-zinc-500" dir="ltr">{{ $cheque['due'] ? Format::date($cheque['due']) : __('ui.common.none') }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        @if ($cheque['overdue'])
                            <flux:badge size="sm" color="red">{{ __('ui.payments.overdue') }}</flux:badge>
                        @endif
                        <span class="tabular-nums">{{ Format::number($cheque['amount']) }}</span>
                    </div>
                </a>
            @empty
                <flux:text class="py-6 text-center text-zinc-500">{{ __('ui.payments.no_cheques') }}</flux:text>
            @endforelse
        </flux:card>

        <flux:card class="space-y-3" data-cash-differences>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ __('ui.payments.cash_differences') }}</flux:heading>
                <flux:badge size="sm" :color="$cash['differences'] > 0 ? 'amber' : 'green'">{{ __('ui.payments.cash_summary', ['off' => Format::number($cash['differences']), 'closes' => Format::number($cash['closes'])]) }}</flux:badge>
            </div>
            @forelse ($cash['rows'] as $row)
                <a wire:key="close-{{ $row['record']->id }}" href="{{ route('shops.data.show', [$row['shop'], 'cash_close', $row['record']]) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-zinc-50 dark:hover:bg-white/5" data-cash-difference>
                    <div class="min-w-0 text-sm">
                        <div class="truncate font-medium">{{ $row['shop']->name }}</div>
                        <div class="text-zinc-500" dir="ltr">{{ Format::date($row['record']->dated_at) }}</div>
                    </div>
                    <span class="tabular-nums {{ $row['difference'] < 0 ? 'text-red-600' : 'text-amber-600' }}" dir="ltr">{{ $row['difference'] > 0 ? '+' : '' }}{{ Format::number($row['difference']) }}</span>
                </a>
            @empty
                <flux:text class="py-6 text-center text-zinc-500">{{ $cash['closes'] > 0 ? __('ui.payments.cash_balanced') : __('ui.payments.no_closes') }}</flux:text>
            @endforelse
        </flux:card>
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

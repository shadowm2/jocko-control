@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Lang;

    $row = $invoice['order'];
@endphp

<div class="space-y-6" wire:poll.10s.visible>
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2" :href="route('shops.orders', $shop)" wire:navigate>{{ __('ui.shop_orders.title') }} — {{ $shop->name }}</flux:button>
    </div>

    <flux:callout icon="eye" :text="__('ui.shop_data.read_only_notice')" />

    <flux:card class="mx-auto max-w-3xl space-y-6 text-sm">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">{{ $shop->name }}</h1>
                <p class="text-zinc-500">{{ __('ui.shop_orders.invoice') }}</p>
                @if ($shop->address)
                    <p class="text-zinc-500">{{ $shop->address }}</p>
                @endif
                @if ($shop->phone)
                    <p class="text-zinc-500" dir="ltr">{{ $shop->phone }}</p>
                @endif
            </div>
            <div class="space-y-1 text-end">
                <div class="font-semibold" dir="ltr">{{ $row['number'] }}</div>
                <div><x-datetime :value="$row['date']" /></div>
                <div class="flex flex-wrap justify-end gap-1">
                    @if ($row['status'])
                        <flux:badge :color="$row['status']->color()" size="sm">{{ $row['status']->label() }}</flux:badge>
                    @endif
                    <flux:badge :color="$row['payment']->color()" size="sm">{{ $row['payment']->label() }}</flux:badge>
                </div>
                <div>
                    <a class="text-xs text-zinc-500 hover:underline" href="{{ route('shops.data.show', [$shop, 'order', $order]) }}" wire:navigate>{{ __('ui.shop_orders.all_fields') }}</a>
                </div>
            </div>
        </div>

        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div>
                <dt class="text-zinc-500">{{ __('ui.shop_orders.customer') }}</dt>
                <dd class="font-medium">{{ $row['customer'] ?? __('ui.common.none') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('ui.shop_orders.mobile') }}</dt>
                <dd class="font-medium" dir="ltr" style="text-align: start">{{ $row['mobile'] ?? __('ui.common.none') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('ui.shop_orders.car') }}</dt>
                <dd class="font-medium">{{ $row['car'] ?? __('ui.common.none') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('ui.shop_orders.warehouse') }}</dt>
                <dd class="font-medium">{{ $row['warehouse'] ?? __('ui.common.none') }}</dd>
            </div>
        </dl>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-zinc-300 text-zinc-500 dark:border-zinc-600">
                        <th class="py-2 text-start font-medium">{{ __('ui.shop_orders.item') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('ui.shop_data.fields.quantity') }}</th>
                        <th class="py-2 text-start font-medium">{{ __('ui.shop_data.fields.unit_price') }}</th>
                        <th class="py-2 text-end font-medium">{{ __('ui.shop_data.fields.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoice['lines'] as $line)
                        <tr class="border-b border-zinc-200 dark:border-zinc-700" data-line>
                            <td class="py-2">{{ $line['item'] ?? __('ui.common.none') }}</td>
                            <td class="py-2 tabular-nums">{{ Format::number((float) $line['quantity']) }} {{ $line['unit'] }}</td>
                            <td class="py-2 tabular-nums">{{ Format::number((float) $line['unit_price']) }}</td>
                            <td class="py-2 text-end tabular-nums">{{ Format::number((float) $line['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-zinc-500">{{ __('ui.shop_orders.no_lines') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="ms-auto w-full max-w-xs space-y-1" data-totals>
            <div class="flex justify-between"><span>{{ __('ui.shop_data.fields.total') }}</span><span class="tabular-nums" data-total>{{ Format::number($invoice['total']) }}</span></div>
            <div class="flex justify-between"><span>{{ __('ui.shop_data.summary.paid') }}</span><span class="tabular-nums" data-paid>{{ Format::number($invoice['paid']) }}</span></div>
            <div class="flex justify-between border-t border-zinc-300 pt-1 text-base font-bold dark:border-zinc-600"><span>{{ __('ui.shop_data.summary.balance') }}</span><span class="tabular-nums" data-balance>{{ Format::number($invoice['balance']) }}</span></div>
        </div>

        @if ($invoice['payments'] !== [])
            <div class="space-y-2">
                <h2 class="font-semibold">{{ __('ui.shop_orders.payments') }}</h2>
                @foreach ($invoice['payments'] as $payment)
                    <div class="flex justify-between border-b border-zinc-200 py-1 dark:border-zinc-700" data-payment>
                        <span>
                            {{ $payment['paid_at'] ? Format::date(Carbon::parse($payment['paid_at'])) : __('ui.common.none') }}
                            · {{ Lang::has('ui.shop_data.values.payment.method.'.$payment['method']) ? __('ui.shop_data.values.payment.method.'.$payment['method']) : $payment['method'] }}
                        </span>
                        <span class="tabular-nums">{{ Format::number((float) $payment['amount']) }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @if (filled($order->field('description')))
            <p class="text-zinc-500">{{ $order->field('description') }}</p>
        @endif
    </flux:card>
</div>

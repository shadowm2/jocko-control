@php
    use App\Support\Format;
    use Illuminate\Support\Facades\Lang;
@endphp

<div class="space-y-8" wire:poll.60s.visible>
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2" :href="route('shops.data.entity', [$shop, $entity])" wire:navigate>{{ __('ui.shop_data.entities.'.$entity) }} — {{ $shop->name }}</flux:button>
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1">{{ $record->label }}</flux:heading>
            @if ($record->removed_at)
                <flux:badge color="red">{{ __('ui.shop_data.removed') }}</flux:badge>
            @endif
        </div>
    </div>

    <flux:callout icon="eye" :text="__('ui.shop_data.read_only_notice')" />

    @if ($record->removed_at)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('ui.shop_data.removed_banner') }}</flux:callout.heading>
            <flux:callout.text><x-datetime :value="$record->removed_at" /></flux:callout.text>
        </flux:callout>
    @endif

    @if ($summary)
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3" data-order-summary>
            @foreach (['total', 'paid', 'balance'] as $part)
                <flux:card>
                    <dt class="text-sm text-zinc-500">{{ __('ui.shop_data.summary.'.$part) }}</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums" data-summary="{{ $part }}">{{ Format::number($summary[$part]) }}</dd>
                </flux:card>
            @endforeach
        </dl>
    @endif

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('ui.shop_data.details') }}</flux:heading>
        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($fields as $field)
                <div>
                    <dt class="text-zinc-500">{{ Lang::has('ui.shop_data.fields.'.$field) ? __('ui.shop_data.fields.'.$field) : $field }}</dt>
                    <dd class="mt-0.5"><x-shop-value :entity="$entity" :field="$field" :data="$record->data" :shop="$shop" :references="$references" /></dd>
                </div>
            @endforeach
        </dl>
        <flux:text size="sm" class="mt-6">
            {{ __('ui.shop_data.synced_at') }}: <x-datetime :value="$record->source_occurred_at" />
        </flux:text>
    </flux:card>

    @foreach ($children as $childEntity => $child)
        <section class="space-y-3" data-children="{{ $childEntity }}">
            <flux:heading size="lg">
                {{ __('ui.shop_data.entities.'.$childEntity) }}
                <flux:badge class="ms-1">{{ Format::number($child['total']) }}</flux:badge>
            </flux:heading>
            <flux:card class="table-card overflow-x-auto p-0!">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('ui.shop_data.record') }}</flux:table.column>
                        @foreach ($child['columns'] as $column)
                            <flux:table.column>{{ Lang::has('ui.shop_data.fields.'.$column) ? __('ui.shop_data.fields.'.$column) : $column }}</flux:table.column>
                        @endforeach
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($child['rows'] as $row)
                            <flux:table.row :key="$row->id">
                                <flux:table.cell variant="strong">
                                    <a class="hover:underline" href="{{ route('shops.data.show', [$shop, $childEntity, $row]) }}" wire:navigate>{{ $row->label }}</a>
                                    @if ($row->removed_at)
                                        <flux:badge size="sm" color="red" class="ms-1">{{ __('ui.shop_data.removed') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                @foreach ($child['columns'] as $column)
                                    <flux:table.cell><x-shop-value :entity="$childEntity" :field="$column" :data="$row->data" :shop="$shop" :references="$child['references']" /></flux:table.cell>
                                @endforeach
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
            @if ($child['total'] > $child['rows']->count())
                <flux:text size="sm">{{ __('ui.shop_data.children_truncated', ['shown' => $child['rows']->count(), 'total' => $child['total']]) }}</flux:text>
            @endif
        </section>
    @endforeach
</div>

@php use App\Support\Format; @endphp

<div class="space-y-6" wire:poll.10s.visible>
    <div>
        <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2" :href="route('shops.show', $shop)" wire:navigate>{{ $shop->name }}</flux:button>
        <flux:heading size="xl" level="1">{{ __('ui.shop_data.title') }}</flux:heading>
        <flux:subheading>{{ __('ui.shop_data.subtitle', ['shop' => $shop->name]) }}</flux:subheading>
    </div>

    <x-shop-tabs :shop="$shop" current="data" />

    <flux:callout icon="eye" :text="__('ui.shop_data.read_only_notice')" />

    @if ($total === 0)
        <flux:card class="px-6 py-14 text-center">
            <flux:icon.circle-stack class="mx-auto mb-3 size-10 text-zinc-300" />
            <flux:heading>{{ __('ui.shop_data.empty') }}</flux:heading>
            <flux:text class="mt-1">{{ __('ui.shop_data.empty_hint') }}</flux:text>
        </flux:card>
    @else
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:card>
                <dt class="text-sm text-zinc-500">{{ __('ui.shop_data.total_records') }}</dt>
                <dd class="mt-1 text-2xl font-semibold">{{ Format::number($total) }}</dd>
            </flux:card>
            <flux:card>
                <dt class="text-sm text-zinc-500">{{ __('ui.shop_data.last_update') }}</dt>
                <dd class="mt-1 text-lg"><x-datetime :value="$lastUpdate" :ago="true" /></dd>
            </flux:card>
        </dl>

        @foreach ($groups as $group => $entities)
            <section class="space-y-3">
                <flux:heading size="lg">{{ __('ui.shop_data.groups.'.$group) }}</flux:heading>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($entities as $entity)
                        <a href="{{ route('shops.data.entity', [$shop, $entity]) }}" wire:navigate data-entity="{{ $entity }}">
                            <flux:card class="flex items-center justify-between gap-3 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <span>{{ __('ui.shop_data.entities.'.$entity) }}</span>
                                <flux:badge :color="($counts[$entity] ?? 0) > 0 ? 'blue' : 'zinc'">{{ Format::number($counts[$entity] ?? 0) }}</flux:badge>
                            </flux:card>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>

@php use App\Enums\ShopStatus; use App\Support\Format; @endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('ui.shops.title') }}</flux:heading>
            <flux:subheading>{{ __('ui.shops.subtitle') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" :href="route('shops.create')" wire:navigate>{{ __('ui.shops.create') }}</flux:button>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-64 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('ui.shops.search_placeholder')" clearable />
        </div>
        <div class="w-56">
            <flux:select wire:model.live="status">
                <flux:select.option value="">{{ __('ui.shops.all_statuses') }}</flux:select.option>
                @foreach (ShopStatus::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        @if ($search !== '' || $status !== '')
            <flux:button variant="ghost" wire:click="resetFilters">{{ __('ui.common.reset') }}</flux:button>
        @endif
    </div>

    <flux:card class="overflow-x-auto p-0!" wire:loading.class="opacity-60" wire:target="search,status,gotoPage,nextPage,previousPage">
        @if ($shops->isEmpty())
            <div class="px-6 py-14 text-center">
                <flux:icon.building-storefront class="mx-auto mb-3 size-10 text-zinc-300" />
                <flux:heading>{{ __('ui.shops.empty') }}</flux:heading>
                @if ($search === '' && $status === '')
                    <flux:text class="mt-1">{{ __('ui.shops.empty_hint') }}</flux:text>
                @endif
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ui.shops.name') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shops.slug') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.common.status') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.shops.devices') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($shops as $shop)
                        <flux:table.row :key="$shop->id">
                            <flux:table.cell variant="strong">
                                <a href="{{ route('shops.show', $shop) }}" wire:navigate class="hover:underline">{{ $shop->name }}</a>
                                @if ($shop->owner_name)
                                    <div class="text-xs font-normal text-zinc-500">{{ $shop->owner_name }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell><span dir="ltr" class="font-mono text-xs">{{ $shop->slug }}</span></flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$shop->status->color()" inset="top bottom">{{ $shop->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ __('ui.shops.devices_online', ['online' => Format::number($shop->online_devices_count), 'total' => Format::number($shop->active_devices_count)]) }}
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" :href="route('shops.show', $shop)" wire:navigate>{{ __('ui.common.view') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('shops.edit', $shop)" wire:navigate :aria-label="__('ui.common.edit')" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <div>
        <flux:pagination :paginator="$shops" />
    </div>
</div>

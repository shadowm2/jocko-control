@php use App\Support\Format; @endphp

<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('ui.events.title') }}</flux:heading>
        <flux:subheading>{{ __('ui.events.subtitle') }}</flux:subheading>
    </div>

    <flux:callout icon="information-circle" :text="__('ui.events.boundary_notice')" />

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-64">
            <flux:select wire:model.live="shop">
                <flux:select.option value="">{{ __('ui.common.all') }} — {{ __('ui.common.shop') }}</flux:select.option>
                @foreach ($shops as $shopOption)
                    <flux:select.option :value="$shopOption->id">{{ $shopOption->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="min-w-64 flex-1">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('ui.events.search_placeholder')" clearable dir="ltr" />
        </div>
        @if ($shop !== '' || $search !== '')
            <flux:button variant="ghost" wire:click="resetFilters">{{ __('ui.common.reset') }}</flux:button>
        @endif
    </div>

    <flux:card class="overflow-x-auto p-0!" wire:loading.class="opacity-60" wire:target="shop,search,nextPage,previousPage">
        @if ($events->isEmpty())
            <div class="px-6 py-14 text-center">
                <flux:icon.inbox class="mx-auto mb-3 size-10 text-zinc-300" />
                <flux:heading>{{ __('ui.events.empty') }}</flux:heading>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ui.events.received_at') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.common.shop') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.common.device') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.events.event_type') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.events.aggregate') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($events as $event)
                        <flux:table.row :key="$event->id">
                            <flux:table.cell><x-datetime :value="$event->received_at" /></flux:table.cell>
                            <flux:table.cell>
                                <a class="hover:underline" href="{{ route('shops.show', $event->shop_id) }}" wire:navigate>{{ $event->shop?->name }}</a>
                            </flux:table.cell>
                            <flux:table.cell>{{ $event->device?->name }}</flux:table.cell>
                            <flux:table.cell variant="strong"><span dir="ltr">{{ $event->event_type }}</span></flux:table.cell>
                            <flux:table.cell>
                                <span dir="ltr" class="text-xs">{{ $event->aggregate_type }}<br><span class="font-mono text-zinc-500">{{ \Illuminate\Support\Str::limit($event->aggregate_id, 13, '…') }}</span></span>
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" wire:click="view({{ $event->id }})">{{ __('ui.common.details') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    {{ $events->links() }}

    <flux:modal name="event-payload" class="md:w-[40rem]">
        @if ($viewing)
            @php $json = Format::json($viewing->payload); @endphp
            <div class="space-y-4">
                <flux:heading size="lg"><span dir="ltr">{{ $viewing->event_type }}</span></flux:heading>
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <dt class="text-zinc-500">{{ __('ui.events.event_id') }}</dt>
                        <dd class="font-mono text-xs" dir="ltr">{{ $viewing->event_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('ui.events.aggregate') }}</dt>
                        <dd class="font-mono text-xs" dir="ltr">{{ $viewing->aggregate_type }} / {{ $viewing->aggregate_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('ui.events.base_version') }}</dt>
                        <dd dir="ltr" style="text-align: start">{{ $viewing->base_version ?? __('ui.common.none') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('ui.events.occurred_at') }}</dt>
                        <dd><x-datetime :value="$viewing->occurred_at" /></dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('ui.events.received_at') }}</dt>
                        <dd><x-datetime :value="$viewing->received_at" /></dd>
                    </div>
                </dl>
                <div>
                    <div class="mb-1 text-sm text-zinc-500">{{ __('ui.events.payload') }}</div>
                    <pre dir="ltr" class="max-h-80 overflow-auto rounded-lg bg-zinc-100 p-4 text-xs dark:bg-zinc-800">{{ $json['text'] }}</pre>
                    @if ($json['truncated'])
                        <flux:text size="sm" class="mt-1">{{ __('ui.events.payload_truncated') }}</flux:text>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</div>

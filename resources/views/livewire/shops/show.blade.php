<div class="space-y-8" wire:poll.30s>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:button variant="subtle" size="sm" icon="arrow-right" class="mb-2" :href="route('shops.index')" wire:navigate>{{ __('ui.shops.title') }}</flux:button>
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $shop->name }}</flux:heading>
                <flux:badge :color="$shop->status->color()" data-shop-status="{{ $shop->status->value }}">{{ $shop->status->label() }}</flux:badge>
            </div>
            <div class="mt-1 text-sm text-zinc-500" dir="ltr">{{ $shop->slug }}</div>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="pencil-square" :href="route('shops.edit', $shop)" wire:navigate>{{ __('ui.common.edit') }}</flux:button>

            <flux:modal.trigger name="allow-restore">
                <flux:button icon="arrow-path">{{ __('ui.shops.allow_restore') }}</flux:button>
            </flux:modal.trigger>

            @if ($shop->isActive())
                <flux:modal.trigger name="suspend-shop">
                    <flux:button variant="danger" icon="pause-circle">{{ __('ui.shops.suspend') }}</flux:button>
                </flux:modal.trigger>
            @else
                <flux:modal.trigger name="reactivate-shop">
                    <flux:button variant="primary" icon="play-circle">{{ __('ui.shops.reactivate') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    <x-shop-tabs :shop="$shop" current="overview" />

    @if ($shop->isSuspended())
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('ui.shops.suspended_banner')" />
    @endif

    @php($overLimit = $shop->plan?->max_devices !== null && $activeDevices > $shop->plan->max_devices)

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('ui.plans.billing') }}</flux:heading>
        @if ($shop->plan)
            <dl class="grid grid-cols-1 gap-x-8 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-zinc-500">{{ __('ui.plans.plan') }}</dt>
                    <dd class="font-medium">{{ $shop->plan->name }} <span dir="ltr" class="text-zinc-500">({{ number_format($shop->plan->price) }} / {{ __('ui.plans.days', ['count' => $shop->plan->billing_days]) }})</span></dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('ui.plans.paid_until') }}</dt>
                    <dd class="font-medium">
                        <span dir="ltr">{{ $shop->paidUntil()?->format('Y-m-d') ?? '—' }}</span>
                        <flux:badge size="sm" :color="$shop->billingStatus()->color()" inset="top bottom">{{ $shop->billingStatus()->label() }}</flux:badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('ui.plans.devices_used') }}</dt>
                    <dd class="font-medium">
                        <span dir="ltr" class="inline-block">{{ $activeDevices }} / {{ $shop->plan->max_devices ?? __('ui.plans.unlimited') }}</span>
                        @if ($overLimit)
                            <flux:badge size="sm" color="red" inset="top bottom">{{ __('ui.plans.over_limit') }}</flux:badge>
                        @endif
                    </dd>
                </div>
            </dl>
        @else
            <flux:text>{{ __('ui.plans.no_plan_hint') }}</flux:text>
        @endif
    </flux:card>

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('ui.shops.information') }}</flux:heading>
        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                'owner_name' => $shop->owner_name,
                'phone' => $shop->phone,
                'email' => $shop->email,
                'timezone' => $shop->timezone,
            ] as $field => $value)
                <div>
                    <dt class="text-zinc-500">{{ __('ui.shops.'.$field) }}</dt>
                    <dd class="font-medium" @if (in_array($field, ['phone', 'email', 'timezone'])) dir="ltr" style="text-align: start" @endif>{{ $value ?: __('ui.common.none') }}</dd>
                </div>
            @endforeach
            <div>
                <dt class="text-zinc-500">{{ __('ui.shops.pictures') }}</dt>
                <dd class="font-medium">{{ $shop->keepsOriginalPictures() ? __('ui.shops.pictures_original') : __('ui.shops.pictures_preview') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('ui.shops.last_sync') }}</dt>
                <dd class="font-medium"><x-datetime :value="$lastSyncAt" /></dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('ui.shops.registered_at') }}</dt>
                <dd class="font-medium"><x-datetime :value="$shop->created_at" /></dd>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-zinc-500">{{ __('ui.shops.address') }}</dt>
                <dd class="font-medium">{{ $shop->address ?: __('ui.common.none') }}</dd>
            </div>
            @if ($shop->notes)
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-zinc-500">{{ __('ui.shops.notes') }}</dt>
                    <dd class="whitespace-pre-line font-medium">{{ $shop->notes }}</dd>
                </div>
            @endif
        </dl>
    </flux:card>

    <livewire:shops.devices-panel :shop-id="$shop->id" :key="'devices-'.$shop->id" />

    <livewire:shops.backlog-chart :shop-id="$shop->id" :key="'backlog-'.$shop->id" />

    <livewire:shops.commands-panel :shop-id="$shop->id" :key="'commands-'.$shop->id" />

    <flux:card>
        <div class="mb-4 flex items-center justify-between gap-4">
            <flux:heading size="lg">{{ __('ui.shops.recent_events') }}</flux:heading>
            <flux:button size="sm" variant="ghost" :href="route('events.index', ['shop' => $shop->id])" wire:navigate>{{ __('ui.shops.view_all_events') }}</flux:button>
        </div>

        @forelse ($events as $event)
            <div wire:key="event-{{ $event->id }}" class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 py-3 text-sm first:border-t-0 dark:border-zinc-800">
                <div class="min-w-0">
                    <x-event-type
                        class="font-medium"
                        :type="$event->event_type"
                    />
                    <span class="text-zinc-500">· {{ $event->device?->name }}</span>
                    @if ($record = $eventRecords->get($event->aggregate_id))
                        <flux:link :href="route('shops.data.show', ['shop' => $shop, 'entity' => $record->entity, 'record' => $record])" wire:navigate class="ms-2 text-xs">{{ __('ui.shops.open_record') }}</flux:link>
                    @endif
                </div>
                <x-datetime :value="$event->received_at" ago class="text-xs text-zinc-500" />
            </div>
        @empty
            <flux:text class="py-4 text-center text-zinc-500">{{ __('ui.shops.no_events') }}</flux:text>
        @endforelse
    </flux:card>

    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('ui.shops.audit_history') }}</flux:heading>

        @forelse ($audit as $log)
            <div wire:key="audit-{{ $log->id }}" class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 py-3 text-sm first:border-t-0 dark:border-zinc-800">
                <div>
                    <span class="font-medium">{{ $log->action->label() }}</span>
                    <span class="text-zinc-500">· {{ $log->user?->name ?? $log->device?->name ?? __('ui.common.system') }}</span>
                </div>
                <x-datetime :value="$log->created_at" class="text-xs text-zinc-500" />
            </div>
        @empty
            <flux:text class="py-4 text-center text-zinc-500">{{ __('ui.shops.no_audit') }}</flux:text>
        @endforelse
    </flux:card>

    <flux:modal name="allow-restore" class="md:w-[28rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('ui.shops.allow_restore_title') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.shops.allow_restore_body') }}</flux:text>
                @if ($shop->restoreAllowed())
                    <flux:text class="mt-2 font-medium">{{ __('ui.shops.restore_open_until', ['time' => $shop->restoreAllowedUntil()?->timezone(config('app.timezone'))->format('Y-m-d H:i')]) }}</flux:text>
                @endif
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('ui.common.cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="allowRestore" wire:loading.attr="disabled" wire:target="allowRestore">{{ __('ui.shops.allow_restore') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="suspend-shop" class="md:w-[28rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('ui.shops.suspend_title') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.shops.suspend_body') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('ui.common.cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="suspend" wire:loading.attr="disabled" wire:target="suspend">{{ __('ui.shops.suspend') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="reactivate-shop" class="md:w-[28rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('ui.shops.reactivate_title') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.shops.reactivate_body') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('ui.common.cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="reactivate" wire:loading.attr="disabled" wire:target="reactivate">{{ __('ui.shops.reactivate') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>

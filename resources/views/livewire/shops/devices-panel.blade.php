<div wire:poll.30s>
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:heading size="lg">{{ __('ui.devices.title') }}</flux:heading>
            <flux:modal.trigger name="register-device">
                <flux:button variant="primary" size="sm" icon="plus">{{ __('ui.devices.register') }}</flux:button>
            </flux:modal.trigger>
        </div>

        @if ($devices->isEmpty())
            <div class="py-10 text-center">
                <flux:icon.computer-desktop class="mx-auto mb-3 size-10 text-zinc-300" />
                <flux:text>{{ __('ui.devices.empty') }}</flux:text>
            </div>
        @else
            <div class="table-card -mx-6 overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('ui.devices.name') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.common.status') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.devices.version') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.devices.last_seen') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.devices.last_sync') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.devices.sync_health') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($devices as $device)
                            @php $connectivity = $device->connectivity(); @endphp
                            <flux:table.row :key="$device->id">
                                <flux:table.cell variant="strong">{{ $device->name }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$connectivity->color()" data-connectivity="{{ $connectivity->value }}">{{ $connectivity->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell><span dir="ltr">{{ $device->app_version ?: __('ui.common.none') }}</span></flux:table.cell>
                                <flux:table.cell><x-datetime :value="$device->last_seen_at" ago /></flux:table.cell>
                                <flux:table.cell><x-datetime :value="$device->last_sync_at" ago /></flux:table.cell>
                                <flux:table.cell class="max-w-64">
                                    @if ($device->hasUnresolvedSyncError())
                                        <flux:badge size="sm" color="red">{{ __('ui.devices.sync_error') }}</flux:badge>
                                        <div class="mt-1 line-clamp-2 text-xs text-red-600 dark:text-red-400" dir="auto" title="{{ $device->last_sync_error }}">{{ $device->last_sync_error }}</div>
                                    @elseif ($device->last_sync_at)
                                        <flux:badge size="sm" color="green">{{ __('ui.devices.sync_ok') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('ui.devices.sync_unknown') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-end">
                                    <flux:dropdown align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('ui.common.actions')" />
                                        <flux:menu>
                                            <flux:menu.item icon="clock" wire:click="showActivity('{{ $device->id }}')">{{ __('ui.devices.activity') }}</flux:menu.item>
                                            @if ($device->isActive())
                                                <flux:menu.item icon="key" wire:click="confirmRotate('{{ $device->id }}')">{{ __('ui.devices.rotate') }}</flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item icon="no-symbol" variant="danger" wire:click="confirmRevoke('{{ $device->id }}')">{{ __('ui.devices.revoke') }}</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </flux:card>

    {{-- Register --}}
    <flux:modal name="register-device" class="md:w-[28rem]">
        <form wire:submit="register" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('ui.devices.register_title') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.devices.register_body') }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('ui.devices.name') }}</flux:label>
                <flux:input wire:model="deviceName" :placeholder="__('ui.devices.name_placeholder')" required />
                <flux:error name="deviceName" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="ghost">{{ __('ui.common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="register">{{ __('ui.devices.register') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- One-time token display --}}
    <flux:modal name="device-token" class="md:w-[34rem]" :dismissible="false" x-on:close="$wire.dismissToken()">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('ui.devices.token_title') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.devices.token_body', ['name' => $issuedForName]) }}</flux:text>
            </div>

            <flux:callout variant="warning" icon="exclamation-triangle" :text="__('ui.devices.token_warning')" />

            @if ($issuedToken)
                <flux:input readonly copyable value="{{ $issuedToken }}" dir="ltr" class="font-mono" data-device-token />
            @endif

            <div class="flex justify-end">
                <flux:modal.close><flux:button variant="primary">{{ __('ui.devices.token_saved') }}</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- Revoke --}}
    <flux:modal name="revoke-device" class="md:w-[28rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('ui.devices.revoke_title') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.devices.revoke_body', ['name' => $targetDevice?->name]) }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('ui.common.cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="revoke" wire:loading.attr="disabled" wire:target="revoke">{{ __('ui.devices.revoke') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Rotate --}}
    <flux:modal name="rotate-token" class="md:w-[28rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('ui.devices.rotate_title') }}</flux:heading>
                <flux:text class="mt-2">{{ __('ui.devices.rotate_body', ['name' => $targetDevice?->name]) }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('ui.common.cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="rotate" wire:loading.attr="disabled" wire:target="rotate">{{ __('ui.devices.rotate') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Recent activity --}}
    <flux:modal name="device-activity" variant="flyout" class="w-full max-w-lg">
        @if ($activity)
            <div class="space-y-6">
                <flux:heading size="lg">{{ __('ui.devices.activity_title', ['name' => $activity['device']->name]) }}</flux:heading>

                <section>
                    <flux:heading class="mb-2">{{ __('ui.shops.recent_events') }}</flux:heading>
                    @forelse ($activity['events'] as $event)
                        <div class="flex justify-between gap-3 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                            <span dir="ltr">{{ $event->event_type }}</span>
                            <x-datetime :value="$event->received_at" ago class="text-xs text-zinc-500" />
                        </div>
                    @empty
                        <flux:text size="sm">{{ __('ui.devices.no_activity') }}</flux:text>
                    @endforelse
                </section>

                <section>
                    <flux:heading class="mb-2">{{ __('ui.shops.recent_commands') }}</flux:heading>
                    @forelse ($activity['commands'] as $command)
                        <div class="flex items-center justify-between gap-3 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                            <span>{{ $command->command_type->label() }}</span>
                            <flux:badge size="sm" :color="$command->status->color()">{{ $command->status->label() }}</flux:badge>
                        </div>
                    @empty
                        <flux:text size="sm">{{ __('ui.devices.no_activity') }}</flux:text>
                    @endforelse
                </section>

                <section>
                    <flux:heading class="mb-2">{{ __('ui.shops.audit_history') }}</flux:heading>
                    @forelse ($activity['audit'] as $log)
                        <div class="flex justify-between gap-3 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                            <span>{{ $log->action->label() }}</span>
                            <x-datetime :value="$log->created_at" class="text-xs text-zinc-500" />
                        </div>
                    @empty
                        <flux:text size="sm">{{ __('ui.devices.no_activity') }}</flux:text>
                    @endforelse
                </section>
            </div>
        @endif
    </flux:modal>
</div>

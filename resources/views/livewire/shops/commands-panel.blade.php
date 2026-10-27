@php use App\Enums\CommandType; use App\Support\Format; @endphp

<div wire:poll.20s>
    <flux:card class="space-y-6">
        <flux:heading size="lg">{{ __('ui.commands.title') }}</flux:heading>

        @if ($activeDevices->isEmpty() || $shop->isSuspended())
            <flux:text>{{ $shop->isSuspended() ? __('ui.commands.shop_suspended') : __('ui.commands.no_active_devices') }}</flux:text>
        @else
            <form wire:submit="queue" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('ui.commands.device') }}</flux:label>
                        <flux:select wire:model="deviceId">
                            <flux:select.option value="">{{ __('ui.commands.select_device') }}</flux:select.option>
                            @foreach ($activeDevices as $device)
                                <flux:select.option :value="$device->id">{{ $device->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="deviceId" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ui.commands.type') }}</flux:label>
                        <flux:select wire:model.live="commandType">
                            <flux:select.option value="">{{ __('ui.commands.select_type') }}</flux:select.option>
                            @foreach (CommandType::cases() as $type)
                                <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="commandType" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ui.commands.expires_in') }}</flux:label>
                        <flux:select wire:model="expiresInHours">
                            @foreach (\App\Livewire\Shops\CommandsPanel::EXPIRY_HOURS as $hours)
                                <flux:select.option :value="$hours">{{ $hours >= 24 ? __('ui.commands.days', ['count' => Format::number($hours / 24)]) : __('ui.commands.hours', ['count' => Format::number($hours)]) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="expiresInHours" />
                    </flux:field>
                </div>

                @if ($selectedType === CommandType::SyncNow)
                    <flux:checkbox wire:model="full" :label="__('ui.commands.param_full')" />
                @elseif ($selectedType === CommandType::CollectDiagnostics)
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <flux:checkbox wire:model="includeLogs" :label="__('ui.commands.param_include_logs')" />
                        <flux:field>
                            <flux:label>{{ __('ui.commands.param_since_minutes') }}</flux:label>
                            <flux:input type="number" min="1" max="1440" wire:model="sinceMinutes" dir="ltr" />
                            <flux:error name="sinceMinutes" />
                        </flux:field>
                    </div>
                @endif

                <flux:error name="payload" />
                <flux:error name="expiresAt" />
                <flux:error name="device" />

                @if ($selectedType?->isSensitive())
                    <flux:callout variant="warning" icon="shield-exclamation">
                        <flux:callout.text>{{ __('ui.commands.sensitive_warning') }}</flux:callout.text>
                        <div class="mt-3">
                            <flux:checkbox wire:model="confirmed" :label="__('ui.commands.confirm_sensitive')" />
                            <flux:error name="confirmed" />
                        </div>
                    </flux:callout>
                @endif

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="queue">{{ __('ui.commands.queue') }}</flux:button>
                </div>
            </form>
        @endif

        @if ($commands->isEmpty())
            <flux:text class="py-4 text-center text-zinc-500">{{ __('ui.commands.empty') }}</flux:text>
        @else
            <div class="-mx-6 overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('ui.commands.type') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.common.device') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.common.status') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.commands.requested_at') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.commands.expires_at') }}</flux:table.column>
                        <flux:table.column>{{ __('ui.commands.requested_by') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($commands as $command)
                            <flux:table.row :key="$command->id">
                                <flux:table.cell variant="strong">{{ $command->command_type->label() }}</flux:table.cell>
                                <flux:table.cell>{{ $command->device?->name }}</flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" :color="$command->status->color()">{{ $command->status->label() }}</flux:badge></flux:table.cell>
                                <flux:table.cell><x-datetime :value="$command->requested_at" /></flux:table.cell>
                                <flux:table.cell><x-datetime :value="$command->expires_at" /></flux:table.cell>
                                <flux:table.cell>{{ $command->requester?->name ?? __('ui.common.none') }}</flux:table.cell>
                                <flux:table.cell class="text-end">
                                    @if ($command->result !== null)
                                        <flux:button size="sm" variant="ghost" wire:click="showResult('{{ $command->id }}')">{{ __('ui.common.result') }}</flux:button>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </flux:card>

    <flux:modal name="command-result" class="md:w-[36rem]">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('ui.commands.result_title') }}</flux:heading>
            @if ($resultCommand)
                @php $json = Format::json($resultCommand->result); @endphp
                <pre dir="ltr" class="max-h-96 overflow-auto rounded-lg bg-zinc-100 p-4 text-xs dark:bg-zinc-800">{{ $json['text'] }}</pre>
                @if ($json['truncated'])
                    <flux:text size="sm">{{ __('ui.events.payload_truncated') }}</flux:text>
                @endif
            @else
                <flux:text>{{ __('ui.commands.no_result') }}</flux:text>
            @endif
        </div>
    </flux:modal>
</div>

<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('ui.audit.title') }}</flux:heading>
        <flux:subheading>{{ __('ui.audit.subtitle') }}</flux:subheading>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-64">
            <flux:select wire:model.live="action">
                <flux:select.option value="">{{ __('ui.audit.all_actions') }}</flux:select.option>
                @foreach (\App\Enums\AuditAction::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-64">
            <flux:select wire:model.live="shop">
                <flux:select.option value="">{{ __('ui.common.all') }} — {{ __('ui.common.shop') }}</flux:select.option>
                @foreach ($shops as $shopOption)
                    <flux:select.option :value="$shopOption->id">{{ $shopOption->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        @if ($action !== '' || $shop !== '')
            <flux:button variant="ghost" wire:click="resetFilters">{{ __('ui.common.reset') }}</flux:button>
        @endif
    </div>

    <flux:card class="table-card overflow-x-auto p-0!" wire:loading.class="opacity-60" wire:target="action,shop,nextPage,previousPage">
        @if ($logs->isEmpty())
            <div class="px-6 py-14 text-center">
                <flux:icon.clipboard-document-list class="mx-auto mb-3 size-10 text-zinc-300" />
                <flux:heading>{{ __('ui.audit.empty') }}</flux:heading>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ui.common.time') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.audit.action') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.audit.actor') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.common.shop') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.common.device') }}</flux:table.column>
                    <flux:table.column>{{ __('ui.audit.ip') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($logs as $log)
                        <flux:table.row :key="$log->id">
                            <flux:table.cell><x-datetime :value="$log->created_at" /></flux:table.cell>
                            <flux:table.cell variant="strong">{{ $log->action->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $log->user?->name ?? ($log->device ? __('ui.common.device') : __('ui.common.system')) }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($log->shop)
                                    <a class="hover:underline" href="{{ route('shops.show', $log->shop) }}" wire:navigate>{{ $log->shop->name }}</a>
                                @else
                                    {{ __('ui.common.none') }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $log->device?->name ?? __('ui.common.none') }}</flux:table.cell>
                            <flux:table.cell><span dir="ltr" class="font-mono text-xs">{{ $log->ip_address ?? '—' }}</span></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    {{ $logs->links() }}
</div>

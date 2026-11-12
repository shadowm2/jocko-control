@php
    use App\Support\Format;

    $tiles = [
        ['key' => 'shops_total', 'icon' => 'building-storefront', 'tone' => 'zinc'],
        ['key' => 'shops_active', 'icon' => 'check-badge', 'tone' => 'green'],
        ['key' => 'shops_suspended', 'icon' => 'pause-circle', 'tone' => 'amber'],
        ['key' => 'devices_active', 'icon' => 'computer-desktop', 'tone' => 'zinc'],
        ['key' => 'devices_online', 'icon' => 'signal', 'tone' => 'green'],
        ['key' => 'devices_offline', 'icon' => 'signal-slash', 'tone' => 'red'],
        ['key' => 'devices_never_connected', 'icon' => 'question-mark-circle', 'tone' => 'zinc'],
        ['key' => 'devices_failing', 'icon' => 'exclamation-triangle', 'tone' => 'red'],
        ['key' => 'events_total', 'icon' => 'inbox-arrow-down', 'tone' => 'zinc'],
        ['key' => 'commands_pending', 'icon' => 'paper-airplane', 'tone' => 'blue'],
    ];
@endphp

<div class="space-y-8" wire:poll.60s>
    <div>
        <flux:heading size="xl" level="1">{{ __('ui.dashboard.title') }}</flux:heading>
        <flux:subheading>{{ __('ui.dashboard.subtitle') }}</flux:subheading>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($tiles as $tile)
            <flux:card class="flex items-center gap-4" data-metric="{{ $tile['key'] }}">
                <div @class([
                    'flex size-10 shrink-0 items-center justify-center rounded-lg',
                    'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $tile['tone'] === 'zinc',
                    'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' => $tile['tone'] === 'green',
                    'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400' => $tile['tone'] === 'amber',
                    'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400' => $tile['tone'] === 'red',
                    'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400' => $tile['tone'] === 'blue',
                ])>
                    <flux:icon :name="$tile['icon']" variant="outline" class="size-5" />
                </div>
                <div class="min-w-0">
                    <div class="text-2xl font-semibold tabular-nums" data-value>{{ Format::number($summary[$tile['key']]) }}</div>
                    <div class="text-sm leading-tight text-zinc-500">{{ __('ui.dashboard.'.$tile['key']) }}</div>
                </div>
            </flux:card>
        @endforeach
    </div>

    <flux:text size="sm" class="text-zinc-500">
        {{ __('ui.dashboard.online_hint', ['minutes' => Format::number((int) config('jocko.online_threshold_minutes'))]) }}
    </flux:text>

    <flux:card data-attention>
        <flux:heading size="lg" class="mb-4">{{ __('ui.dashboard.needs_attention') }}</flux:heading>

        @forelse ($attention as $row)
            <div wire:key="attention-{{ $row['device']->id }}" class="flex items-start justify-between gap-4 border-t border-zinc-100 py-3 first:border-t-0 dark:border-zinc-800">
                <div class="min-w-0 text-sm font-medium">
                    <a class="underline" href="{{ route('shops.show', $row['device']->shop) }}" wire:navigate>{{ $row['device']->shop->name }}</a>
                    <span class="text-zinc-500">· {{ $row['device']->name }}</span>
                </div>
                <div class="flex shrink-0 flex-wrap justify-end gap-2">
                    @if ($row['offline'])
                        <flux:badge color="red" size="sm">{{ __('ui.dashboard.silent_for', ['time' => Format::duration(now()->diffInSeconds($row['device']->last_seen_at, true))]) }}</flux:badge>
                    @endif
                    @if ($row['pending_events'] !== null || $row['oldest_pending_seconds'] !== null)
                        <flux:badge color="amber" size="sm">{{ $row['oldest_pending_seconds'] === null
                                ? __('ui.dashboard.backlog_count', ['count' => Format::number($row['pending_events'])])
                                : __('ui.dashboard.backlog', ['count' => Format::number($row['pending_events'] ?? 0), 'age' => Format::duration($row['oldest_pending_seconds'])]) }}</flux:badge>
                    @endif
                </div>
            </div>
        @empty
            <flux:text class="py-6 text-center text-zinc-500">{{ __('ui.dashboard.all_healthy') }}</flux:text>
        @endforelse

        <flux:text size="sm" class="mt-4 text-zinc-500">
            {{ __('ui.dashboard.attention_hint', ['offline' => Format::duration((int) config('jocko.alerts.offline_minutes') * 60), 'events' => Format::number((int) config('jocko.alerts.backlog_events')), 'age' => Format::duration((int) config('jocko.alerts.backlog_age_minutes') * 60)]) }}
        </flux:text>
    </flux:card>

    @if ($drifted !== [])
        <flux:card data-mirror-drift>
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ __('ui.mirror.fleet_title') }}</flux:heading>
                <flux:badge color="red" size="sm">{{ __('ui.mirror.fleet_count', ['count' => Format::number(count($drifted))]) }}</flux:badge>
            </div>
            @foreach ($drifted as $row)
                <div wire:key="drift-{{ $row['shop']->id }}" class="flex items-center justify-between gap-4 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                    <a class="font-medium underline" href="{{ route('shops.show', $row['shop']) }}" wire:navigate>{{ $row['shop']->name }}</a>
                    <span class="text-zinc-500">{{ __('ui.mirror.fleet_row', ['missing' => Format::number($row['missing']), 'extra' => Format::number($row['extra']), 'entities' => Format::number($row['entities'])]) }}</span>
                </div>
            @endforeach
        </flux:card>
    @endif

    @if ($billing['shops']->isNotEmpty())
        <flux:card data-billing-attention>
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ __('ui.dashboard.billing_attention') }}</flux:heading>
                <div class="flex gap-2">
                    @if ($billing['expired'] > 0)
                        <flux:badge color="red" size="sm">{{ __('ui.dashboard.billing_expired', ['count' => Format::number($billing['expired'])]) }}</flux:badge>
                    @endif
                    @if ($billing['due_soon'] > 0)
                        <flux:badge color="amber" size="sm">{{ __('ui.dashboard.billing_due_soon', ['count' => Format::number($billing['due_soon'])]) }}</flux:badge>
                    @endif
                </div>
            </div>

            @foreach ($billing['shops'] as $shop)
                <div wire:key="billing-{{ $shop->id }}" class="flex items-center justify-between gap-4 border-t border-zinc-100 py-2 text-sm first:border-t-0 dark:border-zinc-800">
                    <div class="min-w-0">
                        <a class="font-medium underline" href="{{ route('shops.show', $shop) }}" wire:navigate>{{ $shop->name }}</a>
                        <span class="text-zinc-500">· {{ $shop->plan?->name }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span dir="ltr" class="text-zinc-500">{{ Format::date($shop->paidUntil()) }}</span>
                        <flux:badge size="sm" :color="$shop->billingStatus()->color()">{{ $shop->billingStatus()->label() }}</flux:badge>
                    </div>
                </div>
            @endforeach
        </flux:card>
    @endif

    @if ($versions['rows'] !== [])
        <flux:card data-versions>
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ __('ui.dashboard.versions') }}</flux:heading>
                @if ($versions['outdated_devices'] > 0)
                    <flux:badge color="amber" size="sm" data-outdated-count="{{ $versions['outdated_devices'] }}">{{ __('ui.dashboard.outdated_devices', ['count' => Format::number($versions['outdated_devices'])]) }}</flux:badge>
                @endif
            </div>

            @foreach ($versions['rows'] as $row)
                <div class="flex items-center justify-between gap-4 border-t border-zinc-100 py-2 first:border-t-0 dark:border-zinc-800" data-version="{{ $row['version'] }}">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate font-mono text-sm" dir="ltr">{{ $row['version'] }}</span>
                        @if ($row['latest'])
                            <flux:badge color="green" size="sm">{{ __('ui.dashboard.latest_version') }}</flux:badge>
                        @elseif ($row['outdated'])
                            <flux:badge color="amber" size="sm">{{ __('ui.dashboard.outdated') }}</flux:badge>
                        @endif
                    </div>
                    <span class="shrink-0 tabular-nums text-sm text-zinc-500">{{ __('ui.dashboard.version_devices', ['count' => Format::number($row['devices'])]) }}</span>
                </div>
            @endforeach

            @if ($versions['unreported_devices'] > 0)
                <flux:text size="sm" class="mt-2 text-zinc-500">{{ __('ui.dashboard.unreported_version', ['count' => Format::number($versions['unreported_devices'])]) }}</flux:text>
            @endif
            @if ($versions['latest'] === null)
                <flux:text size="sm" class="mt-2 text-zinc-500">{{ __('ui.dashboard.versions_hint') }}</flux:text>
            @endif
        </flux:card>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('ui.dashboard.recent_activity') }}</flux:heading>

            @forelse ($activity as $log)
                <div wire:key="activity-{{ $log->id }}" class="flex items-start justify-between gap-4 border-t border-zinc-100 py-3 first:border-t-0 dark:border-zinc-800">
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ $log->action->label() }}</div>
                        <div class="truncate text-xs text-zinc-500">
                            {{ $log->user?->name ?? ($log->device ? $log->device->name : __('ui.common.system')) }}
                            @if ($log->shop)
                                · <a class="underline" href="{{ route('shops.show', $log->shop) }}" wire:navigate>{{ $log->shop->name }}</a>
                            @endif
                        </div>
                    </div>
                    <x-datetime :value="$log->created_at" ago class="shrink-0 text-xs text-zinc-500" />
                </div>
            @empty
                <flux:text class="py-6 text-center text-zinc-500">{{ __('ui.dashboard.no_activity') }}</flux:text>
            @endforelse
        </flux:card>

        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('ui.dashboard.recent_errors') }}</flux:heading>

            @forelse ($syncErrors as $device)
                <div wire:key="error-{{ $device->id }}" class="border-t border-zinc-100 py-3 first:border-t-0 dark:border-zinc-800">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 text-sm font-medium">
                            <a class="underline" href="{{ route('shops.show', $device->shop) }}" wire:navigate>{{ $device->shop->name }}</a>
                            <span class="text-zinc-500">· {{ $device->name }}</span>
                        </div>
                        <x-datetime :value="$device->last_sync_error_at" ago class="shrink-0 text-xs text-zinc-500" />
                    </div>
                    <div class="mt-1 line-clamp-2 text-xs text-red-600 dark:text-red-400" dir="auto">{{ $device->last_sync_error }}</div>
                </div>
            @empty
                <flux:text class="py-6 text-center text-zinc-500">{{ __('ui.dashboard.no_errors') }}</flux:text>
            @endforelse
        </flux:card>
    </div>
</div>

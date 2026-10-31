@props(['shop', 'current'])

@php
    $tabs = [
        'overview' => ['shops.show', 'home'],
        'dashboard' => ['shops.dashboard', 'chart-bar'],
        'orders' => ['shops.orders', 'shopping-bag'],
        'data' => ['shops.data', 'circle-stack'],
    ];
@endphp

<nav class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700" aria-label="{{ $shop->name }}">
    @foreach ($tabs as $key => [$route, $icon])
        <a
            href="{{ route($route, $shop) }}"
            wire:navigate
            @if ($current === $key) aria-current="page" @endif
            @class([
                'flex items-center gap-2 rounded-t-lg px-4 py-2 text-sm',
                'border-b-2 border-blue-600 font-medium text-zinc-900 dark:text-white' => $current === $key,
                'text-zinc-500 hover:text-zinc-900 dark:hover:text-white' => $current !== $key,
            ])
        >
            <flux:icon :name="$icon" class="size-4" />
            {{ __('ui.shop_tabs.'.$key) }}
        </a>
    @endforeach
    {{-- Whether this page is receiving changes live. Without the socket it still refreshes, slowly. --}}
    <span
        x-data="{
            state: 'connecting',
            init() {
                const connection = window.Echo?.connector?.pusher?.connection;
                if (! connection) { this.state = 'offline'; return; }
                this.state = connection.state === 'connected' ? 'live' : 'connecting';
                connection.bind('state_change', ({ current }) => this.state = current === 'connected' ? 'live' : (current === 'connecting' || current === 'initialized' ? 'connecting' : 'offline'));
            },
        }"
        class="ms-auto flex items-center gap-1.5 self-center px-2 text-xs text-zinc-500"
        data-live-indicator
        :title="state === 'live' ? @js(__('ui.live.live_hint')) : @js(__('ui.live.offline_hint'))"
    >
        <span class="inline-block size-2 rounded-full" :class="{ 'bg-green-500': state === 'live', 'bg-amber-500 animate-pulse': state === 'connecting', 'bg-zinc-400': state === 'offline' }"></span>
        <span x-text="state === 'live' ? @js(__('ui.live.live')) : (state === 'connecting' ? @js(__('ui.live.connecting')) : @js(__('ui.live.offline')))"></span>
    </span>
</nav>

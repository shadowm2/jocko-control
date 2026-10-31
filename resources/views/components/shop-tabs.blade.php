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
</nav>

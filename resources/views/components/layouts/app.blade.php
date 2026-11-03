<x-layouts.base :title="$title ?? null">
    <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <flux:sidebar.brand href="{{ route('dashboard') }}" name="{{ __('ui.app_name') }}" wire:navigate>
                <x-slot name="logo" class="flex size-8 items-center justify-center rounded-md bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                    <flux:icon.server-stack variant="mini" class="size-5" />
                </x-slot>
            </flux:sidebar.brand>
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                {{ __('ui.nav.dashboard') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="building-storefront" :href="route('shops.index')" :current="request()->routeIs('shops.*')" wire:navigate>
                {{ __('ui.nav.shops') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="inbox-arrow-down" :href="route('events.index')" :current="request()->routeIs('events.*')" wire:navigate>
                {{ __('ui.nav.events') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="clipboard-document-list" :href="route('audit.index')" :current="request()->routeIs('audit.*')" wire:navigate>
                {{ __('ui.nav.audit') }}
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:spacer />

        <x-locale-switcher class="px-1" />

        <div class="flex items-center justify-between gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
            <div class="min-w-0 text-sm">
                <div class="truncate font-medium">{{ auth()->user()->name }}</div>
                <div class="truncate text-xs text-zinc-500" dir="ltr">{{ auth()->user()->email }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" size="sm" variant="ghost" icon="arrow-left-start-on-rectangle" :aria-label="__('ui.nav.logout')" :tooltip="__('ui.nav.logout')" />
            </form>
        </div>
    </flux:sidebar>

    <flux:header class="border-b border-zinc-200 lg:hidden dark:border-zinc-700">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" :aria-label="__('ui.nav.menu')" />
        <flux:spacer />
        <span class="text-sm font-medium">{{ __('ui.app_name') }}</span>
    </flux:header>

    <flux:main container>
        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" class="mb-6" :heading="session('status')" />
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts.base>

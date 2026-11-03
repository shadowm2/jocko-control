<x-layouts.base :title="$title ?? null">
    <main class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-sm">
            {{ $slot }}

            <x-locale-switcher class="mt-6 justify-center" />
        </div>
    </main>
</x-layouts.base>

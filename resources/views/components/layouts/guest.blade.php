<x-layouts.base :title="$title ?? null">
    <main class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-sm">
            {{ $slot }}
        </div>
    </main>
</x-layouts.base>

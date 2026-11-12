<div class="mx-auto max-w-3xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('ui.notifications.title') }}</flux:heading>
            <flux:subheading>{{ __('ui.notifications.subtitle') }}</flux:subheading>
        </div>
        @if ($unread > 0)
            <flux:button size="sm" icon="check" wire:click="markAllRead" data-mark-all-read>{{ __('ui.notifications.mark_all_read') }}</flux:button>
        @endif
    </div>

    @forelse ($notifications as $notification)
        @php($data = $notification->data)
        <flux:card wire:key="notification-{{ $notification->id }}" class="space-y-2" data-notification="{{ $notification->read_at ? 'read' : 'unread' }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    @unless ($notification->read_at)
                        <span class="size-2 rounded-full bg-blue-500" aria-hidden="true"></span>
                    @endunless
                    <flux:heading>{{ $data['title'] ?? '' }}</flux:heading>
                </div>
                <span class="text-sm text-zinc-500">{{ \App\Support\Format::ago($notification->created_at) }}</span>
            </div>
            <ul class="list-inside list-disc space-y-1 text-sm">
                @foreach ($data['lines'] ?? [] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
            <div class="flex flex-wrap gap-2">
                @isset($data['shop_id'])
                    <flux:button size="xs" variant="ghost" :href="route('shops.dashboard', $data['shop_id'])" wire:navigate>{{ __('ui.notifications.open_shop') }}</flux:button>
                @endisset
                @unless ($notification->read_at)
                    <flux:button size="xs" variant="ghost" wire:click="markRead('{{ $notification->id }}')">{{ __('ui.notifications.mark_read') }}</flux:button>
                @endunless
            </div>
        </flux:card>
    @empty
        <flux:card>
            <flux:text class="py-6 text-center text-zinc-500" data-no-notifications>{{ __('ui.notifications.none') }}</flux:text>
        </flux:card>
    @endforelse

    {{ $notifications->links() }}
</div>

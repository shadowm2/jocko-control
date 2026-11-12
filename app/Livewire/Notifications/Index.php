<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\AuthorizesAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The administrator's inbox: the daily reports shops sent to the `internal` channel.
 */
class Index extends Component
{
    use AuthorizesAdmin, WithPagination;

    public function markRead(string $id): void
    {
        auth()->user()?->unreadNotifications()->whereKey($id)->first()?->markAsRead();
    }

    public function markAllRead(): void
    {
        auth()->user()?->unreadNotifications->markAsRead();
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.notifications.index', [
            'notifications' => $user?->notifications()->latest()->paginate(15),
            'unread' => $user?->unreadNotifications()->count() ?? 0,
        ])->layout('components.layouts.app', ['title' => __('ui.notifications.title')]);
    }
}

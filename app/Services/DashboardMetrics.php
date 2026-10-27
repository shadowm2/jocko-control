<?php

namespace App\Services;

use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Enums\ShopStatus;
use App\Models\AuditLog;
use App\Models\RemoteCommand;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardMetrics
{
    /**
     * Counting a very large inbox table is slow, so the total is cached briefly.
     */
    private const EVENT_COUNT_TTL_SECONDS = 60;

    /**
     * @return array{shops_total: int, shops_active: int, shops_suspended: int, devices_active: int, devices_online: int, devices_offline: int, devices_never_connected: int, devices_failing: int, events_total: int, commands_pending: int}
     */
    public function summary(): array
    {
        $shops = Shop::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $cutoff = ShopDevice::onlineCutoff();

        // One pass over the devices of active shops; revoked devices and the
        // devices of suspended shops can't connect and are excluded.
        $devices = ShopDevice::query()
            ->connectable()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(shop_devices.last_seen_at >= ?), 0) as online', [$cutoff])
            ->selectRaw('coalesce(sum(shop_devices.last_seen_at < ?), 0) as offline', [$cutoff])
            ->selectRaw('coalesce(sum(shop_devices.last_seen_at is null), 0) as never_connected')
            ->first();

        return [
            'shops_total' => (int) $shops->sum(),
            'shops_active' => (int) ($shops[ShopStatus::Active->value] ?? 0),
            'shops_suspended' => (int) ($shops[ShopStatus::Suspended->value] ?? 0),
            'devices_active' => (int) $devices->total,
            'devices_online' => (int) $devices->online,
            'devices_offline' => (int) $devices->offline,
            'devices_never_connected' => (int) $devices->never_connected,
            'devices_failing' => ShopDevice::query()->withRecentSyncFailure()->count(),
            'events_total' => Cache::remember('jocko.metrics.events_total', self::EVENT_COUNT_TTL_SECONDS, fn (): int => SyncEvent::query()->count()),
            'commands_pending' => RemoteCommand::query()
                ->where('status', CommandStatus::Queued->value)
                ->where('expires_at', '>', now())
                ->count(),
        ];
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function recentActivity(int $limit = 10): Collection
    {
        return AuditLog::query()->with(['user:id,name', 'shop:id,name', 'device:id,name'])->latest('created_at')->latest('id')->limit($limit)->get();
    }

    /**
     * @return Collection<int, ShopDevice>
     */
    public function recentSyncErrors(int $limit = 10): Collection
    {
        return ShopDevice::query()
            ->with('shop:id,name,slug,status')
            ->where('status', DeviceStatus::Active->value)
            ->whereNotNull('last_sync_error')
            ->orderByDesc('last_sync_error_at')
            ->limit($limit)
            ->get();
    }
}

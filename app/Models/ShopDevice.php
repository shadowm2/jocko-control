<?php

namespace App\Models;

use App\Enums\DeviceConnectivity;
use App\Enums\DeviceStatus;
use App\Enums\ShopStatus;
use Database\Factories\ShopDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'app_version', 'database_schema_version', 'status', 'last_seen_at', 'last_sync_at', 'last_sync_error', 'last_sync_error_at', 'metadata', 'revoked_at'])]
class ShopDevice extends Model
{
    /** @use HasFactory<ShopDeviceFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_sync_error_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasMany<SyncEvent, $this>
     */
    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class, 'device_id');
    }

    /**
     * @return HasMany<RemoteCommand, $this>
     */
    public function remoteCommands(): HasMany
    {
        return $this->hasMany(RemoteCommand::class, 'device_id');
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'device_id');
    }

    public function isActive(): bool
    {
        return $this->status === DeviceStatus::Active;
    }

    public function isRevoked(): bool
    {
        return $this->status === DeviceStatus::Revoked;
    }

    /**
     * Derive the device's connectivity from server-side timestamps only.
     */
    public function connectivity(): DeviceConnectivity
    {
        if ($this->isRevoked()) {
            return DeviceConnectivity::Revoked;
        }

        if ($this->shop?->isSuspended()) {
            return DeviceConnectivity::ShopSuspended;
        }

        if ($this->last_seen_at === null) {
            return DeviceConnectivity::NeverConnected;
        }

        return $this->last_seen_at->greaterThanOrEqualTo(self::onlineCutoff())
            ? DeviceConnectivity::Online
            : DeviceConnectivity::Offline;
    }

    public function hasUnresolvedSyncError(): bool
    {
        return $this->last_sync_error !== null;
    }

    public static function onlineCutoff(): Carbon
    {
        return now()->subMinutes((int) config('jocko.online_threshold_minutes'));
    }

    /**
     * Active devices of active shops that checked in within the online threshold.
     *
     * @param  Builder<ShopDevice>  $query
     */
    public function scopeOnline(Builder $query): void
    {
        $query->connectable()->where('shop_devices.last_seen_at', '>=', self::onlineCutoff());
    }

    /**
     * Active devices of active shops that connected before but not recently.
     *
     * @param  Builder<ShopDevice>  $query
     */
    public function scopeOffline(Builder $query): void
    {
        $query->connectable()
            ->whereNotNull('shop_devices.last_seen_at')
            ->where('shop_devices.last_seen_at', '<', self::onlineCutoff());
    }

    /**
     * @param  Builder<ShopDevice>  $query
     */
    public function scopeNeverConnected(Builder $query): void
    {
        $query->connectable()->whereNull('shop_devices.last_seen_at');
    }

    /**
     * Devices that are allowed to talk to the central server: active, in an active shop.
     *
     * @param  Builder<ShopDevice>  $query
     */
    public function scopeConnectable(Builder $query): void
    {
        $query->where('shop_devices.status', DeviceStatus::Active->value)
            ->whereHas('shop', fn (Builder $shop) => $shop->where('status', ShopStatus::Active->value));
    }

    /**
     * @param  Builder<ShopDevice>  $query
     */
    public function scopeWithRecentSyncFailure(Builder $query): void
    {
        $query->where('shop_devices.status', DeviceStatus::Active->value)
            ->whereNotNull('shop_devices.last_sync_error')
            ->where('shop_devices.last_sync_error_at', '>=', now()->subHours((int) config('jocko.sync_failure_window_hours')));
    }
}

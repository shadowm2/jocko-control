<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceManagementService
{
    /**
     * Sanctum ability carried by every device token.
     */
    public const ABILITY = 'device:access';

    /**
     * Keys of the heartbeat's local_status object that are kept as diagnostics.
     */
    public const LOCAL_STATUS_KEYS = ['state', 'pending_events', 'database_ok', 'disk_free_mb', 'uptime_seconds'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function register(Shop $shop, string $name, User $admin): IssuedDevice
    {
        return DB::transaction(function () use ($shop, $name, $admin): IssuedDevice {
            $device = $shop->devices()->create(['name' => $name, 'status' => DeviceStatus::Active]);
            $token = $device->createToken('device-access', [self::ABILITY]);

            $this->audit->record(AuditAction::DeviceCreated, $admin, $shop, $device, ['name' => $name]);

            return new IssuedDevice($device, $token->plainTextToken);
        });
    }

    /**
     * Invalidate every existing credential of the device and issue a new one.
     */
    public function rotateToken(ShopDevice $device, User $admin): IssuedDevice
    {
        return DB::transaction(function () use ($device, $admin): IssuedDevice {
            $device = ShopDevice::query()->lockForUpdate()->findOrFail($device->getKey());

            if ($device->isRevoked()) {
                throw ValidationException::withMessages(['device' => __('ui.devices.already_revoked')]);
            }

            $device->tokens()->delete();
            $token = $device->createToken('device-access', [self::ABILITY]);

            $this->audit->record(AuditAction::DeviceTokenRotated, $admin, $device->shop_id, $device);

            return new IssuedDevice($device, $token->plainTextToken);
        });
    }

    public function revoke(ShopDevice $device, User $admin): ShopDevice
    {
        return DB::transaction(function () use ($device, $admin): ShopDevice {
            $device = ShopDevice::query()->lockForUpdate()->findOrFail($device->getKey());

            if ($device->isRevoked()) {
                return $device;
            }

            $device->tokens()->delete();
            $device->update(['status' => DeviceStatus::Revoked, 'revoked_at' => now()]);

            $this->audit->record(AuditAction::DeviceRevoked, $admin, $device->shop_id, $device);

            return $device;
        });
    }

    /**
     * Record that the device made an authenticated request. Uses the server clock.
     */
    public function touch(ShopDevice $device): void
    {
        $device->forceFill(['last_seen_at' => now()])->save();
    }

    /**
     * @param  array<string, mixed>  $data  Validated heartbeat payload.
     */
    public function recordHeartbeat(ShopDevice $device, array $data): ShopDevice
    {
        $metadata = $device->metadata ?? [];

        if (isset($data['local_status']) && is_array($data['local_status'])) {
            $metadata['local_status'] = array_intersect_key($data['local_status'], array_flip(self::LOCAL_STATUS_KEYS));
            $metadata['local_status_at'] = now()->toIso8601String();
        }

        $device->forceFill([
            'last_seen_at' => now(),
            'app_version' => $data['app_version'] ?? $device->app_version,
            'database_schema_version' => $data['database_schema_version'] ?? $device->database_schema_version,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $report = $data['sync'] ?? null;

        if (is_array($report)) {
            if (($report['status'] ?? null) === 'success') {
                $this->markSyncSucceeded($device, save: false);
            } elseif (($report['status'] ?? null) === 'failed') {
                $error = Str::limit((string) ($report['error'] ?? 'Device reported a failed synchronization.'), 1000, '');
                $isNew = $device->last_sync_error !== $error;

                $this->markSyncFailed($device, $error, save: false);

                if ($isNew) {
                    $this->audit->record(AuditAction::SyncReportedFailure, shop: $device->shop_id, device: $device, metadata: ['error' => $error]);
                }
            }
        }

        $device->save();

        return $device;
    }

    public function markSyncSucceeded(ShopDevice $device, bool $save = true): void
    {
        $device->forceFill([
            'last_sync_at' => now(),
            'last_sync_error' => null,
            'last_sync_error_at' => null,
        ]);

        if ($save) {
            $device->save();
        }
    }

    public function markSyncFailed(ShopDevice $device, string $error, bool $save = true): void
    {
        $device->forceFill([
            'last_sync_error' => Str::limit($error, 1000, ''),
            'last_sync_error_at' => now(),
        ]);

        if ($save) {
            $device->save();
        }
    }
}

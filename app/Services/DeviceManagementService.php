<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Models\DeviceBacklogSample;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
    private const BACKLOG_SAMPLE_MINUTES = 5;

    public const LOCAL_STATUS_KEYS = ['state', 'pending_events', 'oldest_pending_seconds', 'database_ok', 'disk_free_mb', 'uptime_seconds'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MirrorCheck $mirror,
    ) {}

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
        $current = $device->getAttribute('metadata');
        $updates = [];
        $status = [];

        if (isset($data['local_status']) && is_array($data['local_status'])) {
            $status = array_intersect_key($data['local_status'], array_flip(self::LOCAL_STATUS_KEYS));
            $updates['local_status'] = $status;
            $updates['local_status_at'] = now()->toIso8601String();
        }

        $counts = $this->cleanRecordCounts($data['record_counts'] ?? null);

        if ($counts !== null) {
            $updates['record_counts'] = $counts;
            $updates['record_counts_at'] = now()->toIso8601String();
            $updates['mirror'] = $this->mirror->compare($device->shop_id, $counts);
        }

        $metadata = [...(is_array($current) ? $current : []), ...$updates];

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
        $this->sampleBacklog($device, $status);

        return $device;
    }

    /**
     * Entity type => the shop's alive and removed record counts, keeping only
     * well-formed type names.
     *
     * @return array<string, array{alive: int, removed: int}>|null
     */
    private function cleanRecordCounts(mixed $counts): ?array
    {
        if (! is_array($counts) || $counts === []) {
            return null;
        }

        $clean = [];

        foreach ($counts as $entity => $row) {
            if (is_string($entity) && preg_match('/^[a-z0-9_]{1,60}$/', $entity) === 1 && is_array($row)) {
                $clean[$entity] = ['alive' => (int) ($row['alive'] ?? 0), 'removed' => (int) ($row['removed'] ?? 0)];
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Keep a point of the backlog history, at most one every few minutes however often the
     * device reports.
     *
     * @param  array<string, mixed>  $status
     */
    private function sampleBacklog(ShopDevice $device, array $status): void
    {
        if (! isset($status['pending_events']) || ! Cache::add('jocko.backlog-sample.'.$device->getKey(), true, now()->addMinutes(self::BACKLOG_SAMPLE_MINUTES))) {
            return;
        }

        DeviceBacklogSample::query()->create([
            'device_id' => $device->getKey(),
            'pending_events' => (int) $status['pending_events'],
            'oldest_pending_seconds' => isset($status['oldest_pending_seconds']) ? (int) $status['oldest_pending_seconds'] : null,
            'sampled_at' => now(),
        ]);
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

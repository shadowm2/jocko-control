<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\ShopRecord;
use Carbon\CarbonImmutable;

/**
 * Compares the record counts a shop reports with the copy kept here, so a gap
 * is found before a restore needs the missing records.
 *
 * The shop only reports when it has nothing left to send, so the two copies are
 * compared the moment a report arrives and the outcome is kept on the device.
 *
 * @phpstan-type Row array{entity: string, shop_alive: int, shop_removed: int, control_alive: int, control_removed: int, missing: int, extra: int}
 * @phpstan-type Result array{status: string, rows: list<Row>, missing: int, extra: int, resendable: list<string>}
 */
class MirrorCheck
{
    public const UNKNOWN = 'unknown';

    public const OK = 'ok';

    public const DRIFT = 'drift';

    /** A report older than this is shown but flagged as old. */
    public const STALE_HOURS = 6;

    /**
     * @param  array<string, array{alive: int, removed: int}>  $reported
     * @return Result
     */
    public function compare(string $shopId, array $reported): array
    {
        $ours = ShopRecord::query()
            ->forShop($shopId)
            ->selectRaw('entity, sum(case when removed_at is null then 1 else 0 end) as alive, sum(case when removed_at is null then 0 else 1 end) as removed')
            ->groupBy('entity')
            ->get()
            ->keyBy('entity');

        $off = [];

        foreach ($reported as $entity => $counts) {
            $row = $ours->get($entity);
            $controlAlive = (int) ($row?->getAttribute('alive') ?? 0);
            $controlRemoved = (int) ($row?->getAttribute('removed') ?? 0);
            $shopTotal = $counts['alive'] + $counts['removed'];

            if ($counts['alive'] === $controlAlive && $counts['removed'] === $controlRemoved) {
                continue;
            }

            $off[] = [
                'entity' => (string) $entity,
                'shop_alive' => $counts['alive'],
                'shop_removed' => $counts['removed'],
                'control_alive' => $controlAlive,
                'control_removed' => $controlRemoved,
                'missing' => max(0, $shopTotal - $controlAlive - $controlRemoved),
                'extra' => max(0, $controlAlive + $controlRemoved - $shopTotal),
            ];
        }

        return [
            'status' => $off === [] ? self::OK : self::DRIFT,
            'rows' => $off,
            'missing' => array_sum(array_column($off, 'missing')),
            'extra' => array_sum(array_column($off, 'extra')),
            // Records the shop has that Control lacks, or whose deleted state differs, can be sent again.
            'resendable' => array_column(array_filter($off, fn (array $row): bool => $row['shop_alive'] + $row['shop_removed'] >= $row['control_alive'] + $row['control_removed']), 'entity'),
        ];
    }

    /**
     * What the shop's reporting device last found.
     *
     * @return array{status: string, checked_at: ?CarbonImmutable, stale: bool, rows: list<Row>, missing: int, extra: int, resendable: list<string>, device: ?ShopDevice}
     */
    public function check(Shop $shop): array
    {
        $device = null;
        $at = null;

        foreach ($shop->devices()->where('status', DeviceStatus::Active->value)->get() as $candidate) {
            $reportedAt = $this->metadataOf($candidate)['record_counts_at'] ?? null;

            if (is_string($reportedAt) && ($at === null || CarbonImmutable::parse($reportedAt)->greaterThan($at))) {
                $device = $candidate;
                $at = CarbonImmutable::parse($reportedAt);
            }
        }

        $stored = $device === null ? null : ($this->metadataOf($device)['mirror'] ?? null);

        if ($device === null || $at === null || ! is_array($stored)) {
            return ['status' => self::UNKNOWN, 'checked_at' => null, 'stale' => false, 'rows' => [], 'missing' => 0, 'extra' => 0, 'resendable' => [], 'device' => null];
        }

        return [
            'status' => (string) $stored['status'],
            'checked_at' => $at,
            'stale' => $at->lt(now()->subHours(self::STALE_HOURS)),
            'rows' => $stored['rows'],
            'missing' => (int) $stored['missing'],
            'extra' => (int) $stored['extra'],
            'resendable' => $stored['resendable'],
            'device' => $device,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataOf(ShopDevice $device): array
    {
        $metadata = $device->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * Shops whose copy here is out of step with what they last reported, most
     * missing records first.
     *
     * @return list<array{shop: Shop, missing: int, extra: int, entities: int}>
     */
    public function drifted(int $limit = 10): array
    {
        $found = [];

        foreach (Shop::query()->whereHas('devices', fn ($devices) => $devices->connectable())->get() as $shop) {
            $result = $this->check($shop);

            if ($result['status'] === self::DRIFT) {
                $found[] = ['shop' => $shop, 'missing' => $result['missing'], 'extra' => $result['extra'], 'entities' => count($result['rows'])];
            }
        }

        usort($found, fn (array $a, array $b): int => [$b['missing'], $b['extra']] <=> [$a['missing'], $a['extra']]);

        return array_slice($found, 0, $limit);
    }
}

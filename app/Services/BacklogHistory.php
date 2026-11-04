<?php

namespace App\Services;

use App\Models\DeviceBacklogSample;
use App\Models\Shop;
use Carbon\CarbonImmutable;

/**
 * How far behind a shop's devices were over the last day or week: the most changes
 * any of its devices had waiting, per slice of time. A slice with no sample (the
 * device was silent) is a gap, not a zero.
 *
 * @phpstan-type Point array{at: CarbonImmutable, value: ?int}
 */
class BacklogHistory
{
    /** range => [slices, seconds per slice] */
    public const RANGES = ['24h' => [48, 1800], '7d' => [56, 10800]];

    /**
     * @return list<Point>
     */
    public function series(Shop $shop, string $range): array
    {
        [$slices, $seconds] = self::RANGES[$range] ?? self::RANGES['24h'];

        $end = (int) floor(now()->getTimestamp() / $seconds) * $seconds + $seconds;
        $start = $end - $slices * $seconds;

        $samples = DeviceBacklogSample::query()
            ->whereIn('device_id', $shop->devices()->select('id'))
            ->where('sampled_at', '>=', CarbonImmutable::createFromTimestampUTC($start))
            ->where('sampled_at', '<', CarbonImmutable::createFromTimestampUTC($end))
            ->get(['pending_events', 'sampled_at']);

        $peaks = [];

        foreach ($samples as $sample) {
            $slice = intdiv($sample->sampled_at->getTimestamp() - $start, $seconds);
            $peaks[$slice] = max($peaks[$slice] ?? 0, $sample->pending_events);
        }

        $points = [];

        for ($i = 0; $i < $slices; $i++) {
            $points[] = ['at' => CarbonImmutable::createFromTimestampUTC($start + $i * $seconds), 'value' => $peaks[$i] ?? null];
        }

        return $points;
    }
}

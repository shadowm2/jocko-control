<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a shop owner wants to know at the end of the day, from the mirror: what
 * was sold and collected today, whether the till matched, and what is waiting.
 *
 * "Today" is the shop's own day (its timezone). Everything is plain numbers and
 * strings so the digest can be stored in a notification as it is.
 *
 * @phpstan-type Digest array{
 *     date: string,
 *     orders: int,
 *     sales: float,
 *     collected: float,
 *     methods: array<string, float>,
 *     cash_close: ?array{expected: float, counted: float, difference: float},
 *     cheques_overdue_count: int,
 *     cheques_overdue_total: float,
 *     cheques_soon_count: int,
 *     cheques_soon_total: float,
 *     debt_total: float,
 *     low_stock_count: int
 * }
 */
class ShopDigest
{
    /** Cheques due within this many days (today included) are listed as coming up. */
    public const CHEQUE_SOON_DAYS = 3;

    public function __construct(private readonly ShopDashboardMetrics $metrics) {}

    /**
     * @return Digest
     */
    public function build(Shop $shop, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($shop->timezone);
        $day = $now->toDateString();
        $dashboard = $this->metrics->for($shop);

        [$orders, $sales] = $this->sold($shop, $now);
        [$collected, $methods] = $this->collected($shop, $day);

        return [
            'date' => $day,
            'orders' => $orders,
            'sales' => $sales,
            'collected' => $collected,
            'methods' => $methods,
            'cash_close' => $this->cashClose($shop, $day),
            ...$this->cheques($shop, $now),
            'debt_total' => (float) $dashboard['debtTotal'],
            'low_stock_count' => (int) $dashboard['lowStockCount'],
        ];
    }

    /**
     * Orders dated today that are not cancelled, and what they bill.
     *
     * @return array{0: int, 1: float}
     */
    private function sold(Shop $shop, CarbonImmutable $now): array
    {
        $from = $now->startOfDay()->utc()->format('Y-m-d H:i:s.v');
        $to = $now->endOfDay()->utc()->format('Y-m-d H:i:s.v');

        $orders = ShopRecord::query()->forShop($shop)->ofEntity('order')->alive()
            ->whereBetween('dated_at', [$from, $to])
            ->whereRaw("coalesce(json_unquote(json_extract(data, '$.status')), '') != 'cancelled'")
            ->pluck('local_id');

        if ($orders->isEmpty()) {
            return [0, 0.0];
        }

        $billed = DB::table('shop_records')
            ->where('shop_id', $shop->getKey())
            ->where('entity', 'order_item')
            ->whereNull('removed_at')
            ->whereIn('parent_id', $orders->all())
            ->sum('amount');

        return [$orders->count(), (float) $billed];
    }

    /**
     * Payments written with today's date (a calendar date), refunds netted, bounced cheques left out.
     *
     * @return array{0: float, 1: array<string, float>}
     */
    private function collected(Shop $shop, string $day): array
    {
        $rows = ShopRecord::query()->forShop($shop)->ofEntity('payment')->alive()->notBounced()
            ->whereBetween('dated_at', [CarbonImmutable::parse($day, 'UTC')->startOfDay(), CarbonImmutable::parse($day, 'UTC')->endOfDay()])
            ->selectRaw("coalesce(json_unquote(json_extract(data, '$.method')), '') as method, sum(amount) as total")
            ->groupBy('method')
            ->get();

        $methods = [];

        foreach ($rows as $row) {
            $methods[(string) $row->getAttribute('method')] = (float) $row->getAttribute('total');
        }

        arsort($methods);

        return [(float) array_sum($methods), $methods];
    }

    /**
     * @return array{expected: float, counted: float, difference: float}|null
     */
    private function cashClose(Shop $shop, string $day): ?array
    {
        $close = ShopRecord::query()->forShop($shop)->ofEntity('cash_close')->alive()
            ->whereBetween('dated_at', [CarbonImmutable::parse($day, 'UTC')->startOfDay(), CarbonImmutable::parse($day, 'UTC')->endOfDay()])
            ->orderByDesc('local_id')
            ->first();

        return $close === null ? null : [
            'expected' => (float) $close->field('expected_cash'),
            'counted' => (float) $close->field('counted_cash'),
            'difference' => (float) $close->field('difference'),
        ];
    }

    /**
     * @return array{cheques_overdue_count: int, cheques_overdue_total: float, cheques_soon_count: int, cheques_soon_total: float}
     */
    private function cheques(Shop $shop, CarbonImmutable $now): array
    {
        $today = $now->toDateString();
        $soonUntil = $now->addDays(self::CHEQUE_SOON_DAYS - 1)->toDateString();
        $counts = ['cheques_overdue_count' => 0, 'cheques_overdue_total' => 0.0, 'cheques_soon_count' => 0, 'cheques_soon_total' => 0.0];

        $pending = ShopRecord::query()->forShop($shop)->ofEntity('payment')->alive()
            ->where('data->method', 'cheque')->where('data->cheque_status', 'pending')->get();

        foreach ($pending as $cheque) {
            $dueOn = $cheque->field('cheque_due_at');
            $due = is_string($dueOn) && $dueOn !== '' ? substr($dueOn, 0, 10) : null;

            if ($due === null) {
                continue;
            }

            if ($due < $today) {
                $counts['cheques_overdue_count']++;
                $counts['cheques_overdue_total'] += (float) $cheque->amount;
            } elseif ($due <= $soonUntil) {
                $counts['cheques_soon_count']++;
                $counts['cheques_soon_total'] += (float) $cheque->amount;
            }
        }

        return $counts;
    }
}

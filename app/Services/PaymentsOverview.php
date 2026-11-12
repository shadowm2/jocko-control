<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopRecord;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the shops collected, from the payments they mirror here: today, this
 * month and the last 30 days, in total, per shop and per payment method.
 *
 * A payment counts on the date written on it (`paid_at`), a calendar date with no
 * time, in the shop's day. Refunds are negative payments, so they net out.
 *
 * @phpstan-type Window array{from: string, to: string}
 */
class PaymentsOverview
{
    public const WINDOWS = ['today', 'month', 'days30'];

    /**
     * The day each window covers, as calendar dates in the display timezone.
     *
     * @return array<string, Window>
     */
    public function windows(): array
    {
        $timezone = (string) config('jocko.display_timezone');
        $today = CarbonImmutable::now($timezone);

        return [
            'today' => ['from' => $today->toDateString(), 'to' => $today->toDateString()],
            'month' => ['from' => Jalali::monthStarts(1, $timezone)[0]->toDateString(), 'to' => $today->toDateString()],
            'days30' => ['from' => $today->subDays(29)->toDateString(), 'to' => $today->toDateString()],
        ];
    }

    /**
     * @return array{
     *     windows: array<string, Window>,
     *     totals: array<string, float>,
     *     byMethod: array<string, array<string, float>>,
     *     shops: list<array{shop: Shop, today: float, month: float, days30: float, methods: array<string, float>}>
     * }
     */
    public function summary(): array
    {
        $windows = $this->windows();
        $totals = array_fill_keys(self::WINDOWS, 0.0);
        $byMethod = array_fill_keys(self::WINDOWS, []);
        $perShop = [];

        foreach (self::WINDOWS as $name) {
            foreach ($this->collected($windows[$name]) as $row) {
                $total = (float) $row->getAttribute('total');
                $method = (string) ($row->getAttribute('method') ?? '');
                $shopId = (string) $row->getAttribute('shop_id');

                $totals[$name] += $total;
                $byMethod[$name][$method] = ($byMethod[$name][$method] ?? 0.0) + $total;
                $perShop[$shopId][$name] = ($perShop[$shopId][$name] ?? 0.0) + $total;

                if ($name === 'month') {
                    $perShop[$shopId]['methods'][$method] = $total;
                }
            }
        }

        $shops = array_values(Shop::query()->orderBy('name')->get()->map(fn (Shop $shop): array => [
            'shop' => $shop,
            'today' => $perShop[$shop->getKey()]['today'] ?? 0.0,
            'month' => $perShop[$shop->getKey()]['month'] ?? 0.0,
            'days30' => $perShop[$shop->getKey()]['days30'] ?? 0.0,
            'methods' => $perShop[$shop->getKey()]['methods'] ?? [],
        ])->sortByDesc('month')->all());

        foreach ($byMethod as $name => $methods) {
            arsort($methods);
            $byMethod[$name] = $methods;
        }

        return compact('windows', 'totals', 'byMethod', 'shops');
    }

    /**
     * Cheques the shops received that are neither cleared nor bounced, with how
     * many are past their due date (a calendar date, in each shop's own day).
     * The list is the most overdue first, cheques with no due date last.
     *
     * @return array{count: int, total: float, overdue_count: int, overdue_total: float, cheques: list<array{shop: Shop, record: ShopRecord, number: ?string, bank: ?string, amount: float, due: ?CarbonImmutable, overdue: bool}>}
     */
    public function chequesDue(int $limit = 15): array
    {
        $rows = ShopRecord::query()
            ->ofEntity('payment')
            ->alive()
            ->where('data->method', 'cheque')
            ->where('data->cheque_status', 'pending')
            ->with('shop:id,name,timezone')
            ->get()
            ->flatMap(function (ShopRecord $record): array {
                $shop = $record->shop;

                if ($shop === null) {
                    return [];
                }

                $dueOn = $record->field('cheque_due_at');
                $due = is_string($dueOn) && $dueOn !== '' ? CarbonImmutable::parse(substr($dueOn, 0, 10), 'UTC') : null;
                $today = CarbonImmutable::now($shop->timezone)->toDateString();

                return [[
                    'shop' => $shop,
                    'record' => $record,
                    'number' => $record->field('cheque_number'),
                    'bank' => $record->field('cheque_bank'),
                    'amount' => (float) $record->amount,
                    'due' => $due,
                    'overdue' => $due !== null && $due->toDateString() < $today,
                ]];
            });

        $overdue = $rows->where('overdue', true);

        return [
            'count' => $rows->count(),
            'total' => (float) $rows->sum('amount'),
            'overdue_count' => $overdue->count(),
            'overdue_total' => (float) $overdue->sum('amount'),
            'cheques' => array_values($rows
                ->sortBy([fn (array $a, array $b): int => ($a['due']?->toDateString() ?? '9999-12-31') <=> ($b['due']?->toDateString() ?? '9999-12-31')])
                ->take($limit)
                ->all()),
        ];
    }

    /**
     * The days the tills were closed in the last `$days` days, and those where
     * the counted cash was not what the day's payments said it should be.
     *
     * @return array{closes: int, differences: int, rows: list<array{shop: Shop, record: ShopRecord, difference: float}>}
     */
    public function cashDifferences(int $days = 14, int $limit = 15): array
    {
        $since = CarbonImmutable::now((string) config('jocko.display_timezone'))->startOfDay()->subDays($days - 1)->toDateString();

        $closes = ShopRecord::query()->ofEntity('cash_close')->alive()->where('dated_at', '>=', $since.' 00:00:00.000');
        $off = (clone $closes)->whereRaw("cast(json_unquote(json_extract(data, '$.difference')) as decimal(18,4)) != 0");

        return [
            'closes' => (clone $closes)->count(),
            'differences' => (clone $off)->count(),
            'rows' => array_values($off->with('shop:id,name')->orderByDesc('dated_at')->limit($limit)->get()
                ->flatMap(fn (ShopRecord $record): array => $record->shop === null ? [] : [['shop' => $record->shop, 'record' => $record, 'difference' => (float) $record->field('difference')]])
                ->all()),
        ];
    }

    /**
     * @param  Window  $window
     * @return Collection<int, ShopRecord>
     */
    private function collected(array $window): Collection
    {
        return ShopRecord::query()
            ->ofEntity('payment')
            ->alive()
            ->notBounced()
            ->whereBetween('dated_at', [
                CarbonImmutable::parse($window['from'], 'UTC')->startOfDay(),
                CarbonImmutable::parse($window['to'], 'UTC')->endOfDay(),
            ])
            ->selectRaw("shop_id, json_unquote(json_extract(data, '$.method')) as method, sum(amount) as total, count(*) as payments")
            ->groupBy('shop_id', 'method')
            ->get();
    }
}

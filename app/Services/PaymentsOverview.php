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

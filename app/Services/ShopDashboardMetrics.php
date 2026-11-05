<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopRecord;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on a shop's dashboard, computed from the mirror the way
 * jocko-shop computes them from its own tables.
 *
 * @phpstan-import-type OrderRow from ShopOrders
 */
class ShopDashboardMetrics
{
    private const LIST_SIZE = 5;

    private const MONTHS = 6;

    private const DAYS = 30;

    /** Purchases that count as spending: ordered, partly or fully received. */
    private const SPENDING = ['ordered', 'partially_received', 'received'];

    public function __construct(
        private readonly ShopOrders $orders,
        private readonly ShopRecordReferences $references,
    ) {}

    /**
     * @return array{
     *     orderCount: int,
     *     statusCounts: array<string, int>,
     *     orderValue: float,
     *     openPurchases: int,
     *     customerCount: int,
     *     lowStockCount: int,
     *     lowStock: list<array{record: ShopRecord, item: ?string, warehouse: ?string, quantity: mixed, minimum: mixed}>,
     *     recentOrders: list<OrderRow>,
     *     recentPurchases: list<array{record: ShopRecord, number: string, supplier: ?string, warehouse: ?string, total: float}>,
     *     cashCloseCount: int,
     *     cashDifferenceCount: int,
     *     cashDifferences: list<array{record: ShopRecord, difference: float}>,
     *     monthLabels: list<string>, monthSales: list<float>, monthPurchases: list<float>,
     *     dayLabels: list<string>, daySales: list<float>
     * }
     */
    public function for(Shop $shop): array
    {
        $statusCounts = $this->entity($shop, 'order')
            ->selectRaw("coalesce(json_unquote(json_extract(data, '$.status')), '') as status, count(*) as total")
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $lowStock = $this->lowStock($shop);

        return [
            'orderCount' => array_sum($statusCounts),
            'statusCounts' => $statusCounts,
            'orderValue' => $this->orderValue($shop),
            'openPurchases' => $this->entity($shop, 'purchase')->whereIn('data->status', ['ordered', 'partially_received'])->count(),
            'customerCount' => $this->entity($shop, 'user')->where('data->type', 'customer')->count(),
            'lowStockCount' => (clone $lowStock)->count(),
            'lowStock' => $this->presentLowStock($shop, $lowStock),
            'recentOrders' => $this->orders->rows($shop, $this->orders->query($shop)->limit(self::LIST_SIZE)->get()),
            'recentPurchases' => $this->recentPurchases($shop),
            ...$this->cashCloses($shop),
            ...$this->trends($shop),
        ];
    }

    /**
     * @return Builder<ShopRecord>
     */
    private function entity(Shop $shop, string $entity): Builder
    {
        return ShopRecord::query()->forShop($shop)->ofEntity($entity)->alive();
    }

    /**
     * Billed value of every order that is not cancelled.
     */
    private function orderValue(Shop $shop): float
    {
        return (float) $this->billedLines($shop, 'order', 'order_item')
            ->whereRaw("coalesce(json_unquote(json_extract(parent.data, '$.status')), '') != 'cancelled'")
            ->sum('line.amount');
    }

    /**
     * Lines (order items, purchase items) joined to their live parent.
     */
    private function billedLines(Shop $shop, string $parent, string $line): QueryBuilder
    {
        return DB::table('shop_records as line')
            ->join('shop_records as parent', function ($join) use ($parent): void {
                $join->on('parent.shop_id', '=', 'line.shop_id')
                    ->on('parent.local_id', '=', 'line.parent_id')
                    ->where('parent.entity', $parent);
            })
            ->where('line.shop_id', $shop->getKey())
            ->where('line.entity', $line)
            ->whereNull('line.removed_at')
            ->whereNull('parent.removed_at');
    }

    /**
     * Stock at or under its minimum, the same rule the shop uses.
     *
     * @return Builder<ShopRecord>
     */
    private function lowStock(Shop $shop): Builder
    {
        $quantity = "cast(json_unquote(json_extract(data, '\$.quantity')) as decimal(18,3))";
        $minimum = "cast(json_unquote(json_extract(data, '\$.min_quantity')) as decimal(18,3))";

        return $this->entity($shop, 'warehouse_item')
            ->whereRaw("json_type(json_extract(data, '\$.min_quantity')) != 'NULL'")
            ->whereRaw("{$quantity} <= {$minimum}");
    }

    /**
     * @param  Builder<ShopRecord>  $lowStock
     * @return list<array{record: ShopRecord, item: ?string, warehouse: ?string, quantity: mixed, minimum: mixed}>
     */
    private function presentLowStock(Shop $shop, Builder $lowStock): array
    {
        $quantity = "cast(json_unquote(json_extract(data, '\$.quantity')) as decimal(18,3))";
        $minimum = "cast(json_unquote(json_extract(data, '\$.min_quantity')) as decimal(18,3))";

        $rows = (clone $lowStock)->orderByRaw("({$minimum} - {$quantity}) desc")->limit(self::LIST_SIZE)->get();
        $references = $this->references->resolve($shop->getKey(), 'warehouse_item', $rows);

        return $rows->map(fn (ShopRecord $row): array => [
            'record' => $row,
            'item' => ($references['item'][$row->field('item_id')] ?? null)?->label,
            'warehouse' => ($references['warehouse'][$row->field('warehouse_id')] ?? null)?->label,
            'quantity' => $row->field('quantity'),
            'minimum' => $row->field('min_quantity'),
        ])->all();
    }

    /**
     * The days the till was closed in the last DAYS days, and those where the
     * counted cash was not what the day's payments said it should be.
     *
     * @return array{cashCloseCount: int, cashDifferenceCount: int, cashDifferences: list<array{record: ShopRecord, difference: float}>}
     */
    private function cashCloses(Shop $shop): array
    {
        $since = CarbonImmutable::now($shop->timezone)->startOfDay()->subDays(self::DAYS - 1)->toDateString();

        $closes = $this->entity($shop, 'cash_close')->where('dated_at', '>=', $since.' 00:00:00.000');
        $off = (clone $closes)->whereRaw("cast(json_unquote(json_extract(data, '\$.difference')) as decimal(18,4)) != 0");

        return [
            'cashCloseCount' => (clone $closes)->count(),
            'cashDifferenceCount' => (clone $off)->count(),
            'cashDifferences' => array_values($off->orderByDesc('dated_at')->limit(self::LIST_SIZE)->get()
                ->map(fn (ShopRecord $record): array => ['record' => $record, 'difference' => (float) $record->field('difference')])
                ->all()),
        ];
    }

    /**
     * @return list<array{record: ShopRecord, number: string, supplier: ?string, warehouse: ?string, total: float}>
     */
    private function recentPurchases(Shop $shop): array
    {
        $purchases = $this->entity($shop, 'purchase')->orderByDesc('local_id')->limit(self::LIST_SIZE)->get();

        if ($purchases->isEmpty()) {
            return [];
        }

        $totals = $this->entity($shop, 'purchase_item')
            ->whereIn('parent_id', $purchases->pluck('local_id')->all())
            ->groupBy('parent_id')
            ->selectRaw('parent_id, coalesce(sum(amount), 0) as total')
            ->pluck('total', 'parent_id');

        $suppliers = $this->entity($shop, 'supplier')
            ->whereIn('local_id', $purchases->map(fn (ShopRecord $purchase) => $purchase->field('supplier_id'))->filter()->all())
            ->get(['local_id', 'data'])
            ->keyBy('local_id');

        $people = ShopRecord::query()->forShop($shop)->ofEntity('user')
            ->whereIn('local_id', $suppliers->map(fn (ShopRecord $supplier) => $supplier->field('user_id'))->filter()->all())
            ->pluck('label', 'local_id');

        $warehouses = ShopRecord::query()->forShop($shop)->ofEntity('warehouse')
            ->whereIn('local_id', $purchases->map(fn (ShopRecord $purchase) => $purchase->field('warehouse_id'))->filter()->all())
            ->pluck('label', 'local_id');

        return $purchases->map(fn (ShopRecord $purchase): array => [
            'record' => $purchase,
            'number' => $purchase->label ?? '#'.$purchase->local_id,
            'supplier' => $people->get($suppliers->get($purchase->field('supplier_id'))?->field('user_id')),
            'warehouse' => $warehouses->get($purchase->field('warehouse_id')),
            'total' => (float) ($totals->get($purchase->local_id) ?? 0),
        ])->all();
    }

    /**
     * Sales and purchases per Jalali month, and sales per day.
     *
     * @return array{monthLabels: list<string>, monthSales: list<float>, monthPurchases: list<float>, dayLabels: list<string>, daySales: list<float>}
     */
    private function trends(Shop $shop): array
    {
        $timezone = $shop->timezone;
        $months = Jalali::monthStarts(self::MONTHS, $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $days = array_map(fn (int $ago): CarbonImmutable => $today->subDays($ago), range(self::DAYS - 1, 0));
        $since = $months[0]->utc();

        $sales = $this->dated($shop, 'order', 'order_item', $since)
            ->whereRaw("coalesce(json_unquote(json_extract(parent.data, '\$.status')), '') != 'cancelled'")
            ->get();
        $purchases = $this->dated($shop, 'purchase', 'purchase_item', $since)
            ->whereIn(DB::raw("json_unquote(json_extract(parent.data, '\$.status'))"), self::SPENDING)
            ->get();

        $perMonth = function (Collection $rows) use ($months, $timezone): array {
            $totals = $rows
                ->groupBy(fn (object $row): string => Jalali::monthKey(CarbonImmutable::parse($row->dated_at, 'UTC'), $timezone))
                ->map(fn (Collection $group): float => (float) $group->sum('total'));

            return array_map(fn (CarbonImmutable $month): float => $totals->get(Jalali::monthKey($month, $timezone), 0.0), $months);
        };

        $salesByDay = $sales
            ->groupBy(fn (object $row): string => CarbonImmutable::parse($row->dated_at, 'UTC')->setTimezone($timezone)->toDateString())
            ->map(fn (Collection $group): float => (float) $group->sum('total'));

        return [
            'monthLabels' => array_map(fn (CarbonImmutable $month): string => Jalali::monthLabel($month, $timezone), $months),
            'monthSales' => $perMonth($sales),
            'monthPurchases' => $perMonth($purchases),
            'dayLabels' => array_map(fn (CarbonImmutable $day): string => Jalali::dayLabel($day, $timezone), $days),
            'daySales' => array_map(fn (CarbonImmutable $day): float => $salesByDay->get($day->toDateString(), 0.0), $days),
        ];
    }

    /**
     * Parents (orders, purchases) dated since a moment with the sum of their lines.
     */
    private function dated(Shop $shop, string $parent, string $line, CarbonImmutable $since): QueryBuilder
    {
        return DB::table('shop_records as parent')
            ->leftJoin('shop_records as line', function ($join) use ($line): void {
                $join->on('line.shop_id', '=', 'parent.shop_id')
                    ->on('line.parent_id', '=', 'parent.local_id')
                    ->where('line.entity', $line)
                    ->whereNull('line.removed_at');
            })
            ->where('parent.shop_id', $shop->getKey())
            ->where('parent.entity', $parent)
            ->whereNull('parent.removed_at')
            ->where('parent.dated_at', '>=', $since->format('Y-m-d H:i:s.v'))
            ->groupBy('parent.id', 'parent.dated_at')
            ->selectRaw('parent.dated_at, coalesce(sum(line.amount), 0) as total');
    }
}

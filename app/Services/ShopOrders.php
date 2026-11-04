<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Shop;
use App\Models\ShopRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Orders of a shop as the shop itself shows them: with customer, car, what was
 * billed and what was paid. Everything is read from the mirror; totals come
 * from the indexed amount/parent_id columns, never from the JSON.
 *
 * @phpstan-type OrderRow array{record: ShopRecord, number: string, date: ?CarbonImmutable, status: ?OrderStatus, payment: PaymentStatus, customer: ?string, mobile: ?string, car: ?string, warehouse: ?string, lines: int, total: float, paid: float}
 */
class ShopOrders
{
    private const DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    public function __construct(private readonly ShopRecordReferences $references) {}

    /**
     * Live orders of the shop, newest first, narrowed by the filters.
     *
     * @param  array{search?: string, status?: string, payment_status?: string, created_from?: string, created_to?: string}  $filters
     * @return Builder<ShopRecord>
     */
    public function query(Shop $shop, array $filters = []): Builder
    {
        $term = strtr(trim($filters['search'] ?? ''), self::DIGITS);
        $status = OrderStatus::tryFrom($filters['status'] ?? '');
        $payment = PaymentStatus::tryFrom($filters['payment_status'] ?? '');

        return ShopRecord::query()
            ->forShop($shop)
            ->ofEntity('order')
            ->alive()
            ->when($term !== '', fn (Builder $query) => $this->matching($query, $shop, $term))
            ->when($status, fn (Builder $query) => $query->where('data->status', $status->value))
            ->when($payment, fn (Builder $query) => $this->withPaymentStatus($query, $payment))
            ->when($this->day($filters['created_from'] ?? null, $shop), fn (Builder $query, CarbonImmutable $day) => $query->where('dated_at', '>=', $day->startOfDay()->utc()))
            ->when($this->day($filters['created_to'] ?? null, $shop), fn (Builder $query, CarbonImmutable $day) => $query->where('dated_at', '<=', $day->endOfDay()->utc()))
            ->orderByDesc('local_id');
    }

    /**
     * Fill in the customer, car, warehouse and money for a page of orders.
     *
     * @param  iterable<ShopRecord>  $orders
     * @return list<OrderRow>
     */
    public function rows(Shop $shop, iterable $orders): array
    {
        $orders = Collection::make($orders)->values();

        if ($orders->isEmpty()) {
            return [];
        }

        $money = $this->money($shop, $orders->pluck('local_id')->all());
        $customers = $this->records($shop, 'user', $orders->map(fn (ShopRecord $order) => $order->field('user_id')));
        $cars = $this->records($shop, 'user_car', $orders->map(fn (ShopRecord $order) => $order->field('user_car_id')));
        $models = $this->records($shop, 'car', $cars->map(fn (ShopRecord $userCar) => $userCar->field('car_id')));
        $warehouses = $this->records($shop, 'warehouse', $orders->map(fn (ShopRecord $order) => $order->field('warehouse_id')));

        return $orders->map(function (ShopRecord $order) use ($money, $customers, $cars, $models, $warehouses): array {
            $customer = $customers->get($order->field('user_id'));
            $userCar = $cars->get($order->field('user_car_id'));
            $totals = $money[$order->local_id] ?? ['lines' => 0, 'total' => 0.0, 'paid' => 0.0];

            return [
                'record' => $order,
                'number' => $order->label ?? '#'.$order->local_id,
                'date' => $order->dated_at?->toImmutable(),
                'status' => OrderStatus::tryFrom((string) $order->field('status')),
                'payment' => PaymentStatus::for($totals['total'], $totals['paid']),
                'customer' => $customer?->label,
                'mobile' => $customer?->field('mobile'),
                'car' => $userCar === null ? null : $models->get($userCar->field('car_id'))?->label,
                'warehouse' => $warehouses->get($order->field('warehouse_id'))?->label,
                ...$totals,
            ];
        })->all();
    }

    /**
     * Everything the invoice page shows.
     *
     * @return array{order: OrderRow, lines: list<array{record: ShopRecord, item: ?string, unit: ?string, quantity: mixed, unit_price: mixed, total: mixed}>, payments: list<array{record: ShopRecord, method: ?string, paid_at: mixed, amount: mixed}>, total: float, paid: float, balance: float}
     */
    public function invoice(Shop $shop, ShopRecord $order): array
    {
        $children = fn (string $entity): Collection => ShopRecord::query()
            ->forShop($shop)->ofEntity($entity)->alive()->where('parent_id', $order->local_id)
            ->orderBy('local_id')->get();

        $lines = $children('order_item');
        $payments = $children('payment')->sortBy(fn (ShopRecord $payment) => [$payment->dated_at, $payment->local_id])->values();
        $items = $this->records($shop, 'item', $lines->map(fn (ShopRecord $line) => $line->field('item_id')));
        $units = $this->records($shop, 'unit', $lines->map(fn (ShopRecord $line) => $line->field('unit_id')));

        $row = $this->rows($shop, [$order])[0];

        return [
            'order' => $row,
            'lines' => $lines->map(fn (ShopRecord $line): array => [
                'record' => $line,
                'item' => $items->get($line->field('item_id'))?->label,
                'unit' => $units->get($line->field('unit_id'))?->field('symbol'),
                'quantity' => $line->field('quantity'),
                'unit_price' => $line->field('unit_price'),
                'total' => $line->field('total'),
            ])->all(),
            'payments' => $payments->map(fn (ShopRecord $payment): array => [
                'record' => $payment,
                'method' => $payment->field('method'),
                'paid_at' => $payment->field('paid_at'),
                'amount' => $payment->field('amount'),
            ])->all(),
            'total' => $row['total'],
            'paid' => $row['paid'],
            'balance' => $row['total'] - $row['paid'],
        ];
    }

    /**
     * What was billed and paid per order, for the given local order ids.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{lines: int, total: float, paid: float}>
     */
    public function money(Shop $shop, array $orderIds): array
    {
        $sums = fn (string $entity): Collection => ShopRecord::query()
            ->forShop($shop)->ofEntity($entity)->alive()
            ->when($entity === 'payment', fn (Builder $query) => $query->notBounced())
            ->whereIn('parent_id', $orderIds)
            ->groupBy('parent_id')
            ->selectRaw('parent_id, count(*) as line_count, coalesce(sum(amount), 0) as total')
            ->get()
            ->keyBy('parent_id');

        $billed = $sums('order_item');
        $paid = $sums('payment');
        $money = [];

        foreach ($orderIds as $id) {
            $money[$id] = [
                'lines' => (int) ($billed->get($id)?->line_count ?? 0),
                'total' => (float) ($billed->get($id)?->total ?? 0),
                'paid' => (float) ($paid->get($id)?->total ?? 0),
            ];
        }

        return $money;
    }

    /**
     * Records of one entity by local id, with what the pages need to name them.
     *
     * @param  iterable<mixed>  $ids
     * @return Collection<int, ShopRecord>
     */
    private function records(Shop $shop, string $entity, iterable $ids): Collection
    {
        $ids = Collection::make($ids)->filter(fn (mixed $id): bool => is_int($id))->unique()->values();

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return ShopRecord::query()
            ->forShop($shop)->ofEntity($entity)->whereIn('local_id', $ids->all())
            ->get(['id', 'entity', 'local_id', 'label', 'data', 'removed_at'])
            ->keyBy('local_id');
    }

    /**
     * By order number, or by the customer's name or mobile.
     *
     * @param  Builder<ShopRecord>  $query
     * @return Builder<ShopRecord>
     */
    private function matching(Builder $query, Shop $shop, string $term): Builder
    {
        $like = '%'.addcslashes($term, '\\%_').'%';

        $customers = ShopRecord::query()
            ->forShop($shop)->ofEntity('user')
            ->where(fn (Builder $user) => $user->where('label', 'like', $like)->orWhere('data->mobile', 'like', $like))
            ->select('local_id');

        return $query->where(fn (Builder $query) => $query->where('label', 'like', $like)->orWhereIn('data->user_id', $customers));
    }

    /**
     * The same rule as PaymentStatus::for(), in SQL so a list can filter on it.
     *
     * @param  Builder<ShopRecord>  $query
     * @return Builder<ShopRecord>
     */
    private function withPaymentStatus(Builder $query, PaymentStatus $status): Builder
    {
        $sum = function (string $entity): string {
            // A bounced cheque was never money, as in ShopRecord::scopeNotBounced().
            $bounced = $entity === 'payment' ? " and coalesce(json_unquote(json_extract(child.data, '$.cheque_status')), '') != 'bounced'" : '';

            return "(select coalesce(sum(child.amount), 0) from shop_records as child where child.shop_id = shop_records.shop_id and child.entity = '{$entity}' and child.parent_id = shop_records.local_id and child.removed_at is null{$bounced})";
        };
        $total = $sum('order_item');
        $paid = $sum('payment');

        return $query->whereRaw(match ($status) {
            PaymentStatus::Unpaid => "round({$paid}, 2) <= 0",
            PaymentStatus::Paid => "round({$paid}, 2) > 0 and round({$paid}, 2) >= round({$total}, 2)",
            PaymentStatus::Partial => "round({$paid}, 2) > 0 and round({$paid}, 2) < round({$total}, 2)",
        });
    }

    private function day(?string $value, Shop $shop): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, $shop->timezone) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}

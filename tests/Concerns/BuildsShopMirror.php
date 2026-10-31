<?php

namespace Tests\Concerns;

use App\Models\Shop;
use App\Models\ShopRecord;

/**
 * Builds mirrored shop records the way the projector would store them.
 */
trait BuildsShopMirror
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mirror(Shop $shop, string $entity, int $id, array $data, ?string $label = null, bool $removed = false): ShopRecord
    {
        $factory = ShopRecord::factory()->for($shop)->entity($entity, ['id' => $id] + $data, $label);

        return ($removed ? $factory->removed() : $factory)->create();
    }

    /**
     * A small but complete shop: a customer with a car, a warehouse with stock,
     * orders in every state and two purchases.
     *
     *  ORD-1 pending      300 000 billed, 100 000 paid  -> partial
     *  ORD-2 completed    500 000 billed, 500 000 paid  -> paid (40 days ago)
     *  ORD-3 cancelled    900 000 billed, nothing paid  -> unpaid, not revenue
     *  ORD-4 in progress  no items                      -> unpaid
     *  ORD-5 removed in the shop                        -> never shown
     *
     * @return array{customer: ShopRecord, orders: array<int, ShopRecord>}
     */
    protected function seedShop(Shop $shop): array
    {
        $now = now()->utc();

        $customer = $this->mirror($shop, 'user', 1, ['type' => 'customer', 'first_name' => 'Ali', 'last_name' => 'Rezaei', 'mobile' => '09120000000'], 'Ali Rezaei');
        $supplierUser = $this->mirror($shop, 'user', 2, ['type' => 'supplier', 'first_name' => 'Sara', 'last_name' => 'Parts', 'mobile' => '09121111111'], 'Sara Parts');
        $this->mirror($shop, 'car', 1, ['name' => 'Pride'], 'Pride');
        $this->mirror($shop, 'user_car', 1, ['user_id' => 1, 'car_id' => 1], '#1');
        $this->mirror($shop, 'warehouse', 1, ['name' => 'Main'], 'Main');
        $this->mirror($shop, 'unit', 1, ['name' => 'Piece', 'symbol' => 'pc'], 'Piece');
        $this->mirror($shop, 'item', 1, ['name' => 'Brake pad'], 'Brake pad');
        $this->mirror($shop, 'item', 2, ['name' => 'Oil filter'], 'Oil filter');

        $order = fn (int $id, string $status, \DateTimeInterface $at, bool $removed = false) => $this->mirror($shop, 'order', $id, [
            'order_number' => "ORD-{$id}", 'status' => $status, 'user_id' => 1, 'user_car_id' => 1, 'warehouse_id' => 1,
            'description' => "Note {$id}", 'created_at' => $at->format('Y-m-d\TH:i:s.u\Z'),
        ], "ORD-{$id}", $removed);

        $orders = [
            1 => $order(1, 'pending', $now),
            2 => $order(2, 'completed', $now->copy()->subDays(40)),
            3 => $order(3, 'cancelled', $now),
            4 => $order(4, 'in_progress', $now),
            5 => $order(5, 'pending', $now, removed: true),
        ];

        $line = fn (int $id, int $orderId, int $itemId, string $quantity, string $price, string $total, bool $removed = false) => $this->mirror($shop, 'order_item', $id, [
            'order_id' => $orderId, 'item_id' => $itemId, 'unit_id' => 1, 'quantity' => $quantity, 'unit_price' => $price, 'total' => $total,
        ], removed: $removed);
        $line(1, 1, 1, '2.0000', '100000.0000', '200000.0000');
        $line(2, 1, 2, '1.0000', '100000.0000', '100000.0000');
        $line(3, 2, 1, '5.0000', '100000.0000', '500000.0000');
        $line(4, 3, 1, '9.0000', '100000.0000', '900000.0000');
        $line(5, 1, 1, '1.0000', '777777.0000', '777777.0000', removed: true);

        $payment = fn (int $id, int $orderId, string $amount, string $on, string $method = 'cash', bool $removed = false) => $this->mirror($shop, 'payment', $id, [
            'order_id' => $orderId, 'amount' => $amount, 'method' => $method, 'paid_at' => $on.'T00:00:00.000000Z',
        ], removed: $removed);
        $payment(1, 1, '100000.0000', $now->format('Y-m-d'));
        $payment(2, 2, '300000.0000', $now->copy()->subDays(40)->format('Y-m-d'), 'card');
        $payment(3, 2, '200000.0000', $now->copy()->subDays(39)->format('Y-m-d'), 'transfer');
        $payment(4, 1, '555555.0000', $now->format('Y-m-d'), removed: true);

        $this->mirror($shop, 'supplier', 1, ['user_id' => 2], 'sup-1');
        $purchase = fn (int $id, string $status) => $this->mirror($shop, 'purchase', $id, [
            'order_number' => "PO-{$id}", 'status' => $status, 'supplier_id' => 1, 'warehouse_id' => 1,
            'ordered_at' => $now->format('Y-m-d\TH:i:s.u\Z'), 'created_at' => $now->format('Y-m-d\TH:i:s.u\Z'),
        ], "PO-{$id}");
        $purchase(1, 'ordered');
        $purchase(2, 'draft');
        $this->mirror($shop, 'purchase_item', 1, ['purchase_id' => 1, 'item_id' => 1, 'total' => '360000.0000']);
        $this->mirror($shop, 'purchase_item', 2, ['purchase_id' => 2, 'item_id' => 1, 'total' => '999999.0000']);

        $stock = fn (int $id, int $itemId, string $quantity, ?string $minimum) => $this->mirror($shop, 'warehouse_item', $id, [
            'warehouse_id' => 1, 'item_id' => $itemId, 'quantity' => $quantity, 'min_quantity' => $minimum,
        ]);
        $stock(1, 1, '1.000', '2.000');
        $stock(2, 2, '10.000', '2.000');
        $this->mirror($shop, 'warehouse_item', 3, ['warehouse_id' => 1, 'item_id' => 2, 'quantity' => '0.000', 'min_quantity' => null]);

        return ['customer' => $customer, 'orders' => $orders];
    }
}

<?php

namespace App\Enums;

/**
 * Derived from what an order has been paid so far; never stored. The same
 * rule as jocko-shop, so both applications agree.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public static function for(float $total, float $paid): self
    {
        return match (true) {
            round($paid, 2) <= 0 => self::Unpaid,
            round($paid, 2) >= round($total, 2) => self::Paid,
            default => self::Partial,
        };
    }

    public function label(): string
    {
        return __('ui.shop_orders.payment_statuses.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'red',
            self::Partial => 'amber',
            self::Paid => 'green',
        };
    }
}

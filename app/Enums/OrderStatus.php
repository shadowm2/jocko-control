<?php

namespace App\Enums;

/**
 * The status of a mirrored shop order, as jocko-shop names it.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('ui.shop_orders.statuses.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::InProgress => 'amber',
            self::Completed => 'green',
            self::Cancelled => 'red',
        };
    }
}

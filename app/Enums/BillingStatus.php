<?php

namespace App\Enums;

/**
 * Where a shop stands with its plan. Only informational.
 */
enum BillingStatus: string
{
    case NoPlan = 'no_plan';
    case Paid = 'paid';
    case DueSoon = 'due_soon';
    case Expired = 'expired';

    /** Days before the paid-until date from which a shop counts as due soon. */
    public const DUE_SOON_DAYS = 7;

    public function label(): string
    {
        return __('ui.billing_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::NoPlan => 'zinc',
            self::Paid => 'green',
            self::DueSoon => 'amber',
            self::Expired => 'red',
        };
    }
}

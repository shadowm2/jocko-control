<?php

namespace App\Enums;

enum ShopStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return __('ui.shop_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Suspended => 'amber',
        };
    }
}

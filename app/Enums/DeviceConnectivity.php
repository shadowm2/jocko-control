<?php

namespace App\Enums;

/**
 * Derived (never stored) connectivity state of a device, in order of precedence.
 */
enum DeviceConnectivity: string
{
    case Revoked = 'revoked';
    case ShopSuspended = 'shop_suspended';
    case NeverConnected = 'never_connected';
    case Online = 'online';
    case Offline = 'offline';

    public function label(): string
    {
        return __('ui.connectivity.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Online => 'green',
            self::Offline => 'red',
            self::NeverConnected => 'zinc',
            self::ShopSuspended => 'amber',
            self::Revoked => 'zinc',
        };
    }
}

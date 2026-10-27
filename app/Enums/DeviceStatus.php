<?php

namespace App\Enums;

enum DeviceStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return __('ui.device_status.'.$this->value);
    }
}

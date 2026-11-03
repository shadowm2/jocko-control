<?php

namespace App\Console\Commands;

use App\Services\DeviceAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jocko:check-devices')]
#[Description('Email the administrators about devices that need attention, and when they are fine again')]
class CheckDevices extends Command
{
    public function handle(DeviceAlerts $alerts): int
    {
        $result = $alerts->check();

        $this->components->info("Alerted about {$result['alerted']} device(s); {$result['recovered']} recovered.");

        return self::SUCCESS;
    }
}

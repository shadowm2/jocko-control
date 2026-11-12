<?php

namespace App\Console\Commands;

use App\Services\DigestSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jocko:send-digests')]
#[Description('Send each shop its daily report once its send time has come, through the channels its settings choose')]
class SendDigests extends Command
{
    public function handle(DigestSender $digests): int
    {
        $sent = $digests->sendDue();

        $this->components->info("Daily report sent for {$sent} shop(s).");

        return self::SUCCESS;
    }
}

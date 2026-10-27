<?php

namespace App\Console\Commands;

use App\Services\RemoteCommandService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jocko:expire-commands')]
#[Description('Mark queued remote commands whose expiry time has passed as expired')]
class ExpireRemoteCommands extends Command
{
    public function handle(RemoteCommandService $commands): int
    {
        $expired = $commands->expireDue();

        $this->components->info("Expired {$expired} remote command(s).");

        return self::SUCCESS;
    }
}

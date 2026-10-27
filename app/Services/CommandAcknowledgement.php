<?php

namespace App\Services;

use App\Models\RemoteCommand;

final readonly class CommandAcknowledgement
{
    public function __construct(
        public RemoteCommand $command,
        public bool $alreadyAcknowledged,
    ) {}
}

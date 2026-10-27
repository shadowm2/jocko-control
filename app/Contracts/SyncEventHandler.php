<?php

namespace App\Contracts;

use App\Models\SyncEvent;

/**
 * Extension point for applying received events to central business tables.
 *
 * Handlers run after an event has been durably stored in the inbox and outside
 * of the ingestion transaction. A handler failure never discards the event.
 * Register implementations in config('jocko.sync.handlers').
 */
interface SyncEventHandler
{
    public function supports(SyncEvent $event): bool;

    public function handle(SyncEvent $event): void;
}

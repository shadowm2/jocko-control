<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the administrators who have a shop's pages open that something about
 * it changed, so those pages refresh themselves. It carries no data: a page
 * reloads what it shows through its own, authorized queries.
 *
 * Sent right away rather than queued: it is dispatched after the response, and
 * the pages must not wait for a queue worker.
 */
class ShopChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public const DATA = 'data';

    public const COMMANDS = 'commands';

    /**
     * @param  list<string>  $scopes  what changed: self::DATA, self::COMMANDS
     */
    public function __construct(
        public readonly string $shopId,
        public readonly array $scopes,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('shops.'.$this->shopId)];
    }

    public function broadcastAs(): string
    {
        return 'ShopChanged';
    }

    /**
     * @return array{scopes: list<string>}
     */
    public function broadcastWith(): array
    {
        return ['scopes' => $this->scopes];
    }
}

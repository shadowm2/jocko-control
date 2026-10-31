<?php

namespace App\Services;

use App\Events\ShopChanged;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects "this shop changed" during a request and announces it once, after
 * the response has been sent. A batch of a hundred device events is therefore
 * one broadcast, and a device never waits for the socket server.
 *
 * A broadcast that fails (the socket server is down) is logged and dropped:
 * the pages keep a slow poll as a fallback, and the data is never at risk.
 */
#[Singleton]
class ShopChangeBroadcaster
{
    /** @var array<string, array<string, true>> */
    private array $pending = [];

    private bool $flushScheduled = false;

    public function changed(string $shopId, string $scope): void
    {
        $this->pending[$shopId][$scope] = true;

        if (! $this->flushScheduled) {
            $this->flushScheduled = true;
            app()->terminating($this->flush(...));
        }
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $this->flushScheduled = false;

        foreach ($pending as $shopId => $scopes) {
            try {
                ShopChanged::dispatch($shopId, array_keys($scopes));
            } catch (Throwable $e) {
                Log::warning('Could not announce a shop change to the open pages.', ['shop_id' => $shopId, 'exception' => $e::class]);
            }
        }
    }
}

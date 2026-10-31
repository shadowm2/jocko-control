<?php

namespace App\Livewire\Concerns;

use App\Events\ShopChanged;

/**
 * Makes a page that shows one shop's data refresh itself the moment that shop
 * changes, through the private `shops.{shopId}` channel (see ShopChanged).
 *
 * The component needs a `$shopId` and may list which kinds of change matter to
 * it in liveScopes(); a change of another kind does not re-render it. The
 * page keeps a slow `wire:poll` as a fallback for a dropped connection.
 */
trait RefreshesLive
{
    /**
     * @return list<string>
     */
    protected function liveScopes(): array
    {
        return [ShopChanged::DATA];
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ["echo-private:shops.{$this->shopId},.ShopChanged" => 'refreshLive'];
    }

    /**
     * Livewire re-renders after any handled event; skip that when the change
     * is not one this page shows.
     *
     * @param  array{scopes?: list<string>}  $payload
     */
    public function refreshLive(array $payload = []): void
    {
        if (array_intersect($payload['scopes'] ?? [], $this->liveScopes()) === []) {
            $this->skipRender();
        }
    }
}

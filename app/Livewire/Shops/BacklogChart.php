<?php

namespace App\Livewire\Shops;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\Shop;
use App\Services\BacklogHistory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * How many changes a shop's devices had waiting to be sent, over the last day or week.
 */
class BacklogChart extends Component
{
    use AuthorizesAdmin;

    #[Locked]
    public string $shopId;

    /** @var '24h'|'7d' */
    public string $range = '24h';

    public function render(BacklogHistory $history): View
    {
        $range = array_key_exists($this->range, BacklogHistory::RANGES) ? $this->range : '24h';
        $points = $history->series(Shop::query()->findOrFail($this->shopId), $range);
        $values = array_filter(array_column($points, 'value'), fn (?int $value): bool => $value !== null);

        return view('livewire.shops.backlog-chart', [
            'points' => $points,
            'range' => $range,
            'peak' => $values === [] ? null : max($values),
            'latest' => $values === [] ? null : end($values),
        ]);
    }
}

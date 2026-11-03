<?php

namespace App\Livewire\Payments;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Services\PaymentsOverview;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * What every shop collected today, this month and in the last 30 days.
 */
class Index extends Component
{
    use AuthorizesAdmin;

    public function render(PaymentsOverview $overview): View
    {
        return view('livewire.payments.index', $overview->summary())
            ->layout('components.layouts.app', ['title' => __('ui.payments.title')]);
    }
}

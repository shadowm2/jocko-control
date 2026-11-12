<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Services\DashboardMetrics;
use App\Services\FleetVersions;
use App\Services\MirrorCheck;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Dashboard extends Component
{
    use AuthorizesAdmin;

    public function render(DashboardMetrics $metrics, FleetVersions $versions, MirrorCheck $mirror): View
    {
        return view('livewire.dashboard', [
            'summary' => $metrics->summary(),
            'attention' => $metrics->attention(),
            'billing' => $metrics->billingAttention(),
            'drifted' => $mirror->drifted(),
            'activity' => $metrics->recentActivity(),
            'syncErrors' => $metrics->recentSyncErrors(),
            'versions' => $versions->summary(),
        ])->layout('components.layouts.app', ['title' => __('ui.dashboard.title')]);
    }
}

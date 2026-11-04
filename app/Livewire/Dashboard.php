<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Services\DashboardMetrics;
use App\Services\FleetVersions;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Dashboard extends Component
{
    use AuthorizesAdmin;

    public function render(DashboardMetrics $metrics, FleetVersions $versions): View
    {
        return view('livewire.dashboard', [
            'summary' => $metrics->summary(),
            'attention' => $metrics->attention(),
            'activity' => $metrics->recentActivity(),
            'syncErrors' => $metrics->recentSyncErrors(),
            'versions' => $versions->summary(),
        ])->layout('components.layouts.app', ['title' => __('ui.dashboard.title')]);
    }
}

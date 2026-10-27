<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Livewire update requests are plain HTTP requests: authorize every one of them,
 * not just the initial page load.
 */
trait AuthorizesAdmin
{
    public function bootAuthorizesAdmin(): void
    {
        Gate::authorize('manage-control');
    }
}

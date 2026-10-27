<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAdmin;
use App\Models\ShopDevice;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap(['shop_device' => ShopDevice::class]);

        if (config('jocko.require_https')) {
            URL::forceScheme('https');
        }

        // Every signed-in user is an administrator; deactivated accounts lose access.
        Gate::define('manage-control', fn (mixed $user): bool => $user instanceof User && $user->is_active);

        // Livewire replays only some route middleware on its update requests.
        Livewire::addPersistentMiddleware([EnsureAdmin::class]);

        RateLimiter::for('device-ip', fn (Request $request) => Limit::perMinute((int) config('jocko.api.rate_limit_per_ip'))->by('ip:'.$request->ip()));

        RateLimiter::for('device-api', fn (Request $request) => Limit::perMinute((int) config('jocko.api.rate_limit_per_device'))
            ->by('device:'.($request->user()?->getKey() ?? $request->ip())));
    }
}

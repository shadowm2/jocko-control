<?php

use App\Http\Controllers\Auth\LoginController;
use App\Livewire\Audit;
use App\Livewire\Dashboard;
use App\Livewire\Events;
use App\Livewire\Shops;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
|
| There is deliberately no registration route: administrators are created
| with `php artisan jocko:create-admin`.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'admin'])->group(function (): void {
    Route::livewire('/', Dashboard::class)->name('dashboard');

    Route::livewire('shops', Shops\Index::class)->name('shops.index');
    Route::livewire('shops/create', Shops\Form::class)->name('shops.create');
    Route::livewire('shops/{shop}', Shops\Show::class)->name('shops.show');
    Route::livewire('shops/{shop}/edit', Shops\Form::class)->name('shops.edit');

    Route::livewire('shops/{shop}/dashboard', Shops\Dashboard::class)->name('shops.dashboard');
    Route::livewire('shops/{shop}/orders', Shops\Orders\Index::class)->name('shops.orders');
    Route::livewire('shops/{shop}/orders/{record}', Shops\Orders\Show::class)->name('shops.orders.show');

    Route::livewire('shops/{shop}/data', Shops\Data\Overview::class)->name('shops.data');
    Route::livewire('shops/{shop}/data/{entity}', Shops\Data\Records::class)->name('shops.data.entity');
    Route::livewire('shops/{shop}/data/{entity}/{record}', Shops\Data\Show::class)->name('shops.data.show');

    Route::livewire('events', Events\Index::class)->name('events.index');
    Route::livewire('audit', Audit\Index::class)->name('audit.index');
});

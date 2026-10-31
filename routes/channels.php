<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

// Only administrators may listen to a shop's changes; every active user is one.
Broadcast::channel('shops.{shopId}', fn ($user): bool => Gate::forUser($user)->allows('manage-control'));

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

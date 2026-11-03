<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('jocko:expire-commands')->everyMinute()->withoutOverlapping();
Schedule::command('jocko:prune')->dailyAt('03:30')->withoutOverlapping();

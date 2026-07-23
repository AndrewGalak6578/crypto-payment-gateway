<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('settlements:reconcile-attempts --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('settlements:reconcile-gas-fundings --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('settlements:reap-reservations --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('webhooks:dispatch-pending --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

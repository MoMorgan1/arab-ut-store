<?php

use App\Console\Commands\PublishOrderPaidEvents;
use App\Console\Commands\PurgeGuestCartClaims;
use App\Console\Commands\RefreshDisplayExchangeRates;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RefreshDisplayExchangeRates::class)->daily();
Schedule::command(PurgeGuestCartClaims::class)->hourly()->withoutOverlapping();
Schedule::command(PublishOrderPaidEvents::class)->everyMinute()->withoutOverlapping();

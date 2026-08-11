<?php

use App\Console\Commands\PurgeCartItemSecrets;
use App\Console\Commands\PurgeGuestCartClaims;
use App\Console\Commands\RefreshDisplayExchangeRates;
use App\Console\Commands\RefreshStoreReviews;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RefreshDisplayExchangeRates::class)->daily();
Schedule::command(RefreshStoreReviews::class)->hourly()->withoutOverlapping(15)->onOneServer();
Schedule::command(PurgeCartItemSecrets::class)->hourly()->withoutOverlapping();
Schedule::command(PurgeGuestCartClaims::class)->hourly()->withoutOverlapping();

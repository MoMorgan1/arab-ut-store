<?php

use App\Http\Controllers\Automation\CatalogSnapshotController;
use App\Http\Controllers\Automation\CoinsPricingRunController;
use App\Http\Controllers\Automation\SbcCatalogSnapshotController;
use App\Http\Controllers\Automation\SbcCoinsPricingReadController;
use App\Http\Middleware\VerifyN8nCatalogSignature;
use App\Http\Middleware\VerifyN8nPricingSignature;
use App\Http\Middleware\VerifyN8nSbcCatalogSignature;
use App\Http\Middleware\VerifyN8nSbcPricingReadSignature;
use Illuminate\Support\Facades\Route;

Route::post('/automation/v1/catalog/snapshots', CatalogSnapshotController::class)
    ->middleware(['throttle:automation-catalog', VerifyN8nCatalogSignature::class])
    ->name('automation.catalog.snapshots.store');

Route::post('/automation/v1/catalog/sbc/snapshots', SbcCatalogSnapshotController::class)
    ->middleware(['throttle:automation-catalog', VerifyN8nSbcCatalogSignature::class])
    ->name('automation.catalog.sbc.snapshots.store');

Route::post('/automation/v1/pricing/coins/runs', CoinsPricingRunController::class)
    ->middleware(['throttle:automation-pricing', VerifyN8nPricingSignature::class])
    ->name('automation.pricing.coins.runs.store');

Route::get('/automation/v1/pricing/coins/sbc-bases', SbcCoinsPricingReadController::class)
    ->middleware(['throttle:automation-sbc-pricing-read', VerifyN8nSbcPricingReadSignature::class])
    ->name('automation.pricing.coins.sbc-bases.show');

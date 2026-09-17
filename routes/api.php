<?php

use App\Http\Controllers\Automation\CatalogSnapshotController;
use App\Http\Controllers\Automation\CoinsPricingBaselineController;
use App\Http\Controllers\Automation\CoinsPricingRunController;
use App\Http\Controllers\Automation\FulfillmentPlacementController;
use App\Http\Controllers\Automation\SbcCatalogSnapshotController;
use App\Http\Controllers\Automation\SbcCoinsPricingReadController;
use App\Http\Controllers\Payments\PaylinkWebhookController;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\VerifyN8nCatalogSignature;
use App\Http\Middleware\VerifyN8nFulfillmentSignature;
use App\Http\Middleware\VerifyN8nPricingBaselineSignature;
use App\Http\Middleware\VerifyN8nPricingSignature;
use App\Http\Middleware\VerifyN8nSbcCatalogSignature;
use App\Http\Middleware\VerifyN8nSbcPricingReadSignature;
use App\Http\Middleware\VerifyPaylinkWebhook;
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

// What the last applied run published, for a pricing run that has nothing of
// its own to carry forward. n8n hands a MANUAL execution empty workflow
// memory, so without this the only way to see a run was to wait for the hour.
Route::get('/automation/v1/pricing/coins/baseline', CoinsPricingBaselineController::class)
    ->middleware([VerifyN8nPricingBaselineSignature::class, 'throttle:automation-pricing-baseline'])
    ->name('automation.pricing.coins.baseline.show');

Route::get('/automation/v1/pricing/coins/sbc-bases', SbcCoinsPricingReadController::class)
    ->middleware([VerifyN8nSbcPricingReadSignature::class, 'throttle:automation-sbc-pricing-read'])
    ->name('automation.pricing.coins.sbc-bases.show');

// Signature first, unlike the older automation routes: a forged request must not burn the real
// credential's limiter bucket, because a placement that never lands leaves a paid order invisible.
Route::post('/automation/v1/fulfillment/placements', FulfillmentPlacementController::class)
    ->middleware([VerifyN8nFulfillmentSignature::class, 'throttle:automation-fulfillment'])
    ->name('automation.fulfillment.placements.store');

Route::post('/payments/paylink/webhook', PaylinkWebhookController::class)
    ->middleware([NoStore::class, VerifyPaylinkWebhook::class, 'throttle:paylink-webhook'])
    ->name('payments.paylink.webhook');

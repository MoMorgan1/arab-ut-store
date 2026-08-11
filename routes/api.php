<?php

use App\Http\Controllers\Automation\CatalogSnapshotController;
use App\Http\Middleware\VerifyN8nCatalogSignature;
use Illuminate\Support\Facades\Route;

Route::post('/automation/v1/catalog/snapshots', CatalogSnapshotController::class)
    ->middleware(['throttle:automation-catalog', VerifyN8nCatalogSignature::class])
    ->name('automation.catalog.snapshots.store');
